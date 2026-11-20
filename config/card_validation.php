<?php
declare(strict_types=1);

require_once __DIR__ . '/auth_session.php';

/** @return array{status:string,motivo_codigo:string} */
function cardValidationResolve(PDO $pdo, string $token, ?string $today = null): array
{
    $invalid = ['status' => 'invalida', 'motivo_codigo' => 'invalida'];
    if (preg_match('/^[a-f0-9]{64}$/D', $token) !== 1) {
        return $invalid;
    }

    $driver = (string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
    $query = 'SELECT c.id, c.tenant_id, c.subcliente_id, c.status_carteirinha, c.data_validade, '
        . 't.ativo AS titular_ativo, t.subcliente_id AS titular_subcliente_id, '
        . 'sede.status_conta AS sede_status, unidade.status_conta AS unidade_status '
        . 'FROM carteirinhas_emitidas c '
        . 'INNER JOIN titulares t ON t.id = c.titular_id AND t.tenant_id = c.tenant_id '
        . 'INNER JOIN tenants sede ON sede.id = c.tenant_id '
        . "LEFT JOIN tenants unidade ON unidade.id = c.subcliente_id AND unidade.parent_id = c.tenant_id AND unidade.tipo = 'subcliente' "
        . 'WHERE c.codigo_validacao_qr = :token';
    if ($driver === 'mysql') {
        $query .= ' FOR UPDATE';
    }

    $ownsTransaction = !$pdo->inTransaction();
    if ($ownsTransaction) {
        $pdo->beginTransaction();
    }

    try {
        $statement = $pdo->prepare($query);
        $statement->execute(['token' => $token]);
        $card = $statement->fetch(PDO::FETCH_ASSOC);
        if (!is_array($card)) {
            if ($ownsTransaction) {
                $pdo->commit();
            }
            return $invalid;
        }

        $status = cardValidationState($card, $today ?? gmdate('Y-m-d'));
        $update = $pdo->prepare(
            'UPDATE carteirinhas_emitidas SET total_leituras_qr = total_leituras_qr + 1 WHERE id = :id'
        );
        $update->execute(['id' => (int) $card['id']]);
        $audit = $pdo->prepare(
            "INSERT INTO auditoria_processos (tenant_id, subcliente_id, ator_tipo, acao) "
            . "VALUES (:tenant, :unit, 'publico', 'carteirinha.qr_lida')"
        );
        $audit->execute(['tenant' => (int) $card['tenant_id'], 'unit' => $card['subcliente_id']]);

        if ($ownsTransaction) {
            $pdo->commit();
        }
        return $status;
    } catch (Throwable $exception) {
        if ($ownsTransaction && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $exception;
    }
}

/** @param array<string, mixed> $card
 *  @return array{status:string,motivo_codigo:string}
 */
function cardValidationState(array $card, string $today): array
{
    $cardStatus = strtolower((string) $card['status_carteirinha']);
    if ($cardStatus === 'revogada') {
        return ['status' => 'revogada', 'motivo_codigo' => 'revogada'];
    }
    if (in_array($cardStatus, ['vencida', 'expirada'], true)
        || (is_string($card['data_validade']) && $card['data_validade'] !== '' && $card['data_validade'] < $today)) {
        return ['status' => 'vencida', 'motivo_codigo' => 'vencida'];
    }
    if ($cardStatus === 'suspensa' || (int) $card['titular_ativo'] !== 1
        || $card['sede_status'] !== 'ativo'
        || ($card['subcliente_id'] !== null && $card['unidade_status'] !== 'ativo')
        || (($card['subcliente_id'] === null) !== ($card['titular_subcliente_id'] === null))
        || ($card['subcliente_id'] !== null
            && (int) $card['subcliente_id'] !== (int) $card['titular_subcliente_id'])) {
        return ['status' => 'suspensa', 'motivo_codigo' => 'suspensa'];
    }
    if ($cardStatus !== 'ativa') {
        return ['status' => 'suspensa', 'motivo_codigo' => 'suspensa'];
    }

    return ['status' => 'ativa', 'motivo_codigo' => 'ativa'];
}
