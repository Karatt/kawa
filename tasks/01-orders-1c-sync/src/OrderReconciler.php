<?php

declare(strict_types=1);

final class OrderReconciler
{
    /** @var array<string, true> */
    private const PROCESSABLE_STATUSES = ['new' => true, 'confirmed' => true];

    /**
     * @param array<int, mixed> $orders
     * @param array<string, array{code: string, name: string, price: float, stock: float}> $products
     * @param array<string, true> $duplicateSkus
     * @return array{rows: array<int, array<string, scalar>>, summary: array<string, int>}
     */
    public function reconcile(array $orders, array $products, array $duplicateSkus, IssueLogger $logger): array
    {
        $orderOccurrences = $this->countOrderOccurrences($orders, $logger);
        $reservedBySku = $this->collectDemand($orders, $orderOccurrences, $products, $duplicateSkus, $logger);
        $rows = [];
        $seenOrders = [];

        foreach ($orders as $sourceIndex => $order) {
            if (!is_array($order)) {
                $logger->error('Пропущена запись заказа, которая не является объектом.', ['record' => $sourceIndex + 1]);
                continue;
            }

            $orderId = trim((string) ($order['id'] ?? ''));
            if ($orderId === '' || isset($seenOrders[$orderId])) {
                continue;
            }
            $seenOrders[$orderId] = true;

            $status = trim((string) ($order['status'] ?? ''));
            $items = $order['items'] ?? null;
            $baseIssues = [];
            if (($orderOccurrences[$orderId] ?? 0) > 1) {
                $baseIssues[] = 'DUPLICATE_ORDER_ID';
            }
            if (!isset(self::PROCESSABLE_STATUSES[$status])) {
                $baseIssues[] = $status === 'cancelled' ? 'CANCELLED' : 'UNSUPPORTED_STATUS';
            }
            if (!is_array($items) || $items === []) {
                $baseIssues[] = 'EMPTY_OR_INVALID_ITEMS';
                $logger->error('У заказа нет ни одной корректной товарной строки.', ['order_id' => $orderId]);
                $rows[] = $this->row($orderId, $status, '', 0, null, null, null, 0, $baseIssues, $orderOccurrences[$orderId] ?? 1);
                continue;
            }

            $validOrderLineSum = 0.0;
            $allLinesHavePrice = true;
            foreach ($items as $itemIndex => $item) {
                $line = is_array($item) ? $item : [];
                $sku = NomenclatureLoader::normalizeSku($line['sku'] ?? '');
                $quantity = $this->parseQuantity($line['quantity'] ?? null);
                $orderPrice = NomenclatureLoader::parseNumber($line['price'] ?? null);
                $issues = $baseIssues;

                if ($sku === '') {
                    $issues[] = 'INVALID_SKU';
                }
                if ($quantity === null) {
                    $issues[] = 'INVALID_QUANTITY';
                }
                if ($orderPrice === null || $orderPrice < 0) {
                    $issues[] = 'INVALID_PRICE';
                    $allLinesHavePrice = false;
                }
                if ($sku === '' || $quantity === null || $orderPrice === null || $orderPrice < 0) {
                    $logger->error('Некорректная товарная строка заказа.', [
                        'order_id' => $orderId,
                        'sku' => $sku,
                        'item_index' => $itemIndex + 1,
                    ]);
                }

                $product = $products[$sku] ?? null;
                if ($sku !== '' && isset($duplicateSkus[$sku])) {
                    $issues[] = 'DUPLICATE_1C_SKU';
                } elseif ($sku !== '' && $product === null) {
                    $issues[] = 'SKU_NOT_FOUND';
                }
                if ($product !== null && $orderPrice !== null && abs($product['price'] - $orderPrice) > 0.009) {
                    $issues[] = 'PRICE_MISMATCH';
                }
                if ($product !== null && ($reservedBySku[$sku] ?? 0.0) > $product['stock'] + 0.0001) {
                    $issues[] = 'INSUFFICIENT_AGGREGATE_STOCK';
                }

                if ($quantity !== null && $orderPrice !== null) {
                    $validOrderLineSum += $quantity * $orderPrice;
                }
                $rows[] = $this->row(
                    $orderId,
                    $status,
                    $sku,
                    $quantity ?? 0,
                    $orderPrice,
                    $product['price'] ?? null,
                    $product['stock'] ?? null,
                    $reservedBySku[$sku] ?? 0,
                    $issues,
                    $orderOccurrences[$orderId] ?? 1,
                );
            }

            $declaredTotal = NomenclatureLoader::parseNumber($order['total'] ?? null);
            if ($allLinesHavePrice && ($declaredTotal === null || abs($declaredTotal - $validOrderLineSum) > 0.009)) {
                foreach ($rows as &$row) {
                    if ($row['order_id'] === $orderId) {
                        $row['issues'] = $this->appendIssue((string) $row['issues'], 'ORDER_TOTAL_MISMATCH');
                        $row['action'] = $this->actionForIssues((string) $row['issues']);
                    }
                }
                unset($row);
                $logger->warning('Сумма заказа не совпадает с суммой его строк.', ['order_id' => $orderId]);
            }
        }

        $summary = ['total_rows' => count($rows), 'ready_rows' => 0, 'blocked_rows' => 0];
        foreach ($rows as $row) {
            if ($row['issues'] === '') {
                $summary['ready_rows']++;
            } else {
                $summary['blocked_rows']++;
            }
        }

        return ['rows' => $rows, 'summary' => $summary];
    }

    /** @param array<int, mixed> $orders @return array<string, int> */
    private function countOrderOccurrences(array $orders, IssueLogger $logger): array
    {
        $occurrences = [];
        foreach ($orders as $sourceIndex => $order) {
            if (!is_array($order)) {
                continue;
            }
            $orderId = trim((string) ($order['id'] ?? ''));
            if ($orderId === '') {
                $logger->error('У заказа отсутствует идентификатор.', ['record' => $sourceIndex + 1]);
                continue;
            }
            $occurrences[$orderId] = ($occurrences[$orderId] ?? 0) + 1;
        }

        return $occurrences;
    }

    /**
     * @param array<int, mixed> $orders
     * @param array<string, int> $orderOccurrences
     * @param array<string, array{code: string, name: string, price: float, stock: float}> $products
     * @param array<string, true> $duplicateSkus
     * @return array<string, float>
     */
    private function collectDemand(array $orders, array $orderOccurrences, array $products, array $duplicateSkus, IssueLogger $logger): array
    {
        $demand = [];
        $seenOrders = [];
        foreach ($orders as $sourceIndex => $order) {
            if (!is_array($order)) {
                continue;
            }
            $orderId = trim((string) ($order['id'] ?? ''));
            if ($orderId === '' || isset($seenOrders[$orderId])) {
                continue;
            }
            $seenOrders[$orderId] = true;
            $status = trim((string) ($order['status'] ?? ''));
            if (!isset(self::PROCESSABLE_STATUSES[$status])) {
                continue;
            }
            if (($orderOccurrences[$orderId] ?? 0) > 1) {
                $logger->warning('Повторный заказ исключён из резерва остатков.', ['order_id' => $orderId]);
                continue;
            }
            if (!is_array($order['items'] ?? null)) {
                continue;
            }
            foreach ($order['items'] as $item) {
                if (!is_array($item)) {
                    continue;
                }
                $sku = NomenclatureLoader::normalizeSku($item['sku'] ?? '');
                $quantity = $this->parseQuantity($item['quantity'] ?? null);
                if ($sku === '' || $quantity === null || !isset($products[$sku]) || isset($duplicateSkus[$sku])) {
                    continue;
                }
                $demand[$sku] = ($demand[$sku] ?? 0.0) + $quantity;
            }
        }

        return $demand;
    }

    private function parseQuantity(mixed $value): ?float
    {
        $quantity = NomenclatureLoader::parseNumber($value);
        if ($quantity === null || $quantity <= 0 || floor($quantity) !== $quantity) {
            return null;
        }

        return $quantity;
    }

    /** @param array<int, string> $issues @return array<string, scalar> */
    private function row(string $orderId, string $status, string $sku, float $quantity, ?float $orderPrice, ?float $price1c, ?float $stock1c, float $reserved, array $issues, int $duplicates): array
    {
        $issueList = implode('|', array_values(array_unique($issues)));

        return [
            'order_id' => $orderId,
            'status' => $status,
            'sku' => $sku,
            'quantity' => $quantity,
            'order_price_byn' => $orderPrice ?? '',
            'price_1c_byn' => $price1c ?? '',
            'stock_1c' => $stock1c ?? '',
            'reserved_active_orders' => $reserved,
            'stock_balance_after_reserve' => $stock1c === null ? '' : $stock1c - $reserved,
            'duplicate_order_records' => $duplicates,
            'issues' => $issueList,
            'action' => $this->actionForIssues($issueList),
        ];
    }

    private function appendIssue(string $issues, string $issue): string
    {
        return implode('|', array_unique(array_filter([...explode('|', $issues), $issue])));
    }

    private function actionForIssues(string $issues): string
    {
        if ($issues === '') {
            return 'Передать в 1С';
        }
        if (str_contains($issues, 'CANCELLED')) {
            return 'Не передавать: заказ отменён';
        }
        if (str_contains($issues, 'DUPLICATE_ORDER_ID')) {
            return 'Не передавать до проверки дубля';
        }

        return 'Не передавать до исправления расхождений';
    }
}
