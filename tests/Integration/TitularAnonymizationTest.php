<?php
declare(strict_types=1);

require_once __DIR__ . '/TenantIsolationFixture.php';
require_once dirname(__DIR__, 2) . '/config/titular_anonymization.php';

$oldKey = getenv('IEPR_ANONYMIZATION_KEY');
$oldStorage = getenv('IEPR_PRIVATE_STORAGE_PATH');
$storagePath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'ieprcard-anonymization-' . bin2hex(random_bytes(6));
if (!mkdir($storagePath, 0700, true)) {
    throw new RuntimeException('Could not create temporary anonymization storage.');
}
putenv('IEPR_ANONYMIZATION_KEY=' . base64_encode(random_bytes(32)));
putenv('IEPR_PRIVATE_STORAGE_PATH=' . $storagePath);

try {
    foreach (tenantTestConnections() as $driver => $pdo) {
        $fixture = tenantTestSeed($pdo);
        $suffix = bin2hex(random_bytes(5));
        $insert = $pdo->prepare(
            'INSERT INTO titulares (tenant_id, subcliente_id, nome_completo, cpf, rg, cargo_funcao, matricula, '
            . 'data_nascimento, whatsapp, email_login, senha_portal_hash, token_unico_carteira, foto_url, '
            . 'dados_extras_json, ativo, acesso_app_liberado, criado_em) '
            . 'VALUES (:tenant, :subclient, :name, :cpf, :rg, :role, :registration, :birth, :phone, :email, '
            . ':password, :token, :photo, :extras, 1, 1, :created)'
        );
        $insert->execute([
            'tenant' => $fixture['tenant_id'],
            'subclient' => $fixture['subcliente_id'],
            'name' => 'Sensitive Holder ' . $suffix,
            'cpf' => '52998224725',
            'rg' => 'RG-' . $suffix,
            'role' => 'Membro ' . $suffix,
            'registration' => 'M-' . $suffix,
            'birth' => '1990-02-03',
            'phone' => '+5511999999999',
            'email' => 'holder-' . $suffix . '@example.test',
            'password' => password_hash('temporary-test-secret', PASSWORD_DEFAULT),
            'token' => 'token-' . $suffix,
            'photo' => 'private://' . $fixture['tenant_id'] . '/' . str_repeat('a', 48) . '.jpg',
            'extras' => '{"custom":"sensitive"}',
            'created' => gmdate('Y-m-d H:i:s'),
        ]);
        $titularId = (int) $pdo->lastInsertId();
        $now = 1_800_000_000;
        $requestId = bin2hex(random_bytes(20));

        $request = titularAnonymizationRequest(
            $pdo,
            $fixture['tenant_id'],
            $titularId,
            $requestId,
            $now
        );
        $stored = $pdo->prepare(
            'SELECT solicitacao_id, referencia_titular_cifrada, estado, solicitado_em, prazo_em '
            . 'FROM solicitacoes_anonimizacao WHERE solicitacao_id = :id'
        );
        $stored->execute(['id' => $requestId]);
        $row = $stored->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)
            || $request['solicitacao_id'] !== $requestId
            || $request['prazo_em'] !== gmdate('Y-m-d H:i:s', $now + 72 * 60 * 60)
            || $row['estado'] !== 'pendente'
            || $row['solicitado_em'] !== gmdate('Y-m-d H:i:s', $now)
            || $row['prazo_em'] !== $request['prazo_em']
            || str_contains((string) $row['referencia_titular_cifrada'], '{')
            || str_contains((string) $row['referencia_titular_cifrada'], 'tenant_id')) {
            throw new RuntimeException($driver . ': deletion requests must store an encrypted reference and a 72-hour UTC deadline.');
        }
        titularAnonymizationRequest($pdo, $fixture['tenant_id'], $titularId, $requestId, $now + 1);

        $result = titularAnonymizationProcessDue($pdo, $now);
        $holder = $pdo->prepare(
            'SELECT nome_completo, cpf, rg, cargo_funcao, matricula, data_nascimento, whatsapp, email_login, '
            . 'senha_portal_hash, token_unico_carteira, foto_url, dados_extras_json, ativo, acesso_app_liberado '
            . 'FROM titulares WHERE id = :id AND tenant_id = :tenant'
        );
        $holder->execute(['id' => $titularId, 'tenant' => $fixture['tenant_id']]);
        $anonymized = $holder->fetch(PDO::FETCH_ASSOC);
        $stored->execute(['id' => $requestId]);
        $completed = $stored->fetch(PDO::FETCH_ASSOC);
        if (($result['processed'] ?? null) !== 1 || ($result['failed'] ?? null) !== 0
            || !is_array($anonymized) || $anonymized['nome_completo'] !== 'Titular anonimizado'
            || $anonymized['cpf'] !== null || $anonymized['rg'] !== null || $anonymized['cargo_funcao'] !== null
            || $anonymized['matricula'] !== null || $anonymized['data_nascimento'] !== null
            || $anonymized['whatsapp'] !== null || $anonymized['email_login'] !== null
            || $anonymized['senha_portal_hash'] !== null || $anonymized['token_unico_carteira'] !== null
            || $anonymized['foto_url'] !== null || $anonymized['dados_extras_json'] !== null
            || (int) $anonymized['ativo'] !== 0 || (int) $anonymized['acesso_app_liberado'] !== 0
            || !is_array($completed) || $completed['estado'] !== 'concluida'
            || $completed['referencia_titular_cifrada'] !== '') {
            throw new RuntimeException($driver . ': due deletion requests must erase direct identifiers and clear their encrypted queue reference.');
        }
        $replayed = titularAnonymizationRequest($pdo, $fixture['tenant_id'], $titularId, $requestId, $now + 72 * 60 * 60);
        if (($replayed['estado'] ?? null) !== 'concluida') {
            throw new RuntimeException($driver . ': completed deletion requests must remain idempotent after their encrypted reference is erased.');
        }

        $retentionInsert = $pdo->prepare(
            'INSERT INTO titulares (tenant_id, nome_completo, cpf, ativo, criado_em) '
            . 'VALUES (:tenant, :name, :cpf, 1, :created)'
        );
        $retentionInsert->execute([
            'tenant' => $fixture['tenant_id'],
            'name' => 'Retention Holder ' . $suffix,
            'cpf' => '11144477735',
            'created' => '2024-01-01 00:00:00',
        ]);
        $retentionId = (int) $pdo->lastInsertId();
        $queueBaseline = (int) $pdo->query('SELECT COALESCE(MAX(id), 0) FROM solicitacoes_anonimizacao')->fetchColumn();
        $inactiveAt = strtotime('2024-02-29 10:00:00 UTC');
        $inactive = titularAnonymizationDeactivate($pdo, $fixture['tenant_id'], $retentionId, $inactiveAt);
        $again = titularAnonymizationDeactivate($pdo, $fixture['tenant_id'], $retentionId, $inactiveAt + 30 * 86400);
        $lifecycle = $pdo->prepare('SELECT nome_completo, cpf, ativo, inativado_em, anonimizado_em FROM titulares WHERE id = :id');
        $lifecycle->execute(['id' => $retentionId]);
        $inactiveRecord = $lifecycle->fetch(PDO::FETCH_ASSOC);
        $beforeDeadline = titularAnonymizationProcessDue($pdo, strtotime('2025-02-28 09:59:59 UTC'));
        $lifecycle->execute(['id' => $retentionId]);
        $beforeRecord = $lifecycle->fetch(PDO::FETCH_ASSOC);
        $atDeadline = titularAnonymizationProcessDue($pdo, strtotime('2025-02-28 10:00:00 UTC'));
        $afterRetention = titularAnonymizationProcessDue($pdo, strtotime('2025-02-28 10:00:01 UTC'));
        $lifecycle->execute(['id' => $retentionId]);
        $retained = $lifecycle->fetch(PDO::FETCH_ASSOC);
        if ($inactive['inativado_em'] !== gmdate('Y-m-d H:i:s', $inactiveAt)
            || $again['inativado_em'] !== $inactive['inativado_em']
            || !is_array($inactiveRecord) || $inactiveRecord['inativado_em'] !== $inactive['inativado_em']
            || (int) $inactiveRecord['ativo'] !== 0
            || ($beforeDeadline['processed'] ?? null) !== 0 || !is_array($beforeRecord)
            || $beforeRecord['nome_completo'] !== 'Retention Holder ' . $suffix
            || ($atDeadline['processed'] ?? null) !== 1 || !is_array($retained)
            || $retained['nome_completo'] !== 'Titular anonimizado' || $retained['cpf'] !== null
            || $retained['anonimizado_em'] !== '2025-02-28 10:00:00'
            || ($afterRetention['processed'] ?? null) !== 0) {
            throw new RuntimeException($driver . ': retention must preserve inactive holder data for 12 calendar months, then anonymize exactly at the deadline: ' . json_encode([$inactive, $again, $inactiveRecord, $beforeDeadline, $beforeRecord, $atDeadline, $retained]));
        }

        $pdo->prepare('DELETE FROM solicitacoes_anonimizacao WHERE solicitacao_id = :id')->execute(['id' => $requestId]);
        $pdo->prepare('DELETE FROM titulares WHERE id = :id')->execute(['id' => $titularId]);
        $pdo->prepare('DELETE FROM solicitacoes_anonimizacao WHERE id > :baseline')->execute(['baseline' => $queueBaseline]);
        $pdo->prepare('DELETE FROM titulares WHERE id = :id')->execute(['id' => $retentionId]);
        tenantTestCleanup($pdo, $fixture);
        fwrite(STDOUT, 'PASS: ' . $driver . " anonymization request stores only an encrypted reference and a 72-hour deadline.\n");
    }
} finally {
    putenv('IEPR_ANONYMIZATION_KEY=' . ($oldKey === false ? '' : $oldKey));
    putenv('IEPR_PRIVATE_STORAGE_PATH=' . ($oldStorage === false ? '' : $oldStorage));
    @rmdir($storagePath);
}
