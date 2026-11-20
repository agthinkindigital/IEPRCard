<?php
declare(strict_types=1);

require_once __DIR__ . '/tenant_auth.php';

/**
 * @return array{id:int,tenant_id:int,subcliente_id:?int,papel_sistema:string}|null
 */
function portalAuthAuthenticate(
    PDO $pdo,
    TenantContext $context,
    string $cpf,
    string $dateOfBirth,
    string $token,
    ?string $today = null
): ?array {
    if (!portalAuthContextIsSupported($pdo, $context)) {
        return null;
    }

    $holder = null;
    if ($token !== '') {
        if ($cpf !== '' || $dateOfBirth !== '' || preg_match('/^[a-f0-9]{64}$/D', $token) !== 1) {
            return null;
        }
        $statement = $pdo->prepare(
            'SELECT id, tenant_id, subcliente_id FROM titulares WHERE token_unico_carteira = :token_hash LIMIT 2'
        );
        $statement->execute(['token_hash' => hash('sha256', $token)]);
        $matches = $statement->fetchAll(PDO::FETCH_ASSOC);
        $holder = count($matches) === 1 ? $matches[0] : null;
    } else {
        $normalizedCpf = portalAuthNormalizeCpf($cpf);
        $normalizedDate = portalAuthNormalizeDate($dateOfBirth);
        $statement = $pdo->prepare(
            'SELECT id, tenant_id, subcliente_id, data_nascimento FROM titulares '
            . 'WHERE tenant_id = :tenant_id AND cpf = :cpf ORDER BY id LIMIT 2'
        );
        $statement->execute(['tenant_id' => $context->tenantId, 'cpf' => $normalizedCpf ?? '00000000000']);
        $matches = $statement->fetchAll(PDO::FETCH_ASSOC);
        $candidate = count($matches) === 1 ? $matches[0] : null;
        $storedDate = is_array($candidate) && is_string($candidate['data_nascimento'])
            ? $candidate['data_nascimento']
            : '1970-01-01';
        $providedDate = $normalizedDate ?? '0000-00-00';
        $dateMatches = hash_equals($storedDate, $providedDate);
        if ($normalizedCpf !== null && $normalizedDate !== null && is_array($candidate) && $dateMatches) {
            $holder = $candidate;
        }
    }

    $eligible = portalAuthHolderCanAccess($pdo, (int) ($holder['id'] ?? 0), $today ?? gmdate('Y-m-d'));
    if (!is_array($holder) || !portalAuthHolderMatchesContext($holder, $context) || !$eligible) {
        return null;
    }

    return portalAuthIdentityFromHolder($holder);
}

/** @return array{token:string,token_hash:string} */
function portalAuthCreateAccessToken(): array
{
    $token = bin2hex(random_bytes(32));
    return ['token' => $token, 'token_hash' => hash('sha256', $token)];
}

/**
 * @return list<array{id:int,tenant_id:int,subcliente_id:?int,nome_completo:string,cargo_funcao:?string,organizacao:string,unidade:?string,status_carteirinha:string,data_validade:?string,webp_frente_url:?string,webp_verso_url:?string}>
 */
function portalAuthCards(PDO $pdo, TenantContext $context, ?string $today = null): array
{
    $identity = portalAuthCurrentHolder($pdo, $context);
    if ($identity === null) {
        return [];
    }

    $holderId = filter_var($identity['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    $tenantId = filter_var($identity['tenant_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    $rawUnitId = $identity['subcliente_id'] ?? null;
    $unitId = $rawUnitId === null ? null : filter_var($rawUnitId, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    if ($holderId === false || $tenantId === false || ($rawUnitId !== null && $unitId === false)
        || ($identity['papel_sistema'] ?? null) !== 'titular_final') {
        return [];
    }

    $parameters = [
        'holder' => (int) $holderId,
        'tenant' => (int) $tenantId,
        'today' => $today ?? gmdate('Y-m-d'),
    ];
    if ($unitId !== null) {
        $parameters['card_unit'] = (int) $unitId;
        $parameters['holder_unit'] = (int) $unitId;
        $unitJoinPredicate = 'AND unit.id = :unit_join';
        $parameters['unit_join'] = (int) $unitId;
        $unitScopePredicate = 'c.subcliente_id = :card_unit AND t.subcliente_id = :holder_unit';
    } else {
        $unitJoinPredicate = '';
        $unitScopePredicate = 'c.subcliente_id IS NULL AND t.subcliente_id IS NULL';
    }

    $statement = $pdo->prepare(
        'SELECT c.id, c.tenant_id, c.subcliente_id, t.nome_completo, t.cargo_funcao, '
        . 'root.nome_organizacao AS organizacao, unit.nome_organizacao AS unidade, '
        . 'c.status_carteirinha, c.data_validade, c.webp_frente_url, c.webp_verso_url '
        . 'FROM carteirinhas_emitidas c '
        . 'INNER JOIN titulares t ON t.id = c.titular_id AND t.tenant_id = c.tenant_id '
        . 'INNER JOIN tenants root ON root.id = c.tenant_id AND root.parent_id IS NULL '
        . "AND root.tipo = 'cliente' AND root.status_conta = 'ativo' "
        . 'LEFT JOIN tenants unit ON unit.id = c.subcliente_id ' . $unitJoinPredicate . ' AND unit.parent_id = c.tenant_id '
        . "AND unit.tipo = 'subcliente' AND unit.status_conta = 'ativo' "
        . 'WHERE c.titular_id = :holder AND c.tenant_id = :tenant '
        . 'AND ' . $unitScopePredicate . ' '
        . 'AND t.ativo = 1 AND t.acesso_app_liberado = 1 AND c.status_carteirinha = :active '
        . 'AND (c.data_validade IS NULL OR c.data_validade >= :today) '
        . 'AND (c.subcliente_id IS NULL OR unit.id IS NOT NULL) '
        . 'ORDER BY c.emitida_em DESC, c.id DESC'
    );
    $parameters['active'] = 'ativa';
    $statement->execute($parameters);

    return array_map(static function (array $card): array {
        return [
            'id' => (int) $card['id'],
            'tenant_id' => (int) $card['tenant_id'],
            'subcliente_id' => $card['subcliente_id'] === null ? null : (int) $card['subcliente_id'],
            'nome_completo' => (string) $card['nome_completo'],
            'cargo_funcao' => $card['cargo_funcao'] === null ? null : (string) $card['cargo_funcao'],
            'organizacao' => (string) $card['organizacao'],
            'unidade' => $card['unidade'] === null ? null : (string) $card['unidade'],
            'status_carteirinha' => (string) $card['status_carteirinha'],
            'data_validade' => $card['data_validade'] === null ? null : (string) $card['data_validade'],
            'webp_frente_url' => $card['webp_frente_url'] === null ? null : (string) $card['webp_frente_url'],
            'webp_verso_url' => $card['webp_verso_url'] === null ? null : (string) $card['webp_verso_url'],
        ];
    }, $statement->fetchAll(PDO::FETCH_ASSOC));
}

function portalAuthStartSession(?bool $https = null): bool
{
    return authSessionStart('IEPRCARDPORTAL', $https);
}

/** @param array<string,mixed> $session */
function portalAuthCsrfToken(array &$session): string
{
    return authSessionCsrfToken($session);
}

/** @param array<string,mixed> $session */
function portalAuthVerifyCsrf(array $session, mixed $submittedToken): bool
{
    return authSessionVerifyCsrf($session, $submittedToken);
}

function portalAuthLogin(
    PDO $pdo,
    TenantContext $context,
    string $cpf,
    string $dateOfBirth,
    string $token,
    mixed $submittedCsrfToken,
    string $clientIp,
    ?int $timestamp = null,
    ?string $rateLimitPath = null
): ?array {
    if (!portalAuthStartSession() || !portalAuthAllowAttempt($clientIp, $timestamp, $rateLimitPath)) {
        return null;
    }
    if (!portalAuthVerifyCsrf($_SESSION, $submittedCsrfToken)) {
        return null;
    }
    if (isset($_SESSION['portal_holder_id'])) {
        if (portalAuthCurrentHolder($pdo, $context) !== null) {
            return null;
        }
    }

    $identity = portalAuthAuthenticate($pdo, $context, $cpf, $dateOfBirth, $token);
    if ($identity === null) {
        return null;
    }

    $_SESSION = [];
    if (!session_regenerate_id(true)) {
        portalAuthLogout();
        return null;
    }
    $_SESSION['portal_holder_id'] = $identity['id'];
    $_SESSION['portal_tenant_id'] = $identity['tenant_id'];
    $_SESSION['portal_subcliente_id'] = $identity['subcliente_id'];
    $_SESSION['portal_authenticated_at'] = time();
    $_SESSION['last_activity_at'] = time();
    portalAuthCsrfToken($_SESSION);

    return $identity;
}

/** @return array{id:int,tenant_id:int,subcliente_id:?int,papel_sistema:string}|null */
function portalAuthCurrentHolder(PDO $pdo, TenantContext $context): ?array
{
    if (session_status() !== PHP_SESSION_ACTIVE || session_name() !== 'IEPRCARDPORTAL'
        || !array_key_exists('portal_holder_id', $_SESSION)) {
        return null;
    }

    $holderId = filter_var($_SESSION['portal_holder_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    $tenantId = filter_var($_SESSION['portal_tenant_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    $rawUnitId = $_SESSION['portal_subcliente_id'] ?? null;
    $unitId = $rawUnitId === null ? null : filter_var($rawUnitId, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    $lastActivity = filter_var($_SESSION['last_activity_at'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    if ($holderId === false || $tenantId === false || ($rawUnitId !== null && $unitId === false)
        || $lastActivity === false || time() - $lastActivity > 1800) {
        portalAuthResetExpiredSession();
        return null;
    }

    $statement = $pdo->prepare(
        'SELECT id, tenant_id, subcliente_id FROM titulares WHERE id = :id AND ativo = 1 AND acesso_app_liberado = 1 LIMIT 1'
    );
    $statement->execute(['id' => (int) $holderId]);
    $row = $statement->fetch(PDO::FETCH_ASSOC);
    $identity = is_array($row) ? portalAuthIdentityFromHolder($row) : null;
    if ($identity === null || $identity['tenant_id'] !== (int) $tenantId
        || $identity['subcliente_id'] !== ($unitId === false ? null : $unitId)
        || !portalAuthContextIsSupported($pdo, $context)
        || !portalAuthHolderMatchesContext($row, $context)
        || !portalAuthHolderCanAccess($pdo, $identity['id'], gmdate('Y-m-d'))) {
        portalAuthResetExpiredSession();
        return null;
    }

    $_SESSION['last_activity_at'] = time();
    return $identity;
}

function portalAuthLogout(): void
{
    authSessionLogout('IEPRCARDPORTAL');
}

function portalAuthResetExpiredSession(): void
{
    authSessionResetExpired('IEPRCARDPORTAL');
}

function portalAuthAllowAttempt(string $clientIp, ?int $timestamp = null, ?string $storagePath = null): bool
{
    $storagePath ??= (string) (getenv('IEPRCARD_PORTAL_RATE_LIMIT_PATH')
        ?: dirname(__DIR__) . '/var/portal-login-rate-limit.json');
    return authSessionAllowAttempt($clientIp, $timestamp, $storagePath);
}

function portalAuthContextIsSupported(PDO $pdo, TenantContext $context): bool
{
    return in_array($context->tipo, ['cliente', 'subcliente'], true)
        && $context->tenantId !== null && tenantAuthContextIsActive($pdo, $context);
}

/** @param array<string,mixed> $holder */
function portalAuthHolderMatchesContext(array $holder, TenantContext $context): bool
{
    if ((int) ($holder['tenant_id'] ?? 0) !== $context->tenantId) {
        return false;
    }
    if ($context->tipo === 'cliente') {
        return true;
    }
    return $context->tipo === 'subcliente'
        && $context->subclienteId !== null
        && (int) ($holder['subcliente_id'] ?? 0) === $context->subclienteId;
}

function portalAuthHolderCanAccess(PDO $pdo, int $holderId, string $today): bool
{
    $statement = $pdo->prepare(
        'SELECT 1 FROM titulares t '
        . 'INNER JOIN tenants root ON root.id = t.tenant_id AND root.parent_id IS NULL '
        . "AND root.tipo = 'cliente' AND root.status_conta = 'ativo' "
        . "LEFT JOIN tenants unit ON unit.id = t.subcliente_id AND unit.parent_id = t.tenant_id AND unit.tipo = 'subcliente' AND unit.status_conta = 'ativo' "
        . 'WHERE t.id = :holder AND t.ativo = 1 AND t.acesso_app_liberado = 1 '
        . 'AND (t.subcliente_id IS NULL OR unit.id IS NOT NULL) '
        . 'AND EXISTS (SELECT 1 FROM carteirinhas_emitidas c '
        . 'INNER JOIN titulares owner ON owner.id = c.titular_id AND owner.tenant_id = c.tenant_id '
        . 'WHERE c.titular_id = t.id AND c.tenant_id = t.tenant_id '
        . 'AND ((c.subcliente_id IS NULL AND t.subcliente_id IS NULL) OR c.subcliente_id = t.subcliente_id) '
        . "AND c.status_carteirinha = 'ativa' AND (c.data_validade IS NULL OR c.data_validade >= :today)) LIMIT 1"
    );
    $statement->execute(['holder' => $holderId, 'today' => $today]);
    return $statement->fetchColumn() !== false;
}

function portalAuthNormalizeCpf(string $cpf): ?string
{
    $normalized = preg_replace('/\D+/', '', $cpf);
    return is_string($normalized) && preg_match('/^\d{11}$/D', $normalized) === 1 ? $normalized : null;
}

function portalAuthNormalizeDate(string $date): ?string
{
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/D', $date) !== 1) {
        return null;
    }
    $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
    $errors = DateTimeImmutable::getLastErrors();
    return $parsed !== false && ($errors === false || ($errors['warning_count'] === 0 && $errors['error_count'] === 0))
        && $parsed->format('Y-m-d') === $date ? $date : null;
}

/** @param array<string,mixed> $row
 *  @return array{id:int,tenant_id:int,subcliente_id:?int,papel_sistema:string}|null
 */
function portalAuthIdentityFromHolder(array $row): ?array
{
    $id = filter_var($row['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    $tenantId = filter_var($row['tenant_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    $rawUnitId = $row['subcliente_id'] ?? null;
    $unitId = $rawUnitId === null ? null : filter_var($rawUnitId, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    if ($id === false || $tenantId === false || ($rawUnitId !== null && $unitId === false)) {
        return null;
    }

    return [
        'id' => (int) $id,
        'tenant_id' => (int) $tenantId,
        'subcliente_id' => $unitId === false ? null : $unitId,
        'papel_sistema' => 'titular_final',
    ];
}
