<?php
declare(strict_types=1);

require_once __DIR__ . '/motor_permissoes.php';
require_once __DIR__ . '/titular_anonymization.php';
require_once __DIR__ . '/batch_import_staging_support.php';
require_once __DIR__ . '/batch_import_photo_archive.php';

/** @param array<string,mixed> $identity @return array{situacao:string,inativado_em:?string} */
function titularManagementSetStatus(
    PDO $pdo,
    TenantContext $context,
    array $identity,
    int $titularId,
    bool $active,
    ?int $now = null
): array {
    if ($titularId < 1 || $context->tenantId === null
        || (int) ($identity['tenant_id'] ?? 0) !== $context->tenantId) {
        throw new DomainException('TITULAR_STATUS_DENIED');
    }

    $timestamp = gmdate('Y-m-d H:i:s', $now ?? time());
    $driver = strtolower((string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME));
    $lock = $driver === 'mysql' ? ' FOR UPDATE' : '';
    $pdo->beginTransaction();
    try {
        $scope = tenantScopeBuildPredicate($identity, null, 't');
        $initialQuery = $pdo->prepare(
            'SELECT t.id, t.tenant_id, t.subcliente_id, t.ativo, t.acesso_app_liberado, '
            . 't.inativado_em, t.anonimizado_em FROM titulares t WHERE t.id = :id AND ' . $scope['sql'] . ' LIMIT 1'
        );
        $initialQuery->execute(['id' => $titularId] + $scope['params']);
        $initial = $initialQuery->fetch(PDO::FETCH_ASSOC);
        if (!is_array($initial) || (int) $initial['tenant_id'] !== $context->tenantId
            || !pode($pdo, $context, $identity, 'titular.editar', $initial['subcliente_id'] === null
                ? null : (int) $initial['subcliente_id'])) {
            throw new DomainException('TITULAR_STATUS_DENIED');
        }
        if ($initial['anonimizado_em'] !== null) {
            throw new DomainException('TITULAR_STATUS_ANONYMIZED');
        }

        if ($active && (int) $initial['ativo'] === 0 && is_string($initial['inativado_em'])) {
            $key = titularAnonymizationEncryptionKey();
            $retentionRequestId = hash_hmac(
                'sha256',
                'retention:' . $context->tenantId . ':' . $titularId . ':' . $initial['inativado_em'],
                $key
            );
            $cancel = $pdo->prepare(
                "UPDATE solicitacoes_anonimizacao SET estado = 'cancelada', referencia_titular_cifrada = '', "
                . 'concluido_em = :cancelled, erro_codigo = NULL '
                . "WHERE solicitacao_id = :request_id AND estado IN ('pendente', 'falha')"
            );
            $cancel->execute(['cancelled' => $timestamp, 'request_id' => $retentionRequestId]);
        }

        $holderQuery = $pdo->prepare(
            'SELECT id, tenant_id, subcliente_id, ativo, acesso_app_liberado, inativado_em, anonimizado_em '
            . 'FROM titulares t WHERE t.id = :id AND ' . $scope['sql'] . ' LIMIT 1' . $lock
        );
        $holderQuery->execute(['id' => $titularId] + $scope['params']);
        $holder = $holderQuery->fetch(PDO::FETCH_ASSOC);
        if (!is_array($holder) || $holder['ativo'] !== $initial['ativo']
            || $holder['inativado_em'] !== $initial['inativado_em']) {
            throw new DomainException('TITULAR_STATUS_CONFLICT');
        }
        if ($holder['anonimizado_em'] !== null) {
            throw new DomainException('TITULAR_STATUS_ANONYMIZED');
        }
        $wasActive = (int) $holder['ativo'] === 1;
        if ($wasActive === $active) {
            $pdo->commit();
            return [
                'situacao' => $active ? 'ativo' : 'inativo',
                'inativado_em' => !$active && is_string($holder['inativado_em']) ? $holder['inativado_em'] : null,
            ];
        }

        $inactiveAt = $active
            ? (is_string($holder['inativado_em']) ? $holder['inativado_em'] : null)
            : $timestamp;
        $update = $pdo->prepare(
            'UPDATE titulares SET ativo = :active, acesso_app_liberado = 0, inativado_em = :inactive '
            . 'WHERE id = :id AND tenant_id = :tenant AND anonimizado_em IS NULL'
        );
        $update->execute([
            'active' => $active ? 1 : 0,
            'inactive' => $inactiveAt,
            'id' => $titularId,
            'tenant' => $context->tenantId,
        ]);
        if ($update->rowCount() !== 1) {
            throw new DomainException('TITULAR_STATUS_CONFLICT');
        }

        $audit = $pdo->prepare(
            'INSERT INTO auditoria_processos (tenant_id, subcliente_id, ator_usuario_id, ator_tipo, acao, entidade, '
            . 'entidade_id, antes_json, depois_json, criado_em) '
            . 'VALUES (:tenant, :unit, :actor, :actor_type, :action, :entity, :entity_id, :before_json, :after_json, :created)'
        );
        $audit->execute([
            'tenant' => $context->tenantId,
            'unit' => $holder['subcliente_id'],
            'actor' => (int) $identity['id'],
            'actor_type' => (string) $identity['papel_sistema'],
            'action' => $active ? 'titular.activated' : 'titular.deactivated',
            'entity' => 'titulares',
            'entity_id' => $titularId,
            'before_json' => json_encode([
                'ativo' => $wasActive,
                'acesso_app_liberado' => (int) $holder['acesso_app_liberado'] === 1,
                'inativado_em' => $holder['inativado_em'],
            ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
            'after_json' => json_encode([
                'ativo' => $active,
                'acesso_app_liberado' => false,
                'inativado_em' => $inactiveAt,
            ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
            'created' => $timestamp,
        ]);
        $pdo->commit();

        return ['situacao' => $active ? 'ativo' : 'inativo', 'inativado_em' => $active ? null : $inactiveAt];
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $exception;
    }
}

/** @param array<string,mixed> $identity @return array{updated:bool} */
function titularManagementReplacePhoto(
    PDO $pdo,
    TenantContext $context,
    array $identity,
    int $titularId,
    string $bytes
): array {
    if ($titularId < 1 || $context->tenantId === null
        || (int) ($identity['tenant_id'] ?? 0) !== $context->tenantId) {
        throw new DomainException('TITULAR_PHOTO_DENIED');
    }
    $validated = titularManagementNormalizePhoto($bytes);
    $timestamp = gmdate('Y-m-d H:i:s');
    $driver = strtolower((string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME));
    $lock = $driver === 'mysql' ? ' FOR UPDATE' : '';
    $newKey = null;
    $oldKey = null;
    $pdo->beginTransaction();
    try {
        $scope = tenantScopeBuildPredicate($identity, null, 't');
        $query = $pdo->prepare(
            'SELECT t.id, t.tenant_id, t.subcliente_id, t.foto_url, t.anonimizado_em FROM titulares t '
            . 'WHERE t.id = :id AND ' . $scope['sql'] . ' LIMIT 1' . $lock
        );
        $query->execute(['id' => $titularId] + $scope['params']);
        $holder = $query->fetch(PDO::FETCH_ASSOC);
        if (!is_array($holder) || (int) $holder['tenant_id'] !== $context->tenantId
            || !pode($pdo, $context, $identity, 'titular.editar', $holder['subcliente_id'] === null
                ? null : (int) $holder['subcliente_id'])) {
            throw new DomainException('TITULAR_PHOTO_DENIED');
        }
        if ($holder['anonimizado_em'] !== null) {
            throw new DomainException('TITULAR_STATUS_ANONYMIZED');
        }
        $oldKey = is_string($holder['foto_url']) ? $holder['foto_url'] : null;
        $newKey = batchImportPhotoStorePrivate($context->tenantId, $validated['extension'], $validated['bytes']);
        $update = $pdo->prepare(
            'UPDATE titulares SET foto_url = :photo WHERE id = :id AND tenant_id = :tenant AND anonimizado_em IS NULL'
        );
        $update->execute(['photo' => $newKey, 'id' => $titularId, 'tenant' => $context->tenantId]);
        if ($update->rowCount() !== 1) {
            throw new DomainException('TITULAR_PHOTO_CONFLICT');
        }
        $audit = $pdo->prepare(
            'INSERT INTO auditoria_processos (tenant_id, subcliente_id, ator_usuario_id, ator_tipo, acao, entidade, '
            . 'entidade_id, antes_json, depois_json, criado_em) '
            . 'VALUES (:tenant, :unit, :actor, :actor_type, :action, :entity, :entity_id, :before_json, :after_json, :created)'
        );
        $audit->execute([
            'tenant' => $context->tenantId,
            'unit' => $holder['subcliente_id'],
            'actor' => (int) $identity['id'],
            'actor_type' => (string) $identity['papel_sistema'],
            'action' => 'titular.photo_replaced',
            'entity' => 'titulares',
            'entity_id' => $titularId,
            'before_json' => json_encode(['foto_presente' => $oldKey !== null], JSON_THROW_ON_ERROR),
            'after_json' => json_encode(['foto_presente' => true], JSON_THROW_ON_ERROR),
            'created' => $timestamp,
        ]);
        $pdo->commit();
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        if (is_string($newKey)) {
            try { batchImportPhotoDeletePrivate($context->tenantId, $newKey); } catch (Throwable) {}
        }
        throw $exception;
    }

    if (is_string($oldKey) && $oldKey !== $newKey
        && preg_match('~^private://' . $context->tenantId . '/[a-f0-9]{48}\.(jpg|jpeg|png|webp)$~D', $oldKey) === 1) {
        try { batchImportPhotoDeletePrivate($context->tenantId, $oldKey); } catch (Throwable) {}
    }

    return ['updated' => true];
}

/** @return array{extension:string,bytes:string} */
function titularManagementNormalizePhoto(string $bytes): array
{
    if ($bytes === '' || strlen($bytes) > BATCH_IMPORT_PHOTO_MAX_FILE_BYTES || !function_exists('imagecreatefromstring')) {
        throw new DomainException('TITULAR_PHOTO_INVALID');
    }
    $info = @getimagesizefromstring($bytes);
    if (!is_array($info) || !is_int($info[0] ?? null) || !is_int($info[1] ?? null)
        || $info[0] < 1 || $info[1] < 1 || $info[0] * $info[1] > BATCH_IMPORT_PHOTO_MAX_PIXELS) {
        throw new DomainException('TITULAR_PHOTO_INVALID');
    }
    $formats = [IMAGETYPE_JPEG => 'jpg', IMAGETYPE_PNG => 'png', IMAGETYPE_WEBP => 'webp'];
    $type = $info[2] ?? null;
    if (!is_int($type) || !isset($formats[$type])) throw new DomainException('TITULAR_PHOTO_INVALID');
    $image = @imagecreatefromstring($bytes);
    if (!$image instanceof GdImage) throw new DomainException('TITULAR_PHOTO_INVALID');
    ob_start();
    $written = match ($type) {
        IMAGETYPE_JPEG => imagejpeg($image, null, 90),
        IMAGETYPE_PNG => imagepng($image, null, 6),
        IMAGETYPE_WEBP => function_exists('imagewebp') ? imagewebp($image, null, 90) : false,
        default => false,
    };
    $normalized = ob_get_clean();
    imagedestroy($image);
    if ($written !== true || !is_string($normalized) || $normalized === ''
        || strlen($normalized) > BATCH_IMPORT_PHOTO_MAX_FILE_BYTES) {
        throw new DomainException('TITULAR_PHOTO_INVALID');
    }
    return ['extension' => $formats[$type], 'bytes' => $normalized];
}

function titularManagementResolvePhotoPath(int $tenantId, string $storedKey): string
{
    if ($tenantId < 1 || preg_match('~^private://([1-9][0-9]*)/([a-f0-9]{48})\.(jpg|jpeg|png|webp)$~D', $storedKey, $matches) !== 1
        || (int) $matches[1] !== $tenantId) {
        throw new RuntimeException('TITULAR_PHOTO_STORAGE_KEY_INVALID');
    }
    $root = batchImportStagingPrivateRoot();
    $directory = $root . DIRECTORY_SEPARATOR . (string) $tenantId;
    if (is_link($directory)) throw new RuntimeException('TITULAR_PHOTO_STORAGE_KEY_INVALID');
    $realDirectory = realpath($directory);
    if (!is_string($realDirectory) || !batchImportStagingPathWithin($realDirectory, $root)) {
        throw new RuntimeException('TITULAR_PHOTO_STORAGE_KEY_INVALID');
    }
    $path = $realDirectory . DIRECTORY_SEPARATOR . $matches[2] . '.' . $matches[3];
    if (is_link($path)) throw new RuntimeException('TITULAR_PHOTO_STORAGE_KEY_INVALID');
    $realPath = realpath($path);
    if (!is_string($realPath) || !is_file($realPath) || !batchImportStagingPathWithin($realPath, $root)) {
        throw new RuntimeException('TITULAR_PHOTO_STORAGE_KEY_INVALID');
    }
    return $realPath;
}
