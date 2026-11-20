# Hooks e eventos

O catálogo de eventos e filtros que existia neste documento descrevia uma arquitetura-alvo herdada, não comportamento presente no código. Atualmente não há implementação de `eventoDisparar()`, `hookAplicarFiltro()` nem de um dispatcher comum de eventos.

A arquitetura proposta para transações, outbox e efeitos externos está centralizada na [seção 6 do SAD](architecture/SAD.md) e no [ADR-0006](adr/0006-event-bus-e-outbox.md). O ADR permanece como proposta e não deve ser interpretado como sistema já implementado. O escopo futuro de WhatsApp/IA aguarda decisões do mantenedor na [Issue #5](https://github.com/agthinkindigital/IEPRCard/issues/5). Cobrança, checkout e webhooks Asaas/Mercado Pago permanecem fora do MVP, conforme [pagamentos e financeiro](07-pagamentos-e-financeiro.md) e [ADR-0008](adr/0008-webhooks-idempotentes-e-segredos.md).
