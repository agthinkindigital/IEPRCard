<?php
declare(strict_types=1);

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
