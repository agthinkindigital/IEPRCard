# ADR-0011: Observabilidade com `correlation_id` e golden signals

| Campo | Valor |
| --- | --- |
| Status | Proposta (preenche lacuna inexistente em `docs/00-17`) |
| Data | 2026-11-18 |
| Decisores | Arquiteto + operações |
| Relacionados | [SAD §8](../architecture/SAD.md), ADR-0006, ADR-0009, ADR-0012 |

## Contexto

`docs/08` descreve telemetria de **negócio** (carteirinhas geradas, titulares, conversas, cota, QR, receita por nível Admin/Cliente/Subcliente). Não existe nada para latência, erros, saturação ou rastreabilidade de um lote desde o POST até o envio no WhatsApp. Sem isso, um incidente de "carteirinha não chegou" leva a caçar logs por IP e timestamp, sem conseguir correlacionar as camadas.

## Decisão

- Todo request recebe `correlation_id` (UUID v4) no entrypoint; ele é propagado para logs, outbox, worker, chamadas a gateways, webhooks e MCP, e devolvido no header da resposta.
- Logs estruturados em JSON com `correlation_id`, `tenant_id`, `subcliente_id`, `ator_tipo`, `evento_chave`, `duracao_ms`, `status`, `nivel`. Nunca logar CPF, token, senha ou chave de API.
- Golden signals instrumentados no gateway/entrypoint:
  - **Latência:** p95/p99 por rota e por motor de render;
  - **Tráfego:** req/s por tenant, lotes/h, carteirinhas/h, leituras QR/h;
  - **Erros:** 5xx, `COTA_EXCEDIDA`, webhook rejeitado, falha de render, DLQ;
  - **Saturação:** disco, conexões MySQL, `pm.max_children`, backlog da fila, CPU.
- Telemetria de negócio e operacional ficam em domínios separados: `telemetria_eventos` (append-only, sem PII) é a de negócio; a operacional alimenta dashboards/alertas e não é exposta a Subclientes. Dados e métricas obedecem à política de privacidade em `docs/01` e `docs/08`.
- Métricas operacionais de lote registram demanda diária, tempo em fila, duração, throughput, falhas/retries e saturação. O painel mostra lote recomendado e pico previsto sobre janela X configurável; o cálculo inicial usa P95 dos picos diários, com 30 dias propostos como padrão e histórico insuficiente sinalizado abaixo de 7 dias.
- O Administrador edita lote e pico manualmente. A aplicação salva uma versão pendente; a ação `Reiniciar worker e aplicar` solicita a ativação. Em hospedagem compartilhada, a próxima execução agendada do worker aplica a versão; lotes em andamento mantêm a versão original. Ver ADR-0012 para o perfil de execução.
- Alertas iniciais: cota > 80%, DLQ > 0, 5xx sustentado, WebP > 80 ms, disco > 80%, trial expirando, token de suporte utilizado.
- OpenTelemetry fica como evolução; no MVP o `correlation_id` já dá rastreabilidade ponta-a-ponta.

## Alternativas consideradas

| Alternativa | Prós | Contras | Veredito |
| --- | --- | --- | --- |
| Reaproveitar `telemetria_eventos` para tudo | Uma só tabela | Mistura métrica financeira com latência; risco de expor infraestrutura ao Subcliente e PII em metadados | Rejeitada |
| Log de texto livre com timestamp | Zero mudança | Impossível correlacionar lote assíncrono; alertas frágeis | Rejeitada |
| Logs JSON + correlation id + golden signals separados (escolhida) | Diagnóstico rápido, alertas acionáveis, compatível com o baixo custo de infra | Exige disciplina de log e um coletor simples no servidor | Aceita |

## Consequências

**Positivas:** incidentes de emissão e pagamento são rastreáveis; Gate 4 e Gate 5 ganham evidência objetiva.

**Negativas:** mais campos em cada log e um ponto de agregação; manter a regra "sem PII em log" exige revisão nos parâmetros de webhook e IA.

## Como validar

- Spec: 100% dos logs de um fluxo de lote compartilham o mesmo `correlation_id`, do POST ao envio pela outbox.
- Dashboard com os quatro sinais por tenant e alertas configurados conforme a lista acima.
- Nenhum CPF/token aparece em log ou em `metadados_json` (teste de varredura).
- O painel distingue versão pendente e ativa, exibe a janela do cálculo e aplica configurações somente após o pedido explícito de reinício do worker.
