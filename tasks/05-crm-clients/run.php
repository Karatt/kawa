<?php

declare(strict_types=1);

const DATA_DIR = __DIR__ . '/data';
const OUTPUT_DIR = __DIR__ . '/var';

/** @return list<array<string, string>> */
function readCsv(string $path): array
{
    $handle = fopen($path, 'rb');
    if ($handle === false) {
        throw new RuntimeException("Не удалось открыть {$path}");
    }

    $headers = fgetcsv($handle, 0, ';', '"', '');
    if ($headers === false) {
        throw new RuntimeException("Пустой CSV: {$path}");
    }
    $headers = array_map(static fn (string $header): string => trim(preg_replace('/^\xEF\xBB\xBF/', '', $header) ?? ''), $headers);

    $rows = [];
    $line = 1;
    while (($values = fgetcsv($handle, 0, ';', '"', '')) !== false) {
        $line++;
        if (count($values) !== count($headers)) {
            logMessage('WARNING', 'Пропущена строка с неверным количеством столбцов.', ['file' => basename($path), 'line' => $line]);
            continue;
        }
        /** @var array<string, string> $row */
        $row = array_combine($headers, array_map('trim', $values));
        $rows[] = $row;
    }
    fclose($handle);

    return $rows;
}

/** @param array<string, scalar> $context */
function logMessage(string $level, string $message, array $context = []): void
{
    $suffix = $context === [] ? '' : ' ' . json_encode($context, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    file_put_contents(OUTPUT_DIR . '/processing.log', sprintf("%s [%s] %s%s\n", date(DATE_ATOM), $level, $message, $suffix), FILE_APPEND | LOCK_EX);
}

function normalizeUnp(string $unp): string
{
    $digits = preg_replace('/\D/', '', $unp) ?? '';

    return preg_match('/^\d{9}$/', $digits) === 1 ? $digits : '';
}

function normalizePhone(string $phone): string
{
    $digits = preg_replace('/\D/', '', $phone) ?? '';
    if (preg_match('/^8\d{10}$/', $digits) === 1) {
        return '375' . substr($digits, 1);
    }

    return preg_match('/^375\d{9}$/', $digits) === 1 ? $digits : '';
}

function normalizeEmail(string $email): string
{
    $email = strtolower(trim($email));

    return filter_var($email, FILTER_VALIDATE_EMAIL) === false ? '' : $email;
}

function normalizeName(string $name): string
{
    $name = mb_strtolower(trim($name), 'UTF-8');
    $name = str_replace(['«', '»', '"', '“', '”'], '', $name);
    $name = preg_replace('/\b(ооо|чуп|чтуп|одо|ип)\b/u', ' ', $name) ?? '';
    $name = preg_replace('/[^\p{L}\p{N}]+/u', ' ', $name) ?? '';

    return trim(preg_replace('/\s+/u', ' ', $name) ?? '');
}

/** @param list<array<string, string>> $tickets @return array<string, int> */
function countTicketsByClient(array $tickets): array
{
    $counts = [];
    foreach ($tickets as $ticket) {
        $clientId = $ticket['client_id'];
        $counts[$clientId] = ($counts[$clientId] ?? 0) + 1;
    }

    return $counts;
}

/** @param array<string, string> $client @return array<string, string> */
function normalizedClient(array $client): array
{
    return [
        'unp' => normalizeUnp($client['unp']),
        'phone' => normalizePhone($client['phone']),
        'email' => normalizeEmail($client['email']),
        'name' => normalizeName($client['name']),
        'created_at' => $client['created_at'],
    ];
}

/** @param array<string, array<string, string>> $clients @return array<string, string> */
function buildParents(array $clients): array
{
    return array_fill_keys(array_keys($clients), '');
}

/** @param array<string, string> $parents */
function findRoot(array &$parents, string $clientId): string
{
    if ($parents[$clientId] === '') {
        return $clientId;
    }
    $parents[$clientId] = findRoot($parents, $parents[$clientId]);

    return $parents[$clientId];
}

/** @param array<string, string> $parents */
function unite(array &$parents, string $firstId, string $secondId): void
{
    $firstRoot = findRoot($parents, $firstId);
    $secondRoot = findRoot($parents, $secondId);
    if ($firstRoot !== $secondRoot) {
        $parents[$secondRoot] = $firstRoot;
    }
}

/**
 * @param array<string, array<string, string>> $clients
 * @return array{groups: list<list<string>>, fieldMatches: array<string, array<string, list<string>>>}
 */
function findDuplicateGroups(array $clients): array
{
    $parents = buildParents($clients);
    $fieldMatches = [];
    foreach (['unp', 'phone', 'email'] as $field) {
        $byValue = [];
        foreach ($clients as $clientId => $client) {
            $value = $client[$field];
            if ($value !== '') {
                $byValue[$value][] = $clientId;
            }
        }
        foreach ($byValue as $value => $ids) {
            if (count($ids) < 2) {
                continue;
            }
            $fieldMatches[$field][$value] = $ids;
        }
    }

    $clientIds = array_keys($clients);
    for ($left = 0, $max = count($clientIds); $left < $max; $left++) {
        for ($right = $left + 1; $right < $max; $right++) {
            $firstId = $clientIds[$left];
            $secondId = $clientIds[$right];
            $first = $clients[$firstId];
            $second = $clients[$secondId];
            $sameUnp = $first['unp'] !== '' && $first['unp'] === $second['unp'];
            $corroboratingMatches = 0;
            foreach (['phone', 'email', 'name'] as $field) {
                if ($first[$field] !== '' && $first[$field] === $second[$field]) {
                    $corroboratingMatches++;
                }
            }
            if ($sameUnp || $corroboratingMatches >= 2) {
                unite($parents, $firstId, $secondId);
            }
        }
    }

    $groups = [];
    foreach (array_keys($clients) as $clientId) {
        $groups[findRoot($parents, $clientId)][] = $clientId;
    }
    $groups = array_values(array_filter($groups, static fn (array $ids): bool => count($ids) > 1));
    usort($groups, static fn (array $left, array $right): int => $left[0] <=> $right[0]);

    return ['groups' => $groups, 'fieldMatches' => $fieldMatches];
}

/** @param list<string> $clientIds @param array<string, array<string, string>> $clients @return list<string> */
function reasonsForGroup(array $clientIds, array $clients): array
{
    $reasons = [];
    foreach (['unp' => 'одинаковый УНП', 'phone' => 'одинаковый нормализованный телефон', 'email' => 'одинаковый нормализованный e-mail'] as $field => $label) {
        $values = [];
        foreach ($clientIds as $clientId) {
            if ($clients[$clientId][$field] !== '') {
                $values[$clients[$clientId][$field]][] = $clientId;
            }
        }
        foreach ($values as $ids) {
            if (count($ids) > 1) {
                $reasons[] = $label . ': ' . implode(', ', $ids);
            }
        }
    }
    $names = array_unique(array_map(static fn (string $id): string => $clients[$id]['name'], $clientIds));
    if (count($names) === 1) {
        $reasons[] = 'совпадает нормализованное название организации';
    }

    return $reasons;
}

/** @param list<string> $clientIds @param array<string, array<string, string>> $clients @param array<string, int> $ticketCounts */
function selectMasterClient(array $clientIds, array $clients, array $ticketCounts): string
{
    usort($clientIds, static function (string $leftId, string $rightId) use ($clients, $ticketCounts): int {
        $leftCompleteness = count(array_filter(['unp', 'phone', 'email', 'name'], static fn (string $field): bool => $clients[$leftId][$field] !== ''));
        $rightCompleteness = count(array_filter(['unp', 'phone', 'email', 'name'], static fn (string $field): bool => $clients[$rightId][$field] !== ''));
        return [
            $ticketCounts[$rightId] ?? 0,
            $rightCompleteness,
            $clients[$leftId]['created_at'],
            $leftId,
        ] <=> [
            $ticketCounts[$leftId] ?? 0,
            $leftCompleteness,
            $clients[$rightId]['created_at'],
            $rightId,
        ];
    });

    return $clientIds[0];
}

function ticketWord(int $count): string
{
    $remainder = $count % 100;
    if ($remainder >= 11 && $remainder <= 14) {
        return 'обращений';
    }
    return match ($count % 10) {
        1 => 'обращение',
        2, 3, 4 => 'обращения',
        default => 'обращений',
    };
}

/** @param array<string, array<string, string>> $clients @return list<array{ids: list<string>, reason: string}> */
function findSimilarButDistinct(array $clients): array
{
    $pairs = [];
    $ids = array_keys($clients);
    for ($left = 0, $max = count($ids); $left < $max; $left++) {
        for ($right = $left + 1; $right < $max; $right++) {
            $first = $clients[$ids[$left]];
            $second = $clients[$ids[$right]];
            $sameName = $first['name'] !== '' && $first['name'] === $second['name'];
            $samePhone = $first['phone'] !== '' && $first['phone'] === $second['phone'];
            $sameEmail = $first['email'] !== '' && $first['email'] === $second['email'];
            $sameUnp = $first['unp'] !== '' && $first['unp'] === $second['unp'];
            if (!$sameName && !$samePhone) {
                continue;
            }
            if ($sameUnp || $sameEmail || ($sameName && $samePhone)) {
                continue;
            }
            $reason = $sameName
                ? 'Совпадает только нормализованное название; УНП, телефон и e-mail различаются.'
                : 'Совпадает только телефон; названия, УНП и e-mail различаются.';
            $pairs[] = ['ids' => [$ids[$left], $ids[$right]], 'reason' => $reason];
        }
    }

    return $pairs;
}

/** @param array<string, array<string, string>> $rawClients @param array<string, array<string, string>> $clients @param list<list<string>> $groups @param array<string, int> $ticketCounts @param list<array{ids: list<string>, reason: string}> $distinctPairs */
function writeReport(array $rawClients, array $clients, array $groups, array $ticketCounts, array $distinctPairs): void
{
    $lines = [
        '# Кандидаты на объединение клиентов',
        '',
        'Отчёт носит рекомендательный характер: скрипт не меняет карточки и обращения. Контакты нормализованы перед сравнением; УНП считается сильным идентификатором, а совпадение только имени или телефона требует ручной проверки.',
        '',
        '## Вероятные дубли',
        '',
    ];
    foreach ($groups as $number => $clientIds) {
        $masterId = selectMasterClient($clientIds, $clients, $ticketCounts);
        $movedTickets = array_sum(array_map(static fn (string $id): int => $id === $masterId ? 0 : ($ticketCounts[$id] ?? 0), $clientIds));
        $totalTickets = array_sum(array_map(static fn (string $id): int => $ticketCounts[$id] ?? 0, $clientIds));
        $lines[] = '### Группа ' . ($number + 1) . ' — точно';
        $lines[] = '';
        $lines[] = '| Карточка | Клиент | УНП | Телефон | E-mail | Обращений |';
        $lines[] = '|---|---|---|---|---|---:|';
        foreach ($clientIds as $clientId) {
            $client = $rawClients[$clientId];
            $lines[] = sprintf('| %s | %s | %s | %s | %s | %d |', $clientId, $client['name'], $client['unp'] ?: '—', $client['phone'], trim($client['email']), $ticketCounts[$clientId] ?? 0);
        }
        $lines[] = '';
        $lines[] = '**Основания:** ' . implode('; ', reasonsForGroup($clientIds, $clients)) . '.';
        $lines[] = '';
        $lines[] = sprintf('**Главная карточка:** `%s` — выбрана по числу обращений, затем по заполненности и более ранней дате создания. После подтверждения объединения перенести в неё %d %s; всего станет %d.', $masterId, $movedTickets, ticketWord($movedTickets), $totalTickets);
        $lines[] = '';
    }

    $lines[] = '## Похожи, но не объединять автоматически';
    $lines[] = '';
    foreach ($distinctPairs as $pair) {
        [$firstId, $secondId] = $pair['ids'];
        $lines[] = sprintf('- `%s` (%s) и `%s` (%s): %s', $firstId, $rawClients[$firstId]['name'], $secondId, $rawClients[$secondId]['name'], $pair['reason']);
    }
    $lines[] = '';
    $lines[] = '## Контроль перед ручным объединением';
    $lines[] = '';
    $lines[] = 'Проверить актуальность УНП в 1С/реестре, сохранить все исходные идентификаторы в журнале слияния, переназначить обращения транзакционно и оставить перенаправление со старых карточек. Автоматическое удаление или слияние в этом решении не выполняется.';
    $lines[] = '';

    file_put_contents(OUTPUT_DIR . '/duplicates-report.md', implode("\n", $lines), LOCK_EX);
}

function main(): void
{
    if (!is_dir(OUTPUT_DIR) && !mkdir(OUTPUT_DIR, 0775, true) && !is_dir(OUTPUT_DIR)) {
        throw new RuntimeException('Не удалось создать каталог var');
    }
    file_put_contents(OUTPUT_DIR . '/processing.log', '');
    logMessage('INFO', 'Запущен анализ кандидатов на дубли.');

    $rawClients = [];
    foreach (readCsv(DATA_DIR . '/crm_clients.csv') as $client) {
        if ($client['client_id'] === '' || isset($rawClients[$client['client_id']])) {
            throw new RuntimeException('Найдена пустая или повторяющаяся карточка клиента.');
        }
        $rawClients[$client['client_id']] = $client;
    }
    $tickets = readCsv(DATA_DIR . '/tickets.csv');
    $clients = array_map('normalizedClient', $rawClients);
    $ticketCounts = countTicketsByClient($tickets);
    $result = findDuplicateGroups($clients);
    $distinctPairs = findSimilarButDistinct($clients);
    writeReport($rawClients, $clients, $result['groups'], $ticketCounts, $distinctPairs);

    logMessage('INFO', 'Анализ завершён.', ['groups' => count($result['groups']), 'manual_review_pairs' => count($distinctPairs)]);
    printf("Готово: %d групп вероятных дублей, %d похожих пар для ручной проверки.\n", count($result['groups']), count($distinctPairs));
}

try {
    main();
} catch (Throwable $exception) {
    if (is_dir(OUTPUT_DIR)) {
        logMessage('ERROR', 'Анализ остановлен с ошибкой.', ['error' => $exception->getMessage()]);
    }
    fwrite(STDERR, "Ошибка: {$exception->getMessage()}\n");
    exit(1);
}
