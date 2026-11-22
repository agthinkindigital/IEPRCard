<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/config/privacy_subjects.php';

return static function (PDO $pdo): void {
    $tenants = $pdo->query(
        "SELECT t.id FROM tenants t WHERE NOT EXISTS ("
        . "SELECT 1 FROM privacy_subject_records r WHERE r.tipo_registro = 'tenant_contato' AND r.registro_id = t.id"
        . ') ORDER BY t.id'
    )->fetchAll(PDO::FETCH_COLUMN);
    foreach ($tenants as $tenantId) {
        privacySubjectEnsureForRecord($pdo, 'tenant_contato', (int) $tenantId, (int) $tenantId);
    }
};
