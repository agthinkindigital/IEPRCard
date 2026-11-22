<?php
declare(strict_types=1);

require_once __DIR__ . '/motor_permissoes.php';

/** @param array<string, mixed> $identity @return array{tenant_id:int,stored_key:string}|null */
function portalAuthPdfAsset(PDO $pdo, array $identity, int $cardId, ?string $today = null): ?array
{
    $holderId = filter_var($identity['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    $tenantId = filter_var($identity['tenant_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    $rawUnitId = $identity['subcliente_id'] ?? null;
    $unitId = $rawUnitId === null
        ? null
        : filter_var($rawUnitId, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    if ($cardId < 1 || $holderId === false || $tenantId === false
        || ($rawUnitId !== null && $unitId === false)
        || ($identity['papel_sistema'] ?? null) !== 'titular_final') {
        return null;
    }

    $unitPredicate = $unitId === null ? 'c.subcliente_id IS NULL AND holder.subcliente_id IS NULL' : 'c.subcliente_id = :unit AND holder.subcliente_id = :holder_unit';
    $unitJoinPredicate = $unitId === null ? '' : 'AND unit.id = :unit_join';
    $query = $pdo->prepare(
        'SELECT c.tenant_id, c.pdf_individual_url, root.plano_id, root.pdf_portal_habilitado, '
        . 'root.permissoes_unidade_json '
        . 'FROM carteirinhas_emitidas c '
        . 'INNER JOIN titulares holder ON holder.id = c.titular_id AND holder.tenant_id = c.tenant_id '
        . 'INNER JOIN tenants root ON root.id = c.tenant_id AND root.parent_id IS NULL '
        . "AND root.tipo = 'cliente' AND root.status_conta = 'ativo' "
        . 'LEFT JOIN tenants unit ON unit.id = c.subcliente_id ' . $unitJoinPredicate . ' '
        . 'AND unit.parent_id = c.tenant_id AND unit.tipo = \'subcliente\' AND unit.status_conta = \'ativo\' '
        . 'WHERE c.id = :card AND c.titular_id = :holder AND c.tenant_id = :tenant AND ' . $unitPredicate . ' '
        . 'AND holder.ativo = 1 AND holder.acesso_app_liberado = 1 AND c.status_carteirinha = :active '
        . 'AND (c.data_validade IS NULL OR c.data_validade >= :today) '
        . 'AND (c.subcliente_id IS NULL OR unit.id IS NOT NULL) LIMIT 1'
    );
    $parameters = [
        'card' => $cardId,
        'holder' => (int) $holderId,
        'tenant' => (int) $tenantId,
        'active' => 'ativa',
        'today' => $today ?? gmdate('Y-m-d'),
    ];
    if ($unitId !== null) {
        $parameters['unit'] = (int) $unitId;
        $parameters['holder_unit'] = (int) $unitId;
        $parameters['unit_join'] = (int) $unitId;
    }
    $query->execute($parameters);
    $card = $query->fetch(PDO::FETCH_ASSOC);
    if (!is_array($card) || (int) $card['pdf_portal_habilitado'] !== 1
        || !is_string($card['pdf_individual_url']) || $card['pdf_individual_url'] === '') {
        return null;
    }

    $plan = $pdo->prepare(
        'SELECT habilitado FROM plano_feature_flags WHERE plano_id = :plan AND feature_key = :feature LIMIT 1'
    );
    $plan->execute(['plan' => (int) $card['plano_id'], 'feature' => 'carteirinha.pdf']);
    if ((int) $plan->fetchColumn() !== 1) {
        return null;
    }
    if ($unitId !== null
        && !in_array('carteirinha.pdf', motorPermissoesDecodeList($card['permissoes_unidade_json']), true)) {
        return null;
    }

    return ['tenant_id' => (int) $card['tenant_id'], 'stored_key' => (string) $card['pdf_individual_url']];
}
