# Referência do domínio

Os termos canônicos e suas regras estão nas fontes abaixo; este índice evita definições concorrentes nos briefs de engenharia.

| Termo | Referência canônica |
| --- | --- |
| Administrador SaaS | [`docs/01` §2](../01-regras-de-negocio.md), [`SAD` §1.2](../architecture/SAD.md) |
| Cliente Master / Sede (tenant raiz) | [`docs/01` §1–3](../01-regras-de-negocio.md), [ADR-0003](../adr/0003-multi-tenancy-por-linha.md) |
| Subcliente (unidade operacional/tenant filho) | [`docs/01` §1–3](../01-regras-de-negocio.md), [ADR-0003](../adr/0003-multi-tenancy-por-linha.md) |
| Titular final (dono da carteirinha) | [`docs/01` §5](../01-regras-de-negocio.md) |
| Teto descendente de permissões | [`docs/04`](../04-rbac-e-permissoes.md), [ADR-0004](../adr/0004-rbac-teto-descendente-e-cotas.md) |

Invariante de leitura: `tenant_id` e `subcliente_id` delimitam dados, mas valores vindos do request nunca autorizam o acesso; autenticação e escopo do backend são obrigatórios. Ver [ADR-0003](../adr/0003-multi-tenancy-por-linha.md).
