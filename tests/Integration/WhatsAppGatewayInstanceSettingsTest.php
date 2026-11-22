<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/config/conn.php';
require_once dirname(__DIR__, 2) . '/config/schema_self_healing.php';
require_once dirname(__DIR__, 2) . '/database/migrations/runner.php';
require_once dirname(__DIR__, 2) . '/config/whatsapp_gateway.php';

$pdo = dbConnect(['DB_DRIVER' => 'sqlite', 'DB_SQLITE_PATH' => ':memory:']);
schemaAplicarMigracoes($pdo, 'test'); schemaGarantirEstrutura($pdo, 'test');
$pdo->prepare("INSERT INTO usuarios (papel_sistema,nome,email,senha_hash,ativo) VALUES ('superadmin','Flow Admin','flow-admin@example.test',:hash,1)")
    ->execute(['hash' => password_hash('synthetic-flow-password', PASSWORD_DEFAULT)]);
$userId = (int) $pdo->lastInsertId();
$identity = ['id' => $userId, 'papel_sistema' => 'superadmin', 'tenant_id' => null, 'subcliente_id' => null];
$pdo->prepare("INSERT INTO tenants (tipo,nome_organizacao,status_conta) VALUES ('cliente','Flow Tenant','ativo')")->execute();
$tenantId = (int) $pdo->lastInsertId();
$now = gmdate('Y-m-d H:i:s');
$pdo->prepare(
    "INSERT INTO whatsapp_instancias (tenant_id,escopo_chave,nome_instancia,device_id,webhook_token_hash,fluxo_cadastro_ativo,estado,criado_em,atualizado_em) "
    . "VALUES (:tenant,:scope,'flow-test','flow-device',:token,0,'ativa',:created,:updated)"
)->execute(['tenant' => $tenantId, 'scope' => 'tenant:' . $tenantId, 'token' => hash('sha256', 'synthetic-flow-token'), 'created' => $now, 'updated' => $now]);
$instanceId = (int) $pdo->lastInsertId();
whatsappGatewayInstanceSetRegistrationFlow($pdo, $identity, $instanceId, true);
if ((int) $pdo->query('SELECT fluxo_cadastro_ativo FROM whatsapp_instancias WHERE id = ' . $instanceId)->fetchColumn() !== 1) {
    throw new RuntimeException('Superadmin must be able to explicitly opt an active instance into the flow.');
}
whatsappGatewayInstanceSetRegistrationFlow($pdo, $identity, $instanceId, false);
try {
    whatsappGatewayInstanceSetRegistrationFlow($pdo, ['id' => $userId, 'papel_sistema' => 'tenant_admin'], $instanceId, true);
    throw new RuntimeException('A tenant identity changed a global WhatsApp instance setting.');
} catch (DomainException $exception) {
    if ($exception->getMessage() !== 'AI_CONFIG_FORBIDDEN') throw $exception;
}
if ((int) $pdo->query('SELECT fluxo_cadastro_ativo FROM whatsapp_instancias WHERE id = ' . $instanceId)->fetchColumn() !== 0) {
    throw new RuntimeException('Tenant access denial must preserve the instance opt-out.');
}
fwrite(STDOUT, "PASS: WhatsApp registration is an explicit, superadmin-only per-instance opt-in.\n");
return 'pass';
