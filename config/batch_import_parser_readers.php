<?php
declare(strict_types=1);

/** @return list<array{line:int,values:list<mixed>}> */
function batchImportParserCsv(string $path): array
{
    $contents = file_get_contents($path);
    if (!is_string($contents)) {
        throw new DomainException('IMPORT_FILE_INVALID');
    }
    if (str_starts_with($contents, "\xEF\xBB\xBF")) {
        $contents = substr($contents, 3);
    }
    if (preg_match('//u', $contents) !== 1) {
        $converted = iconv('ISO-8859-1', 'UTF-8', $contents);
        if (!is_string($converted)) {
            throw new DomainException('IMPORT_ENCODING_INVALID');
        }
        $contents = $converted;
    }
    if (!batchImportParserCsvQuotesAreBalanced($contents)) {
        throw new DomainException('IMPORT_CSV_INVALID');
    }

    $stream = fopen('php://temp', 'r+');
    if ($stream === false) {
        throw new RuntimeException('IMPORT_FILE_INVALID');
    }
    try {
        fwrite($stream, $contents);
        rewind($stream);
        $rows = [];
        $line = 0;
        while (($values = fgetcsv($stream, BATCH_IMPORT_MAX_FILE_BYTES + 1, ';', '"', '')) !== false) {
            ++$line;
            $rows[] = ['line' => $line, 'values' => $values];
            if (count($rows) > BATCH_IMPORT_MAX_ROWS + 1) {
                throw new DomainException('IMPORT_ROW_LIMIT_EXCEEDED');
            }
        }
        return $rows;
    } finally {
        fclose($stream);
    }
}

function batchImportParserCsvQuotesAreBalanced(string $contents): bool
{
    $quoted = false;
    $length = strlen($contents);
    for ($index = 0; $index < $length; ++$index) {
        if ($contents[$index] !== '"') {
            continue;
        }
        if ($quoted && $index + 1 < $length && $contents[$index + 1] === '"') {
            ++$index;
            continue;
        }
        $quoted = !$quoted;
    }

    return !$quoted;
}

/** @return list<array{line:int,values:list<mixed>}> */
function batchImportParserJson(string $path): array
{
    $contents = file_get_contents($path);
    if (!is_string($contents)) {
        throw new DomainException('IMPORT_FILE_INVALID');
    }
    try {
        $decoded = json_decode($contents, false, 64, JSON_THROW_ON_ERROR);
    } catch (JsonException) {
        throw new DomainException('IMPORT_JSON_INVALID');
    }
    if (!is_array($decoded) || !array_is_list($decoded) || $decoded === []) {
        throw new DomainException('IMPORT_JSON_INVALID');
    }
    if (count($decoded) > BATCH_IMPORT_MAX_ROWS) {
        throw new DomainException('IMPORT_ROW_LIMIT_EXCEEDED');
    }

    $headers = [];
    foreach ($decoded as $record) {
        if (!$record instanceof stdClass) {
            throw new DomainException('IMPORT_JSON_INVALID');
        }
        foreach (array_keys(get_object_vars($record)) as $key) {
            if (!in_array($key, $headers, true)) {
                $headers[] = $key;
            }
        }
        if (count($headers) > BATCH_IMPORT_MAX_COLUMNS) {
            throw new DomainException('IMPORT_COLUMN_LIMIT_EXCEEDED');
        }
    }
    if ($headers === []) {
        throw new DomainException('IMPORT_HEADER_INVALID');
    }

    $rows = [['line' => 1, 'values' => $headers]];
    foreach ($decoded as $index => $record) {
        $fields = get_object_vars($record);
        $values = [];
        foreach ($headers as $header) {
            $value = $fields[$header] ?? null;
            $values[] = is_scalar($value) ? (is_bool($value) ? ($value ? 'true' : 'false') : (string) $value) : $value;
        }
        $rows[] = ['line' => $index + 2, 'values' => $values];
    }

    return $rows;
}

