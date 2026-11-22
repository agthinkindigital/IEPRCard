<?php
declare(strict_types=1);

function whatsappConversationSend(PDO $pdo, array $instance, string $phone, string $text, ?callable $transport = null): void
{
    if ($transport !== null) {
        $transport($instance, $phone, $text);
        return;
    }
    $configuration = $pdo->query(
        "SELECT api_base_url, api_token_cifrado FROM whatsapp_config_global WHERE chave = 'global' LIMIT 1"
    )->fetch(PDO::FETCH_ASSOC);
    if (!is_array($configuration) || !is_string($instance['device_id'] ?? null) || $instance['device_id'] === '') {
        throw new RuntimeException('WHATSAPP_GOWA_NOT_CONFIGURED');
    }
    $response = whatsappGatewayApiRequest(
        (string) $configuration['api_base_url'],
        aiGlobalConfigOpen((string) $configuration['api_token_cifrado']),
        'enviar_mensagem',
        ['device_id' => $instance['device_id'], 'telefone' => $phone, 'mensagem' => $text]
    );
    if (($response['ok'] ?? false) !== true) {
        throw new RuntimeException('WHATSAPP_GOWA_SEND_FAILED');
    }
}

/** @return array{processed:int,failed:int} */
function whatsappConversationProcessPendingDeliveries(PDO $pdo, int $limit = 20, ?callable $transport = null): array
{
    if ($limit < 1 || $limit > 100) throw new InvalidArgumentException('WHATSAPP_DELIVERY_LIMIT_INVALID');
    $rows = $pdo->prepare(
        "SELECT c.id, c.tenant_id, c.titular_id, c.job_id, c.etapa_entrega, i.device_id, t.whatsapp, "
        . 'card.webp_frente_url, card.webp_verso_url '
        . 'FROM whatsapp_conversas c JOIN whatsapp_instancias i ON i.id = c.instancia_id AND i.estado = \'ativa\' '
        . 'AND i.fluxo_cadastro_ativo = 1 JOIN titulares t ON t.id = c.titular_id AND t.tenant_id = c.tenant_id '
        . "JOIN lotes_importacao job ON job.id = c.job_id AND job.status_lote = 'concluido' "
        . "JOIN carteirinhas_emitidas card ON card.lote_id = c.job_id AND card.titular_id = c.titular_id AND card.status_carteirinha = 'ativa' "
        . "WHERE c.estado = 'entrega_pendente' ORDER BY c.id LIMIT " . $limit
    );
    $rows->execute();
    $processed = 0;
    $failed = 0;
    foreach ($rows->fetchAll(PDO::FETCH_ASSOC) as $row) {
        try {
            if (!is_string($row['whatsapp']) || $row['whatsapp'] === ''
                || !is_string($row['device_id']) || $row['device_id'] === '') {
                throw new RuntimeException('WHATSAPP_DELIVERY_TARGET_UNAVAILABLE');
            }
            $stage = (int) $row['etapa_entrega'];
            while ($stage === 0 || $stage === 1) {
                $assetKey = $stage === 0 ? $row['webp_frente_url'] : $row['webp_verso_url'];
                if (!is_string($assetKey) || $assetKey === '') throw new RuntimeException('WHATSAPP_DELIVERY_ASSET_UNAVAILABLE');
                $image = cardIssuanceWorkerReadAsset((int) $row['tenant_id'], $assetKey);
                if (strlen($image) > 2_000_000) throw new RuntimeException('WHATSAPP_DELIVERY_ASSET_UNAVAILABLE');
                whatsappConversationSendImage(
                    $pdo,
                    (string) $row['device_id'],
                    $row['whatsapp'],
                    base64_encode($image),
                    $stage === 0 ? 'Frente da sua carteirinha.' : 'Verso da sua carteirinha.',
                    $transport
                );
                $nextStage = $stage + 1;
                $updateStage = $pdo->prepare(
                    "UPDATE whatsapp_conversas SET etapa_entrega = :stage, tentativas_entrega = tentativas_entrega + 1, "
                    . 'ultimo_erro_entrega = NULL, atualizado_em = :updated WHERE id = :id AND estado = \'entrega_pendente\' AND etapa_entrega = :expected'
                );
                $updateStage->execute([
                    'stage' => $nextStage, 'updated' => gmdate('Y-m-d H:i:s'), 'id' => (int) $row['id'], 'expected' => $stage,
                ]);
                if ($updateStage->rowCount() !== 1) throw new RuntimeException('WHATSAPP_DELIVERY_STATE_CONFLICT');
                $stage = $nextStage;
            }
            $complete = $pdo->prepare(
                "UPDATE whatsapp_conversas SET estado = 'concluida', ultimo_erro_entrega = NULL, atualizado_em = :updated "
                . "WHERE id = :id AND estado = 'entrega_pendente' AND etapa_entrega = 2"
            );
            $complete->execute(['updated' => gmdate('Y-m-d H:i:s'), 'id' => (int) $row['id']]);
            if ($complete->rowCount() === 1) ++$processed;
        } catch (Throwable) {
            $failedUpdate = $pdo->prepare(
                "UPDATE whatsapp_conversas SET tentativas_entrega = tentativas_entrega + 1, "
                . "ultimo_erro_entrega = 'delivery_failed', atualizado_em = :updated WHERE id = :id AND estado = 'entrega_pendente'"
            );
            $failedUpdate->execute(['updated' => gmdate('Y-m-d H:i:s'), 'id' => (int) $row['id']]);
            ++$failed;
        }
    }
    return ['processed' => $processed, 'failed' => $failed];
}
function whatsappConversationSendImage(
    PDO $pdo,
    string $deviceId,
    string $phone,
    string $base64,
    string $caption,
    ?callable $transport = null
): void
{
    if ($transport !== null) {
        $transport($deviceId, $phone, $base64, $caption);
        return;
    }
    $configuration = $pdo->query(
        "SELECT api_base_url, api_token_cifrado FROM whatsapp_config_global WHERE chave = 'global' LIMIT 1"
    )->fetch(PDO::FETCH_ASSOC);
    if (!is_array($configuration)) throw new RuntimeException('WHATSAPP_GOWA_NOT_CONFIGURED');
    $response = whatsappGatewayApiRequest(
        (string) $configuration['api_base_url'],
        aiGlobalConfigOpen((string) $configuration['api_token_cifrado']),
        'enviar_midia',
        ['device_id' => $deviceId, 'telefone' => $phone, 'conteudo_base64' => $base64, 'tipo' => 'image', 'legenda' => $caption],
        null,
        20
    );
    if (($response['ok'] ?? false) !== true) throw new RuntimeException('WHATSAPP_GOWA_SEND_FAILED');
}
function whatsappConversationCleanupExpired(PDO $pdo, int $limit = 100, ?int $now = null): int
{
    if ($limit < 1 || $limit > 200) throw new InvalidArgumentException('WHATSAPP_CONVERSATION_CLEANUP_LIMIT_INVALID');
    $stamp = gmdate('Y-m-d H:i:s', $now ?? time());
    $query = $pdo->prepare(
        'SELECT id, tenant_id FROM whatsapp_conversas WHERE expira_em < :now AND titular_id IS NULL '
        . "AND estado IN ('aguardando_consentimento','recusado','revogado') ORDER BY id LIMIT " . $limit
    );
    $query->execute(['now' => $stamp]);
    $deleted = 0;
    foreach ($query->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $deterministicPhotoKey = 'private://' . (int) $row['tenant_id'] . '/'
            . substr(hash('sha256', (int) $row['id'] . ':1:jpg'), 0, 48) . '.jpg';
        whatsappConversationDeleteDraftPhoto((int) $row['tenant_id'], ['foto_url' => $deterministicPhotoKey]);
        $remove = $pdo->prepare('DELETE FROM whatsapp_conversas WHERE id = :id AND expira_em < :now AND titular_id IS NULL');
        $remove->execute(['id' => (int) $row['id'], 'now' => $stamp]);
        $deleted += $remove->rowCount();
    }
    return $deleted;
}
