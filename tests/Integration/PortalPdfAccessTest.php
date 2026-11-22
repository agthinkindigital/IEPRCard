<?php
declare(strict_types=1);

require_once __DIR__ . '/TenantIsolationFixture.php';
require_once dirname(__DIR__, 2) . '/config/portal_auth.php';
require_once dirname(__DIR__, 2) . '/config/tenant_pdf_portal.php';
require_once dirname(__DIR__, 2) . '/database/migrations/runner.php';

$privateRoot = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'ieprcard-portal-pdf-' . bin2hex(random_bytes(6));
$previousPrivateRoot = getenv('IEPR_PRIVATE_STORAGE_PATH');
putenv('IEPR_PRIVATE_STORAGE_PATH=' . $privateRoot);
mkdir($privateRoot, 0700, true);

foreach (tenantTestConnections() as $driver => $pdo) {
    schemaAplicarMigracoes($pdo, 'test');
    $fixture = tenantTestSeed($pdo);
    $suffix = bin2hex(random_bytes(4));
    $pdo->prepare("INSERT INTO planos (nome, ativo) VALUES (:name, 1)")->execute(['name' => 'PDF ' . $suffix]);
    $planId = (int) $pdo->lastInsertId();
    $pdo->prepare('UPDATE tenants SET plano_id = :plan WHERE id = :tenant')->execute([
        'plan' => $planId,
        'tenant' => $fixture['tenant_id'],
    ]);
    $pdo->prepare(
        'INSERT INTO plano_feature_flags (plano_id, feature_key, habilitado) VALUES (:plan, :key, 1)'
    )->execute(['plan' => $planId, 'key' => 'carteirinha.pdf']);

    $pdo->prepare(
        'INSERT INTO designs_carteirinha (tenant_id, nome_layout, orientacao, criado_em) '
        . 'VALUES (:tenant, :name, :orientation, :created)'
    )->execute([
        'tenant' => $fixture['tenant_id'],
        'name' => 'PDF test ' . $suffix,
        'orientation' => 'horizontal',
        'created' => gmdate('Y-m-d H:i:s'),
    ]);
    $designId = (int) $pdo->lastInsertId();
    $pdo->prepare(
        'INSERT INTO titulares (tenant_id, subcliente_id, nome_completo, data_nascimento, acesso_app_liberado, ativo, criado_em) '
        . 'VALUES (:tenant, :unit, :name, :birth, 1, 1, :created)'
    )->execute([
        'tenant' => $fixture['tenant_id'],
        'unit' => $fixture['subcliente_id'],
        'name' => 'PDF Holder ' . $suffix,
        'birth' => '1990-02-03',
        'created' => gmdate('Y-m-d H:i:s'),
    ]);
    $holderId = (int) $pdo->lastInsertId();

    $unitDirectory = $privateRoot . DIRECTORY_SEPARATOR . $fixture['tenant_id'];
    mkdir($unitDirectory, 0700, true);
    $token = substr(hash('sha256', 'pdf:' . $suffix), 0, 48);
    $pdfBytes = "%PDF-1.4\nportal pdf fixture\n%%EOF\n";
    file_put_contents($unitDirectory . DIRECTORY_SEPARATOR . $token . '.pdf', $pdfBytes);
    $storedPdf = 'private://' . $fixture['tenant_id'] . '/' . $token . '.pdf';
    $pdo->prepare(
        'INSERT INTO carteirinhas_emitidas '
        . '(tenant_id, subcliente_id, titular_id, design_id, codigo_validacao_qr, status_carteirinha, data_validade, pdf_individual_url, emitida_em) '
        . 'VALUES (:tenant, :unit, :holder, :design, :qr, :status, :validity, :pdf, :created)'
    )->execute([
        'tenant' => $fixture['tenant_id'],
        'unit' => $fixture['subcliente_id'],
        'holder' => $holderId,
        'design' => $designId,
        'qr' => bin2hex(random_bytes(32)),
        'status' => 'ativa',
        'validity' => gmdate('Y-m-d', strtotime('+1 year')),
        'pdf' => $storedPdf,
        'created' => gmdate('Y-m-d H:i:s'),
    ]);
    $cardId = (int) $pdo->lastInsertId();
    $identity = [
        'id' => $holderId,
        'tenant_id' => $fixture['tenant_id'],
        'subcliente_id' => $fixture['subcliente_id'],
        'papel_sistema' => 'titular_final',
    ];

    if (portalAuthPdfAsset($pdo, $identity, $cardId) !== null) {
        throw new RuntimeException($driver . ': PDF access must be denied by default.');
    }
    $pdo->exec('UPDATE tenants SET pdf_portal_habilitado = 1 WHERE id = ' . $fixture['tenant_id']);
    if (portalAuthPdfAsset($pdo, $identity, $cardId) !== null) {
        throw new RuntimeException($driver . ': unit PDF access requires explicit Sede delegation.');
    }
    $pdo->prepare('UPDATE tenants SET permissoes_unidade_json = :permissions WHERE id = :tenant')->execute([
        'permissions' => '["carteirinha.pdf"]',
        'tenant' => $fixture['tenant_id'],
    ]);
    $asset = portalAuthPdfAsset($pdo, $identity, $cardId);
    if (($asset['tenant_id'] ?? null) !== $fixture['tenant_id'] || ($asset['stored_key'] ?? null) !== $storedPdf) {
        throw new RuntimeException($driver . ': an enabled, delegated unit holder must resolve only their own active PDF.');
    }
    $pdo->prepare('UPDATE plano_feature_flags SET habilitado = 0 WHERE plano_id = :plan AND feature_key = :feature')
        ->execute(['plan' => $planId, 'feature' => 'carteirinha.pdf']);
    if (portalAuthPdfAsset($pdo, $identity, $cardId) !== null) {
        throw new RuntimeException($driver . ': a disabled plan feature must deny PDF downloads.');
    }
    $pdo->prepare('UPDATE plano_feature_flags SET habilitado = 1 WHERE plano_id = :plan AND feature_key = :feature')
        ->execute(['plan' => $planId, 'feature' => 'carteirinha.pdf']);
    $pdo->prepare('UPDATE carteirinhas_emitidas SET status_carteirinha = :status WHERE id = :id')
        ->execute(['status' => 'suspensa', 'id' => $cardId]);
    if (portalAuthPdfAsset($pdo, $identity, $cardId) !== null) {
        throw new RuntimeException($driver . ': an inactive card must not resolve a PDF asset.');
    }
    $pdo->prepare('UPDATE carteirinhas_emitidas SET status_carteirinha = :status, data_validade = :expiry WHERE id = :id')
        ->execute(['status' => 'ativa', 'expiry' => '2000-01-01', 'id' => $cardId]);
    if (portalAuthPdfAsset($pdo, $identity, $cardId) !== null) {
        throw new RuntimeException($driver . ': an expired card must not resolve a PDF asset.');
    }
    $pdo->prepare('UPDATE carteirinhas_emitidas SET data_validade = :expiry WHERE id = :id')
        ->execute(['expiry' => gmdate('Y-m-d', strtotime('+1 year')), 'id' => $cardId]);
    $pdo->exec('UPDATE tenants SET pdf_portal_habilitado = 0 WHERE id = ' . $fixture['tenant_id']);
    if (portalAuthPdfAsset($pdo, $identity, $cardId) !== null) {
        throw new RuntimeException($driver . ': disabling Sede PDF access must revoke unit access.');
    }

    $ownerEmail = 'pdf-owner-' . $suffix . '@example.test';
    $pdo->prepare(
        'INSERT INTO usuarios (tenant_id, papel_sistema, nome, email, senha_hash, ativo, criado_em) '
        . 'VALUES (:tenant, :role, :name, :email, :password, 1, :created)'
    )->execute([
        'tenant' => $fixture['tenant_id'],
        'role' => 'titular_cliente',
        'name' => 'PDF Owner',
        'email' => $ownerEmail,
        'password' => password_hash('unused-password', PASSWORD_DEFAULT),
        'created' => gmdate('Y-m-d H:i:s'),
    ]);
    $ownerId = (int) $pdo->lastInsertId();
    $ownerIdentity = [
        'id' => $ownerId,
        'tenant_id' => $fixture['tenant_id'],
        'subcliente_id' => null,
        'papel_sistema' => 'titular_cliente',
    ];
    $rootContext = new TenantContext('cliente', $fixture['tenant_id'], null, []);
    tenantPdfPortalSetEnabled($pdo, $rootContext, $ownerIdentity, $fixture['tenant_id'], true);
    $state = $pdo->prepare('SELECT pdf_portal_habilitado FROM tenants WHERE id = :tenant');
    $state->execute(['tenant' => $fixture['tenant_id']]);
    $audit = $pdo->prepare(
        "SELECT COUNT(*) FROM auditoria_processos WHERE tenant_id = :tenant AND acao = 'tenant.portal_pdf_access_changed'"
    );
    $audit->execute(['tenant' => $fixture['tenant_id']]);
    if ((int) $state->fetchColumn() !== 1 || (int) $audit->fetchColumn() !== 1) {
        throw new RuntimeException($driver . ': the Sede owner must change PDF access through an audited RBAC service.');
    }
    try {
        tenantPdfPortalSetEnabled(
            $pdo,
            new TenantContext('subcliente', $fixture['tenant_id'], $fixture['subcliente_id'], []),
            $ownerIdentity,
            $fixture['tenant_id'],
            false
        );
        throw new RuntimeException($driver . ': a unit context must not manage the Sede PDF setting.');
    } catch (DomainException $exception) {
        if ($exception->getMessage() !== 'TENANT_PDF_ACCESS_DENIED') {
            throw $exception;
        }
    }
    $pdo->prepare('UPDATE titulares SET subcliente_id = NULL WHERE id = :holder')->execute(['holder' => $holderId]);
    $pdo->prepare('UPDATE carteirinhas_emitidas SET subcliente_id = NULL WHERE id = :card')->execute(['card' => $cardId]);
    $rootIdentity = $identity;
    $rootIdentity['subcliente_id'] = null;
    if (portalAuthPdfAsset($pdo, $rootIdentity, $cardId) === null) {
        throw new RuntimeException($driver . ': an enabled Sede must be able to provide its own titular PDF without unit delegation.');
    }
    tenantPdfPortalSetEnabled($pdo, $rootContext, $ownerIdentity, $fixture['tenant_id'], false);
    if (portalAuthPdfAsset($pdo, $rootIdentity, $cardId) !== null) {
        throw new RuntimeException($driver . ': disabling the Sede PDF setting must revoke access for Sede holders.');
    }

    $pdo->prepare('DELETE FROM auditoria_processos WHERE tenant_id = :tenant AND acao = :action')->execute([
        'tenant' => $fixture['tenant_id'],
        'action' => 'tenant.portal_pdf_access_changed',
    ]);
    $pdo->prepare('DELETE FROM carteirinhas_emitidas WHERE id = :id')->execute(['id' => $cardId]);
    $pdo->prepare('DELETE FROM titulares WHERE id = :id')->execute(['id' => $holderId]);
    $pdo->prepare('DELETE FROM designs_carteirinha WHERE id = :id')->execute(['id' => $designId]);
    $pdo->prepare('DELETE FROM usuarios WHERE id = :id')->execute(['id' => $ownerId]);
    $pdo->prepare('UPDATE tenants SET plano_id = NULL WHERE id = :tenant')->execute(['tenant' => $fixture['tenant_id']]);
    $pdo->prepare('DELETE FROM plano_feature_flags WHERE plano_id = :plan')->execute(['plan' => $planId]);
    $pdo->prepare('DELETE FROM planos WHERE id = :plan')->execute(['plan' => $planId]);
    tenantTestCleanup($pdo, $fixture);
    @unlink($unitDirectory . DIRECTORY_SEPARATOR . $token . '.pdf');
    @rmdir($unitDirectory);

    fwrite(STDOUT, 'PASS: titular PDF authorization defaults to denied and requires Sede activation plus unit delegation (' . $driver . ").\n");
}

if ($previousPrivateRoot === false) {
    putenv('IEPR_PRIVATE_STORAGE_PATH');
} else {
    putenv('IEPR_PRIVATE_STORAGE_PATH=' . $previousPrivateRoot);
}
@rmdir($privateRoot);

return 'pass';
