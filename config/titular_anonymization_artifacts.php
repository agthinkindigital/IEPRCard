<?php
declare(strict_types=1);

require_once __DIR__ . '/batch_import_staging_support.php';

/** @param array<string, mixed> $holder */
function titularAnonymizationDeleteHolderArtifacts(PDO $pdo, int $tenantId, int $titularId, array $holder): void
{
    $keys = [];
    if (is_string($holder['foto_url'] ?? null) && $holder['foto_url'] !== '') {
        $keys[] = $holder['foto_url'];
    }
    $cards = $pdo->prepare(
        'SELECT lote_id, webp_frente_url, webp_verso_url, pdf_individual_url FROM carteirinhas_emitidas '
        . 'WHERE tenant_id = :tenant AND titular_id = :titular'
    );
    $cards->execute(['tenant' => $tenantId, 'titular' => $titularId]);
    $lotIds = [];
    foreach ($cards->fetchAll(PDO::FETCH_ASSOC) as $card) {
        if (filter_var($card['lote_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) !== false) {
            $lotIds[(int) $card['lote_id']] = true;
        }
        foreach (['webp_frente_url', 'webp_verso_url', 'pdf_individual_url'] as $column) {
            if (is_string($card[$column] ?? null) && $card[$column] !== '') {
                $keys[] = $card[$column];
            }
        }
    }
    foreach (array_unique($keys) as $storedKey) {
        titularAnonymizationDeletePrivateArtifact($tenantId, $storedKey);
    }
    foreach (array_keys($lotIds) as $lotId) {
        titularAnonymizationInvalidateConsolidatedPdf($pdo, $tenantId, $lotId);
    }
}

function titularAnonymizationInvalidateConsolidatedPdf(PDO $pdo, int $tenantId, int $lotId): void
{
    if ($tenantId < 1 || $lotId < 1) {
        throw new InvalidArgumentException('ANONYMIZATION_LOT_INVALID');
    }
    $query = $pdo->prepare(
        'SELECT pdf_consolidado_url FROM lotes_importacao WHERE tenant_id = :tenant AND id = :id LIMIT 1'
    );
    $query->execute(['tenant' => $tenantId, 'id' => $lotId]);
    $storedKey = $query->fetchColumn();
    if (is_string($storedKey) && $storedKey !== '') {
        titularAnonymizationDeletePrivateArtifact($tenantId, $storedKey);
    }
    $pdo->prepare(
        'UPDATE lotes_importacao SET pdf_consolidado_url = NULL WHERE tenant_id = :tenant AND id = :id'
    )->execute(['tenant' => $tenantId, 'id' => $lotId]);
}

function titularAnonymizationDeletePrivateArtifact(int $tenantId, string $storedKey): void
{
    if (preg_match('/^private:\/\/([1-9][0-9]*)\/([a-f0-9]{48})\.(jpg|jpeg|png|webp|pdf)$/D', $storedKey, $matches) !== 1
        || (int) $matches[1] !== $tenantId) {
        throw new RuntimeException('ANONYMIZATION_ARTIFACT_KEY_INVALID');
    }
    $root = batchImportStagingPrivateRoot();
    $directory = $root . DIRECTORY_SEPARATOR . (string) $tenantId;
    if (!file_exists($directory) && !is_link($directory)) {
        return;
    }
    if (is_link($directory)) {
        throw new RuntimeException('ANONYMIZATION_ARTIFACT_KEY_INVALID');
    }
    $realDirectory = realpath($directory);
    if (!is_string($realDirectory) || !batchImportStagingPathWithin($realDirectory, $root)) {
        throw new RuntimeException('ANONYMIZATION_ARTIFACT_KEY_INVALID');
    }
    $path = $realDirectory . DIRECTORY_SEPARATOR . $matches[2] . '.' . $matches[3];
    if (is_link($path)) {
        throw new RuntimeException('ANONYMIZATION_ARTIFACT_KEY_INVALID');
    }
    if (!file_exists($path)) {
        return;
    }
    $realPath = realpath($path);
    if (!is_string($realPath) || !is_file($realPath) || !batchImportStagingPathWithin($realPath, $root)
        || !unlink($realPath)) {
        throw new RuntimeException('ANONYMIZATION_ARTIFACT_DELETE_FAILED');
    }
}
