<?php
declare(strict_types=1);

require_once __DIR__ . '/motor_permissoes.php';
require_once __DIR__ . '/tenant_resolver.php';
require_once __DIR__ . '/batch_import_parser.php';
require_once __DIR__ . '/batch_import_staging.php';

/** @param array<string, mixed> $identity @param array<string, mixed> $filters @return array{items:list<array{id:int,nome:string,cpf_mascarado:?string,matricula:?string,unidade:string,situacao:string,inativado_em:?string,origem:string,pode_editar:bool}>,total:int,pagina:int,por_pagina:int} */
function titularListar(PDO $pdo, TenantContext $context, array $identity, array $filters = []): array
{
    $identityUnitId = titularReadNullablePositiveId($identity['subcliente_id'] ?? null);
    if (!pode($pdo, $context, $identity, 'titular.ver', $identityUnitId)) {
        throw new DomainException('TITULAR_READ_DENIED');
    }

    $page = titularReadPositiveFilterInt($filters['pagina'] ?? 1, 1, 1_000_000);
    $perPage = min(100, titularReadPositiveFilterInt($filters['por_pagina'] ?? 25, 25, 100_000));
    $search = $filters['busca'] ?? '';
    if (!is_string($search) || strlen($search) > 180) {
        throw new DomainException('TITULAR_READ_FILTER_INVALID');
    }
    $search = trim($search);
    $status = $filters['situacao'] ?? null;
    if ($status !== null && !in_array($status, ['ativo', 'inativo'], true)) {
        throw new DomainException('TITULAR_READ_FILTER_INVALID');
    }

    $unitId = null;
    if (array_key_exists('subcliente_id', $filters) && $filters['subcliente_id'] !== null && $filters['subcliente_id'] !== '') {
        $unitId = titularReadPositiveFilterInt($filters['subcliente_id'], 0, PHP_INT_MAX);
    }
    if ($identityUnitId !== null && $unitId !== null && $unitId !== $identityUnitId) {
        throw new DomainException('TITULAR_READ_DENIED');
    }
    if ($unitId !== null && !pode($pdo, $context, $identity, 'titular.ver', $unitId)) {
        throw new DomainException('TITULAR_READ_DENIED');
    }

    $scope = tenantScopeBuildPredicate($identity, null, 't');
    $conditions = [$scope['sql']];
    $params = $scope['params'];
    if ($unitId !== null) {
        $conditions[] = 't.subcliente_id = :filter_subcliente_id';
        $params['filter_subcliente_id'] = $unitId;
    }
    if ($status !== null) {
        $conditions[] = 't.ativo = :filter_ativo';
        $params['filter_ativo'] = $status === 'ativo' ? 1 : 0;
    }
    if ($search !== '') {
        $escapedSearch = strtr($search, ['!' => '!!', '%' => '!%', '_' => '!_']);
        $conditions[] = "(t.nome_completo LIKE :filter_nome ESCAPE '!' OR t.matricula LIKE :filter_matricula ESCAPE '!')";
        $params['filter_nome'] = '%' . $escapedSearch . '%';
        $params['filter_matricula'] = '%' . $escapedSearch . '%';
    }
    $where = implode(' AND ', $conditions);

    $count = $pdo->prepare('SELECT COUNT(*) FROM titulares t WHERE ' . $where);
    $count->execute($params);
    $total = (int) $count->fetchColumn();

    $offset = ($page - 1) * $perPage;
    $query = $pdo->prepare(
        'SELECT t.id, t.nome_completo, t.cpf, t.matricula, t.ativo, t.inativado_em, t.subcliente_id, '
        . "COALESCE(unidade.nome_organizacao, 'Sede') AS nome_unidade, "
        . "CASE WHEN t.lote_origem_id IS NULL THEN 'manual' ELSE COALESCE(origem.origem_tipo, 'importacao') END AS origem "
        . 'FROM titulares t '
        . 'LEFT JOIN tenants unidade ON unidade.id = t.subcliente_id AND unidade.parent_id = t.tenant_id '
        . 'LEFT JOIN lotes_importacao origem ON origem.id = t.lote_origem_id AND origem.tenant_id = t.tenant_id '
        . 'WHERE ' . $where . ' ORDER BY t.id DESC LIMIT ' . $perPage . ' OFFSET ' . $offset
    );
    $query->execute($params);
    $items = [];
    foreach ($query->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $items[] = [
            'id' => (int) $row['id'],
            'nome' => (string) $row['nome_completo'],
            'cpf_mascarado' => titularReadMaskCpf($row['cpf']),
            'matricula' => is_string($row['matricula']) ? $row['matricula'] : null,
            'unidade' => (string) $row['nome_unidade'],
            'situacao' => (int) $row['ativo'] === 1 ? 'ativo' : 'inativo',
            'inativado_em' => (int) $row['ativo'] === 0 && is_string($row['inativado_em']) ? $row['inativado_em'] : null,
            'origem' => (string) $row['origem'],
            'pode_editar' => pode(
                $pdo,
                $context,
                $identity,
                'titular.editar',
                $row['subcliente_id'] === null ? null : (int) $row['subcliente_id']
            ),
        ];
    }

    return ['items' => $items, 'total' => $total, 'pagina' => $page, 'por_pagina' => $perPage];
}

/** @param array<string,mixed> $identity @return array{id:int,nome:string,matricula:?string,situacao:string,inativado_em:?string,foto_disponivel:bool,anonimizado:bool,pode_editar:bool}|null */
function titularDetalhar(PDO $pdo, TenantContext $context, array $identity, int $titularId): ?array
{
    if ($titularId < 1) return null;
    $scope = tenantScopeBuildPredicate($identity, null, 't');
    $query = $pdo->prepare(
        'SELECT t.id, t.nome_completo, t.matricula, t.ativo, t.inativado_em, t.foto_url, t.anonimizado_em, t.subcliente_id '
        . 'FROM titulares t WHERE t.id = :id AND ' . $scope['sql'] . ' LIMIT 1'
    );
    $query->execute(['id' => $titularId] + $scope['params']);
    $row = $query->fetch(PDO::FETCH_ASSOC);
    if (!is_array($row) || !pode(
        $pdo,
        $context,
        $identity,
        'titular.ver',
        $row['subcliente_id'] === null ? null : (int) $row['subcliente_id']
    )) return null;
    return [
        'id' => (int) $row['id'],
        'nome' => (string) $row['nome_completo'],
        'matricula' => is_string($row['matricula']) ? $row['matricula'] : null,
        'situacao' => (int) $row['ativo'] === 1 ? 'ativo' : 'inativo',
        'inativado_em' => (int) $row['ativo'] === 0 && is_string($row['inativado_em']) ? $row['inativado_em'] : null,
        'foto_disponivel' => is_string($row['foto_url']) && $row['foto_url'] !== '',
        'anonimizado' => $row['anonimizado_em'] !== null,
        'pode_editar' => $row['anonimizado_em'] === null && pode(
            $pdo,
            $context,
            $identity,
            'titular.editar',
            $row['subcliente_id'] === null ? null : (int) $row['subcliente_id']
        ),
    ];
}

/** @param array<string, mixed> $identity @return array{lote_id:int,status:string,colunas:list<string>,amostra:list<array<string,string|null>>,erros:list<array{linha?:int,campo?:string,codigo:string}>,total_registros:int} */
function batchImportStagingLer(PDO $pdo, TenantContext $context, array $identity, int $lotId, ?DateTimeImmutable $now = null): array
{
    $identityUnitId = titularReadNullablePositiveId($identity['subcliente_id'] ?? null);
    if ($lotId < 1 || !pode($pdo, $context, $identity, 'titular.importar', $identityUnitId)) {
        throw new DomainException('TITULAR_IMPORT_READ_DENIED');
    }
    $scope = tenantScopeBuildPredicate($identity, null, 'li');
    $query = $pdo->prepare(
        'SELECT li.id, li.tenant_id, li.subcliente_id, li.arquivo_original_url, li.colunas_detectadas_json, '
        . 'li.total_registros, li.status_lote, li.criado_em FROM lotes_importacao li '
        . 'WHERE li.id = :id AND ' . $scope['sql'] . ' LIMIT 1'
    );
    $query->execute(['id' => $lotId] + $scope['params']);
    $lot = $query->fetch(PDO::FETCH_ASSOC);
    if (!is_array($lot)) {
        throw new DomainException('IMPORT_LOT_NOT_FOUND');
    }

    $unitId = titularReadNullablePositiveId($lot['subcliente_id'] ?? null);
    if ($unitId !== null && !pode($pdo, $context, $identity, 'titular.importar', $unitId)) {
        throw new DomainException('IMPORT_LOT_NOT_FOUND');
    }
    $status = (string) $lot['status_lote'];
    $base = [
        'lote_id' => (int) $lot['id'],
        'status' => $status,
        'colunas' => titularReadDecodeColumns($lot['colunas_detectadas_json'] ?? null),
        'amostra' => [],
        'erros' => [],
        'total_registros' => (int) $lot['total_registros'],
    ];
    if ($status !== 'aguardando_mapa') {
        return $base;
    }

    $createdAt = is_string($lot['criado_em'] ?? null) ? trim($lot['criado_em']) : '';
    if ($createdAt === '') {
        $base['status'] = 'expirado';
        return $base;
    }
    try {
        $created = new DateTimeImmutable($createdAt, new DateTimeZone('UTC'));
        $now ??= new DateTimeImmutable('now', new DateTimeZone('UTC'));
        if ($created->modify('+24 hours') <= $now) {
            $base['status'] = 'expirado';
            return $base;
        }
    } catch (Throwable) {
        $base['status'] = 'expirado';
        return $base;
    }

    try {
        $stagedRows = batchImportStagingReadRows($pdo, (int) $lot['tenant_id'], (int) $lot['id']);
        if ($stagedRows === [] || count($stagedRows) !== (int) $lot['total_registros']) {
            $base['status'] = 'arquivo_indisponivel';
            $base['erros'] = [['codigo' => 'IMPORT_STAGING_UNAVAILABLE']];
            return $base;
        }
        $base['amostra'] = array_slice(array_map(static fn(array $staged): array => $staged['row'], $stagedRows), 0, 10);
        $base['erros'] = [];
    } catch (Throwable) {
        $base['status'] = 'arquivo_indisponivel';
        $base['erros'] = [['codigo' => 'IMPORT_SOURCE_UNAVAILABLE']];
    }

    return $base;
}

function titularReadPositiveFilterInt(mixed $value, int $default, int $max): int
{
    if ($value === null || $value === '') {
        return $default;
    }
    $parsed = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => $max]]);
    if ($parsed === false) {
        throw new DomainException('TITULAR_READ_FILTER_INVALID');
    }

    return (int) $parsed;
}

function titularReadNullablePositiveId(mixed $value): ?int
{
    if ($value === null || $value === '') {
        return null;
    }
    $parsed = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    if ($parsed === false) {
        throw new DomainException('TENANT_SCOPE_INVALIDO');
    }

    return (int) $parsed;
}

function titularReadMaskCpf(mixed $cpf): ?string
{
    if (!is_string($cpf) || preg_match('/^[0-9]{11}$/D', $cpf) !== 1) {
        return null;
    }

    return '***.***.***-' . substr($cpf, -2);
}

/** @return list<string> */
function titularReadDecodeColumns(mixed $json): array
{
    if (!is_string($json)) {
        return [];
    }
    $decoded = json_decode($json, true);
    if (!is_array($decoded)) {
        return [];
    }

    return array_values(array_filter($decoded, static fn (mixed $column): bool => is_string($column)));
}
