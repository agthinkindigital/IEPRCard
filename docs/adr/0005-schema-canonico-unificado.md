# ADR-0005: Schema canônico unificado e nomes de colunas

| Campo | Valor |
| --- | --- |
| Status | Aceita; schema e paridade SQLite/MySQL/MariaDB verificados em E01.2 |
| Data | 2026-11-18 |
| Decisores | Arquiteto + `/developer` + `/qa-analyst` |
| Relacionados | [SAD §1.4 C1–C4, §4.6, §5.5](../architecture/SAD.md), ADR-0002, ADR-0010 |

## Contexto

`docs/03` contém **dois ERDs divergentes**. O primeiro usa `tenant_id`/`subcliente_id` e `USUARIOS`; o adendo usa `cliente_sede_id`/`subcliente_id`, `USUARIOS_EQUIPE` e `CATEGORIAS_TITULAR`. `docs/03` §2.2 cita `configuracoes_pagamento`, enquanto `docs/07` cita `configuracoes_gateway`. Além disso, `TDD-01` afirma "todas as 15 tabelas", mas o conjunto consolidado (RBAC, auditoria, webhooks, outbox, MCP) passa de 20. Sem uma decisão, cada agente executor cria um nome diferente e a suíte de testes vira especificação inválida.

## Decisão

Adotar um **único dicionário canônico**, versionado em `docs/architecture/SAD.md` §4.6:

| Conceito | Nome canônico | Regra |
| --- | --- | --- |
| Cliente pagante (Sede) | `tenant_id` | FK raiz em todas as tabelas filhas; `tenants.parent_id IS NULL` |
| Unidade operacional | `subcliente_id` | `NULL` = dados da Sede; preenchido = unidade |
| Operadores | `usuarios` | colunas `perfil_acesso_id`, `papel_sistema`, `criado_por_usuario_id` |
| Perfis granulares | `perfis_acesso`, `permissoes`, `usuario_permissoes` | substitui `papeis`/`papel_permissoes` |
| Credenciais de gateway | `configuracoes_gateway` | tabela futura; linha única `id = 1`, valores cifrados quando integração financeira entrar no escopo |
| Estado da conta | `status_conta` + `regime_cobranca` + `isento_pagamento` | uma única máquina de estados (SAD §5.4) |
| Eventos externos confiáveis | `outbox_eventos` | ver ADR-0006 |

Schema-alvo atual (35 tabelas): `planos`, `plano_feature_flags`, `tenants`, `dominios_tenant`, `perfis_acesso`, `permissoes`, `usuario_permissoes`, `usuarios`, `categorias_titular`, `designs_carteirinha`, `lotes_importacao`, `titulares`, `carteirinhas_emitidas`, `pagamentos_saas`, `pagamentos_titular`, `contas_recebedoras`, `repasses_titular`, `telemetria_eventos`, `telemetria_diaria`, `auditoria_processos`, `acessos_suporte`, `mensagens_whatsapp`, `whatsapp_conversas`, `configuracoes_gateway`, `ia_config`, `ia_config_global`, `webhooks_recebidos`, `outbox_eventos`, `reservas_cota`, `mcp_api_keys`, `mcp_audit_logs`, `configuracoes_operacionais`, `metricas_lotes_diarias`, `solicitacoes_anonimizacao`, `configuracoes_instalacao` (revisões globais de instalação; ADR-0003).

O schema mínimo do MVP exclui `pagamentos_saas`, `pagamentos_titular`, `contas_recebedoras`, `repasses_titular` e `configuracoes_gateway`. Cobranças, checkout, split, repasse e webhooks de Asaas/Mercado Pago estão fora do MVP. Os cinco objetos ficam reservados para integração futura; referências de domínio estão em `docs/07` e ADR-0008. A configuração global de IA usa `ia_config_global`; `ia_config` é mantida sem uso durante a janela de rollback definida no ADR-0010. As demais tabelas apoiam métricas e versões de operação (ADR-0011) e pedidos de anonimização (política em `docs/01`, execução e backup em ADR-0012).

**Atualização concluída:** `TDD-01` valida o catálogo do MVP e a paridade SQLite/MySQL. `docs/03` aponta ao DER conceitual do SAD e ao catálogo físico versionado, sem manter os ERDs divergentes anteriores. As cinco tabelas de pagamentos só entram no contrato de schema quando essa fase futura for priorizada; a asserção "15 tabelas" foi removida.

O catálogo executável de tabelas, colunas, tipos, índices e FKs vive em `database/schema/`; o SAD §4.6 mantém o DER conceitual e aponta para esse catálogo, sem duplicar o dicionário físico. Diretórios de versão são imutáveis; evoluções acrescentam uma versão e uma migração numerada. A extensão `0008` e a migração `0009` acrescentam a configuração global de IA sem alterar a tabela anterior; a aplicação passa a usar a tabela nova durante a janela de rollback.

A confirmação de importação mantém a origem sem copiar dados pessoais: a extensão de schema `0005` adiciona `titulares.lote_origem_id`, FK nullable para `lotes_importacao`, e a migração `0006` aplica a coluna em bancos existentes. Titulares criados manualmente permanecem com origem nula. O vínculo permite ao worker selecionar o conjunto importado e revalidar o escopo da Sede/unidade.

A extensão `0006` e a migração `0007` adicionam em `lotes_importacao` a origem do job de emissão e o hash opcional da chave de idempotência, com unicidade por tenant. A extensão `0007` e a migração `0008` adicionam `titular_origem_id`, FK opcional para emissão individual. Um job referencia exatamente um lote importado concluído ou um titular elegível; a segunda modalidade não altera `titulares.lote_origem_id`, que permanece nulo para cadastros manuais. A origem, design e modo integram a validação da chave idempotente.

Para `telemetria_diaria`, o índice único não pode depender apenas da coluna nullable `subcliente_id`: SQLite e MySQL permitem múltiplas chaves únicas com `NULL`, o que duplica agregados da Sede sob gravações concorrentes. A extensão `0004` adiciona `subcliente_scope_id`, normalizada para `0` na Sede e para o ID da unidade; a migração `0005` consolida agregados legados duplicados antes do backfill. Escritas novas sempre usam essa chave normalizada.

**Migração:** em bancos já criados com o adendo, usar `ALTER TABLE ... ADD COLUMN` + view de compatibilidade, sem `DROP` na mesma release (Expand & Contract, ADR-0010). Nomes antigos sobrevivem como views somente leitura por uma release.

## Alternativas consideradas

| Alternativa | Prós | Contras | Veredito |
| --- | --- | --- | --- |
| Manter os dois ERDs e aceitar aliases | Nenhuma mudança inicial | Ambiguidade permanente, testes incoerentes, bugs de escopo | Rejeitada |
| Adotar `cliente_sede_id` do adendo | Mais explícito semanticamente | Quebra `docs/02`, `docs/04`, `docs/08` e quase todas as issues; custo de renomeio maior | Rejeitada |
| Adotar `tenant_id` como canônico (escolhida) | Alinhado com a maioria dos documentos e com o TenantScope; menos retrabalho | Exige documentar que `tenant_id` sempre significa a Sede, nunca o tenant imediato | Aceita |

## Consequências

**Positivas:** uma linguagem ubíqua para developer, QA e MCP `db.inspect`; TDD-01 passa a ser especificação confiável.

**Negativas:** exige cuidado na interpretação de `tenant_id` em consultas de Subcliente. O catálogo e a atualização de `docs/03` estão concluídos; o número físico de tabelas é derivado do catálogo.

## Como validar

- `mcp.db.inspect` aponta zero divergências entre SQLite e MySQL.
- `TDD-01` valida todas as tabelas canônicas e `EXPLAIN QUERY PLAN` dos índices de `SAD.md` §5.5.
- Nenhuma coluna `cliente_sede_id` em código novo sem autorização via nova ADR.
