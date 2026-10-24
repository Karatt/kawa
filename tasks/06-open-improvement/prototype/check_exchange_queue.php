<?php
/**
 * Прототип Exchange Watchdog.
 * Запуск: php check_exchange_queue.php [путь-к-json] [время-проверки-ISO-8601]
 */

declare(strict_types=1);

const MAX_PENDING_AGE_MINUTES = 15;
const MAX_RETRIES_FOR_PERMANENT_ERROR = 3;
const PERMANENT_DATA_ERROR_STATUSES = [422];

$inputFile = $argv[1] ?? __DIR__ . '/queue-snapshot.json';
$now = new DateTimeImmutable($argv[2] ?? '2026-10-22T11:05:00+03:00');

if (!is_file($inputFile)) {
    fwrite(STDERR, "Снимок очереди не найден: {$inputFile}\n");
    exit(1);
}

try {
    $snapshot = json_decode((string) file_get_contents($inputFile), true, flags: JSON_THROW_ON_ERROR);
} catch (JsonException $exception) {
    fwrite(STDERR, "Некорректный JSON снимка: {$exception->getMessage()}\n");
    exit(1);
}

$pendingJobs = array_values(array_filter(
    $snapshot['jobs'] ?? [],
    static fn (array $job): bool => $job['status'] === 'pending',
));

if ($pendingJobs === []) {
    echo "[OK] Очередь обмена пуста\n";
    exit(0);
}

$alerts = [];
$oldestAgeMinutes = 0;

foreach ($pendingJobs as $job) {
    $createdAt = new DateTimeImmutable($job['created_at']);
    $ageMinutes = (int) floor(($now->getTimestamp() - $createdAt->getTimestamp()) / 60);
    $oldestAgeMinutes = max($oldestAgeMinutes, $ageMinutes);
    $lastError = $job['last_error'] ?? null;
    $httpStatus = $lastError['http_status'] ?? null;
    $isPermanentDataError = in_array($httpStatus, PERMANENT_DATA_ERROR_STATUSES, true);

    if ($isPermanentDataError && $job['attempts'] >= MAX_RETRIES_FOR_PERMANENT_ERROR) {
        $alerts[] = sprintf(
            '[CRITICAL] job=%d order=%s age=%dmin attempts=%d HTTP=%d: %s. Действие: не повторять автоматически; проверить клиента %s в 1С и передать его до повтора заказа.',
            $job['id'],
            $job['order_no'],
            $ageMinutes,
            $job['attempts'],
            $httpStatus,
            $lastError['message'],
            $lastError['client_id'] ?? 'не указан',
        );
    } elseif (is_int($httpStatus) && $httpStatus >= 400 && $httpStatus < 500) {
        $alerts[] = sprintf(
            '[WARNING] job=%d order=%s HTTP=%d не классифицирован как постоянная ошибка. Проверить причину вручную, не отключая штатные повторы.',
            $job['id'],
            $job['order_no'],
            $httpStatus,
        );
    }
}

if ($oldestAgeMinutes > MAX_PENDING_AGE_MINUTES) {
    $alerts[] = sprintf(
        '[WARNING] pending=%d oldest_age=%dmin. Проверить воркер, порядок очереди и первую ошибку.',
        count($pendingJobs),
        $oldestAgeMinutes,
    );
}

if ($alerts === []) {
    printf("[OK] pending=%d oldest_age=%dmin\n", count($pendingJobs), $oldestAgeMinutes);
    exit(0);
}

echo implode(PHP_EOL, $alerts) . PHP_EOL;
exit(2);
