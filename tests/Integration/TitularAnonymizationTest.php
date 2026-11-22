<?php
declare(strict_types=1);

require_once __DIR__ . '/TenantIsolationFixture.php';
require_once dirname(__DIR__, 2) . '/config/titular_anonymization.php';
require_once dirname(__DIR__, 2) . '/config/privacy_subject_lifecycle.php';

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
        $createdAt = gmdate('Y-m-d H:i:s');
        $pdo->prepare(
            "INSERT INTO whatsapp_instancias (tenant_id, escopo_chave, nome_instancia, webhook_token_hash, estado, fluxo_cadastro_ativo, criado_em, atualizado_em) "
            . "VALUES (:tenant, :scope, :name, :token_hash, 'ativa', 0, :created, :updated)"
        )->execute([
            'tenant' => $fixture['tenant_id'],
            'scope' => 'anonymization-test:' . $suffix,
            'name' => 'anonymization-' . $suffix,
            'token_hash' => hash('sha256', $suffix),
            'created' => $createdAt,
            'updated' => $createdAt,
        ]);
        $instanceId = (int) $pdo->lastInsertId();
        $pdo->prepare(
            "INSERT INTO whatsapp_conversas (instancia_id, tenant_id, subcliente_id, contato_hmac, estado, "
            . 'consentimento_finalidade, consentimento_em, titular_id, expira_em, criado_em, atualizado_em) '
            . "VALUES (:instance, :tenant, :subclient, :contact, 'concluida', :purpose, :consented, :holder, :expires, :created, :updated)"
        )->execute([
            'instance' => $instanceId,
            'tenant' => $fixture['tenant_id'],
            'subclient' => $fixture['subcliente_id'],
            'contact' => hash('sha256', $suffix),
            'purpose' => 'cadastro_titular_emissao_carteirinha',
            'consented' => $createdAt,
            'holder' => $titularId,
            'expires' => gmdate('Y-m-d H:i:s', 1_800_086_400),
            'created' => $createdAt,
            'updated' => $createdAt,
        ]);
        $now = 1_800_000_000;
        $keylessRequestId = bin2hex(random_bytes(20));
        $activeKey = getenv('IEPR_ANONYMIZATION_KEY');
        putenv('IEPR_ANONYMIZATION_KEY=');
        $keyFailure = null;
        try {
            titularAnonymizationRequest($pdo, $fixture['tenant_id'], $titularId, $keylessRequestId, $now);
        } catch (RuntimeException $exception) {
            $keyFailure = $exception->getMessage();
        } finally {
            putenv('IEPR_ANONYMIZATION_KEY=' . ($activeKey === false ? '' : $activeKey));
        }
        $keylessRow = $pdo->prepare('SELECT COUNT(*) FROM solicitacoes_anonimizacao WHERE solicitacao_id = :id');
        $keylessRow->execute(['id' => $keylessRequestId]);
        if ($keyFailure !== 'ANONYMIZATION_KEY_UNAVAILABLE' || (int) $keylessRow->fetchColumn() !== 0) {
            throw new RuntimeException($driver . ': a missing encryption key must fail closed before writing a deletion request.');
        }
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
        $conversation = $pdo->query('SELECT estado, contato_hmac, dados_cifrados, titular_id FROM whatsapp_conversas LIMIT 1')->fetch(PDO::FETCH_ASSOC);
        if (($result['processed'] ?? null) !== 1 || ($result['failed'] ?? null) !== 0
            || !is_array($anonymized) || $anonymized['nome_completo'] !== 'Titular anonimizado'
            || $anonymized['cpf'] !== null || $anonymized['rg'] !== null || $anonymized['cargo_funcao'] !== null
            || $anonymized['matricula'] !== null || $anonymized['data_nascimento'] !== null
            || $anonymized['whatsapp'] !== null || $anonymized['email_login'] !== null
            || $anonymized['senha_portal_hash'] !== null || $anonymized['token_unico_carteira'] !== null
            || $anonymized['foto_url'] !== null || $anonymized['dados_extras_json'] !== null
            || (int) $anonymized['ativo'] !== 0 || (int) $anonymized['acesso_app_liberado'] !== 0
            || !is_array($conversation) || $conversation['estado'] !== 'anonimizada' || $conversation['contato_hmac'] !== null
            || $conversation['dados_cifrados'] !== null || $conversation['titular_id'] !== null
            || !is_array($completed) || $completed['estado'] !== 'concluida'
            || $completed['referencia_titular_cifrada'] !== '') {
            throw new RuntimeException($driver . ': due deletion requests must erase direct identifiers and clear their encrypted queue reference.');
        }
        $replayed = titularAnonymizationRequest($pdo, $fixture['tenant_id'], $titularId, $requestId, $now + 72 * 60 * 60);
        if (($replayed['estado'] ?? null) !== 'concluida') {
            throw new RuntimeException($driver . ': completed deletion requests must remain idempotent after their encrypted reference is erased.');
        }

        if ($driver === 'MySQL/MariaDB') {
            $concurrentInsert = $pdo->prepare(
                'INSERT INTO titulares (tenant_id, nome_completo, cpf, ativo, criado_em) '
                . 'VALUES (:tenant, :name, :cpf, 1, :created)'
            );
            $concurrentInsert->execute([
                'tenant' => $fixture['tenant_id'],
                'name' => 'Concurrent Holder ' . $suffix,
                'cpf' => '11144477735',
                'created' => gmdate('Y-m-d H:i:s'),
            ]);
            $concurrentTitularId = (int) $pdo->lastInsertId();
            $concurrentRequestId = bin2hex(random_bytes(20));
            titularAnonymizationRequest($pdo, $fixture['tenant_id'], $concurrentTitularId, $concurrentRequestId, $now);
            $concurrentQueue = $pdo->prepare('SELECT id FROM solicitacoes_anonimizacao WHERE solicitacao_id = :id');
            $concurrentQueue->execute(['id' => $concurrentRequestId]);
            $concurrentQueueId = (int) $concurrentQueue->fetchColumn();
            $startFile = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'ieprcard-anonymization-race-' . $suffix;
            $processes = [];
            try {
                foreach ([1, 2] as $_) {
                    $pipes = [];
                    $process = proc_open(
                        [PHP_BINARY, __DIR__ . '/TitularAnonymizationRaceWorker.php', $startFile, (string) $concurrentQueueId, (string) $now],
                        [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']],
                        $pipes
                    );
                    if (!is_resource($process)) {
                        throw new RuntimeException('Anonymization race worker could not start.');
                    }
                    fclose($pipes[0]);
                    $processes[] = ['process' => $process, 'stdout' => $pipes[1], 'stderr' => $pipes[2]];
                }
                file_put_contents($startFile, 'start');
                $results = [];
                foreach ($processes as $entry) {
                    $stdout = trim(stream_get_contents($entry['stdout']));
                    $stderr = trim(stream_get_contents($entry['stderr']));
                    fclose($entry['stdout']);
                    fclose($entry['stderr']);
                    $exit = proc_close($entry['process']);
                    if ($exit !== 0 || $stderr !== '') {
                        throw new RuntimeException('Anonymization race worker failed.');
                    }
                    $results[] = $stdout;
                }
                $concurrentState = $pdo->prepare(
                    'SELECT estado, tentativas, referencia_titular_cifrada FROM solicitacoes_anonimizacao WHERE id = :id'
                );
                $concurrentState->execute(['id' => $concurrentQueueId]);
                $raceRow = $concurrentState->fetch(PDO::FETCH_ASSOC);
                if ($results !== ['OK', 'OK'] || !is_array($raceRow) || $raceRow['estado'] !== 'concluida'
                    || (int) $raceRow['tentativas'] !== 1 || $raceRow['referencia_titular_cifrada'] !== '') {
                    throw new RuntimeException('Concurrent anonymization must apply exactly once and clear its reference.');
                }
            } finally {
                foreach ($processes as $entry) {
                    if (is_resource($entry['process'])) {
                        proc_terminate($entry['process']);
                        fclose($entry['stdout']);
                        fclose($entry['stderr']);
                        proc_close($entry['process']);
                    }
                }
                if (is_file($startFile)) {
                    unlink($startFile);
                }
                $pdo->prepare('DELETE FROM solicitacoes_anonimizacao WHERE id = :id')->execute(['id' => $concurrentQueueId]);
                $pdo->prepare('DELETE FROM titulares WHERE id = :id')->execute(['id' => $concurrentTitularId]);
            }
        }

        $genericNow = strtotime('2026-11-22 10:00:00 UTC');
        $genericEmail = 'privacy-subject-' . $suffix . '@example.test';
        $pdo->prepare(
            'INSERT INTO usuarios (tenant_id, papel_sistema, nome, email, senha_hash, ativo, criado_em) '
            . "VALUES (:tenant, 'titular_cliente', :name, :email, :password, 0, :created)"
        )->execute([
            'tenant' => $fixture['tenant_id'], 'name' => 'Sensitive user ' . $suffix,
            'email' => $genericEmail, 'password' => password_hash('synthetic-user-secret', PASSWORD_DEFAULT),
            'created' => '2024-01-01 00:00:00',
        ]);
        $genericUserId = (int) $pdo->lastInsertId();
        $genericSubject = privacySubjectCreate($pdo, 'pessoa', $fixture['tenant_id'], '2024-01-01 00:00:00');
        privacySubjectLinkRecord($pdo, $genericSubject['id'], 'usuario', $genericUserId);
        $pdo->prepare(
            'UPDATE tenants SET nome_responsavel = :name, cargo_responsavel = :role WHERE id = :id'
        )->execute([
            'name' => 'Sensitive owner ' . $suffix, 'role' => 'Contact role ' . $suffix,
            'id' => $fixture['tenant_id'],
        ]);
        privacySubjectLinkRecord($pdo, $genericSubject['id'], 'tenant_contato', $fixture['tenant_id']);
        $pdo->prepare(
            'INSERT INTO acessos_suporte (tenant_id, usuario_id, token_hash, tipo, criado_em, expira_em, ip_usado) '
            . 'VALUES (:tenant, :user, :token, :type, :created, :expires, :ip)'
        )->execute([
            'tenant' => $fixture['tenant_id'], 'user' => $genericUserId, 'token' => hash('sha256', 'support-' . $suffix),
            'type' => 'suporte', 'created' => '2024-01-01 01:00:00', 'expires' => '2024-01-01 02:00:00',
            'ip' => '198.51.100.42',
        ]);
        $supportId = (int) $pdo->lastInsertId();
        privacySubjectLinkRecord($pdo, $genericSubject['id'], 'acesso_suporte', $supportId);
        $pdo->prepare(
            'INSERT INTO mcp_api_keys (tenant_id, usuario_id, nome, chave_hash, escopos_json, criado_em) '
            . 'VALUES (:tenant, :user, :name, :hash, :scopes, :created)'
        )->execute([
            'tenant' => $fixture['tenant_id'], 'user' => $genericUserId, 'name' => 'Sensitive MCP label ' . $suffix,
            'hash' => 'synthetic-mcp-hash-' . $suffix, 'scopes' => '[]', 'created' => '2024-01-01 01:00:00',
        ]);
        $mcpKeyId = (int) $pdo->lastInsertId();
        privacySubjectLinkRecord($pdo, $genericSubject['id'], 'mcp_api_key', $mcpKeyId);
        $pdo->prepare(
            "UPDATE privacy_subjects SET estado = 'inativo', inativado_em = :inactive WHERE id = :id"
        )->execute(['inactive' => '2025-11-22 10:00:00', 'id' => $genericSubject['id']]);
        $genericResult = privacySubjectLifecycleProcessDue($pdo, $genericNow, 10);
        $genericUserQuery = $pdo->prepare('SELECT nome, email, senha_hash, ativo FROM usuarios WHERE id = :id');
        $genericUserQuery->execute(['id' => $genericUserId]);
        $genericUser = $genericUserQuery->fetch(PDO::FETCH_ASSOC);
        $genericSubjectQuery = $pdo->prepare('SELECT estado, anonimizado_em FROM privacy_subjects WHERE id = :id');
        $genericSubjectQuery->execute(['id' => $genericSubject['id']]);
        $genericSubjectState = $genericSubjectQuery->fetch(PDO::FETCH_ASSOC);
        $genericContact = $pdo->query(
            'SELECT nome_responsavel, cargo_responsavel FROM tenants WHERE id = ' . $fixture['tenant_id']
        )->fetch(PDO::FETCH_ASSOC);
        $genericSupportQuery = $pdo->prepare('SELECT token_hash, ip_usado FROM acessos_suporte WHERE id = :id');
        $genericSupportQuery->execute(['id' => $supportId]);
        $genericSupport = $genericSupportQuery->fetch(PDO::FETCH_ASSOC);
        $genericMcpQuery = $pdo->prepare('SELECT nome, chave_hash, ativo FROM mcp_api_keys WHERE id = :id');
        $genericMcpQuery->execute(['id' => $mcpKeyId]);
        $genericMcp = $genericMcpQuery->fetch(PDO::FETCH_ASSOC);
        if (($genericResult['processed'] ?? null) !== 1 || ($genericResult['failed'] ?? null) !== 0
            || !is_array($genericUser) || $genericUser['nome'] !== 'Usuário anonimizado'
            || $genericUser['email'] !== 'anonimizado-' . $genericUserId . '@invalid.example'
            || $genericUser['senha_hash'] !== '!disabled-privacy-user-' . $genericUserId
            || (int) $genericUser['ativo'] !== 0 || !is_array($genericSubjectState)
            || $genericSubjectState['estado'] !== 'anonimizado'
            || $genericSubjectState['anonimizado_em'] !== gmdate('Y-m-d H:i:s', $genericNow)
            || !is_array($genericContact) || $genericContact['nome_responsavel'] !== null
            || $genericContact['cargo_responsavel'] !== null || !is_array($genericSupport)
            || str_contains((string) $genericSupport['token_hash'], $suffix) || $genericSupport['ip_usado'] !== null
            || !is_array($genericMcp) || $genericMcp['nome'] !== 'Chave revogada'
            || str_contains((string) $genericMcp['chave_hash'], $suffix) || (int) $genericMcp['ativo'] !== 0) {
            throw new RuntimeException($driver . ': generic inactive privacy subjects must erase account PII after twelve months: '
                . json_encode([$genericResult, $genericUser, $genericSubjectState, $genericContact, $genericSupport, $genericMcp]));
        }

        $requestUserEmail = 'privacy-request-' . $suffix . '@example.test';
        $pdo->prepare(
            'INSERT INTO usuarios (tenant_id, papel_sistema, nome, email, senha_hash, ativo, criado_em) '
            . "VALUES (:tenant, 'titular_cliente', :name, :email, :password, 1, :created)"
        )->execute([
            'tenant' => $fixture['tenant_id'], 'name' => 'Deletion request user ' . $suffix,
            'email' => $requestUserEmail, 'password' => password_hash('synthetic-request-secret', PASSWORD_DEFAULT),
            'created' => gmdate('Y-m-d H:i:s', $genericNow),
        ]);
        $requestUserId = (int) $pdo->lastInsertId();
        $requestSubject = privacySubjectCreate($pdo, 'pessoa', $fixture['tenant_id'], gmdate('Y-m-d H:i:s', $genericNow));
        privacySubjectLinkRecord($pdo, $requestSubject['id'], 'usuario', $requestUserId);
        $genericRequestId = bin2hex(random_bytes(16));
        $genericRequestAt = $genericNow + 100;
        $genericRequest = privacySubjectAnonymizationRequest(
            $pdo,
            $requestSubject['id'],
            $genericRequestId,
            $genericRequestAt
        );
        $genericRequestReplay = privacySubjectAnonymizationRequest(
            $pdo,
            $requestSubject['id'],
            $genericRequestId,
            $genericRequestAt + 10
        );
        $beforeGenericRequestDeadline = privacySubjectLifecycleProcessDue(
            $pdo,
            $genericRequestAt + 72 * 60 * 60 - 1,
            10
        );
        $requestUser = $pdo->prepare('SELECT nome, email FROM usuarios WHERE id = :id');
        $requestUser->execute(['id' => $requestUserId]);
        $requestUserBeforeDeadline = $requestUser->fetch(PDO::FETCH_ASSOC);
        $atGenericRequestDeadline = privacySubjectLifecycleProcessDue(
            $pdo,
            $genericRequestAt + 72 * 60 * 60,
            10
        );
        $requestUser->execute(['id' => $requestUserId]);
        $requestUserAfterDeadline = $requestUser->fetch(PDO::FETCH_ASSOC);
        $genericRequestRow = $pdo->prepare(
            'SELECT estado, privacy_subject_id, referencia_titular_cifrada FROM solicitacoes_anonimizacao WHERE solicitacao_id = :id'
        );
        $genericRequestRow->execute(['id' => $genericRequestId]);
        $genericRequestState = $genericRequestRow->fetch(PDO::FETCH_ASSOC);
        if (($genericRequest['estado'] ?? null) !== 'pendente'
            || ($genericRequestReplay['estado'] ?? null) !== 'pendente'
            || ($beforeGenericRequestDeadline['processed'] ?? null) !== 0
            || !is_array($requestUserBeforeDeadline) || $requestUserBeforeDeadline['email'] !== $requestUserEmail
            || ($atGenericRequestDeadline['processed'] ?? null) !== 1
            || !is_array($requestUserAfterDeadline) || $requestUserAfterDeadline['nome'] !== 'Usuário anonimizado'
            || $requestUserAfterDeadline['email'] !== 'anonimizado-' . $requestUserId . '@invalid.example'
            || !is_array($genericRequestState) || $genericRequestState['estado'] !== 'concluida'
            || $genericRequestState['privacy_subject_id'] !== null
            || $genericRequestState['referencia_titular_cifrada'] !== '') {
            throw new RuntimeException($driver . ': generic deletion requests must honor 72 hours and remove subject linkage on completion: '
                . json_encode([$genericRequest, $beforeGenericRequestDeadline, $atGenericRequestDeadline, $requestUserBeforeDeadline, $requestUserAfterDeadline, $genericRequestState]));
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
        $pdo->prepare('DELETE FROM solicitacoes_anonimizacao WHERE solicitacao_id = :id')->execute(['id' => $genericRequestId]);
        $pdo->prepare('DELETE FROM usuarios WHERE id = :id')->execute(['id' => $requestUserId]);
        $pdo->prepare('DELETE FROM privacy_subjects WHERE id = :id')->execute(['id' => $requestSubject['id']]);
        $pdo->prepare('DELETE FROM usuarios WHERE id = :id')->execute(['id' => $genericUserId]);
        $pdo->prepare('DELETE FROM privacy_subjects WHERE id = :id')->execute(['id' => $genericSubject['id']]);
        tenantTestCleanup($pdo, $fixture);
        fwrite(STDOUT, 'PASS: ' . $driver . " anonymization request stores only an encrypted reference and a 72-hour deadline.\n");
    }
} finally {
    putenv('IEPR_ANONYMIZATION_KEY=' . ($oldKey === false ? '' : $oldKey));
    putenv('IEPR_PRIVATE_STORAGE_PATH=' . ($oldStorage === false ? '' : $oldStorage));
    @rmdir($storagePath);
}
