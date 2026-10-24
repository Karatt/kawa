<?php

declare(strict_types=1);

require_once __DIR__ . '/src/IssueLogger.php';
require_once __DIR__ . '/src/NomenclatureLoader.php';
require_once __DIR__ . '/src/OrderReconciler.php';

const DEFAULT_ORDERS_PATH = __DIR__ . '/data/orders.json';
const DEFAULT_NOMENCLATURE_PATH = __DIR__ . '/data/nomenclature_1c.csv';
const REPORT_PATH = __DIR__ . '/var/reconciliation-report.csv';
const LOG_PATH = __DIR__ . '/var/sync.log';

/** @return array<int, mixed> */
function loadOrders(string $path): array
{
    $contents = file_get_contents($path);
    if ($contents === false) {
        throw new RuntimeException("Не удалось прочитать заказы: {$path}");
    }

    $response = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
    if (($response['success'] ?? false) !== true || !is_array($response['data']['orders'] ?? null)) {
        throw new RuntimeException('Ответ API не содержит data.orders или помечен как неуспешный.');
    }

    return $response['data']['orders'];
}

/** @param array<int, array<string, scalar>> $rows */
function writeReport(string $path, array $rows): void
{
    $handle = fopen($path, 'wb');
    if ($handle === false) {
        throw new RuntimeException("Не удалось создать отчёт: {$path}");
    }

    fwrite($handle, "\xEF\xBB\xBF");
    $columns = [
        'ID заказа' => 'order_id',
        'Статус API' => 'status',
        'Артикул' => 'sku',
        'Количество' => 'quantity',
        'Цена заказа, BYN' => 'order_price_byn',
        'Цена 1С, BYN' => 'price_1c_byn',
        'Остаток 1С' => 'stock_1c',
        'Резерв по активным заказам' => 'reserved_active_orders',
        'Остаток после резерва' => 'stock_balance_after_reserve',
        'Записей с таким ID' => 'duplicate_order_records',
        'Расхождения' => 'issues',
        'Действие' => 'action',
    ];
    fputcsv($handle, array_keys($columns), ';', '"', '');
    foreach ($rows as $row) {
        $reportRow = [];
        foreach ($columns as $field) {
            $reportRow[] = $field === 'issues' ? describeIssues((string) $row[$field]) : $row[$field];
        }
        fputcsv($handle, $reportRow, ';', '"', '');
    }
    fclose($handle);
}

function describeIssues(string $issues): string
{
    $labels = [
        'CANCELLED' => 'Заказ отменён',
        'DUPLICATE_1C_SKU' => 'В 1С несколько карточек с этим артикулом',
        'DUPLICATE_ORDER_ID' => 'Повтор ID заказа',
        'EMPTY_OR_INVALID_ITEMS' => 'В заказе нет товарных строк',
        'INSUFFICIENT_AGGREGATE_STOCK' => 'Не хватает остатка с учётом всех активных заказов',
        'INVALID_PRICE' => 'Некорректная цена в заказе',
        'INVALID_QUANTITY' => 'Некорректное количество',
        'INVALID_SKU' => 'Некорректный артикул',
        'ORDER_TOTAL_MISMATCH' => 'Итог заказа не равен сумме строк',
        'PRICE_MISMATCH' => 'Цена заказа отличается от цены 1С',
        'SKU_NOT_FOUND' => 'Артикул отсутствует в 1С',
        'UNSUPPORTED_STATUS' => 'Статус заказа не поддержан для передачи',
    ];

    return implode('; ', array_map(static fn (string $issue): string => $labels[$issue] ?? $issue, array_filter(explode('|', $issues))));
}

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "Скрипт предназначен для запуска из командной строки.\n");
    exit(1);
}

$ordersPath = $argv[1] ?? DEFAULT_ORDERS_PATH;
$nomenclaturePath = $argv[2] ?? DEFAULT_NOMENCLATURE_PATH;
if (!is_dir(__DIR__ . '/var') && !mkdir(__DIR__ . '/var', 0775, true) && !is_dir(__DIR__ . '/var')) {
    fwrite(STDERR, "Не удалось создать каталог var.\n");
    exit(1);
}
file_put_contents(LOG_PATH, '');

try {
    $logger = new IssueLogger(LOG_PATH);
    $nomenclature = (new NomenclatureLoader())->load($nomenclaturePath, $logger);
    $result = (new OrderReconciler())->reconcile(loadOrders($ordersPath), $nomenclature['products'], $nomenclature['duplicateSkus'], $logger);
    writeReport(REPORT_PATH, $result['rows']);

    printf(
        "Готово: строк отчёта — %d; готово к передаче — %d; требует проверки — %d.\nОтчёт: %s\nЛог: %s\n",
        $result['summary']['total_rows'],
        $result['summary']['ready_rows'],
        $result['summary']['blocked_rows'],
        REPORT_PATH,
        LOG_PATH,
    );
} catch (Throwable $exception) {
    fwrite(STDERR, "Ошибка запуска: {$exception->getMessage()}\n");
    exit(1);
}
