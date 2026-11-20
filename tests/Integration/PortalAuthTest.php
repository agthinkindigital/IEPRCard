<?php
declare(strict_types=1);

require_once __DIR__ . '/TenantIsolationFixture.php';
require_once dirname(__DIR__, 2) . '/config/portal_auth.php';

foreach (tenantTestConnections() as $driver => $pdo) {
    $fixture = tenantTestSeed($pdo);
    $otherFixture = null;
    $holderId = null;
    $designId = null;
    try {
        $pdo->prepare(
            'INSERT INTO designs_carteirinha (tenant_id, nome_layout, orientacao, criado_em) '
            . 'VALUES (:tenant, :name, :orientation, :created)'
        )->execute([
            'tenant' => $fixture['tenant_id'],
            'name' => 'Portal auth design',
            'orientation' => 'horizontal',
            'created' => gmdate('Y-m-d H:i:s'),
        ]);
        $designId = (int) $pdo->lastInsertId();

        $accessToken = portalAuthCreateAccessToken();
        $token = $accessToken['token'];
        if (preg_match('/^[a-f0-9]{64}$/D', $token) !== 1 || hash('sha256', $token) !== $accessToken['token_hash']) {
            throw new RuntimeException($driver . ': wallet links must use a random bearer token with only its digest stored.');
        }
        $pdo->prepare(
            'INSERT INTO titulares '
            . '(tenant_id, subcliente_id, nome_completo, cpf, data_nascimento, token_unico_carteira, acesso_app_liberado, ativo, criado_em) '
            . 'VALUES (:tenant, :unit, :name, :cpf, :birth_date, :token_hash, 1, 1, :created)'
        )->execute([
            'tenant' => $fixture['tenant_id'],
            'unit' => $fixture['subcliente_id'],
            'name' => 'Portal Test Holder',
            'cpf' => '52998224725',
            'birth_date' => '1990-02-03',
            'token_hash' => $accessToken['token_hash'],
            'created' => gmdate('Y-m-d H:i:s'),
        ]);
        $holderId = (int) $pdo->lastInsertId();
        $pdo->prepare(
            'INSERT INTO carteirinhas_emitidas '
            . '(tenant_id, subcliente_id, titular_id, design_id, codigo_validacao_qr, status_carteirinha, data_validade, '
            . 'webp_frente_url, webp_verso_url, emitida_em) '
            . 'VALUES (:tenant, :unit, :holder, :design, :qr, :status, :validity, :front, :back, :created)'
        )->execute([
            'tenant' => $fixture['tenant_id'],
            'unit' => $fixture['subcliente_id'],
            'holder' => $holderId,
            'design' => $designId,
            'qr' => bin2hex(random_bytes(32)),
            'status' => 'ativa',
            'validity' => gmdate('Y-m-d', strtotime('+1 year')),
            'front' => 'tenant-artifacts/front.webp',
            'back' => 'tenant-artifacts/back.webp',
            'created' => gmdate('Y-m-d H:i:s'),
        ]);

        $context = new TenantContext('subcliente', $fixture['tenant_id'], $fixture['subcliente_id'], []);
        $cpfIdentity = portalAuthAuthenticate($pdo, $context, '529.982.247-25', '1990-02-03', '');
        if ($cpfIdentity !== [
            'id' => $holderId,
            'tenant_id' => $fixture['tenant_id'],
            'subcliente_id' => $fixture['subcliente_id'],
            'papel_sistema' => 'titular_final',
        ]) {
            throw new RuntimeException($driver . ': CPF and date of birth must authenticate only the enabled holder in the resolved unit.');
        }

        if (portalAuthAuthenticate($pdo, $context, '529.982.247-25', '1990-02-04', '') !== null) {
            throw new RuntimeException($driver . ': incorrect credentials must return the same generic denial.');
        }

        if (portalAuthAuthenticate($pdo, $context, '', '', $token) !== $cpfIdentity) {
            throw new RuntimeException($driver . ': the unique wallet token must authenticate without storing the raw token.');
        }
        $invalidToken = ($token[0] === '0' ? '1' : '0') . substr($token, 1);
        if (portalAuthAuthenticate($pdo, $context, '', '', $invalidToken) !== null) {
            throw new RuntimeException($driver . ': an unknown wallet token must receive the same generic denial as bad credentials.');
        }

        $limiterPath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'iepr-portal-limiter-' . bin2hex(random_bytes(6)) . '.json';
        for ($attempt = 0; $attempt < 60; $attempt++) {
            if (!portalAuthAllowAttempt('192.0.2.17', 1_800_000_000, $limiterPath)) {
                throw new RuntimeException($driver . ': portal rate limiting must allow the first 60 requests in the minute.');
            }
        }
        $limiterData = file_get_contents($limiterPath);
        if (portalAuthAllowAttempt('192.0.2.17', 1_800_000_000, $limiterPath)
            || !is_string($limiterData) || str_contains($limiterData, '192.0.2.17')
            || !str_contains($limiterData, hash('sha256', '192.0.2.17'))) {
            throw new RuntimeException($driver . ': the portal rate limit must stop request 61 and store only a hashed IP key.');
        }

        if (portalAuthCards($pdo, $context) !== []) {
            throw new RuntimeException($driver . ': knowledge of a holder identity must not reveal cards without an authenticated session.');
        }

        $ratePath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'iepr-portal-rate-' . bin2hex(random_bytes(6)) . '.json';
        if (!portalAuthStartSession(true)) {
            throw new RuntimeException($driver . ': the portal must start its isolated anonymous session before issuing a CSRF token.');
        }
        $loginCsrf = portalAuthCsrfToken($_SESSION);
        if (portalAuthLogin($pdo, $context, '529.982.247-25', '1990-02-03', '', str_repeat('0', 64), '127.0.0.1', time(), $ratePath) !== null) {
            throw new RuntimeException($driver . ': login must reject a missing or invalid session-bound CSRF token.');
        }
        $previousSessionId = session_id();
        $loggedIn = portalAuthLogin($pdo, $context, '529.982.247-25', '1990-02-03', '', $loginCsrf, '127.0.0.1', time(), $ratePath);
        if ($loggedIn !== $cpfIdentity || session_name() !== 'IEPRCARDPORTAL' || session_id() === $previousSessionId
            || portalAuthCurrentHolder($pdo, $context) !== $cpfIdentity
            || !portalAuthVerifyCsrf($_SESSION, portalAuthCsrfToken($_SESSION))
            || portalAuthVerifyCsrf($_SESSION, str_repeat('0', 64))) {
            throw new RuntimeException($driver . ': successful login must rotate into a protected holder session with a valid CSRF token.');
        }
        $cards = portalAuthCards($pdo, $context);
        if (count($cards) !== 1 || $cards[0]['tenant_id'] !== $fixture['tenant_id']
            || $cards[0]['subcliente_id'] !== $fixture['subcliente_id']
            || $cards[0]['nome_completo'] !== 'Portal Test Holder'
            || $cards[0]['webp_frente_url'] !== 'tenant-artifacts/front.webp'
            || array_key_exists('cpf', $cards[0]) || array_key_exists('codigo_validacao_qr', $cards[0])) {
            throw new RuntimeException($driver . ': the private card query must derive identity from the session and exclude CPF and QR secret.');
        }
        if (portalAuthLogin($pdo, $context, '529.982.247-25', '1990-02-03', '', portalAuthCsrfToken($_SESSION), '127.0.0.1', time(), $ratePath) !== null
            || portalAuthCurrentHolder($pdo, $context) !== $cpfIdentity) {
            throw new RuntimeException($driver . ': a live holder session must not be replaced by another login attempt.');
        }
        $_SESSION['last_activity_at'] = time() - 1801;
        if (portalAuthLogin($pdo, $context, '529.982.247-25', '1990-02-03', '', portalAuthCsrfToken($_SESSION), '127.0.0.1', time(), $ratePath) !== $cpfIdentity) {
            throw new RuntimeException($driver . ': an expired holder session must be reset and permit a fresh login.');
        }
        $pdo->prepare('UPDATE titulares SET acesso_app_liberado = 0 WHERE id = :id')->execute(['id' => $holderId]);
        if (portalAuthCurrentHolder($pdo, $context) !== null) {
            throw new RuntimeException($driver . ': disabling portal access must invalidate the active holder session on the next request.');
        }
        portalAuthLogout();
        $pdo->prepare('UPDATE titulares SET acesso_app_liberado = 1 WHERE id = :id')->execute(['id' => $holderId]);
        if (session_status() !== PHP_SESSION_NONE) {
            throw new RuntimeException($driver . ': logout must destroy the titular session.');
        }
        @unlink($ratePath);
        @unlink($limiterPath);

        $otherFixture = tenantTestSeed($pdo);
        $otherContext = new TenantContext('cliente', $otherFixture['tenant_id'], null, []);
        if (portalAuthAuthenticate($pdo, $otherContext, '529.982.247-25', '1990-02-03', '') !== null) {
            throw new RuntimeException($driver . ': a different tenant context must not authenticate this holder.');
        }

        $pdo->prepare('UPDATE titulares SET acesso_app_liberado = 0 WHERE id = :id')->execute(['id' => $holderId]);
        if (portalAuthAuthenticate($pdo, $context, '529.982.247-25', '1990-02-03', '') !== null
            || portalAuthAuthenticate($pdo, $context, '', '', $token) !== null) {
            throw new RuntimeException($driver . ': disabled online access must invalidate both credential methods.');
        }
        $pdo->prepare('UPDATE titulares SET acesso_app_liberado = 1 WHERE id = :id')->execute(['id' => $holderId]);
        $pdo->prepare("UPDATE carteirinhas_emitidas SET data_validade = '2000-01-01' WHERE titular_id = :holder")
            ->execute(['holder' => $holderId]);
        if (portalAuthAuthenticate($pdo, $context, '529.982.247-25', '1990-02-03', '') !== null
            || portalAuthAuthenticate($pdo, $context, '', '', $token) !== null) {
            throw new RuntimeException($driver . ': an expired card must not establish portal access.');
        }

        fwrite(STDOUT, 'PASS: private titular portal authentication contract (' . $driver . ").\n");
    } finally {
        if ($holderId !== null) {
            $pdo->prepare('DELETE FROM carteirinhas_emitidas WHERE titular_id = :holder')->execute(['holder' => $holderId]);
            $pdo->prepare('DELETE FROM titulares WHERE id = :holder')->execute(['holder' => $holderId]);
        }
        if ($designId !== null) {
            $pdo->prepare('DELETE FROM designs_carteirinha WHERE id = :design')->execute(['design' => $designId]);
        }
        if ($otherFixture !== null) {
            tenantTestCleanup($pdo, $otherFixture);
        }
        tenantTestCleanup($pdo, $fixture);
    }
}

return 'pass';
