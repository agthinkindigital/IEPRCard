<?php
declare(strict_types=1);

require_once __DIR__ . '/TenantIsolationFixture.php';
require_once dirname(__DIR__, 2) . '/config/card_validation.php';

foreach (tenantTestConnections() as $driver => $pdo) {
    $fixture = tenantTestSeed($pdo);
    try {
        $pdo->prepare(
            'INSERT INTO designs_carteirinha (tenant_id, nome_layout, orientacao, criado_em) '
            . 'VALUES (:tenant, :name, :orientation, :created)'
        )->execute([
            'tenant' => $fixture['tenant_id'],
            'name' => 'QR validation design',
            'orientation' => 'horizontal',
            'created' => gmdate('Y-m-d H:i:s'),
        ]);
        $designId = (int) $pdo->lastInsertId();
        $pdo->prepare(
            'INSERT INTO titulares (tenant_id, subcliente_id, nome_completo, ativo, criado_em) '
            . 'VALUES (:tenant, :unit, :name, 1, :created)'
        )->execute([
            'tenant' => $fixture['tenant_id'],
            'unit' => $fixture['subcliente_id'],
            'name' => 'QR Test Holder',
            'created' => gmdate('Y-m-d H:i:s'),
        ]);
        $holderId = (int) $pdo->lastInsertId();
        $token = bin2hex(random_bytes(32));
        $pdo->prepare(
            'INSERT INTO carteirinhas_emitidas '
            . '(tenant_id, subcliente_id, titular_id, design_id, codigo_validacao_qr, status_carteirinha, data_validade, emitida_em) '
            . 'VALUES (:tenant, :unit, :holder, :design, :token, :status, :validity, :created)'
        )->execute([
            'tenant' => $fixture['tenant_id'],
            'unit' => $fixture['subcliente_id'],
            'holder' => $holderId,
            'design' => $designId,
            'token' => $token,
            'status' => 'ativa',
            'validity' => gmdate('Y-m-d', strtotime('+1 year')),
            'created' => gmdate('Y-m-d H:i:s'),
        ]);

        $result = cardValidationResolve($pdo, $token);
        if ($result !== ['status' => 'ativa', 'motivo_codigo' => 'ativa']) {
            throw new RuntimeException($driver . ': an active card must return the public minimal validation result.');
        }
        $counter = (int) $pdo->query('SELECT total_leituras_qr FROM carteirinhas_emitidas WHERE codigo_validacao_qr = ' . $pdo->quote($token))->fetchColumn();
        if ($counter !== 1) {
            throw new RuntimeException($driver . ': a successful QR lookup must increment its counter atomically.');
        }
        $audit = $pdo->query("SELECT tenant_id, subcliente_id, ator_tipo, acao, entidade, entidade_id, antes_json, depois_json FROM auditoria_processos WHERE acao = 'carteirinha.qr_lida'")->fetch(PDO::FETCH_ASSOC);
        if (!is_array($audit) || (int) $audit['tenant_id'] !== $fixture['tenant_id']
            || (int) $audit['subcliente_id'] !== $fixture['subcliente_id']
            || $audit['ator_tipo'] !== 'publico' || $audit['entidade_id'] !== null
            || $audit['antes_json'] !== null || $audit['depois_json'] !== null) {
            throw new RuntimeException($driver . ': QR auditing must contain only the approved tenant/unit event and fixed action.');
        }
        $invalid = cardValidationResolve($pdo, 'not-a-token');
        if ($invalid !== ['status' => 'invalida', 'motivo_codigo' => 'invalida']) {
            throw new RuntimeException($driver . ': malformed tokens must return a generic invalid response.');
        }
        $pdo->prepare("UPDATE carteirinhas_emitidas SET data_validade = '2000-01-01' WHERE codigo_validacao_qr = :token")
            ->execute(['token' => $token]);
        if (cardValidationResolve($pdo, $token) !== ['status' => 'vencida', 'motivo_codigo' => 'vencida']) {
            throw new RuntimeException($driver . ': an expired validity date must report an expired card.');
        }
        $pdo->prepare("UPDATE carteirinhas_emitidas SET data_validade = '2099-01-01', status_carteirinha = 'revogada' WHERE codigo_validacao_qr = :token")
            ->execute(['token' => $token]);
        if (cardValidationResolve($pdo, $token) !== ['status' => 'revogada', 'motivo_codigo' => 'revogada']) {
            throw new RuntimeException($driver . ': a revoked card must keep its distinct public state.');
        }
        $pdo->prepare("UPDATE carteirinhas_emitidas SET status_carteirinha = 'suspensa' WHERE codigo_validacao_qr = :token")
            ->execute(['token' => $token]);
        if (cardValidationResolve($pdo, $token) !== ['status' => 'suspensa', 'motivo_codigo' => 'suspensa']) {
            throw new RuntimeException($driver . ': a suspended card must report its distinct inactive state.');
        }
        $pdo->prepare("UPDATE carteirinhas_emitidas SET status_carteirinha = 'ativa' WHERE codigo_validacao_qr = :token")
            ->execute(['token' => $token]);
        $pdo->prepare('UPDATE titulares SET ativo = 0 WHERE id = :holder')->execute(['holder' => $holderId]);
        if (cardValidationResolve($pdo, $token) !== ['status' => 'suspensa', 'motivo_codigo' => 'suspensa']) {
            throw new RuntimeException($driver . ': an inactive holder must suspend public validation.');
        }
        $pdo->prepare('UPDATE titulares SET ativo = 1 WHERE id = :holder')->execute(['holder' => $holderId]);
        $pdo->prepare("UPDATE tenants SET status_conta = 'suspensa' WHERE id = :unit")
            ->execute(['unit' => $fixture['subcliente_id']]);
        if (cardValidationResolve($pdo, $token) !== ['status' => 'suspensa', 'motivo_codigo' => 'suspensa']) {
            throw new RuntimeException($driver . ': a suspended unit must suspend public validation.');
        }
        $pdo->prepare("UPDATE tenants SET status_conta = 'ativo' WHERE id = :unit")
            ->execute(['unit' => $fixture['subcliente_id']]);
        $pdo->prepare("UPDATE tenants SET status_conta = 'suspensa' WHERE id = :tenant")
            ->execute(['tenant' => $fixture['tenant_id']]);
        if (cardValidationResolve($pdo, $token) !== ['status' => 'suspensa', 'motivo_codigo' => 'suspensa']) {
            throw new RuntimeException($driver . ': an inactive issuing account must suspend public validation.');
        }
        fwrite(STDOUT, 'PASS: QR validation persistence contract (' . $driver . ").\n");
    } finally {
        $pdo->prepare("DELETE FROM auditoria_processos WHERE tenant_id = :tenant AND acao = 'carteirinha.qr_lida'")
            ->execute(['tenant' => $fixture['tenant_id']]);
        $pdo->prepare('DELETE FROM carteirinhas_emitidas WHERE tenant_id = :tenant')->execute(['tenant' => $fixture['tenant_id']]);
        $pdo->prepare('DELETE FROM titulares WHERE tenant_id = :tenant')->execute(['tenant' => $fixture['tenant_id']]);
        $pdo->prepare('DELETE FROM designs_carteirinha WHERE tenant_id = :tenant')->execute(['tenant' => $fixture['tenant_id']]);
        tenantTestCleanup($pdo, $fixture);
    }
}

return 'pass';
