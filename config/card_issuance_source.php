<?php
declare(strict_types=1);

function cardIssuanceSourceQuantity(
    PDO $pdo,
    int $tenantId,
    ?int $subclienteId,
    ?int $sourceLotId,
    ?int $titularId,
    array $designRecord,
    string $outputMode,
    ?string $mappingOverrideJson
): int {
    $individualSample = null;
    if ($sourceLotId !== null) {
        $source = $pdo->prepare(
            'SELECT subcliente_id, status_lote FROM lotes_importacao WHERE id = :id AND tenant_id = :tenant LIMIT 1'
        );
        $source->execute(['id' => $sourceLotId, 'tenant' => $tenantId]);
        $sourceRecord = $source->fetch(PDO::FETCH_ASSOC);
        if (!is_array($sourceRecord)
            || cardIssuanceNullableId($sourceRecord['subcliente_id']) !== $subclienteId
            || $sourceRecord['status_lote'] !== 'concluido') {
            throw new DomainException('ISSUANCE_SOURCE_NOT_FOUND');
        }
        $count = $pdo->prepare(
            'SELECT COUNT(*) FROM titulares WHERE lote_origem_id = :lot AND tenant_id = :tenant '
            . 'AND ((subcliente_id = :subclient_match) OR (subcliente_id IS NULL AND :subclient_null IS NULL))'
        );
        $count->execute([
            'lot' => $sourceLotId,
            'tenant' => $tenantId,
            'subclient_match' => $subclienteId,
            'subclient_null' => $subclienteId,
        ]);
        $quantity = (int) $count->fetchColumn();
    } else {
        $individualSampleQuery = $pdo->prepare(
            'SELECT id, tenant_id, subcliente_id, ativo, nome_completo, cpf, rg, cargo_funcao, matricula, '
            . 'data_nascimento, data_batismo_ou_admissao, whatsapp, email_login, foto_url, dados_extras_json '
            . 'FROM titulares WHERE id = :id AND tenant_id = :tenant AND ativo = 1 '
            . 'AND ((subcliente_id = :child_match) OR (subcliente_id IS NULL AND :child_null IS NULL)) LIMIT 1'
        );
        $individualSampleQuery->execute([
            'id' => $titularId,
            'tenant' => $tenantId,
            'child_match' => $subclienteId,
            'child_null' => $subclienteId,
        ]);
        $individualSample = $individualSampleQuery->fetch(PDO::FETCH_ASSOC);
        if (!is_array($individualSample)) {
            throw new DomainException('ISSUANCE_SOURCE_NOT_FOUND');
        }
        $quantity = 1;
    }

    if ($quantity < 1 || $quantity > 500) {
        throw new DomainException('ISSUANCE_SOURCE_EMPTY_OR_TOO_LARGE');
    }
    if ($outputMode !== 'webp' && $quantity > 50) {
        throw new DomainException('ISSUANCE_PDF_MAX_50');
    }
    if ($mappingOverrideJson === null) {
        return $quantity;
    }

    try {
        $designMap = json_decode((string) $designRecord['mapa_campos_json'], true, 512, JSON_THROW_ON_ERROR);
    } catch (JsonException) {
        throw new DomainException('ISSUANCE_MAPPING_INVALID');
    }
    if ($sourceLotId !== null) {
        $sampleQuery = $pdo->prepare(
            'SELECT nome_completo, cpf, rg, cargo_funcao, matricula, data_nascimento, '
            . 'data_batismo_ou_admissao, whatsapp, email_login, foto_url, dados_extras_json '
            . 'FROM titulares WHERE lote_origem_id = :source AND tenant_id = :tenant '
            . 'AND ((subcliente_id = :child_match) OR (subcliente_id IS NULL AND :child_null IS NULL)) '
            . 'ORDER BY id LIMIT 1'
        );
        $sampleQuery->execute([
            'source' => $sourceLotId,
            'tenant' => $tenantId,
            'child_match' => $subclienteId,
            'child_null' => $subclienteId,
        ]);
        $sample = $sampleQuery->fetch(PDO::FETCH_ASSOC);
    } else {
        $sample = $individualSample;
    }
    if (!is_array($sample)) {
        throw new DomainException('ISSUANCE_SOURCE_EMPTY_OR_TOO_LARGE');
    }
    $extra = json_decode((string) ($sample['dados_extras_json'] ?? '{}'), true);
    $record = ['titular' => $sample + ['dados_extras' => is_array($extra) ? $extra : []]];
    $systemContext = [];
    foreach (is_array($designMap['elementos'] ?? null) ? $designMap['elementos'] : [] as $element) {
        if (($element['tipo'] ?? null) === 'qrcode_validacao') {
            $systemContext['_sistema_url_validacao'] = 'https://preview.invalid/validation';
            break;
        }
    }
    if (!designMappingResolve($designMap, $mappingOverrideJson, $record, $systemContext)['valid']) {
        throw new DomainException('ISSUANCE_MAPPING_INVALID');
    }

    return $quantity;
}
