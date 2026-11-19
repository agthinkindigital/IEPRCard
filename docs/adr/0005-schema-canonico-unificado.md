# ADR-0005: Schema canônico unificado e nomes de colunas

| Campo | Valor |
| --- | --- |
| Status | Aceita; catálogo executável implementado em E01.2; validação MySQL/MariaDB pendente |
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

Schema-alvo do produto (32 tabelas): `planos`, `plano_feature_flags`, `tenants`, `dominios_tenant`, `perfis_acesso`, `permissoes`, `usuario_permissoes`, `usuarios`, `categorias_titular`, `designs_carteirinha`, `lotes_importacao`, `titulares`, `carteirinhas_emitidas`, `pagamentos_saas`, `pagamentos_titular`, `contas_recebedoras`, `repasses_titular`, `telemetria_eventos`, `telemetria_diaria`, `auditoria_processos`, `acessos_suporte`, `mensagens_whatsapp`, `configuracoes_gateway`, `ia_config`, `webhooks_recebidos`, `outbox_eventos`, `reservas_cota`, `mcp_api_keys`, `mcp_audit_logs`, `configuracoes_operacionais`, `metricas_lotes_diarias`, `solicitacoes_anonimizacao`.

O schema mínimo do MVP tem 27 tabelas: exclui `pagamentos_saas`, `pagamentos_titular`, `contas_recebedoras`, `repasses_titular` e `configuracoes_gateway`. Cobranças, checkout, split, repasse e webhooks de Asaas/Mercado Pago estão fora do MVP. Os cinco objetos ficam reservados para integração futura; referências de domínio estão em `docs/07` e ADR-0008. As três últimas tabelas apoiam métricas e versões de operação (ADR-0011) e pedidos de anonimização (política em `docs/01`, execução e backup em ADR-0012). A migração `0004` adiciona os marcos de inativação/anonimização e os erros operacionais da fila; a referência do titular fica cifrada e é removida após a conclusão.

**Atualização obrigatória:** reescrever `TDD-01` para validar as 27 tabelas do MVP e a paridade SQLite/MySQL. As cinco tabelas de pagamentos só entram no contrato de schema quando essa fase futura for priorizada. A asserção "15 tabelas" deixa de existir.

O catálogo executável das 27 tabelas, colunas, tipos, índices e FKs vive em `database/schema/0001/`; o SAD §4.6 mantém o DER conceitual e aponta para esse catálogo, sem duplicar o dicionário físico. Diretórios de versão são imutáveis; evoluções acrescentam uma versão e uma migração numerada. A migração `0001` aplica apenas a versão inicial, de forma repetível.

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

**Negativas:** exige atualização de `docs/03` e `docs/10` (fora deste artefato) e cuidado na interpretação de `tenant_id` em consultas de Subcliente.

## Como validar

- `mcp.db.inspect` aponta zero divergências entre SQLite e MySQL.
- `TDD-01` valida todas as tabelas canônicas e `EXPLAIN QUERY PLAN` dos índices de `SAD.md` §5.5.
- Nenhuma coluna `cliente_sede_id` em código novo sem autorização via nova ADR.
