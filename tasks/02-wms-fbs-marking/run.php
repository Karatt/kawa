<?php

declare(strict_types=1);

const DATA_DIR = __DIR__ . '/data';
const OUTPUT_DIR = __DIR__ . '/var';

/** @return array<int, array<string, string>> */
function readCsv(string $path): array
{
    $handle = fopen($path, 'rb');
    if ($handle === false) {
        throw new RuntimeException("Не удалось открыть {$path}");
    }
    $header = fgetcsv($handle, 0, ';', '"', '');
    if ($header === false) {
        throw new RuntimeException("Пустой CSV: {$path}");
    }
    $header = array_map(static fn (string $value): string => preg_replace('/^\xEF\xBB\xBF/', '', trim($value)) ?? '', $header);
    $rows = [];
    $line = 1;
    while (($values = fgetcsv($handle, 0, ';', '"', '')) !== false) {
        $line++;
        if (count($values) !== count($header)) {
            logIssue('ERROR', 'Пропущена битая строка CSV.', ['file' => basename($path), 'line' => $line]);
            continue;
        }
        $rows[] = array_combine($header, array_map('trim', $values));
    }
    fclose($handle);

    return $rows;
}

/** @param array<string, mixed> $context */
function logIssue(string $level, string $message, array $context = []): void
{
    $suffix = $context === [] ? '' : ' ' . json_encode($context, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    file_put_contents(OUTPUT_DIR . '/processing.log', sprintf("%s [%s] %s%s\n", date(DATE_ATOM), $level, $message, $suffix), FILE_APPEND | LOCK_EX);
}

/** @param array<int, array<int, scalar>> $rows @param array<int, string> $headers */
function writeCsv(string $filename, array $headers, array $rows): void
{
    $handle = fopen(OUTPUT_DIR . '/' . $filename, 'wb');
    if ($handle === false) {
        throw new RuntimeException("Не удалось создать {$filename}");
    }
    fwrite($handle, "\xEF\xBB\xBF");
    fputcsv($handle, $headers, ';', '"', '');
    foreach ($rows as $row) {
        fputcsv($handle, $row, ';', '"', '');
    }
    fclose($handle);
}

/** @return array{bySku: array<string, array{name: string, barcode: string, marked: bool}>, byBarcode: array<string, string>} */
function loadCatalog(): array
{
    $bySku = [];
    $byBarcode = [];
    foreach (readCsv(DATA_DIR . '/catalog.csv') as $row) {
        $sku = $row['sku'];
        $barcode = $row['barcode'];
        if ($sku === '' || !preg_match('/^\d{13}$/', $barcode) || isset($bySku[$sku]) || isset($byBarcode[$barcode])) {
            throw new RuntimeException("Некорректный или повторяющийся товар в catalog.csv: {$sku}");
        }
        $bySku[$sku] = ['name' => $row['name'], 'barcode' => $barcode, 'marked' => $row['marked'] === '1'];
        $byBarcode[$barcode] = $sku;
    }

    return ['bySku' => $bySku, 'byBarcode' => $byBarcode];
}

/** @return array<string, array<int, array{cell: string, qty: int}>> */
function loadStock(): array
{
    $stock = [];
    foreach (readCsv(DATA_DIR . '/stock_cells.csv') as $row) {
        $quantity = filter_var($row['qty'], FILTER_VALIDATE_INT);
        if ($quantity === false || $quantity < 0) {
            logIssue('ERROR', 'Некорректный остаток в ячейке.', $row);
            continue;
        }
        $stock[$row['sku']][] = ['cell' => $row['cell'], 'qty' => $quantity];
    }
    foreach ($stock as &$cells) {
        usort($cells, static fn (array $left, array $right): int => $left['cell'] <=> $right['cell']);
    }
    unset($cells);

    return $stock;
}

/** @return array<int, array<string, mixed>> */
function loadTasks(): array
{
    $json = file_get_contents(DATA_DIR . '/fbs_tasks.json');
    if ($json === false) {
        throw new RuntimeException('Не удалось прочитать fbs_tasks.json');
    }
    $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
    if (!is_array($data['tasks'] ?? null)) {
        throw new RuntimeException('В fbs_tasks.json отсутствует tasks');
    }
    $tasks = $data['tasks'];
    usort($tasks, static fn (array $left, array $right): int => [$left['ship_by'], $left['task_id']] <=> [$right['ship_by'], $right['task_id']]);

    return $tasks;
}

/**
 * @param array<int, array<string, mixed>> $tasks
 * @param array<string, array<int, array{cell: string, qty: int}>> $stock
 * @param array<string, array{name: string, barcode: string, marked: bool}> $catalog
 * @return array{rows: array<int, array<int, scalar>>, unavailable: array<string, array<int, string>>}
 */
function buildPickList(array $tasks, array $stock, array $catalog): array
{
    $available = $stock;
    $rows = [];
    $unavailable = [];
    foreach ($tasks as $task) {
        $taskId = (string) $task['task_id'];
        foreach ($task['items'] as $item) {
            $sku = (string) ($item['sku'] ?? '');
            $requested = filter_var($item['qty'] ?? null, FILTER_VALIDATE_INT);
            if (!isset($catalog[$sku]) || $requested === false || $requested <= 0 || ($item['barcode'] ?? '') !== $catalog[$sku]['barcode']) {
                $unavailable[$taskId][] = "Некорректная строка задания: {$sku}";
                logIssue('ERROR', 'Некорректная строка сборочного задания.', ['task_id' => $taskId, 'sku' => $sku]);
                continue;
            }
            $left = $requested;
            if (isset($available[$sku])) {
                foreach ($available[$sku] as &$cell) {
                    if ($left === 0 || $cell['qty'] === 0) {
                        continue;
                    }
                    $take = min($left, $cell['qty']);
                    $cell['qty'] -= $take;
                    $left -= $take;
                    $rows[] = [$cell['cell'], $taskId, $task['ship_by'], $sku, $catalog[$sku]['name'], $take, 'Взять'];
                }
                unset($cell);
            }
            if ($left > 0) {
                $unavailable[$taskId][] = "{$sku}: не хватает {$left} шт.";
                $rows[] = ['', $taskId, $task['ship_by'], $sku, $catalog[$sku]['name'], $left, 'Не брать: нет остатка'];
            }
        }
    }
    usort($rows, static fn (array $left, array $right): int => [$left[0] === '' ? 'ZZZ' : $left[0], $left[2], $left[1], $left[3]] <=> [$right[0] === '' ? 'ZZZ' : $right[0], $right[2], $right[1], $right[3]]);

    return ['rows' => $rows, 'unavailable' => $unavailable];
}

/** @return array<string, array<int, array{barcode: string, marking: string}>> */
function loadScans(): array
{
    $scans = [];
    foreach (readCsv(DATA_DIR . '/scans.csv') as $row) {
        $scans[$row['task_id']][] = ['barcode' => $row['barcode'], 'marking' => $row['marking_code']];
    }

    return $scans;
}

/** @param array<string, array<int, array{barcode: string, marking: string}>> $scans @return array<string, true> */
function findDuplicateMarks(array $scans): array
{
    $owners = [];
    foreach ($scans as $taskId => $taskScans) {
        foreach ($taskScans as $scan) {
            if ($scan['marking'] !== '') {
                $owners[$scan['marking']][] = $taskId;
            }
        }
    }
    $duplicates = [];
    foreach ($owners as $marking => $taskIds) {
        if (count($taskIds) > 1) {
            $duplicates[$marking] = true;
        }
    }

    return $duplicates;
}

/** @param array<int, string> $issues */
function addIssue(array &$issues, string $issue): void
{
    $issues[$issue] = $issue;
}

/**
 * @param array<int, array<string, mixed>> $tasks
 * @param array<string, array<int, array{barcode: string, marking: string}>> $scans
 * @param array<string, array{name: string, barcode: string, marked: bool}> $catalog
 * @param array<string, string> $barcodeToSku
 * @param array<string, true> $duplicateMarks
 * @param array<string, array<int, string>> $unavailable
 * @return array<int, array<int, scalar>>
 */
function buildAssemblyControl(array $tasks, array $scans, array $catalog, array $barcodeToSku, array $duplicateMarks, array $unavailable): array
{
    $rows = [];
    $knownTaskIds = [];
    foreach ($tasks as $task) {
        $taskId = (string) $task['task_id'];
        $knownTaskIds[$taskId] = true;
        $expected = [];
        foreach ($task['items'] as $item) {
            $expected[$item['barcode']] = ($expected[$item['barcode']] ?? 0) + (int) $item['qty'];
        }
        $actual = [];
        $issues = [];
        foreach ($scans[$taskId] ?? [] as $scan) {
            $barcode = $scan['barcode'];
            $actual[$barcode] = ($actual[$barcode] ?? 0) + 1;
            $sku = $barcodeToSku[$barcode] ?? null;
            if ($sku === null) {
                addIssue($issues, "Неизвестный штрихкод {$barcode}");
                continue;
            }
            if (!isset($expected[$barcode])) {
                addIssue($issues, "Лишний скан {$barcode} ({$catalog[$sku]['name']})");
            }
            if ($catalog[$sku]['marked']) {
                $marking = $scan['marking'];
                if ($marking === '') {
                    addIssue($issues, "Нет КИЗ для {$sku}");
                } elseif (!preg_match('/^01(\d{14})21.+$/', $marking, $matches) || $matches[1] !== '0' . $barcode) {
                    addIssue($issues, "КИЗ не соответствует штрихкоду {$barcode}");
                } elseif (isset($duplicateMarks[$marking])) {
                    addIssue($issues, "КИЗ повторно использован: {$marking}");
                }
            } elseif ($scan['marking'] !== '') {
                addIssue($issues, "КИЗ отсканирован для немаркированного {$sku}");
            }
        }
        foreach ($expected as $barcode => $quantity) {
            $scanned = $actual[$barcode] ?? 0;
            $sku = $barcodeToSku[$barcode];
            if ($scanned < $quantity) {
                addIssue($issues, "Не хватает сканов {$sku}: " . ($quantity - $scanned) . ' шт.');
            }
            if ($scanned > $quantity) {
                addIssue($issues, "Лишних сканов {$sku}: " . ($scanned - $quantity) . ' шт.');
            }
        }
        foreach ($unavailable[$taskId] ?? [] as $issue) {
            addIssue($issues, "Лист подбора: {$issue}");
        }
        $issueText = implode(' | ', $issues);
        $rows[] = [$taskId, $task['ship_by'], count($scans[$taskId] ?? []), $issueText === '' ? 'OK' : 'ПРОБЛЕМА', $issueText, $issueText === '' ? 'Можно передавать на отгрузку' : 'Остановить отгрузку и исправить'];
    }
    foreach ($scans as $taskId => $taskScans) {
        if (isset($knownTaskIds[$taskId])) {
            continue;
        }
        logIssue('ERROR', 'Сканы привязаны к несуществующему заданию.', ['task_id' => $taskId, 'scan_count' => count($taskScans)]);
        $rows[] = [$taskId, '', count($taskScans), 'ПРОБЛЕМА', 'Сканы привязаны к несуществующему заданию', 'Остановить отгрузку и проверить журнал'];
    }

    return $rows;
}

/**
 * @param array<string, array<int, array{cell: string, qty: int}>> $stock
 * @param array<string, string> $barcodeToSku
 * @param array<string, array{name: string, barcode: string, marked: bool}> $catalog
 * @param array<string, array<int, array{barcode: string, marking: string}>> $scans
 * @return array<int, array<int, scalar>>
 */
function buildInventoryReport(array $stock, array $barcodeToSku, array $catalog, array $scans): array
{
    $counted = [];
    $unknown = [];
    foreach (readCsv(DATA_DIR . '/inventory_count.csv') as $row) {
        $quantity = filter_var($row['counted_qty'], FILTER_VALIDATE_INT);
        if ($quantity === false || $quantity < 0) {
            logIssue('ERROR', 'Некорректный результат пересчёта.', $row);
            continue;
        }
        $sku = $barcodeToSku[$row['barcode']] ?? null;
        if ($sku === null) {
            $unknown[] = [$row['cell'], $row['barcode'], $quantity];
            continue;
        }
        $counted[$row['cell']][$sku] = $quantity;
    }
    $expected = [];
    $expectedBySku = [];
    foreach ($stock as $sku => $cells) {
        foreach ($cells as $cell) {
            $expected[$cell['cell']][$sku] = $cell['qty'];
            $expectedBySku[$sku] = ($expectedBySku[$sku] ?? 0) + $cell['qty'];
        }
    }
    $countedBySku = [];
    foreach ($counted as $items) {
        foreach ($items as $sku => $quantity) {
            $countedBySku[$sku] = ($countedBySku[$sku] ?? 0) + $quantity;
        }
    }
    $scannedByBarcode = [];
    foreach ($scans as $taskScans) {
        foreach ($taskScans as $scan) {
            $scannedByBarcode[$scan['barcode']] = ($scannedByBarcode[$scan['barcode']] ?? 0) + 1;
        }
    }
    $rows = [];
    foreach ($expected as $cell => $items) {
        foreach ($items as $sku => $bookQuantity) {
            if (!array_key_exists($sku, $counted[$cell] ?? [])) {
                $rows[] = [$cell, $sku, $catalog[$sku]['name'], $bookQuantity, '', '', 'Ячейка не пересчитана', 'Повторно пересчитать ячейку'];
                continue;
            }
            $actual = $counted[$cell][$sku];
            if ($actual === $bookQuantity) {
                continue;
            }
            $difference = $actual - $bookQuantity;
            $skuBalanceMatches = ($countedBySku[$sku] ?? 0) === $expectedBySku[$sku];
            if ($skuBalanceMatches) {
                $reason = 'Вероятно неучтённое перемещение между ячейками';
                $action = 'Оформить перемещение после проверки';
            } elseif ($difference < 0 && ($scannedByBarcode[$catalog[$sku]['barcode']] ?? 0) === abs($difference)) {
                $reason = 'Возможно, товар отобран по сканам, но не списан из ячейки';
                $action = 'Сверить со сборочными заданиями и списанием';
            } else {
                $reason = $difference < 0 ? 'Недостача требует проверки' : 'Излишек требует проверки';
                $action = 'Проверить ячейку и движения товара';
            }
            $rows[] = [$cell, $sku, $catalog[$sku]['name'], $bookQuantity, $actual, $difference, $reason, $action];
        }
    }
    foreach ($counted as $cell => $items) {
        foreach ($items as $sku => $actual) {
            if (!isset($expected[$cell][$sku])) {
                $skuBalanceMatches = ($countedBySku[$sku] ?? 0) === $expectedBySku[$sku];
                $reason = $skuBalanceMatches ? 'Вероятно неучтённое перемещение между ячейками' : 'Товар найден в неучтённой ячейке';
                $action = $skuBalanceMatches ? 'Оформить перемещение после проверки' : 'Проверить и оформить перемещение';
                $rows[] = [$cell, $sku, $catalog[$sku]['name'], 0, $actual, $actual, $reason, $action];
            }
        }
    }
    foreach ($unknown as [$cell, $barcode, $quantity]) {
        $rows[] = [$cell, '', 'Неизвестный штрихкод ' . $barcode, 0, $quantity, $quantity, 'Неидентифицированный излишек', 'Идентифицировать товар до оприходования'];
    }
    usort($rows, static fn (array $left, array $right): int => [$left[0], $left[1]] <=> [$right[0], $right[1]]);

    return $rows;
}

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "Скрипт запускается только из CLI.\n");
    exit(1);
}
if (!is_dir(OUTPUT_DIR) && !mkdir(OUTPUT_DIR, 0775, true) && !is_dir(OUTPUT_DIR)) {
    fwrite(STDERR, "Не удалось создать каталог var.\n");
    exit(1);
}
file_put_contents(OUTPUT_DIR . '/processing.log', '');

try {
    $catalog = loadCatalog();
    $stock = loadStock();
    $tasks = loadTasks();
    $pick = buildPickList($tasks, $stock, $catalog['bySku']);
    $scans = loadScans();
    $control = buildAssemblyControl($tasks, $scans, $catalog['bySku'], $catalog['byBarcode'], findDuplicateMarks($scans), $pick['unavailable']);
    $inventory = buildInventoryReport($stock, $catalog['byBarcode'], $catalog['bySku'], $scans);

    writeCsv('pick-list.csv', ['Ячейка', 'Задание', 'Отгрузить до', 'Артикул', 'Товар', 'Количество', 'Действие'], $pick['rows']);
    writeCsv('assembly-control.csv', ['Задание', 'Отгрузить до', 'Сканов', 'Статус', 'Проблемы', 'Действие'], $control);
    writeCsv('inventory-differences.csv', ['Ячейка', 'Артикул', 'Товар', 'Учётный остаток', 'Пересчитано', 'Разница', 'Классификация', 'Действие'], $inventory);

    $blocked = count(array_filter($control, static fn (array $row): bool => $row[3] !== 'OK'));
    printf("Готово: лист подбора — %d строк, проблемных заданий — %d, расхождений инвентаризации — %d.\n", count($pick['rows']), $blocked, count($inventory));
} catch (Throwable $exception) {
    fwrite(STDERR, "Ошибка запуска: {$exception->getMessage()}\n");
    exit(1);
}
