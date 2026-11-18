# IEPRCard

Projeto de sistema multi-tenant para emissão e validação de carteirinhas digitais e impressas. A implementação começou pela camada de conexão PDO dual (E01.1); o escopo e a ordem de trabalho ficam nas [issues do GitHub](https://github.com/agthinkindigital/IEPRCard/issues).

## Banco e testes

Configure as variáveis do banco no ambiente do processo PHP; `.env.example` lista os nomes, mas o projeto não carrega arquivos `.env`. SQLite é o padrão local. Habilite `pdo_sqlite` para rodar `php tests/Integration/DualDatabaseSelfHealingTest.php`. A suíte completa `php tests/run_all.php` também exige `pdo_mysql` e `TEST_MYSQL_HOST`, `TEST_MYSQL_DATABASE` e `TEST_MYSQL_USERNAME`; se o smoke test MySQL for ignorado, o runner retorna código diferente de zero. O CI usa serviços descartáveis MySQL 8.4 e MariaDB 10.11.

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
