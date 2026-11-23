<?php
declare(strict_types=1);

function backupDestinationActiveLimit(): int
{
    $profile = getenv('IEPR_BACKUP_HOSTING_PROFILE');
    $profile = $profile === false || trim($profile) === '' ? 'shared' : strtolower(trim($profile));
    if (!in_array($profile, ['shared', 'vps'], true)) {
        throw new DomainException('BACKUP_DESTINATION_HOSTING_PROFILE_INVALID');
    }
    if ($profile === 'shared') {
        return 1;
    }

    $configured = getenv('IEPR_BACKUP_ACTIVE_DESTINATIONS_LIMIT');
    if ($configured === false || trim($configured) === '') {
        return 1;
    }

    if (preg_match('/^[1-9][0-9]*$/D', trim($configured)) !== 1) {
        throw new DomainException('BACKUP_DESTINATION_ACTIVE_LIMIT_INVALID');
    }
    $limit = (int) $configured;
    if ($limit > 100) {
        throw new DomainException('BACKUP_DESTINATION_ACTIVE_LIMIT_INVALID');
    }
    return $limit;
}

/** @param array<string, mixed> $identity */
function backupDestinationSetEnabled(PDO $pdo, array $identity, int $id, bool $enabled): void
{
    backupDestinationAssertSuperadmin($pdo, $identity);
    if (!$enabled) {
        $statement = $pdo->prepare('UPDATE destinos_backup SET ativo = 0, atualizado_em = :updated WHERE id = :id');
        $statement->execute(['updated' => gmdate('Y-m-d H:i:s'), 'id' => $id]);
        if ($statement->rowCount() !== 1) {
            $exists = $pdo->prepare('SELECT 1 FROM destinos_backup WHERE id = :id');
            $exists->execute(['id' => $id]);
            if ($exists->fetchColumn() !== false) {
                return;
            }
            throw new DomainException('BACKUP_DESTINATION_NOT_FOUND');
        }
        return;
    }

    $limit = backupDestinationActiveLimit();
    $pdo->beginTransaction();
    try {
        if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql') {
            $pdo->query('SELECT id FROM destinos_backup ORDER BY id FOR UPDATE')->fetchAll(PDO::FETCH_COLUMN);
        } else {
            $lock = $pdo->prepare('UPDATE destinos_backup SET atualizado_em = atualizado_em WHERE id = :id');
            $lock->execute(['id' => $id]);
        }

        $destination = $pdo->prepare('SELECT ativo, ultimo_teste_status FROM destinos_backup WHERE id = :id');
        $destination->execute(['id' => $id]);
        $row = $destination->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            throw new DomainException('BACKUP_DESTINATION_NOT_FOUND');
        }
        if ($row['ultimo_teste_status'] !== 'success') {
            throw new DomainException('BACKUP_DESTINATION_TEST_REQUIRED');
        }

        $activeCount = (int) $pdo->query('SELECT COUNT(*) FROM destinos_backup WHERE ativo = 1')->fetchColumn();
        if ((int) $row['ativo'] === 1) {
            if ($activeCount > $limit) {
                throw new DomainException('BACKUP_DESTINATION_ACTIVE_LIMIT_REACHED');
            }
            $pdo->commit();
            return;
        }
        if ($activeCount >= $limit) {
            throw new DomainException('BACKUP_DESTINATION_ACTIVE_LIMIT_REACHED');
        }

        $statement = $pdo->prepare('UPDATE destinos_backup SET ativo = 1, atualizado_em = :updated WHERE id = :id');
        $statement->execute(['updated' => gmdate('Y-m-d H:i:s'), 'id' => $id]);
        $pdo->commit();
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $error;
    }
}
