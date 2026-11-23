# Servidor MCP

O IEPRCard oferece MCP remoto por Streamable HTTP stateless: cada POST é autenticado com OAuth, validado por escopo e tenant, limitado por cliente e auditado sem persistir argumentos, segredos ou PII. O serviço não depende de daemon residente. Transporte, autenticação, rate limit e auditoria estão descritos no [ADR-0009](adr/0009-mcp-escopos-e-patch-seguro.md); este documento registra o contrato atual das tools.

## Tools disponíveis no runtime

`tools/list` retorna somente as tools cujo escopo consta no token. A resposta e os argumentos são limitados ao contrato de cada tool.

| Tool | Escopo | Argumentos | Resultado |
| --- | --- | --- | --- |
| `system.read` | `system.read` | `{}` | Estado lógico mínimo e versão pública do protocolo. |
| `telemetry.read` | `telemetry.read` | `{"from":"YYYY-MM-DD","to":"YYYY-MM-DD"}` | Agregados diários da Sede vinculada ao cliente OAuth e de suas unidades, em janela UTC inclusiva de até 366 dias. Não aceita IDs de tenant ou unidade. |
| `db.inspect` | `db.inspect` | `{}` ou `{"tabela":"nome_canônico"}` | Catálogo canônico de tabelas/colunas, índices e chaves estrangeiras, mais divergências estruturais detectadas. Não lê linhas de aplicação, valores padrão, SQL ou credenciais. Tabela desconhecida, argumentos extras e valores malformados são rejeitados. |

As consultas de schema usam o catálogo e a introspecção dual SQLite/MySQL. Uma inspeção de tabela retorna apenas a estrutura e as divergências daquela tabela. O endpoint aplica o mesmo rate limit e registra o método/resultado no log de auditoria sem gravar os argumentos.

## Escopos sem tool implementada

O registro OAuth também aceita `design.read` e `batch.dry_run` para provisionamento futuro; esses escopos não tornam uma tool visível por si só. `design.write` aguarda decisão de escopo e aprovação específica. `code.patch` não está implementado. Não configure escopos esperando funcionalidades que não aparecem em `tools/list`.

O Gate 5 de `docs/12-quality-gates.md` continua pendente para validação ponta a ponta de edição/preview via agente externo e para as decisões de escrita. Não há tool MCP que altere designs ou arquivos no runtime atual.
