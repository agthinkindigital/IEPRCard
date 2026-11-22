<?php
declare(strict_types=1);

require_once __DIR__ . '/privacy_subjects.php';

function adminAuthProvisionInitialSuperadmin(
    PDO $pdo,
    string $name,
    string $email,
    string $password,
    ?string $lockPath = null,
): int {
    $name = trim($name);
    $email = strtolower(trim($email));
    $nameLength = preg_match_all('/./us', $name);
    if ($name === '' || $nameLength === false || $nameLength > 180) {
        throw new InvalidArgumentException('Nome inválido.');
    }
    if (filter_var($email, FILTER_VALIDATE_EMAIL) === false || strlen($email) > 254) {
        throw new InvalidArgumentException('E-mail inválido.');
    }
    $passwordLength = preg_match_all('/./us', $password);
    if ($passwordLength === false || $passwordLength < 12 || strlen($password) > 1024) {
        throw new InvalidArgumentException('A senha deve ter pelo menos 12 caracteres e no máximo 1024 bytes.');
    }

    $lockPath ??= dirname(__DIR__) . '/var/admin-bootstrap.lock';
    $directory = dirname($lockPath);
    if (!is_dir($directory) && !@mkdir($directory, 0700, true) && !is_dir($directory)) {
        throw new RuntimeException('Não foi possível preparar o bloqueio de provisionamento.');
    }
    $lock = @fopen($lockPath, 'c');
    if ($lock === false) {
        throw new RuntimeException('Não foi possível bloquear o provisionamento.');
    }

    try {
        @chmod($lockPath, 0600);
        if (!flock($lock, LOCK_EX)) {
            throw new RuntimeException('Não foi possível bloquear o provisionamento.');
        }
        $pdo->beginTransaction();
        if (adminBootstrapHasUsers($pdo)) {
            throw new RuntimeException('A configuração inicial só é permitida quando ainda não há usuários.');
        }

        $insert = $pdo->prepare(
            'INSERT INTO usuarios (tenant_id, subcliente_id, perfil_acesso_id, criado_por_usuario_id, '
            . 'papel_sistema, nome, email, senha_hash, ativo) '
            . 'VALUES (NULL, NULL, NULL, NULL, :role, :name, :email, :password_hash, 1)'
        );
        $insert->execute([
            'role' => 'superadmin',
            'name' => $name,
            'email' => $email,
            'password_hash' => password_hash($password, PASSWORD_DEFAULT),
        ]);
        $id = (int) $pdo->lastInsertId();
        $subject = privacySubjectCreate($pdo, 'pessoa');
        privacySubjectLinkRecord($pdo, $subject['id'], 'usuario', $id);
        $pdo->commit();

        return $id;
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $error;
    } finally {
        flock($lock, LOCK_UN);
        fclose($lock);
    }
}

function adminBootstrapHasUsers(PDO $pdo): bool
{
    return $pdo->query('SELECT 1 FROM usuarios LIMIT 1')->fetchColumn() !== false;
}
