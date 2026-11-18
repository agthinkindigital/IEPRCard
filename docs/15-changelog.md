# Changelog

## 11. Padrão de Changelog e Controle de Evolução (`CHANGELOG.md`)

Toda alteração realizada por desenvolvedor ou agente via MCP deve atualizar obrigatoriamente o arquivo `CHANGELOG.md` na raiz do projeto, seguindo o padrão *Keep a Changelog* acoplado ao número da Issue e ao status do *Self-Healing* do banco:

```markdown
# Changelog — CardSaaS Monolith

## [0.4.0] - 2026-10-18 (Gate 4 Aprovado)
### Adicionado
- [ISSUE-016] Motor de Telemetria em 3 níveis (`includes/motor_telemetria.php`) com recortes isolados para Admin (14), Cliente (14.1) e Subcliente (14.2).
- [ISSUE-014] Dashboard de acompanhamento financeiro do Administrador (`admin/financeiro.php`) com cálculo de MRR e filtro por gateway.
### Adaptado dos Módulos Base
- [ISSUE-013] Portabilidade de `api/asaas.php`, `api/mercadopago.php`, `webhook_asaas.php` e `webhook_mercadopago.php` para wrapper PDO Dual (SQLite/MySQL) mantendo auto-registro de webhook e validação HMAC.
### Self-Healing DB
- Adicionada tabela `telemetria_eventos` e índices compostos `(tenant_id, subcliente_id, categoria, criado_em)`.

```
