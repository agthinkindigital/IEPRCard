# Pagamentos e Financeiro — referência futura

**Fora do MVP:** não implementar checkout, cobrança SaaS, taxa de emissão, split, repasse, painel financeiro ou webhooks Asaas/Mercado Pago. O código de webhook que o produto já possui é referência para uma fase futura; a integração deve ser reavaliada quando essa fase entrar no escopo.

Este documento preserva requisitos e decisões futuras para evitar perder o contexto. O modelo-alvo e suas tabelas estão descritos no SAD §4.6 e ADR-0008; não fazem parte do schema mínimo nem do comportamento de lançamento.

## Modelo futuro

- O Cliente Master será o pagador da assinatura SaaS; Subclientes não terão cobrança independente.
- A cobrança de taxa ao titular e o repasse ao Cliente exigirão uma integração de marketplace aprovada pelo provedor, onboarding de recebedores e registros de liquidação/reversão.
- Comissões, calendário de repasse e responsabilidades por estornos/chargebacks serão decididos antes de iniciar essa integração.
- O código de webhook Asaas/Mercado Pago disponível ao produto deve ser tratado como referência; sua segurança, compatibilidade e adequação serão revisadas antes de reutilização.

## Referências

Segurança, idempotência, credenciais e futuro tratamento de repasses: ADR-0008. Modelo-alvo: SAD §4.6. Escopo do MVP e backlog: `docs/11` e `docs/14`.

