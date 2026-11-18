<?php
declare(strict_types=1);

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

