<?php
declare(strict_types=1);

require_once __DIR__ . '/TenantIsolationFixture.php';
require_once __DIR__ . '/BatchImportStagingTestSupport.php';
require_once dirname(__DIR__, 2) . '/database/migrations/runner.php';
require_once dirname(__DIR__, 2) . '/config/motor_permissoes.php';
require_once dirname(__DIR__, 2) . '/config/batch_import_parser.php';
require_once dirname(__DIR__, 2) . '/config/batch_import_staging.php';
require_once dirname(__DIR__, 2) . '/config/titular_read.php';

$previousIntegrationKey = getenv('IEPR_INTEGRATION_KEY');
$previousAnonymizationKey = getenv('IEPR_ANONYMIZATION_KEY');
putenv('IEPR_INTEGRATION_KEY=' . base64_encode(random_bytes(SODIUM_CRYPTO_SECRETBOX_KEYBYTES)));
putenv('IEPR_ANONYMIZATION_KEY=' . base64_encode(random_bytes(SODIUM_CRYPTO_SECRETBOX_KEYBYTES)));

foreach (tenantTestConnections() as $driver => $pdo) {
    schemaAplicarMigracoes($pdo, 'test');
    $suffix = bin2hex(random_bytes(5));
    $uploadDirectory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'ieprcard-read-' . $suffix;
    $storagePath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'ieprcard-read-storage-' . $suffix;
    $previousStoragePath = getenv('IEPR_PRIVATE_STORAGE_PATH');
    mkdir($uploadDirectory, 0700, true);
    putenv('IEPR_PRIVATE_STORAGE_PATH=' . $storagePath);
    $fixtures = [];
    $lotId = null;

    try {
        $testTenant = batchImportStagingTestCreateTenant($pdo, $suffix);
        $fixture = $testTenant['fixture'];
        $fixtures[] = $fixture;
        $identity = $testTenant['identity'];
        $context = $testTenant['context'];
        foreach (['titular.ver', 'titular.importar'] as $permission) {
            $pdo->prepare('INSERT INTO plano_feature_flags (plano_id, feature_key, habilitado) VALUES (:plan, :feature, 1)')
                ->execute(['plan' => $testTenant['plan_id'], 'feature' => $permission]);
        }
        $pdo->prepare('UPDATE tenants SET permissoes_unidade_json = :permissions WHERE id = :tenant')
            ->execute([
                'permissions' => json_encode(['titular.ver', 'titular.importar'], JSON_THROW_ON_ERROR),
                'tenant' => $fixture['tenant_id'],
            ]);

        $otherTenant = tenantTestSeed($pdo);
        $fixtures[] = $otherTenant;
        $pdo->prepare(
            'INSERT INTO titulares (tenant_id, nome_completo, cpf, matricula, ativo) VALUES (:tenant, :name, :cpf, :matricula, 1)'
        )->execute([
            'tenant' => $fixture['tenant_id'], 'name' => 'Ana Consulta', 'cpf' => '52998224725', 'matricula' => 'A-100',
        ]);
        $localId = (int) $pdo->lastInsertId();
        $pdo->prepare(
            'INSERT INTO titulares (tenant_id, nome_completo, cpf, matricula, ativo) VALUES (:tenant, :name, :cpf, :matricula, 1)'
        )->execute([
            'tenant' => $otherTenant['tenant_id'], 'name' => 'Pessoa Isolada', 'cpf' => '11144477735', 'matricula' => 'X-1',
        ]);

        $uploadPath = $uploadDirectory . DIRECTORY_SEPARATOR . 'titulares.csv';
        file_put_contents($uploadPath, "nome;matricula\nBruno Importado;B-200\n");
        $staged = batchImportStageFile($pdo, $context, $identity, $fixture['tenant_id'], null, $uploadPath, 'dados.csv');
        $lotId = $staged['lote_id'];
        $pdo->prepare('UPDATE lotes_importacao SET subcliente_id = :unit WHERE id = :id')
            ->execute(['unit' => $fixture['subcliente_id'], 'id' => $lotId]);
        $titular = $pdo->prepare(
            'INSERT INTO titulares (tenant_id, subcliente_id, lote_origem_id, nome_completo, matricula, ativo) '
            . 'VALUES (:tenant, :unit, :lot, :name, :matricula, 0)'
        );
        $titular->execute([
            'tenant' => $fixture['tenant_id'], 'unit' => $fixture['subcliente_id'], 'lot' => $lotId,
            'name' => 'Bruno Importado', 'matricula' => 'B-200',
        ]);
        $unitId = (int) $pdo->lastInsertId();
        $pdo->prepare(
            'INSERT INTO usuarios (tenant_id, subcliente_id, papel_sistema, nome, email, senha_hash, ativo) '
            . 'VALUES (:tenant, :unit, :role, :name, :email, :password, 1)'
        )->execute([
            'tenant' => $fixture['tenant_id'], 'unit' => $fixture['subcliente_id'], 'role' => 'responsavel_sub',
            'name' => 'Unit reader', 'email' => 'unit-reader-' . $suffix . '@example.test',
            'password' => password_hash('unused', PASSWORD_DEFAULT),
        ]);
        $unitIdentity = [
            'id' => (int) $pdo->lastInsertId(),
            'tenant_id' => $fixture['tenant_id'],
            'subcliente_id' => $fixture['subcliente_id'],
            'papel_sistema' => 'responsavel_sub',
        ];
        $unitContext = new TenantContext('subcliente', $fixture['tenant_id'], $fixture['subcliente_id'], []);

        $page = titularListar($pdo, $context, $identity, ['busca' => 'Ana', 'pagina' => 1, 'por_pagina' => 1]);
        if ($page['total'] !== 1 || count($page['items']) !== 1 || $page['items'][0]['id'] !== $localId
            || $page['items'][0]['cpf_mascarado'] !== '***.***.***-25'
            || $page['items'][0]['situacao'] !== 'ativo' || $page['items'][0]['origem'] !== 'manual'
            || array_key_exists('cpf', $page['items'][0])) {
            throw new RuntimeException($driver . ': titular list must be scoped, searchable and mask CPF values.');
        }
        $boundedPage = titularListar($pdo, $context, $identity, ['por_pagina' => 500]);
        if ($boundedPage['por_pagina'] !== 100 || $boundedPage['total'] !== 2
            || count($boundedPage['items']) !== 2
            || titularListar($pdo, $context, $identity, ['busca' => 'Pessoa Isolada'])['total'] !== 0) {
            throw new RuntimeException($driver . ': list pagination must cap at 100 and never return another tenant.');
        }
        $unitPage = titularListar($pdo, $context, $identity, ['subcliente_id' => $fixture['subcliente_id'], 'situacao' => 'inativo']);
        if ($unitPage['total'] !== 1 || $unitPage['items'][0]['id'] !== $unitId || $unitPage['items'][0]['origem'] !== 'csv') {
            throw new RuntimeException($driver . ': unit and status filters must use one bounded query with import origin.');
        }
        titularReadAssertDenied(
            static fn() => titularListar($pdo, $context, $identity, ['subcliente_id' => $otherTenant['subcliente_id']]),
            'TITULAR_READ_DENIED'
        );
        $operatorPage = titularListar($pdo, $unitContext, $unitIdentity);
        if ($operatorPage['total'] !== 1 || $operatorPage['items'][0]['id'] !== $unitId) {
            throw new RuntimeException($driver . ': a unit operator must read only its own unit scope.');
        }

        $before = $pdo->query('SELECT status_lote, arquivo_original_url FROM lotes_importacao WHERE id = ' . (int) $lotId)->fetch(PDO::FETCH_ASSOC);
        $pending = batchImportStagingLer($pdo, $context, $identity, $lotId);
        $after = $pdo->query('SELECT status_lote, arquivo_original_url FROM lotes_importacao WHERE id = ' . (int) $lotId)->fetch(PDO::FETCH_ASSOC);
        if ($pending['status'] !== 'aguardando_mapa' || $pending['colunas'] !== ['nome_completo', 'matricula']
            || $pending['amostra'][0]['nome_completo'] !== 'Bruno Importado'
            || array_key_exists('arquivo_original_url', $pending) || $before !== $after) {
            throw new RuntimeException($driver . ': pending staging read must reconstruct only the bounded sample without writes or private paths.');
        }
        $unitPending = batchImportStagingLer($pdo, $unitContext, $unitIdentity, $lotId);
        if ($unitPending['status'] !== 'aguardando_mapa' || $unitPending['amostra'][0]['nome_completo'] !== 'Bruno Importado') {
            throw new RuntimeException($driver . ': delegated unit import permission must allow reading only its own staged lot.');
        }

        $pdo->prepare("UPDATE lotes_importacao SET criado_em = '2000-01-01 00:00:00' WHERE id = :id")->execute(['id' => $lotId]);
        $expiredBefore = $pdo->query('SELECT status_lote, arquivo_original_url FROM lotes_importacao WHERE id = ' . (int) $lotId)->fetch(PDO::FETCH_ASSOC);
        $expired = batchImportStagingLer($pdo, $context, $identity, $lotId);
        $expiredAfter = $pdo->query('SELECT status_lote, arquivo_original_url FROM lotes_importacao WHERE id = ' . (int) $lotId)->fetch(PDO::FETCH_ASSOC);
        if ($expired['status'] !== 'expirado' || $expired['amostra'] !== [] || $expiredBefore !== $expiredAfter) {
            throw new RuntimeException($driver . ': expired staging reads must return a stable state without parsing or mutation.');
        }

        $pdo->prepare("UPDATE lotes_importacao SET criado_em = CURRENT_TIMESTAMP, arquivo_original_url = NULL WHERE id = :id")
            ->execute(['id' => $lotId]);
        $pdo->prepare('DELETE FROM importacao_linhas_staging WHERE lote_id = :id')->execute(['id' => $lotId]);
        $missingBefore = $pdo->query('SELECT status_lote, arquivo_original_url FROM lotes_importacao WHERE id = ' . (int) $lotId)->fetch(PDO::FETCH_ASSOC);
        $missing = batchImportStagingLer($pdo, $context, $identity, $lotId);
        $missingAfter = $pdo->query('SELECT status_lote, arquivo_original_url FROM lotes_importacao WHERE id = ' . (int) $lotId)->fetch(PDO::FETCH_ASSOC);
        if ($missing['status'] !== 'arquivo_indisponivel' || $missing['amostra'] !== []
            || $missing['erros'] !== [['codigo' => 'IMPORT_STAGING_UNAVAILABLE']] || $missingBefore !== $missingAfter) {
            throw new RuntimeException($driver . ': missing encrypted staging rows must return only a stable coded state without mutation.');
        }

        $pdo->prepare("UPDATE lotes_importacao SET status_lote = 'cancelado' WHERE id = :id")->execute(['id' => $lotId]);
        $terminal = batchImportStagingLer($pdo, $context, $identity, $lotId);
        if ($terminal['status'] !== 'cancelado' || $terminal['amostra'] !== [] || $terminal['erros'] !== []) {
            throw new RuntimeException($driver . ': terminal staging read must not parse or return prior personal data.');
        }

        $limitedIdentity = $identity;
        $limitedIdentity['papel_sistema'] = 'titular_final';
        titularReadAssertDenied(static fn() => titularListar($pdo, $context, $limitedIdentity), 'TITULAR_READ_DENIED');
        titularReadAssertDenied(static fn() => batchImportStagingLer($pdo, $context, $limitedIdentity, $lotId), 'TITULAR_IMPORT_READ_DENIED');
        titularReadAssertDenied(static fn() => batchImportStagingLer(
            $pdo, new TenantContext('cliente', $fixture['tenant_id'] + 1000, null, []), $identity, $lotId
        ), 'TITULAR_IMPORT_READ_DENIED');
    } finally {
        if (is_string($lotId)) {
            $lotId = (int) $lotId;
        }
        if (is_int($lotId)) {
            $pdo->prepare('DELETE FROM titulares WHERE lote_origem_id = :lot')->execute(['lot' => $lotId]);
            $pdo->prepare('DELETE FROM lotes_importacao WHERE id = :id')->execute(['id' => $lotId]);
        }
        foreach ($fixtures as $fixture) {
            $pdo->prepare('DELETE FROM titulares WHERE tenant_id = :tenant')->execute(['tenant' => $fixture['tenant_id']]);
            $pdo->prepare('DELETE FROM usuarios WHERE tenant_id = :tenant')->execute(['tenant' => $fixture['tenant_id']]);
        }
        foreach (array_reverse($fixtures) as $fixture) {
            tenantTestCleanup($pdo, $fixture);
        }
        batchImportStagingTestRemoveTree($storagePath);
        if (is_dir($uploadDirectory)) {
            foreach (scandir($uploadDirectory) ?: [] as $entry) {
                if ($entry !== '.' && $entry !== '..') {
                    unlink($uploadDirectory . DIRECTORY_SEPARATOR . $entry);
                }
            }
            rmdir($uploadDirectory);
        }
        if ($previousStoragePath === false) {
            putenv('IEPR_PRIVATE_STORAGE_PATH');
        } else {
            putenv('IEPR_PRIVATE_STORAGE_PATH=' . $previousStoragePath);
        }
    }
}

$previousIntegrationKey === false ? putenv('IEPR_INTEGRATION_KEY') : putenv('IEPR_INTEGRATION_KEY=' . $previousIntegrationKey);
$previousAnonymizationKey === false ? putenv('IEPR_ANONYMIZATION_KEY') : putenv('IEPR_ANONYMIZATION_KEY=' . $previousAnonymizationKey);

function titularReadAssertDenied(callable $operation, string $expectedCode): void
{
    try {
        $operation();
    } catch (DomainException|RuntimeException $exception) {
        if ($exception->getMessage() !== $expectedCode) {
            throw $exception;
        }

        return;
    }

    throw new RuntimeException('The titular read operation was expected to fail with ' . $expectedCode . '.');
}
