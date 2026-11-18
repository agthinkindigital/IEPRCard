<?php
declare(strict_types=1);

/**
 * @param array<string, string>|null $configuration Override values for tests or explicit connections.
 */
function dbConnect(?array $configuration = null): PDO
{
    $configuration ??= dbReadEnvironment();
    $driver = strtolower(trim($configuration['DB_DRIVER'] ?? 'sqlite'));

    if ($driver === 'sqlite') {
        $path = $configuration['DB_SQLITE_PATH'] ?? sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'ieprcard.sqlite';
        if ($path === '') {
            throw new InvalidArgumentException('DB_SQLITE_PATH cannot be empty.');
        }

        $pdo = new PDO('sqlite:' . $path, null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
        $pdo->exec('PRAGMA foreign_keys = ON');
        if ((int) $pdo->query('PRAGMA foreign_keys')->fetchColumn() !== 1) {
            throw new RuntimeException('SQLite could not enable foreign-key enforcement.');
        }

        if ($path !== ':memory:') {
            $journalMode = strtolower((string) $pdo->query('PRAGMA journal_mode = WAL')->fetchColumn());
            if ($journalMode !== 'wal') {
                throw new RuntimeException('SQLite could not enable WAL journal mode.');
            }
        }

        return $pdo;
    }

    if ($driver !== 'mysql') {
        throw new InvalidArgumentException('DB_DRIVER precisa ser sqlite ou mysql.');
    }

    $database = $configuration['DB_DATABASE'] ?? '';
    $username = $configuration['DB_USERNAME'] ?? '';
    $password = $configuration['DB_PASSWORD'] ?? '';
    $host = $configuration['DB_HOST'] ?? 'localhost';
    $port = $configuration['DB_PORT'] ?? '3306';

    if (!preg_match('/^[A-Za-z0-9_]+$/', $database)) {
        throw new InvalidArgumentException('DB_DATABASE deve conter apenas letras, números e sublinhados.');
    }

    if ($username === '' || !ctype_digit($port) || (int) $port < 1 || (int) $port > 65535) {
        throw new InvalidArgumentException('DB_USERNAME e uma DB_PORT válida são obrigatórios para MySQL.');
    }

    if ($host === '' || str_contains($host, ';')) {
        throw new InvalidArgumentException('DB_HOST é inválido.');
    }

    $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $host, (int) $port, $database);

    try {
        return new PDO($dsn, $username, $password, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
    } catch (PDOException $exception) {
        $state = $exception->getCode();
        throw new RuntimeException('Não foi possível conectar ao banco configurado' . ($state !== '' ? ' (SQLSTATE ' . $state . ')' : '') . '.', 0);
    }
}

function dbConnection(): PDO
{
    static $connection;
    $connection ??= dbConnect();

    return $connection;
}

/** @param array<string|int, mixed> $parameters */
function dbQuery(string $sql, array $parameters = []): PDOStatement
{
    $statement = dbConnection()->prepare($sql);
    $statement->execute($parameters);

    return $statement;
}

/** @param array<string|int, mixed> $parameters
 *  @return array<string, mixed>|null
 */
function dbFetchOne(string $sql, array $parameters = []): ?array
{
    $row = dbQuery($sql, $parameters)->fetch();

    return $row === false ? null : $row;
}

/** @param array<string|int, mixed> $parameters
 *  @return list<array<string, mixed>>
 */
function dbFetchAll(string $sql, array $parameters = []): array
{
    return dbQuery($sql, $parameters)->fetchAll();
}

/** @param array<string|int, mixed> $parameters */
function dbExecute(string $sql, array $parameters = []): int
{
    return dbQuery($sql, $parameters)->rowCount();
}

function dbInsertId(): string
{
    return dbConnection()->lastInsertId();
}

function dbAgora(): string
{
    return gmdate('Y-m-d H:i:s');
}

/** @return array<string, string> */
function dbReadEnvironment(): array
{
    $keys = ['DB_DRIVER', 'DB_SQLITE_PATH', 'DB_HOST', 'DB_PORT', 'DB_DATABASE', 'DB_USERNAME', 'DB_PASSWORD'];
    $values = [];

    foreach ($keys as $key) {
        $value = getenv($key);
        if ($value !== false) {
            $values[$key] = $value;
        }
    }

    return $values;
}
