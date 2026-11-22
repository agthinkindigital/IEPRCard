<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/schema/catalog.php';
require_once dirname(__DIR__, 2) . '/config/schema_self_healing.php';
require_once dirname(__DIR__, 2) . '/config/privacy_subjects.php';

return static function (PDO $pdo): void {
    schemaAplicarCatalogoExpandOnly($pdo, \IEPRCard\Database\Schema\catalogoVersao('0023'));
    $designs = $pdo->query(
        'SELECT id, tenant_id, bg_frente_url, bg_verso_url FROM designs_carteirinha '
        . 'WHERE bg_frente_url IS NOT NULL OR bg_verso_url IS NOT NULL ORDER BY id'
    )->fetchAll(PDO::FETCH_ASSOC);
    $find = $pdo->prepare('SELECT id FROM design_fundos_privacidade WHERE design_id = :design AND lado = :side LIMIT 1');
    $insert = $pdo->prepare(
        'INSERT INTO design_fundos_privacidade (tenant_id, design_id, lado, classificacao, titular_id, asset_url, criado_em, atualizado_em) '
        . "VALUES (:tenant, :design, :side, 'revisar', NULL, :asset, :created, :updated)"
    );
    foreach ($designs as $design) {
        foreach (['frente' => 'bg_frente_url', 'verso' => 'bg_verso_url'] as $side => $field) {
            $asset = $design[$field] ?? null;
            if (!is_string($asset) || $asset === '') continue;
            $find->execute(['design' => $design['id'], 'side' => $side]);
            $rowId = $find->fetchColumn();
            if ($rowId === false) {
                $now = gmdate('Y-m-d H:i:s');
                $insert->execute(['tenant' => $design['tenant_id'], 'design' => $design['id'], 'side' => $side, 'asset' => $asset, 'created' => $now, 'updated' => $now]);
                $rowId = (int) $pdo->lastInsertId();
            }
            $subject = privacySubjectEnsureForRecord($pdo, 'tenant_contato', (int) $design['tenant_id'], (int) $design['tenant_id']);
            privacySubjectLinkRecord($pdo, (int) $subject['id'], 'design_fundo', (int) $rowId);
        }
    }
};
