<?php
declare(strict_types=1);

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
    if (strlen($password) < 12 || strlen($password) > 1024) {
        throw new InvalidArgumentException('A senha deve ter entre 12 e 1024 bytes.');
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
        $existingAdmin = $pdo->query(
            "SELECT id FROM usuarios WHERE tenant_id IS NULL AND papel_sistema = 'superadmin' LIMIT 1"
        )->fetchColumn();
        if ($existingAdmin !== false) {
            throw new RuntimeException('Já existe um Administrador SaaS global.');
        }

        $emailCheck = $pdo->prepare('SELECT id FROM usuarios WHERE tenant_id IS NULL AND email = :email LIMIT 1');
        $emailCheck->execute(['email' => $email]);
        if ($emailCheck->fetchColumn() !== false) {
            throw new RuntimeException('O e-mail já está associado a uma conta global.');
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
