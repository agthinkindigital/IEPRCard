<?php
declare(strict_types=1);

require_once __DIR__ . '/cpf.php';

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
                    $cell = batchImportNormalizeBrPhone($cell);
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

function batchImportNormalizeBrPhone(string $value): string
{
    $digits = preg_replace('/\D+/', '', $value);
    if (!is_string($digits)) {
        throw new InvalidArgumentException('PHONE_INVALID');
    }
    if (str_starts_with($digits, '55') && in_array(strlen($digits), [12, 13], true)) {
        $digits = substr($digits, 2);
    }
    if (!in_array(strlen($digits), [10, 11], true)
        || preg_match('/^[1-9][0-9][0-9]{8,9}$/D', $digits) !== 1
        || strlen($digits) === 11 && $digits[2] !== '9') {
        throw new InvalidArgumentException('PHONE_INVALID');
    }

    return '+55' . $digits;
}

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

/** @return list<array{line:int,values:list<mixed>}> */
function batchImportParserXlsx(string $path): array
{
    if (!class_exists(ZipArchive::class)) {
        throw new RuntimeException('IMPORT_XLSX_REQUIRES_ZIP');
    }
    $zip = new ZipArchive();
    if ($zip->open($path, ZipArchive::RDONLY) !== true) {
        throw new DomainException('IMPORT_XLSX_INVALID');
    }
    try {
        if ($zip->numFiles < 1 || $zip->numFiles > 1000) {
            throw new DomainException('IMPORT_XLSX_INVALID');
        }
        $expandedSize = 0;
        for ($index = 0; $index < $zip->numFiles; ++$index) {
            $entry = $zip->statIndex($index);
            if (!is_array($entry) || !is_string($entry['name'] ?? null)
                || str_starts_with($entry['name'], '/') || str_contains($entry['name'], '\\')
                || preg_match('#(^|/)\.\.(/|$)#', $entry['name']) === 1) {
                throw new DomainException('IMPORT_XLSX_INVALID');
            }
            $expandedSize += (int) ($entry['size'] ?? 0);
            if ($expandedSize > BATCH_IMPORT_MAX_XLSX_EXPANDED_BYTES) {
                throw new DomainException('IMPORT_FILE_SIZE_INVALID');
            }
        }

        $workbook = batchImportParserXml($zip->getFromName('xl/workbook.xml'));
        $relationships = batchImportParserXml($zip->getFromName('xl/_rels/workbook.xml.rels'));
        $namespace = 'http://schemas.openxmlformats.org/spreadsheetml/2006/main';
        $relationshipNamespace = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships';
        $relationshipPackageNamespace = 'http://schemas.openxmlformats.org/package/2006/relationships';
        $workbook->registerXPathNamespace('main', $namespace);
        $sheets = $workbook->xpath('//main:sheets/main:sheet');
        $firstSheet = is_array($sheets) ? ($sheets[0] ?? null) : null;
        if (!$firstSheet instanceof SimpleXMLElement) {
            throw new DomainException('IMPORT_XLSX_INVALID');
        }
        $relationshipId = (string) ($firstSheet->attributes($relationshipNamespace)['id'] ?? '');
        $relationships->registerXPathNamespace('rel', $relationshipPackageNamespace);
        $relationshipNodes = $relationships->xpath('//rel:Relationship');
        $target = null;
        foreach (is_array($relationshipNodes) ? $relationshipNodes : [] as $relationship) {
            $attributes = $relationship->attributes();
            if ((string) ($attributes['Id'] ?? '') === $relationshipId) {
                $target = (string) ($attributes['Target'] ?? '');
                break;
            }
        }
        if ($target === null || $target === '' || str_starts_with($target, '/')
            || preg_match('#(^|/)\.\.(/|$)#', $target) === 1) {
            throw new DomainException('IMPORT_XLSX_INVALID');
        }
        $sheetPath = str_starts_with($target, 'xl/') ? $target : 'xl/' . $target;
        $sheetXml = batchImportParserXml($zip->getFromName($sheetPath));
        $sheetXml->registerXPathNamespace('main', $namespace);

        $sharedStrings = [];
        $sharedXml = $zip->getFromName('xl/sharedStrings.xml');
        if (is_string($sharedXml)) {
            $shared = batchImportParserXml($sharedXml);
            $shared->registerXPathNamespace('main', $namespace);
            $items = $shared->xpath('//main:si');
            foreach (is_array($items) ? $items : [] as $item) {
                $texts = $item->xpath('.//main:t');
                $sharedStrings[] = implode('', array_map('strval', is_array($texts) ? $texts : []));
            }
        }

        $xmlRows = $sheetXml->xpath('//main:sheetData/main:row');
        if (!is_array($xmlRows) || count($xmlRows) > BATCH_IMPORT_MAX_ROWS + 1) {
            throw new DomainException('IMPORT_ROW_LIMIT_EXCEEDED');
        }
        $rows = [];
        foreach ($xmlRows as $rowIndex => $xmlRow) {
            $xmlRow->registerXPathNamespace('main', $namespace);
            $rowAttributes = $xmlRow->attributes();
            $line = (int) ($rowAttributes['r'] ?? ($rowIndex + 1));
            $cellNodes = $xmlRow->xpath('./main:c');
            $values = [];
            foreach (is_array($cellNodes) ? $cellNodes : [] as $cell) {
                $cell->registerXPathNamespace('main', $namespace);
                $attributes = $cell->attributes();
                $column = batchImportParserXlsxColumnIndex((string) ($attributes['r'] ?? ''));
                if ($column >= BATCH_IMPORT_MAX_COLUMNS) {
                    throw new DomainException('IMPORT_COLUMN_LIMIT_EXCEEDED');
                }
                $type = (string) ($attributes['t'] ?? '');
                $cellChildren = $cell->children($namespace);
                if ($type === 'inlineStr') {
                    $texts = $cell->xpath('.//main:t');
                    $value = implode('', array_map('strval', is_array($texts) ? $texts : []));
                } else {
                    $value = isset($cellChildren->v) ? (string) $cellChildren->v : '';
                    if ($type === 's') {
                        $sharedIndex = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
                        if ($sharedIndex === false || !array_key_exists($sharedIndex, $sharedStrings)) {
                            throw new DomainException('IMPORT_XLSX_INVALID');
                        }
                        $value = $sharedStrings[$sharedIndex];
                    } elseif ($type === 'b') {
                        $value = $value === '1' ? 'true' : 'false';
                    }
                }
                if (strlen($value) > BATCH_IMPORT_MAX_CELL_BYTES) {
                    throw new DomainException('IMPORT_CELL_TOO_LARGE');
                }
                $values[$column] = $value;
            }
            if ($values === []) {
                $rows[] = ['line' => $line, 'values' => []];
                continue;
            }
            $maxColumn = max(array_keys($values));
            $denseValues = [];
            for ($column = 0; $column <= $maxColumn; ++$column) {
                $denseValues[] = $values[$column] ?? null;
            }
            $rows[] = ['line' => $line, 'values' => $denseValues];
        }

        return $rows;
    } finally {
        $zip->close();
    }
}

function batchImportParserXml(mixed $xml): SimpleXMLElement
{
    if (!is_string($xml) || $xml === '') {
        throw new DomainException('IMPORT_XLSX_INVALID');
    }
    $previous = libxml_use_internal_errors(true);
    try {
        $element = simplexml_load_string($xml, SimpleXMLElement::class, LIBXML_NONET | LIBXML_COMPACT);
    } finally {
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
    }
    if (!$element instanceof SimpleXMLElement) {
        throw new DomainException('IMPORT_XLSX_INVALID');
    }

    return $element;
}

function batchImportParserXlsxColumnIndex(string $reference): int
{
    if (preg_match('/^([A-Z]+)[0-9]+$/iD', $reference, $matches) !== 1) {
        throw new DomainException('IMPORT_XLSX_INVALID');
    }
    $column = 0;
    foreach (str_split(strtoupper($matches[1])) as $letter) {
        $column = $column * 26 + ord($letter) - 64;
        if ($column > BATCH_IMPORT_MAX_COLUMNS) {
            throw new DomainException('IMPORT_COLUMN_LIMIT_EXCEEDED');
        }
    }

    return $column - 1;
}

/** @param list<mixed> $headers @return list<string> */
function batchImportParserColumns(array $headers): array
{
    if ($headers === [] || count($headers) > BATCH_IMPORT_MAX_COLUMNS) {
        throw new DomainException('IMPORT_HEADER_INVALID');
    }
    $columns = [];
    foreach ($headers as $header) {
        if (!is_string($header) && !is_numeric($header)) {
            throw new DomainException('IMPORT_HEADER_INVALID');
        }
        $name = batchImportParserCanonicalColumn((string) $header);
        if ($name === '') {
            throw new DomainException('IMPORT_HEADER_INVALID');
        }
        if (in_array($name, $columns, true)) {
            throw new DomainException('IMPORT_HEADER_DUPLICATE');
        }
        $columns[] = $name;
    }

    return $columns;
}

function batchImportParserCanonicalColumn(string $header): string
{
    $header = trim(str_replace("\xEF\xBB\xBF", '', $header));
    $ascii = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $header);
    if (is_string($ascii)) {
        $header = str_replace(["'", '`', '´'], '', $ascii);
    }
    $normalized = strtolower(trim((string) preg_replace('/[^a-zA-Z0-9]+/', '_', $header), '_'));
    $aliases = [
        'nome' => 'nome_completo',
        'nome_titular' => 'nome_completo',
        'nome_completo' => 'nome_completo',
        'titular_nome' => 'nome_completo',
        'cpf' => 'cpf',
        'matricula' => 'matricula',
        'numero_matricula' => 'matricula',
        'cargo' => 'cargo_funcao',
        'funcao' => 'cargo_funcao',
        'cargo_funcao' => 'cargo_funcao',
        'telefone' => 'whatsapp',
        'celular' => 'whatsapp',
        'whatsapp' => 'whatsapp',
        'categoria' => 'categoria',
        'categoria_titular' => 'categoria',
    ];

    return $aliases[$normalized] ?? $normalized;
}

/** @param list<mixed> $values */
function batchImportParserRowIsBlank(array $values): bool
{
    foreach ($values as $value) {
        if ($value !== null && trim((string) $value) !== '') {
            return false;
        }
    }

    return true;
}
