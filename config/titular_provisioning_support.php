<?php
declare(strict_types=1);

/** @param list<array<string, mixed>> $inputs @return list<array{nome_completo:string,cpf:?string,matricula:?string,cargo_funcao:?string,categoria_id:?int,whatsapp:?string,foto_url:?string}> */
function titularCreateBatchNormalize(array $inputs): array
{
    if (!array_is_list($inputs) || $inputs === [] || count($inputs) > 25) {
        throw new DomainException('TITULAR_CREATE_INVALID');
    }
    $normalized = [];
    $seenCpfs = [];
    foreach ($inputs as $input) {
        if (!is_array($input)) {
            throw new DomainException('TITULAR_CREATE_INVALID');
        }
        $record = titularCreateNormalize($input);
        if ($record['cpf'] !== null) {
            if (isset($seenCpfs[$record['cpf']])) {
                throw new DomainException('TITULAR_CREATE_CPF_CONFLICT');
            }
            $seenCpfs[$record['cpf']] = true;
        }
        $normalized[] = $record;
    }

    return $normalized;
}

function titularCreateBatchValidateScope(PDO $pdo, int $tenantId, ?int $subclienteId, int $quantity): void
{
    $root = $pdo->prepare(
        "SELECT plano_id, status_conta, limite_titulares FROM tenants "
        . "WHERE id = :id AND parent_id IS NULL AND tipo = 'cliente' LIMIT 1"
    );
    $root->execute(['id' => $tenantId]);
    $rootState = $root->fetch(PDO::FETCH_ASSOC);
    if (!is_array($rootState) || $rootState['status_conta'] !== 'ativo'
        || tenantQuotaNullableInteger($rootState['plano_id']) === null) {
        throw new DomainException('TITULAR_CREATE_ROOT_INACTIVE');
    }

    $plan = $pdo->prepare('SELECT ativo FROM planos WHERE id = :id LIMIT 1');
    $plan->execute(['id' => (int) $rootState['plano_id']]);
    if ((int) $plan->fetchColumn() !== 1) {
        throw new DomainException('TITULAR_CREATE_PLAN_UNAVAILABLE');
    }
    $feature = $pdo->prepare(
        'SELECT habilitado FROM plano_feature_flags WHERE plano_id = :plan AND feature_key = :key LIMIT 1'
    );
    $feature->execute(['plan' => (int) $rootState['plano_id'], 'key' => 'titular.criar']);
    if ((int) $feature->fetchColumn() !== 1) {
        throw new DomainException('TITULAR_CREATE_PLAN_UNAVAILABLE');
    }

    $childLimit = null;
    if ($subclienteId !== null) {
        $child = $pdo->prepare(
            "SELECT status_conta, limite_titulares FROM tenants "
            . "WHERE id = :id AND parent_id = :parent AND tipo = 'subcliente' LIMIT 1"
        );
        $child->execute(['id' => $subclienteId, 'parent' => $tenantId]);
        $childState = $child->fetch(PDO::FETCH_ASSOC);
        if (!is_array($childState) || $childState['status_conta'] !== 'ativo') {
            throw new DomainException('TITULAR_CREATE_TARGET_INVALID');
        }
        $childLimit = tenantQuotaNullableInteger($childState['limite_titulares']);
    }

    $rootLimit = tenantQuotaNullableInteger($rootState['limite_titulares']);
    if ($rootLimit === null) {
        throw new DomainException('TITULAR_CREATE_QUOTA_UNCONFIGURED');
    }
    if (titularCreateCount($pdo, $tenantId) + $quantity > $rootLimit
        || $subclienteId !== null && $childLimit !== null
            && titularCreateCount($pdo, $tenantId, $subclienteId) + $quantity > $childLimit) {
        throw new DomainException('TITULAR_CREATE_QUOTA_EXCEEDED');
    }
}

/** @param list<array{nome_completo:string,cpf:?string,matricula:?string,cargo_funcao:?string,categoria_id:?int,whatsapp:?string,foto_url:?string}> $records */
function titularCreateBatchValidateTargets(PDO $pdo, int $tenantId, array $records): void
{
    $category = $pdo->prepare(
        'SELECT id FROM categorias_titular WHERE id = :id AND tenant_id = :tenant AND ativo = 1 LIMIT 1'
    );
    $duplicate = $pdo->prepare('SELECT id FROM titulares WHERE tenant_id = :tenant AND cpf = :cpf LIMIT 1');
    foreach ($records as $record) {
        if ($record['foto_url'] !== null
            && preg_match('~^private://' . $tenantId . '/[a-f0-9]{48}\.jpg$~D', $record['foto_url']) !== 1) {
            throw new DomainException('TITULAR_CREATE_INVALID');
        }
        if ($record['categoria_id'] !== null) {
            $category->execute(['id' => $record['categoria_id'], 'tenant' => $tenantId]);
            if ($category->fetchColumn() === false) {
                throw new DomainException('TITULAR_CREATE_TARGET_INVALID');
            }
        }
        if ($record['cpf'] !== null) {
            $duplicate->execute(['tenant' => $tenantId, 'cpf' => $record['cpf']]);
            if ($duplicate->fetchColumn() !== false) {
                throw new DomainException('TITULAR_CREATE_CPF_CONFLICT');
            }
        }
    }
}

/** @param array<string, mixed> $identity @param array{nome_completo:string,cpf:?string,matricula:?string,cargo_funcao:?string,categoria_id:?int,whatsapp:?string,foto_url:?string} $record */
function titularCreateInsert(PDO $pdo, array $identity, int $tenantId, ?int $subclienteId, array $record): int
{
    $insert = $pdo->prepare(
        'INSERT INTO titulares (tenant_id, subcliente_id, categoria_id, nome_completo, cpf, cargo_funcao, '
        . 'matricula, whatsapp, foto_url, acesso_app_liberado, ativo, criado_em) '
        . 'VALUES (:tenant, :subclient, :category, :name, :cpf, :role, :registration, :whatsapp, :photo, 0, 1, :created_at)'
    );
    $insert->execute([
        'tenant' => $tenantId,
        'subclient' => $subclienteId,
        'category' => $record['categoria_id'],
        'name' => $record['nome_completo'],
        'cpf' => $record['cpf'],
        'role' => $record['cargo_funcao'],
        'registration' => $record['matricula'],
        'whatsapp' => $record['whatsapp'],
        'photo' => $record['foto_url'],
        'created_at' => gmdate('Y-m-d H:i:s'),
    ]);
    $titularId = (int) $pdo->lastInsertId();
    $audit = $pdo->prepare(
        'INSERT INTO auditoria_processos (tenant_id, subcliente_id, ator_usuario_id, ator_tipo, acao, entidade, '
        . 'entidade_id, antes_json, depois_json, criado_em) VALUES (:tenant, :subclient, :actor, :actor_type, '
        . ':action, :entity, :entity_id, NULL, :after_json, :created_at)'
    );
    $audit->execute([
        'tenant' => $tenantId,
        'subclient' => $subclienteId,
        'actor' => ($identity['id'] ?? null) === null ? null : (int) $identity['id'],
        'actor_type' => (string) $identity['papel_sistema'],
        'action' => 'titular.created',
        'entity' => 'titulares',
        'entity_id' => $titularId,
        'after_json' => json_encode([
            'categoria_id' => $record['categoria_id'],
            'subcliente_id' => $subclienteId,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
        'created_at' => gmdate('Y-m-d H:i:s'),
    ]);

    return $titularId;
}

/** @param array<string, mixed> $input @return array{nome_completo:string,cpf:?string,matricula:?string,cargo_funcao:?string,categoria_id:?int,whatsapp:?string,foto_url:?string} */
function titularCreateNormalize(array $input): array
{
    $allowedKeys = ['nome_completo', 'cpf', 'matricula', 'cargo_funcao', 'categoria_id', 'whatsapp', 'foto_url'];
    if (array_diff(array_keys($input), $allowedKeys) !== []) {
        throw new DomainException('TITULAR_CREATE_INVALID');
    }
    $name = titularCreateText($input['nome_completo'] ?? null, 180, false);
    $registration = titularCreateText($input['matricula'] ?? null, 80, true);
    $role = titularCreateText($input['cargo_funcao'] ?? null, 120, true);
    $cpf = titularCreateCpf($input['cpf'] ?? null);
    $whatsapp = titularCreatePhone($input['whatsapp'] ?? null);
    $categoryValue = $input['categoria_id'] ?? null;
    $categoryId = null;
    if ($categoryValue !== null && $categoryValue !== '') {
        if (!is_int($categoryValue) && (!is_string($categoryValue) || preg_match('/^[1-9][0-9]*$/D', $categoryValue) !== 1)) {
            throw new DomainException('TITULAR_CREATE_INVALID');
        }
        $categoryId = filter_var($categoryValue, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($categoryId === false) {
            throw new DomainException('TITULAR_CREATE_INVALID');
        }
    }

    return [
        'nome_completo' => $name,
        'cpf' => $cpf,
        'matricula' => $registration,
        'cargo_funcao' => $role,
        'categoria_id' => $categoryId,
        'whatsapp' => $whatsapp,
        'foto_url' => titularCreateText($input['foto_url'] ?? null, 500, true),
    ];
}

function titularCreatePhone(mixed $value): ?string
{
    if ($value === null || is_string($value) && trim($value) === '') {
        return null;
    }
    if (!is_string($value)) {
        throw new DomainException('TITULAR_CREATE_INVALID');
    }
    try {
        return phoneNormalizeBr($value);
    } catch (InvalidArgumentException) {
        throw new DomainException('TITULAR_CREATE_INVALID');
    }
}

function titularCreateText(mixed $value, int $maximumLength, bool $nullable): ?string
{
    if ($value === null && $nullable) {
        return null;
    }
    if (!is_string($value)) {
        throw new DomainException('TITULAR_CREATE_INVALID');
    }
    $normalized = trim($value);
    if ($normalized === '' && $nullable) {
        return null;
    }
    $length = preg_match_all('/./us', $normalized);
    if ($normalized === '' || $length === false || $length > $maximumLength) {
        throw new DomainException('TITULAR_CREATE_INVALID');
    }

    return $normalized;
}

function titularCreateCpf(mixed $value): ?string
{
    try {
        return cpfNormalizeBr($value);
    } catch (InvalidArgumentException) {
        throw new DomainException('TITULAR_CREATE_INVALID');
    }
}

function titularCreateCount(PDO $pdo, int $tenantId, ?int $subclienteId = null): int
{
    $sql = 'SELECT COUNT(*) FROM titulares WHERE tenant_id = :tenant AND ativo = 1';
    if ($subclienteId !== null) {
        $sql .= ' AND subcliente_id = :subclient';
    }
    $count = $pdo->prepare($sql);
    $count->bindValue(':tenant', $tenantId, PDO::PARAM_INT);
    if ($subclienteId !== null) {
        $count->bindValue(':subclient', $subclienteId, PDO::PARAM_INT);
    }
    $count->execute();

    return (int) $count->fetchColumn();
}

/** @param array<string, mixed> $identity */
function titularCreateCanManage(
    PDO $pdo,
    TenantContext $context,
    array $identity,
    int $tenantId,
    ?int $subclienteId
): bool {
    if (($identity['papel_sistema'] ?? null) === 'integracao_whatsapp') {
        require_once __DIR__ . '/whatsapp_gateway_webhook.php';
        return whatsappGatewayServicePrincipalCan($pdo, $context, $identity, $tenantId, $subclienteId, 'titular.criar');
    }
    if (($identity['papel_sistema'] ?? null) === 'superadmin') {
        return $context->tipo === 'admin' && $context->tenantId === null && $context->subclienteId === null
            && pode($pdo, $context, $identity, 'admin.clientes');
    }

    $tenantScopeMatches = ($context->tipo === 'cliente' && $context->tenantId === $tenantId && $context->subclienteId === null)
        || ($context->tipo === 'subcliente' && $context->tenantId === $tenantId
            && $context->subclienteId === $subclienteId);

    return $tenantScopeMatches && pode($pdo, $context, $identity, 'titular.criar', $subclienteId);
}
