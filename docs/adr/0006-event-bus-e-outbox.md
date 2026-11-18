# ADR-0006: Event bus interno com outbox transacional

| Campo | Valor |
| --- | --- |
| Status | Proposta (evolução de `docs/05`) |
| Data | 2026-11-18 |
| Decisores | Arquiteto + time de engenharia |
| Relacionados | [SAD §6.1](../architecture/SAD.md), ADR-0001, ADR-0008 |

## Contexto

`docs/05` define `eventoDisparar()` e `hookAplicarFiltro()` e uma lista de eventos cujos listeners enviam WhatsApp, gravam telemetria e notificam operadores. Se o listener chamar a API externa dentro do mesmo request, dois falhas graves aparecem: (1) a API externa pode travar o request do operador; (2) se o commit do banco falhar depois do envio, o titular recebe uma carteirinha que não existe. O ponto é atomicidade: efeito externo precisa nascer da mesma transação que o estado.

## Decisão

- Mantêm-se `eventoDisparar()` e `hookAplicarFiltro()` como primitivas de domínio.
- Listener de efeito **externo** não chama a API diretamente: grava linha em `outbox_eventos` com `evento_chave`, `payload_json`, `status='pendente'`, `tentativas=0`, `proxima_tentativa_em`, na **mesma transação** da mudança de estado.
- `cron.php`/worker drena a outbox em lotes com retry exponencial (1 min → 5 min → 25 min → 2 h), circuit breaker por provedor (Asaas, MP, WhatsApp, IA, e-mail) e DLQ após 5 tentativas.
- Cada envio é idempotente por chave de negócio (`id_evento + destino`), reutilizando os índices únicos já previstos para webhooks.
- Efeitos puramente internos (telemetria, auditoria, debite de cota) continuam síncronos na mesma transação.

## Alternativas consideradas

| Alternativa | Prós | Contras | Veredito |
| --- | --- | --- | --- |
| Chamar API externa no listener | Implementação mais curta | Perda de efeito, travamento do request, sem retry | Rejeitada |
| RabbitMQ / Kafka | Retry, DLQ e ordenação nativos | Infraestrutura adicional, custo, operação e contratos para um time pequeno | Rejeitada agora |
| Redis + fila | Rápido e leve | Persistência e DLQ frágeis se mal configurado; nova dependência | Rejeitada agora |
| Outbox em banco (escolhida) | Atomicidade com o estado, zero infra nova, compatível com SQLite/MySQL | Requer worker e limpeza de registros antigos; latência de entrega de segundos | Aceita |

## Consequências

**Positivas:** nenhum "banco commitou, WhatsApp não enviou"; retry automático e DLQ observável; a telemetria continua simples.

**Negativas:** entrega eventual (segundos a minutos) — aceitável para WhatsApp/e-mail; tabela cresce e precisa de retenção (24 meses) e índice `(status, proxima_tentativa_em)`.

## Como validar

- Teste de integração: simular falha da API de WhatsApp → outbox permanece `pendente`, worker reprocessa e marca `enviado` sem duplicar mensagem.
- Alerta operacional: `DLQ > 0` ou `tentativas >= 5` (SAD §8.1).
- Nenhuma chamada `curl`/SDK externa dentro de `motor_eventos.php`.
