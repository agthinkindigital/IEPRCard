<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/config/batch_import_parser.php';

if (!class_exists(ZipArchive::class)) {
    throw new RuntimeException('The BatchImportParserTest requires PHP zip support.');
}

$basePath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'ieprcard-import-' . bin2hex(random_bytes(5));
$csvPath = $basePath . '.csv';
$xlsxPath = $basePath . '.xlsx';
$jsonPath = $basePath . '.json';
$invalidPath = $basePath . '-invalid.csv';
$overflowCsvPath = $basePath . '-overflow.csv';
$overflowXlsxPath = $basePath . '-overflow.xlsx';
$overflowJsonPath = $basePath . '-overflow.json';
$invalidXlsxPath = $basePath . '-invalid.xlsx';
$invalidJsonPath = $basePath . '-invalid.json';

try {
    $headers = ['Nome Completo', 'CPF', 'Matrícula', 'WhatsApp'];
    $records = [];
    for ($index = 0; $index < 500; ++$index) {
        $records[] = [
            'Nome Completo' => 'João Silva ' . str_pad((string) $index, 3, '0', STR_PAD_LEFT),
            'CPF' => batchImportParserTestCpf($index),
            'Matrícula' => 'A-' . str_pad((string) $index, 4, '0', STR_PAD_LEFT),
            'WhatsApp' => '1190000' . str_pad((string) $index, 4, '0', STR_PAD_LEFT),
        ];
    }

    $csvLines = [implode(';', $headers)];
    foreach ($records as $record) {
        $csvLines[] = implode(';', array_map(
            static fn(string $value): string => '"' . str_replace('"', '""', $value) . '"',
            array_values($record)
        ));
    }
    $csvUtf8 = implode("\r\n", $csvLines) . "\r\n";
    $csvLatin1 = iconv('UTF-8', 'ISO-8859-1', $csvUtf8);
    if (!is_string($csvLatin1)) {
        throw new RuntimeException('Could not produce ISO-8859-1 CSV fixture.');
    }
    file_put_contents($csvPath, $csvLatin1);
    file_put_contents($jsonPath, json_encode($records, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
    batchImportParserWriteXlsx($xlsxPath, $headers, $records);

    $csv = batchImportParse($csvPath);
    $xlsx = batchImportParse($xlsxPath);
    $json = batchImportParse($jsonPath);
    foreach (['CSV' => $csv, 'XLSX' => $xlsx, 'JSON' => $json] as $format => $result) {
        if (count($result['rows']) !== 500 || $result['columns'] !== ['nome_completo', 'cpf', 'matricula', 'whatsapp']
            || count($result['preview']) !== 10 || $result['errors'] !== []) {
            throw new RuntimeException($format . ': expected 500 normalized records, four canonical columns, ten-row preview and no errors.');
        }
    }
    if ($csv['rows'] !== $xlsx['rows'] || $csv['rows'] !== $json['rows']) {
        throw new RuntimeException('CSV, XLSX and JSON must produce identical normalized row arrays.');
    }
    if ($csv['rows'][0]['nome_completo'] !== 'João Silva 000' || $csv['rows'][0]['cpf'] !== batchImportParserTestCpf(0)
        || $csv['rows'][0]['whatsapp'] !== '+5511900000000') {
        throw new RuntimeException('Encoding, CPF, and BR phone normalization must match the import contract.');
    }

    file_put_contents($invalidPath, "Nome Completo;CPF;WhatsApp\nPessoa privada;111.111.111-11;123\n");
    $invalid = batchImportParse($invalidPath);
    if (count($invalid['errors']) !== 2
        || $invalid['errors'][0] !== ['linha' => 2, 'campo' => 'cpf', 'codigo' => 'IMPORT_CPF_INVALID']
        || $invalid['errors'][1] !== ['linha' => 2, 'campo' => 'whatsapp', 'codigo' => 'IMPORT_PHONE_INVALID']
        || str_contains(json_encode($invalid['errors'], JSON_THROW_ON_ERROR), 'Pessoa privada')
        || str_contains(json_encode($invalid['errors'], JSON_THROW_ON_ERROR), '11111111111')) {
        throw new RuntimeException('Row validation errors must identify line/field without echoing PII.');
    }

    $overflowRecords = [...$records, $records[0]];
    $overflowLines = [implode(';', $headers)];
    foreach ($overflowRecords as $record) {
        $overflowLines[] = implode(';', array_map(
            static fn(string $value): string => '"' . str_replace('"', '""', $value) . '"',
            array_values($record)
        ));
    }
    file_put_contents($overflowCsvPath, implode("\n", $overflowLines));
    batchImportParserWriteXlsx($overflowXlsxPath, $headers, $overflowRecords);
    file_put_contents($overflowJsonPath, json_encode($overflowRecords, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
    foreach ([$overflowCsvPath, $overflowXlsxPath, $overflowJsonPath] as $overflowPath) {
        try {
            batchImportParse($overflowPath);
            throw new RuntimeException('Every supported format must reject more than 500 records.');
        } catch (DomainException $exception) {
            if ($exception->getMessage() !== 'IMPORT_ROW_LIMIT_EXCEEDED') {
                throw $exception;
            }
        }
    }

    file_put_contents($invalidXlsxPath, 'not an XLSX archive');
    file_put_contents($invalidJsonPath, '{"truncated":');
    foreach ([[$invalidXlsxPath, 'IMPORT_XLSX_INVALID'], [$invalidJsonPath, 'IMPORT_JSON_INVALID']] as [$invalidPathToParse, $expectedCode]) {
        try {
            batchImportParse($invalidPathToParse);
            throw new RuntimeException('Malformed XLSX/JSON input must fail deterministically.');
        } catch (DomainException $exception) {
            if ($exception->getMessage() !== $expectedCode) {
                throw $exception;
            }
        }
    }

    fwrite(STDOUT, "PASS: CSV/XLSX/JSON import parser normalization, preview, validation and row bounds.\n");
} finally {
    foreach ([$csvPath, $xlsxPath, $jsonPath, $invalidPath, $overflowCsvPath, $overflowXlsxPath, $overflowJsonPath, $invalidXlsxPath, $invalidJsonPath] as $path) {
        if (is_file($path)) {
            unlink($path);
        }
    }
}

function batchImportParserTestCpf(int $seed): string
{
    $base = str_pad((string) ($seed + 100000000), 9, '0', STR_PAD_LEFT);
    $sum = 0;
    for ($index = 0; $index < 9; ++$index) {
        $sum += (int) $base[$index] * (10 - $index);
    }
    $remainder = $sum % 11;
    $first = $remainder < 2 ? 0 : 11 - $remainder;
    $sum = 0;
    for ($index = 0; $index < 9; ++$index) {
        $sum += (int) $base[$index] * (11 - $index);
    }
    $sum += $first * 2;
    $remainder = $sum % 11;
    $second = $remainder < 2 ? 0 : 11 - $remainder;

    return $base . $first . $second;
}

/** @param list<string> $headers @param list<array<string, string>> $records */
function batchImportParserWriteXlsx(string $path, array $headers, array $records): void
{
    $zip = new ZipArchive();
    if ($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
        throw new RuntimeException('Could not create XLSX fixture.');
    }
    $zip->addFromString(
        'xl/workbook.xml',
        '<?xml version="1.0" encoding="UTF-8"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" '
        . 'xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="Titulares" sheetId="1" r:id="rId1"/>'
        . '</sheets></workbook>'
    );
    $zip->addFromString(
        'xl/_rels/workbook.xml.rels',
        '<?xml version="1.0" encoding="UTF-8"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
        . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" '
        . 'Target="worksheets/sheet1.xml"/></Relationships>'
    );
    $sheet = '<?xml version="1.0" encoding="UTF-8"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>';
    $sheet .= batchImportParserXlsxRow(1, $headers);
    foreach ($records as $index => $record) {
        $sheet .= batchImportParserXlsxRow($index + 2, array_values($record));
    }
    $sheet .= '</sheetData></worksheet>';
    $zip->addFromString('xl/worksheets/sheet1.xml', $sheet);
    $zip->close();
}

/** @param list<string> $values */
function batchImportParserXlsxRow(int $rowNumber, array $values): string
{
    $xml = '<row r="' . $rowNumber . '">';
    foreach ($values as $columnIndex => $value) {
        $column = '';
        $number = $columnIndex + 1;
        while ($number > 0) {
            $remainder = ($number - 1) % 26;
            $column = chr(65 + $remainder) . $column;
            $number = intdiv($number - 1, 26);
        }
        $cell = $column . $rowNumber;
        $xml .= '<c r="' . $cell . '" t="inlineStr"><is><t>'
            . htmlspecialchars($value, ENT_XML1 | ENT_COMPAT, 'UTF-8')
            . '</t></is></c>';
    }

    return $xml . '</row>';
}
