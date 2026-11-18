<?php
declare(strict_types=1);

require_once __DIR__ . '/cpf.php';
require_once __DIR__ . '/phone.php';

const BATCH_IMPORT_MAX_FILE_BYTES = 10485760;
const BATCH_IMPORT_MAX_ROWS = 500;
const BATCH_IMPORT_MAX_COLUMNS = 100;
const BATCH_IMPORT_MAX_CELL_BYTES = 65536;
const BATCH_IMPORT_MAX_XLSX_EXPANDED_BYTES = 31457280;

/** @return array{columns:list<string>,rows:list<array<string,string|null>>,preview:list<array<string,string|null>>,errors:list<array{linha:int,campo:string,codigo:string}>,total_rows:int} */
function batchImportParse(string $path): array
{
    if (!is_file($path) || is_link($path)) {
        throw new DomainException('IMPORT_FILE_INVALID');
    }
    $size = filesize($path);
    if (!is_int($size) || $size < 1 || $size > BATCH_IMPORT_MAX_FILE_BYTES) {
        throw new DomainException('IMPORT_FILE_SIZE_INVALID');
    }
    $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));
    $rawRows = match ($extension) {
        'csv' => batchImportParserCsv($path),
        'xlsx' => batchImportParserXlsx($path),
        'json' => batchImportParserJson($path),
        default => throw new DomainException('IMPORT_FORMAT_UNSUPPORTED'),
    };
    if ($rawRows === []) {
        throw new DomainException('IMPORT_HEADER_INVALID');
    }

    $headerRow = array_shift($rawRows);
    if (!is_array($headerRow)) {
        throw new DomainException('IMPORT_HEADER_INVALID');
    }
    $columns = batchImportParserColumns($headerRow['values']);
    $rows = [];
    $errors = [];
    foreach ($rawRows as $rawRow) {
        $line = $rawRow['line'];
        $values = $rawRow['values'];
        if (batchImportParserRowIsBlank($values)) {
            continue;
        }
        if (count($rows) >= BATCH_IMPORT_MAX_ROWS) {
            throw new DomainException('IMPORT_ROW_LIMIT_EXCEEDED');
        }
        if (count($values) > count($columns)) {
            $errors[] = ['linha' => $line, 'campo' => '_linha', 'codigo' => 'IMPORT_ROW_EXTRA_CELLS'];
        }

        $normalized = [];
        foreach ($columns as $index => $column) {
            $cell = $values[$index] ?? null;
            if (!is_string($cell) && $cell !== null) {
                $cell = null;
                $errors[] = ['linha' => $line, 'campo' => $column, 'codigo' => 'IMPORT_CELL_INVALID'];
            }
            $cell = is_string($cell) ? trim($cell) : null;
            if ($cell === '') {
                $cell = null;
            }
            if ($cell !== null && strlen($cell) > BATCH_IMPORT_MAX_CELL_BYTES) {
                $cell = null;
                $errors[] = ['linha' => $line, 'campo' => $column, 'codigo' => 'IMPORT_CELL_TOO_LARGE'];
            }
            if ($cell !== null && $column === 'cpf') {
                try {
                    $cell = cpfNormalizeBr($cell);
                } catch (InvalidArgumentException) {
                    $errors[] = ['linha' => $line, 'campo' => 'cpf', 'codigo' => 'IMPORT_CPF_INVALID'];
                }
            }
            if ($cell !== null && $column === 'whatsapp') {
                try {
                    $cell = phoneNormalizeBr($cell);
                } catch (InvalidArgumentException) {
                    $errors[] = ['linha' => $line, 'campo' => 'whatsapp', 'codigo' => 'IMPORT_PHONE_INVALID'];
                }
            }
            $normalized[$column] = $cell;
        }
        $rows[] = $normalized;
    }

    return [
        'columns' => $columns,
        'rows' => $rows,
        'preview' => array_slice($rows, 0, 10),
        'errors' => $errors,
        'total_rows' => count($rows),
    ];
}

require_once __DIR__ . '/batch_import_parser_support.php';
require_once __DIR__ . '/batch_import_parser_readers.php';
require_once __DIR__ . '/batch_import_parser_xlsx.php';
