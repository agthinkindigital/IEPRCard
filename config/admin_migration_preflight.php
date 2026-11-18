<?php
declare(strict_types=1);

require_once __DIR__ . '/admin_migration.php';
require_once __DIR__ . '/conn.php';
require_once __DIR__ . '/schema_self_healing.php';
require_once dirname(__DIR__) . '/database/schema/inspect.php';
require_once dirname(__DIR__) . '/database/migration/sqlite_to_mysql.php';

function adminMigrationPreflight(): array
{
    $sourcePath = trim((string) (getenv('MIGRATION_SOURCE_SQLITE_PATH') ?: ''));
    if ($sourcePath === '' && strtolower((string) getenv('DB_DRIVER')) === 'sqlite') {
        $sourcePath = trim((string) (getenv('DB_SQLITE_PATH') ?: ''));
    }
    $target = adminMigrationTargetConfiguration();
    $sourcePdo = null;
    $targetPdo = null;
    $checks = [];
    $sourceLabel = $sourcePath !== '' ? basename($sourcePath) : 'Não configurada';
    $targetLabel = $target['database'] !== '' ? $target['database'] : 'Não configurado';
    $sourceRecords = [];
    $sourceTotal = 0;

    if ($sourcePath === '' || !is_file($sourcePath) || !is_readable($sourcePath)) {
        $checks[] = adminMigrationCheck('Arquivo de origem SQLite disponível', 'Bloqueado', 'Configure MIGRATION_SOURCE_SQLITE_PATH para um arquivo legível.');
    } else {
        try {
            $sourcePdo = dbConnect(['DB_DRIVER' => 'sqlite', 'DB_SQLITE_PATH' => $sourcePath]);
            $checks[] = adminMigrationCheck('Conexão com origem SQLite', 'Aprovado', 'Arquivo acessível; caminho completo oculto.');
        } catch (Throwable) {
            $checks[] = adminMigrationCheck('Conexão com origem SQLite', 'Bloqueado', 'Não foi possível abrir o arquivo configurado. Verifique o caminho e as permissões.');
        }
    }

    if ($target['database'] === '' || $target['username'] === '') {
        $checks[] = adminMigrationCheck('Conexão com destino MySQL/MariaDB', 'Bloqueado', 'Configure as variáveis MIGRATION_TARGET_* no ambiente do PHP.');
    } elseif (!in_array('mysql', PDO::getAvailableDrivers(), true)) {
        $checks[] = adminMigrationCheck('Conexão com destino MySQL/MariaDB', 'Bloqueado', 'A extensão PDO MySQL não está habilitada neste PHP.');
    } else {
        try {
            $targetPdo = dbConnect($target);
            $checks[] = adminMigrationCheck('Conexão com destino MySQL/MariaDB', 'Aprovado', 'Conexão estabelecida; credenciais ocultas.');
        } catch (Throwable) {
            $checks[] = adminMigrationCheck('Conexão com destino MySQL/MariaDB', 'Bloqueado', 'Não foi possível conectar. Revise a disponibilidade e as credenciais no ambiente.');
        }
    }

    $catalog = \IEPRCard\Database\Schema\catalogoCanonico();
    if ($sourcePdo instanceof PDO) {
        try {
            ieprMigracaoValidarSchema($sourcePdo, $catalog, 'origem');
            if ($sourcePdo->query('PRAGMA foreign_key_check')->fetchAll(PDO::FETCH_ASSOC) !== []) {
                throw new RuntimeException('foreign_keys');
            }
            $checks[] = adminMigrationCheck('Schema e integridade da origem', 'Aprovado', 'Schema canônico e referências válidas.');
            foreach ($catalog as $table => $_definition) {
                $count = (int) $sourcePdo->query('SELECT COUNT(*) FROM ' . \IEPRCard\Database\Schema\citar($table, 'sqlite'))->fetchColumn();
                $sourceRecords[$table] = $count;
                $sourceTotal += $count;
            }
        } catch (Throwable) {
            $checks[] = adminMigrationCheck('Schema e integridade da origem', 'Bloqueado', 'A origem não corresponde ao schema canônico ou contém referências inválidas.');
        }
    } else {
        $checks[] = adminMigrationCheck('Schema e integridade da origem', 'Bloqueado', 'Disponível após conectar à origem.');
    }

    $destinationRecords = 0;
    $destinationHasUnmatchedData = false;
    if ($targetPdo instanceof PDO) {
        $targetSchemaValid = false;
        $foreignKeysEnabled = false;
        try {
            ieprMigracaoValidarSchema($targetPdo, $catalog, 'destino');
            $targetSchemaValid = true;
            $checks[] = adminMigrationCheck('Schema do destino compatível', 'Aprovado', 'Schema canônico de 27 tabelas; sem alteração automática.');
        } catch (Throwable) {
            $checks[] = adminMigrationCheck('Schema do destino compatível', 'Bloqueado', 'O destino precisa ter o schema canônico de 27 tabelas; a tela não aplica alterações.');
        }
        if ($targetSchemaValid) {
            try {
                $foreignKeysEnabled = (int) $targetPdo->query('SELECT @@FOREIGN_KEY_CHECKS')->fetchColumn() === 1;
                $checks[] = adminMigrationCheck(
                    'Integridade referencial habilitada no destino',
                    $foreignKeysEnabled ? 'Aprovado' : 'Bloqueado',
                    $foreignKeysEnabled ? 'O servidor valida chaves estrangeiras.' : 'Habilite a verificação de chaves estrangeiras no servidor.'
                );
            } catch (Throwable) {
                $checks[] = adminMigrationCheck('Integridade referencial habilitada no destino', 'Bloqueado', 'Não foi possível confirmar a verificação de chaves estrangeiras.');
            }
        } else {
            $checks[] = adminMigrationCheck('Integridade referencial habilitada no destino', 'Bloqueado', 'Disponível após validar o schema do destino.');
        }
        if ($targetSchemaValid && $foreignKeysEnabled) {
            try {
                foreach ($catalog as $table => $_definition) {
                    $count = (int) $targetPdo->query('SELECT COUNT(*) FROM ' . \IEPRCard\Database\Schema\citar($table, 'mysql'))->fetchColumn();
                    $destinationRecords += $count;
                    if ($count === 0) {
                        continue;
                    }
                    if ($table === 'permissoes' && $sourcePdo instanceof PDO
                        && ieprMigracaoCatalogoPermissoesPrecarregadoConfere($sourcePdo, $targetPdo, $catalog)) {
                        continue;
                    }
                    $destinationHasUnmatchedData = true;
                }
                $destinationReady = !$destinationHasUnmatchedData;
                $destinationDetail = $destinationHasUnmatchedData
                    ? 'Há dados diferentes do catálogo de permissões correspondente à origem; a cópia foi bloqueada.'
                    : ($destinationRecords > 0
                        ? 'Somente o catálogo de permissões idêntico à origem já está presente e será preservado.'
                        : 'Nenhum registro será mesclado ou sobrescrito.');
                $checks[] = adminMigrationCheck(
                    'Destino pronto para receber',
                    $destinationReady ? 'Aprovado' : 'Bloqueado',
                    $destinationDetail
                );
            } catch (Throwable) {
                $checks[] = adminMigrationCheck('Destino pronto para receber', 'Bloqueado', 'Não foi possível confirmar as contagens e o catálogo preexistente do destino.');
            }
        } else {
            $checks[] = adminMigrationCheck('Destino pronto para receber', 'Bloqueado', 'Disponível após validar o schema e a integridade do destino.');
        }
    } else {
        $checks[] = adminMigrationCheck('Schema do destino compatível', 'Bloqueado', 'Disponível após conectar ao destino.');
        $checks[] = adminMigrationCheck('Integridade referencial habilitada no destino', 'Bloqueado', 'Disponível após conectar ao destino.');
        $checks[] = adminMigrationCheck('Destino pronto para receber', 'Bloqueado', 'Disponível após conectar ao destino.');
    }

    unset($sourcePdo, $targetPdo);
    $ready = count(array_filter($checks, static fn(array $check): bool => $check['status'] === 'Bloqueado')) === 0;

    return [
        'ready' => $ready,
        'validated_at' => time(),
        'source_name' => $sourceLabel,
        'target_name' => $targetLabel,
        'source_records' => $sourceTotal,
        'destination_records' => $destinationRecords,
        'table_estimates' => $sourceRecords,
        'checks' => $checks,
    ];
}

/** @return array<string, string> */
function adminMigrationTargetConfiguration(): array
{
    if (strtolower((string) getenv('DB_DRIVER')) === 'mysql') {
        return [
            'DB_DRIVER' => 'mysql',
            'DB_HOST' => (string) (getenv('DB_HOST') ?: 'localhost'),
            'DB_PORT' => (string) (getenv('DB_PORT') ?: '3306'),
            'DB_DATABASE' => (string) getenv('DB_DATABASE'),
            'DB_USERNAME' => (string) getenv('DB_USERNAME'),
            'DB_PASSWORD' => (string) getenv('DB_PASSWORD'),
            'database' => (string) getenv('DB_DATABASE'),
            'username' => (string) getenv('DB_USERNAME'),
        ];
    }
    return [
        'DB_DRIVER' => 'mysql',
        'DB_HOST' => (string) (getenv('MIGRATION_TARGET_HOST') ?: 'localhost'),
        'DB_PORT' => (string) (getenv('MIGRATION_TARGET_PORT') ?: '3306'),
        'DB_DATABASE' => (string) getenv('MIGRATION_TARGET_DATABASE'),
        'DB_USERNAME' => (string) getenv('MIGRATION_TARGET_USERNAME'),
        'DB_PASSWORD' => (string) getenv('MIGRATION_TARGET_PASSWORD'),
        'database' => (string) getenv('MIGRATION_TARGET_DATABASE'),
        'username' => (string) getenv('MIGRATION_TARGET_USERNAME'),
    ];
}

/** @return array{label: string, status: string, detail: string} */
function adminMigrationCheck(string $label, string $status, string $detail): array
{
    return ['label' => $label, 'status' => $status, 'detail' => $detail];
}
