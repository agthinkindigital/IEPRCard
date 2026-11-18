# IEPRCard

Projeto de sistema multi-tenant para emissão e validação de carteirinhas digitais e impressas. A fundação PDO dual, o schema canônico e a transferência controlada entre bancos estão implementados em E01.1–E01.3; o escopo e a ordem de trabalho ficam nas [issues do GitHub](https://github.com/agthinkindigital/IEPRCard/issues).

## Banco e testes

Configure `APP_ENV` e as variáveis do banco no ambiente do processo PHP; `.env.example` lista os nomes, mas o projeto não carrega arquivos `.env`. SQLite é o padrão local. `APP_ENV=dev|test` habilita self-healing; o padrão seguro é `production`, que somente inspeciona drift. Migrações versionadas são aplicadas explicitamente por `schemaAplicarMigracoes(PDO $pdo, 'production')` em `database/migrations/runner.php`. O catálogo executável de tabelas, colunas, índices e FKs fica em `database/schema/0001/`, referenciado pelo [ADR-0005](docs/adr/0005-schema-canonico-unificado.md) e pelo SAD §4.6.

Habilite `pdo_sqlite` para rodar `php tests/Integration/DualDatabaseSelfHealingTest.php`. A suíte completa `php tests/run_all.php` também exige `pdo_mysql` e `TEST_MYSQL_HOST`, `TEST_MYSQL_DATABASE` e `TEST_MYSQL_USERNAME`; se o smoke test MySQL for ignorado, o runner retorna código diferente de zero. O CI usa serviços descartáveis MySQL 8.4 e MariaDB 10.11.

Para transferir dados, prepare um destino MySQL vazio com o schema canônico, configure `DB_DRIVER=mysql` e as demais variáveis do MySQL, defina `MIGRATION_SOURCE_SQLITE_PATH` para o arquivo de origem e execute `php database/migrate-sqlite-to-mysql.php` pelo CLI. A rotina não altera schemas nem aceita destinos com registros; gera contagens e hashes SHA-256 por tabela sem incluir dados pessoais.

## Documentação

- [Visão e objetivo do produto](docs/00-visao-e-idealizacao.md)
- [Regras de negócio](docs/01-regras-de-negocio.md)
- [Arquitetura detalhada (SAD)](docs/architecture/SAD.md)
- [Issues e escopo de trabalho](docs/11-issues.md)
- [Roadmap](docs/14-roadmap.md)
- [Decisões arquiteturais](docs/adr/)

Consulte o SAD para a arquitetura vigente e os ADRs para decisões específicas. Os demais documentos detalham requisitos e módulos do produto.

## Branches

- `DEV` é a branch de trabalho e validação.
- `PROD` é a branch principal estável. Recebe somente mudanças verificadas, revisadas e promovidas de `DEV`; não faça push direto nela.

As instruções para agentes estão em [AGENTS.md](AGENTS.md).
