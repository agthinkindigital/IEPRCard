<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/config/conn.php';
require_once dirname(__DIR__, 2) . '/config/schema_self_healing.php';
require_once dirname(__DIR__, 2) . '/database/migrations/runner.php';
require_once dirname(__DIR__, 2) . '/config/whatsapp_conversation.php';

$previousStorage = getenv('IEPR_PRIVATE_STORAGE_PATH');
$storage = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'ieprcard-whatsapp-delivery-' . bin2hex(random_bytes(5));
mkdir($storage, 0700, true);
putenv('IEPR_PRIVATE_STORAGE_PATH=' . $storage);
$pdo = dbConnect(['DB_DRIVER' => 'sqlite', 'DB_SQLITE_PATH' => ':memory:']);

try {
    schemaAplicarMigracoes($pdo, 'test');
    schemaGarantirEstrutura($pdo, 'test');
    $pdo->prepare("INSERT INTO tenants (tipo, nome_organizacao, status_conta) VALUES ('cliente', 'WhatsApp Delivery Test', 'ativo')")
        ->execute();
    $tenantId = (int) $pdo->lastInsertId();
    $now = gmdate('Y-m-d H:i:s');
    $pdo->prepare(
        "INSERT INTO designs_carteirinha (tenant_id, nome_layout, orientacao, ativo) VALUES (:tenant, 'Delivery test', 'horizontal', 1)"
    )->execute(['tenant' => $tenantId]);
    $designId = (int) $pdo->lastInsertId();
    $pdo->prepare(
        "INSERT INTO titulares (tenant_id, nome_completo, whatsapp, ativo, criado_em) VALUES (:tenant, 'Test Holder', '+5511999990000', 1, :created)"
    )->execute(['tenant' => $tenantId, 'created' => $now]);
    $holderId = (int) $pdo->lastInsertId();
    $pdo->prepare(
        "INSERT INTO lotes_importacao (tenant_id, design_id, origem_tipo, total_registros, processados_sucesso, status_lote, criado_em, finalizado_em, correlation_id) "
        . "VALUES (:tenant, :design, 'emissao', 1, 1, 'concluido', :created, :finished, :correlation)"
    )->execute([
        'tenant' => $tenantId, 'design' => $designId, 'created' => $now, 'finished' => $now,
        'correlation' => '4be64286-79a0-4a5a-b169-975e3cd7a801',
    ]);
    $jobId = (int) $pdo->lastInsertId();
    $frontKey = cardIssuanceWorkerStore($tenantId, $jobId, 2, 'webp', 'front-webp-bytes');
    $backKey = cardIssuanceWorkerStore($tenantId, $jobId, 3, 'webp', 'back-webp-bytes');
    $pdo->prepare(
        "INSERT INTO carteirinhas_emitidas (tenant_id, titular_id, design_id, lote_id, codigo_validacao_qr, status_carteirinha, webp_frente_url, webp_verso_url, emitida_em) "
        . "VALUES (:tenant, :holder, :design, :job, :code, 'ativa', :front, :back, :created)"
    )->execute([
        'tenant' => $tenantId, 'holder' => $holderId, 'design' => $designId, 'job' => $jobId,
        'code' => bin2hex(random_bytes(16)), 'front' => $frontKey, 'back' => $backKey, 'created' => $now,
    ]);
    $pdo->prepare(
        "INSERT INTO whatsapp_instancias (tenant_id, escopo_chave, nome_instancia, device_id, webhook_token_hash, estado, fluxo_cadastro_ativo, criado_em, atualizado_em) "
        . "VALUES (:tenant, :scope, 'delivery-test', 'device-test', :token, 'ativa', 1, :created, :updated)"
    )->execute([
        'tenant' => $tenantId, 'scope' => 'tenant:' . $tenantId, 'token' => hash('sha256', 'delivery-token'),
        'created' => $now, 'updated' => $now,
    ]);
    $instanceId = (int) $pdo->lastInsertId();
    $pdo->prepare(
        "INSERT INTO whatsapp_conversas (instancia_id, tenant_id, estado, titular_id, job_id, etapa_entrega, tentativas_entrega, expira_em, criado_em, atualizado_em) "
        . "VALUES (:instance, :tenant, 'entrega_pendente', :holder, :job, 0, 0, :expires, :created, :updated)"
    )->execute([
        'instance' => $instanceId, 'tenant' => $tenantId, 'holder' => $holderId, 'job' => $jobId,
        'expires' => gmdate('Y-m-d H:i:s', time() + 3600), 'created' => $now, 'updated' => $now,
    ]);

    $delivered = [];
    $transport = static function (string $device, string $phone, string $encoded, string $caption) use (&$delivered): void {
        $delivered[] = [$device, $phone, base64_decode($encoded, true), $caption];
    };
    $result = whatsappConversationProcessPendingDeliveries($pdo, 20, $transport);
    $conversation = $pdo->query('SELECT estado, etapa_entrega, tentativas_entrega FROM whatsapp_conversas LIMIT 1')->fetch(PDO::FETCH_ASSOC);
    $trace = $pdo->query('SELECT correlation_id, categoria, evento_chave, rota, status_http FROM eventos_tecnicos ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
    if ($result !== ['processed' => 1, 'failed' => 0] || !is_array($conversation)
        || $conversation['estado'] !== 'concluida' || (int) $conversation['etapa_entrega'] !== 2
        || (int) $conversation['tentativas_entrega'] !== 2 || count($delivered) !== 2
        || $delivered[0] !== ['device-test', '+5511999990000', 'front-webp-bytes', 'Frente da sua carteirinha.']
        || $delivered[1] !== ['device-test', '+5511999990000', 'back-webp-bytes', 'Verso da sua carteirinha.']
        || count($trace) !== 2
        || $trace[0] !== [
            'correlation_id' => '4be64286-79a0-4a5a-b169-975e3cd7a801', 'categoria' => 'job',
            'evento_chave' => 'whatsapp.delivery_started', 'rota' => 'worker.whatsapp_delivery', 'status_http' => 0,
        ]
        || $trace[1] !== [
            'correlation_id' => '4be64286-79a0-4a5a-b169-975e3cd7a801', 'categoria' => 'job',
            'evento_chave' => 'whatsapp.delivery_completed', 'rota' => 'worker.whatsapp_delivery', 'status_http' => 0,
        ]) {
        throw new RuntimeException('The delivery worker must send both private WebP sides and persist completed progress.');
    }

    $pdo->prepare(
        "INSERT INTO whatsapp_conversas (instancia_id, tenant_id, estado, titular_id, job_id, etapa_entrega, tentativas_entrega, expira_em, criado_em, atualizado_em) "
        . "VALUES (:instance, :tenant, 'entrega_pendente', :holder, :job, 0, 0, :expires, :created, :updated)"
    )->execute([
        'instance' => $instanceId, 'tenant' => $tenantId, 'holder' => $holderId, 'job' => $jobId,
        'expires' => gmdate('Y-m-d H:i:s', time() + 3600), 'created' => $now, 'updated' => $now,
    ]);
    $failingTransport = static function (): void { throw new RuntimeException('synthetic transport error'); };
    $failedDelivery = whatsappConversationProcessPendingDeliveries($pdo, 20, $failingTransport);
    $failedState = $pdo->query("SELECT estado, tentativas_entrega FROM whatsapp_conversas WHERE estado = 'entrega_pendente' LIMIT 1")
        ->fetch(PDO::FETCH_ASSOC);
    $failedEvents = $pdo->query('SELECT evento_chave FROM eventos_tecnicos ORDER BY id')->fetchAll(PDO::FETCH_COLUMN);
    if ($failedDelivery !== ['processed' => 0, 'failed' => 1] || !is_array($failedState)
        || $failedState['estado'] !== 'entrega_pendente' || (int) $failedState['tentativas_entrega'] !== 1
        || $failedEvents !== [
            'whatsapp.delivery_started', 'whatsapp.delivery_completed',
            'whatsapp.delivery_started', 'whatsapp.delivery_failed',
        ]) {
        throw new RuntimeException('A failed WhatsApp delivery must remain retryable and be traced without stopping the worker.');
    }

    $pdo->prepare('UPDATE lotes_importacao SET correlation_id = NULL WHERE id = :id')->execute(['id' => $jobId]);
    $pdo->prepare(
        "INSERT INTO whatsapp_conversas (instancia_id, tenant_id, estado, titular_id, job_id, etapa_entrega, tentativas_entrega, expira_em, criado_em, atualizado_em) "
        . "VALUES (:instance, :tenant, 'entrega_pendente', :holder, :job, 0, 0, :expires, :created, :updated)"
    )->execute([
        'instance' => $instanceId, 'tenant' => $tenantId, 'holder' => $holderId, 'job' => $jobId,
        'expires' => gmdate('Y-m-d H:i:s', time() + 3600), 'created' => $now, 'updated' => $now,
    ]);
    $legacyDelivery = whatsappConversationProcessPendingDeliveries($pdo, 20, $failingTransport);
    $eventsAfterLegacy = (int) $pdo->query('SELECT COUNT(*) FROM eventos_tecnicos')->fetchColumn();
    if ($legacyDelivery !== ['processed' => 0, 'failed' => 2] || $eventsAfterLegacy !== 4) {
        throw new RuntimeException('Legacy jobs without correlation must still be retried without orphan technical events.');
    }
    fwrite(STDOUT, "PASS: pending WhatsApp delivery sends both WebP sides and records resumable progress.\n");
} finally {
    if ($previousStorage === false) putenv('IEPR_PRIVATE_STORAGE_PATH');
    else putenv('IEPR_PRIVATE_STORAGE_PATH=' . $previousStorage);
    foreach (glob($storage . DIRECTORY_SEPARATOR . '*') ?: [] as $path) {
        if (is_dir($path)) foreach (glob($path . DIRECTORY_SEPARATOR . '*') ?: [] as $file) if (is_file($file)) unlink($file);
        if (is_dir($path)) rmdir($path);
        elseif (is_file($path)) unlink($path);
    }
    @rmdir($storage);
}

return 'pass';
