# ADR-0002: Camada PDO dual SQLite → MySQL

| Campo | Valor |
| --- | --- |
| Status | Aceita (baseline `docs/02`, `docs/11` ISSUE-001/002/003) |
| Data | 2026-11-18 |
| Decisores | Arquiteto + time de engenharia |
| Relacionados | [SAD §5.1](../architecture/SAD.md), ADR-0005, ADR-0010 |

## Contexto

`docs/00` exige deploy instantâneo e portabilidade: SQLite no início (testes rápidos, arquivo único), MySQL/MariaDB em produção. A migração prevista em `docs/11` ISSUE-003 deve transferir um banco populado com zero perda de chaves estrangeiras.

## Decisão

Centralizar todo acesso em `config/conn.php`, com:

- `DB_DRIVER=sqlite|mysql` como única chave de troca;
- wrappers `dbQuery`, `dbFetchOne`, `dbFetchAll`, `dbExecute`, `dbInsertId`, `dbAgora`;
- prepared statements obrigatórios; nenhuma interpolação de valor do usuário;
- datas ISO `YYYY-MM-DD HH:MM:SS` geradas no PHP (`dbAgora()`), nunca `NOW()` cru;
- proibição de `ENUM` (`VARCHAR(30)` + validação de domínio em PHP) e de JSON nativo (`TEXT`);
- SQLite com `PRAGMA journal_mode=WAL` e `foreign_keys=ON`; MySQL com InnoDB e `utf8mb4`.

## Alternativas consideradas

| Alternativa | Prós | Contras | Veredito |
| --- | --- | --- | --- |
| ORM com abstração de driver | Menos SQL manual, migrações prontas | Dependência pesada (viola ADR-0001), esconde índices e N+1, SQL dual não garantido | Rejeitada |
| Escrever SQL por driver | Aproveita recursos nativos de cada banco | Duplicação, divergência de comportamento, dobra a superfície de teste | Rejeitada |
| Trocar para MySQL apenas no produto com código idiomático | Simplicidade local | Impossibilita rodar a suíte TDD em SQLite efêmero como exige `docs/10` | Rejeitada |
| Wrapper PDO dual (escolhida) | Portabilidade, testes rápidos, zero dependência | Perde JSONB/GIN, `ON DUPLICATE KEY`, `RETURNING`; SQLite tem um escritor só | Aceita |

## Consequências

**Positivas:** alternar o driver não altera nenhum arquivo consumidor; migração automatizada validável por contagem/checksum; testes em memória.

**Negativas:** recursos nativos ficam indisponíveis; se um for necessário, criar helper com implementação por driver (nunca espalhar `if ($driver === 'mysql')`). Em pico de escrita, SQLite serializa — usar worker único de escrita e migração antecipada para MySQL (NFR-03).

## Como validar

- `TDD-01`: schema vazio nos dois drivers + self-healing sem perda de colunas.
- `ISSUE-003`: 1.000 registros migrados com checksum idêntico em < 5 s.
- Revisão de SQL: nenhum `NOW()`, `ENUM`, `JSON_EXTRACT` ou concatenação de parâmetro fora do wrapper.
