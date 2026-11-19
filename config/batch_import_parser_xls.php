<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/libs/SimpleXLS.php';

/** @return list<array{line:int,values:list<mixed>}> */
function batchImportParserXls(string $path): array
{
    if (!function_exists('mb_convert_encoding')) {
        throw new RuntimeException('IMPORT_XLS_REQUIRES_MBSTRING');
    }

    set_error_handler(static function (): never {
        throw new DomainException('IMPORT_XLS_INVALID');
    });
    try {
        $workbook = \Shuchkin\SimpleXLS::parseFile($path);
        if (!$workbook instanceof \Shuchkin\SimpleXLS) {
            throw new DomainException('IMPORT_XLS_INVALID');
        }
        $sourceRows = $workbook->rows(0, BATCH_IMPORT_MAX_ROWS + 2);
    } catch (DomainException $exception) {
        throw $exception;
    } catch (Throwable) {
        throw new DomainException('IMPORT_XLS_INVALID');
    } finally {
        restore_error_handler();
    }

    if (count($sourceRows) > BATCH_IMPORT_MAX_ROWS + 1) {
        throw new DomainException('IMPORT_ROW_LIMIT_EXCEEDED');
    }

    $rows = [];
    foreach ($sourceRows as $index => $sourceRow) {
        if (!is_array($sourceRow) || count($sourceRow) > BATCH_IMPORT_MAX_COLUMNS) {
            throw new DomainException('IMPORT_COLUMN_LIMIT_EXCEEDED');
        }
        $values = [];
        foreach ($sourceRow as $value) {
            if ($value === null || is_scalar($value)) {
                $values[] = is_bool($value) ? ($value ? 'true' : 'false') : ($value === null ? null : (string) $value);
                continue;
            }
            $values[] = $value;
        }
        $rows[] = ['line' => $index + 1, 'values' => $values];
    }

    return $rows;
}
