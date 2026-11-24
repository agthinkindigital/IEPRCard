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

Grants de suporte exclusivos do superadmin têm validade `30m`, `1h`, `3h`, `6h`, `12h` ou `24h`. Cada grant escolhe várias Sedes/unidades; uma Sede congela as unidades existentes no momento. Capabilities valem durante toda a janela sem confirmação por chamada; cada chamada revalida grant, capability e alvos. O OAuth de clientes permanece separado e seus Bearer tokens duram cinco minutos. A tela superadmin configura o PAT cifrado e grants. A ferramenta `repo.publish` publica via GitHub API somente em `DEV`, com autoria Leonardo, arquivos de texto rastreados e checks reportados; não permite alterar configuração de CI. `PROD` requer PR, três checks de CI, enforcement de administradores e bloqueio de force-push/exclusão. Promoção continua revisada conforme [ADR-0012](adr/0012-ci-deploy-e-backup.md); a proteção atual não exige aprovação formal de outro colaborador, e o mantenedor revisa o PR antes de mesclar. Grants restaurados por snapshot são revogados; PAT e alvos congelados são omitidos. Tools mutáveis de dados seguem fora do catálogo; não há SQL arbitrário, shell ou filesystem irrestrito. O contrato de segurança detalhado está em [ADR-0009](adr/0009-mcp-escopos-e-patch-seguro.md).

O Gate 5 validou o transporte e as tools read-only com cliente MCP externo na [Issue #90](https://github.com/agthinkindigital/IEPRCard/issues/90). A autorização de suporte e publicação em `DEV` estão implementadas e cobertas pelos testes da [Issue #89](https://github.com/agthinkindigital/IEPRCard/issues/89); a revisão final de segurança e o CI foram aprovados em 2026-11-24. A promoção a `PROD` continua sujeita ao processo revisado e às proteções de branch.
