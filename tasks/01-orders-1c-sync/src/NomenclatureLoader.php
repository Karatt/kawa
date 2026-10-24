<?php

declare(strict_types=1);

final class NomenclatureLoader
{
    /**
     * @return array{products: array<string, array{code: string, name: string, price: float, stock: float}>, duplicateSkus: array<string, true>}
     */
    public function load(string $path, IssueLogger $logger): array
    {
        $handle = fopen($path, 'rb');
        if ($handle === false) {
            throw new RuntimeException("Не удалось открыть выгрузку 1С: {$path}");
        }

        $header = fgetcsv($handle, 0, ';', '"', '');
        if ($header === false) {
            throw new RuntimeException('Выгрузка 1С пуста.');
        }

        $header = array_map(static fn (string $value): string => preg_replace('/^\xEF\xBB\xBF/', '', trim($value)) ?? '', $header);
        $columns = array_flip($header);
        foreach (['Код', 'Артикул', 'Наименование', 'Цена', 'Остаток'] as $requiredColumn) {
            if (!array_key_exists($requiredColumn, $columns)) {
                throw new RuntimeException("В выгрузке 1С нет колонки «{$requiredColumn}».");
            }
        }

        $products = [];
        $duplicateSkus = [];
        $lineNumber = 1;
        while (($row = fgetcsv($handle, 0, ';', '"', '')) !== false) {
            $lineNumber++;
            if (count($row) !== count($header)) {
                $logger->error('Пропущена битая строка выгрузки 1С: неверное число колонок.', ['line' => $lineNumber]);
                continue;
            }

            $sku = self::normalizeSku($row[$columns['Артикул']]);
            $price = self::parseNumber($row[$columns['Цена']]);
            $stock = self::parseNumber($row[$columns['Остаток']]);
            if ($sku === '' || $price === null || $stock === null || $price < 0 || $stock < 0) {
                $logger->error('Пропущена некорректная строка выгрузки 1С.', ['line' => $lineNumber, 'sku' => $sku]);
                continue;
            }

            if (isset($products[$sku])) {
                $duplicateSkus[$sku] = true;
                unset($products[$sku]);
                $logger->error('Выгрузка 1С содержит несколько карточек с одним артикулом.', ['line' => $lineNumber, 'sku' => $sku]);
                continue;
            }

            if (isset($duplicateSkus[$sku])) {
                $logger->error('Дополнительная карточка с дублирующимся артикулом в выгрузке 1С.', ['line' => $lineNumber, 'sku' => $sku]);
                continue;
            }

            $products[$sku] = [
                'code' => trim($row[$columns['Код']]),
                'name' => trim($row[$columns['Наименование']]),
                'price' => $price,
                'stock' => $stock,
            ];
        }

        fclose($handle);

        return ['products' => $products, 'duplicateSkus' => $duplicateSkus];
    }

    public static function normalizeSku(mixed $sku): string
    {
        return strtoupper(trim((string) $sku));
    }

    public static function parseNumber(mixed $value): ?float
    {
        if (!is_string($value) && !is_int($value) && !is_float($value)) {
            return null;
        }

        $normalized = str_replace(["\xC2\xA0", ' '], '', trim((string) $value));
        $normalized = str_replace(',', '.', $normalized);
        if ($normalized === '' || !is_numeric($normalized)) {
            return null;
        }

        $number = (float) $normalized;
        return is_finite($number) ? $number : null;
    }
}
