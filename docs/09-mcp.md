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

O registro OAuth também aceita `design.read` e `batch.dry_run` para provisionamento futuro; esses escopos não tornam uma tool visível por si só. `design.write` e `code.patch` não estão implementados.

## Manutenção interna por MCP

O mantenedor confirmou que manutenção e correção assistidas por IA via MCP fazem parte do MVP, sem acesso MCP para clientes. O superadmin deverá gerar uma autorização de controle com validade escolhida entre `30m`, `1h`, `3h`, `6h`, `12h` e `24h`, selecionando um conjunto de tenants e/ou unidades; após expirar, uma nova autorização será necessária. Cada chamada deverá validar o alvo contra esse conjunto. Ainda falta definir se selecionar uma Sede inclui suas unidades. A definição de quais operações a IA pode executar, de quais mutações exigem aprovação e do tratamento de código/schema está pendente na [Issue #89](https://github.com/agthinkindigital/IEPRCard/issues/89). Até esse contrato ser fechado e revisado, o runtime continua read-only e não há autorização para SQL arbitrário ou `code.patch`.

O Gate 5 já validou o transporte e as tools read-only com cliente MCP externo na [Issue #90](https://github.com/agthinkindigital/IEPRCard/issues/90). Essa evidência não cobre a autorização temporária nem tools de manutenção; a revisão de segurança e os testes dessa extensão serão necessários antes de considerar o suporte remoto entregue.
