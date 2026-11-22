# ADR-0010: Self-healing restrito a dev/test e migrações expand-only

| Campo | Valor |
| --- | --- |
| Status | Aceita; implementação em E01.2; validação MySQL/MariaDB pendente |
| Data | 2026-11-18 |
| Decisores | Arquiteto + `/developer` + `/qa-analyst` |
| Relacionados | [SAD §1.4 C7, §7.2, §9 R1](../architecture/SAD.md), ADR-0002, ADR-0005 |

## Contexto

`config/schema_self_healing.php` foi desenhado para criar tabelas e colunas faltantes usando `PRAGMA table_info()` (SQLite) e `SHOW COLUMNS` (MySQL), evitando migrações manuais. Isso é excelente para testes efêmeros e para o primeiro deploy, mas perigoso em produção: um schema executado por request pode alterar estrutura no meio de um lote, esconder divergência entre ambientes e, se uma rotina futura incluir `DROP`/renomeio, causar perda de dados sem revisão.

## Decisão

- `APP_ENV=dev|test`: self-healing habilitado, exatamente como no TDD-01.
- `APP_ENV=production`: self-healing roda apenas em **modo verificação** (`dry run`), reportando drift; nenhuma DDL é executada.
- Toda mudança de schema em produção passa por migração versionada, numerada, idempotente e aplicada no deploy antes do switch de release (ADR-0012).
- `schemaGarantirEstrutura()` cria tabelas, acrescenta colunas seguras e índices ausentes em dev/test. Colunas obrigatórias sem valor padrão exigem backfill explícito; FKs ausentes em tabelas preexistentes são reportadas para migração, pois SQLite não permite acrescentá-las com `ALTER TABLE` simples.
- `schemaAplicarMigracoes()` executa explicitamente arquivos numerados e exige o ambiente na chamada. Cada migração usa seu catálogo versionado e imutável; as versões ficam no repositório para manter o contrato canônico sem uma tabela de controle extra.
- Migrações seguem **Expand & Contract**:
  1. release A adiciona coluna/tabela nova (nullable) e mantém a antiga;
  2. release B migra leituras e escritas;
  3. release C (posterior, com janela de rollback encerrada) remove o legado.
- É proibido `DROP COLUMN`, `DROP TABLE` ou `RENAME` na mesma release que introduz a mudança.
- O contrato `mcp.db.inspect` passa a ser usado como detector de drift entre ambientes.

## Alternativas consideradas

| Alternativa | Prós | Contras | Veredito |
| --- | --- | --- | --- |
| Self-healing em todos os ambientes | Zero fricção, deploy instantâneo | DDL implícita em request, sem revisão, risco de perda de dados | Rejeitada |
| Migrações versionadas apenas | Segurança e rastreabilidade | Mais trabalho no ciclo de desenvolvimento | Presente apenas em produção |
| Híbrido por ambiente (escolhida) | Velocidade no dev, segurança em produção | Dois caminhos de schema a testar | Aceita |

## Consequências

**Positivas:** TDD-01 continua rápido e confiável; produção ganha previsibilidade de deploy e rollback.

**Negativas:** o time precisa escrever migrações versionadas — compensado pelo fato de que `schema_self_healing.php` já gera o DDL correto e pode ser usado como fonte para gerar a migração numerada.

## Como validar

- `TDD-01` passa em dev/test nos dois drivers.
- CI verifica que nenhuma migração de produção contém `DROP` sem ADR que autorize a remoção.
- `mcp.db.inspect` reporta zero drift após deploy em staging.
