# ADR-0002: Camada PDO dual SQLite → MySQL

| Campo | Valor |
| --- | --- |
| Status | Aceita (baseline `docs/02`, `docs/11` ISSUE-001/002/003) |
| Data | 2026-11-18 |
| Decisores | Arquiteto + time de engenharia |
| Relacionados | [SAD §5.1 e §7.1](../architecture/SAD.md), ADR-0005, ADR-0010, [Issue #102](https://github.com/agthinkindigital/IEPRCard/issues/102) |

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

### Instalação e configuração da conexão

A camada PDO dual não implica provisionar o banco pelo aplicativo. A instalação web aprovada em 2026-11-25 deve aceitar um MySQL/MariaDB já criado pelo operador, testar a conexão antes de persistir segredos e aplicar as migrações versionadas somente em um banco vazio. Não deve executar `CREATE DATABASE`, apagar tabelas nem reabrir a criação do primeiro superadmin quando uma conexão pós-instalação falhar. Credenciais ficam em arquivo privado fora de `public/`, com gravação atômica; variáveis de ambiente prevalecem. O superadmin pode testar ou atualizar a conexão sem visualizar a senha atual. Escopo e critérios executáveis estão na [Issue #102](https://github.com/agthinkindigital/IEPRCard/issues/102); mockup e instruções estão nos guias aprovados de instalação.

Essa capacidade está planejada, não implementada. Até a conclusão da #102, a conexão continua dependendo da configuração operacional existente.

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
- Se o schema de destino já contém o catálogo global `permissoes` da migration `0002`, ele só é aceito quando contagem e checksum coincidem com a origem; a transferência preserva esse catálogo e bloqueia qualquer outro dado preexistente.
- Revisão de SQL: nenhum `NOW()`, `ENUM`, `JSON_EXTRACT` ou concatenação de parâmetro fora do wrapper.
