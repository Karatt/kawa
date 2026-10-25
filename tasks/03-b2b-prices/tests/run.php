<?php
/**
 * Сценарии проверки исправлений задания №03.
 * Запуск: php tests/run.php
 */

declare(strict_types=1);

require __DIR__ . '/../src/PriceResolver.php';

function readCsv(string $file): array
{
    $handle = fopen($file, 'r');
    $headers = fgetcsv($handle, null, ';', '"', '');
    $rows = [];

    while (($row = fgetcsv($handle, null, ';', '"', '')) !== false) {
        $rows[] = array_combine($headers, $row);
    }

    return $rows;
}

function createResolver(DateTimeImmutable $now, string $cacheFile): PriceResolver
{
    $basePath = __DIR__ . '/..';
    $prices = [];
    foreach (readCsv($basePath . '/data/prices.csv') as $row) {
        $prices[$row['sku']][$row['price_type']] = (float) $row['price'];
    }

    $clients = array_column(readCsv($basePath . '/data/clients.csv'), null, 'client_id');
    $products = array_column(readCsv($basePath . '/data/products.csv'), null, 'sku');
    $rules = json_decode((string) file_get_contents($basePath . '/data/discount_rules.json'), true)['rules'];

    return new PriceResolver($prices, $clients, $products, $rules, new FileCache($cacheFile, $now), $now);
}

function assertFloat(float $expected, float $actual, string $description): void
{
    if (abs($expected - $actual) >= 0.005) {
        throw new RuntimeException("{$description}: ожидалось {$expected}, получено {$actual}");
    }

    echo "[OK] {$description}\n";
}

function assertPriceMissing(PriceResolver $resolver): void
{
    try {
        $resolver->basePrice('K-003', 'B-105');
    } catch (PriceNotFoundException) {
        echo "[OK] отсутствующая дилерская цена не подменяется розничной\n";
        return;
    }

    throw new RuntimeException('Отсутствующая дилерская цена должна вызывать исключение');
}

$timezone = new DateTimeZone('Europe/Minsk');
$now = new DateTimeImmutable('2026-10-22 12:00:00', $timezone);
$cacheFile = tempnam(sys_get_temp_dir(), 'price-resolver-');

try {
    file_put_contents($cacheFile, json_encode([
        'price:K-004:B-103:1' => [
            'value' => 15.48,
            'expires_at' => '2026-10-23T09:05:00+03:00',
            'stored_at' => '2026-10-22T09:05:00+03:00',
        ],
    ]));

    $resolver = createResolver($now, $cacheFile);
    assertPriceMissing($resolver);
    assertFloat(0.0, $resolver->discountPercent('K-001', 'B-101', 1), 'сентябрьская акция не действует в октябре');
    assertFloat(5.0, $resolver->discountPercent('K-002', 'B-106', 12), 'применяется максимум из скидок, а не сумма');
    assertFloat(3.51, $resolver->price('K-002', 'B-106', 12), 'цена K-002 на B-106 рассчитана с максимальной скидкой');
    assertFloat(15.72, $resolver->price('K-004', 'B-103'), 'смена типа цен не использует старый ключ кеша');
    assertFloat(4.0, $resolver->discountPercent('K-005', 'B-107', 1), 'октябрьская акция действует 22 октября');
    assertFloat(4.0, createResolver(new DateTimeImmutable('2026-10-31 12:00:00', $timezone), $cacheFile)->discountPercent('K-005', 'B-107', 1), 'акция действует в последний день');
    assertFloat(0.0, createResolver(new DateTimeImmutable('2026-11-01 12:00:00', $timezone), $cacheFile)->discountPercent('K-005', 'B-107', 1), 'акция не действует после последнего дня');
} finally {
    unlink($cacheFile);
}

echo "Все сценарии пройдены\n";
