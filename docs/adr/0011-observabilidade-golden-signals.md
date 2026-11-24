# ADR-0011: Observabilidade com `correlation_id` e golden signals

| Campo | Valor |
| --- | --- |
| Status | Decisão aceita em 2026-11-24; implementação pendente |
| Data | 2026-11-18 |
| Decisores | Arquiteto + operações |
| Relacionados | [SAD §8](../architecture/SAD.md), ADR-0006, ADR-0009, ADR-0012 |

## Contexto

`docs/08` descreve telemetria de **negócio** (carteirinhas geradas, titulares, conversas, cota, QR, receita por nível Admin/Cliente/Subcliente) e o painel de lotes já mostra demanda, previsão e capacidade recomendada. Ainda falta observabilidade de requisições: latência e erros por rota, sinais de saturação e rastreabilidade de um lote desde o POST até o envio no WhatsApp. O MCP cria um `correlation_id` para sua própria auditoria, mas esse identificador não é gerado nem propagado de forma geral. Sem isso, um incidente de "carteirinha não chegou" leva a caçar logs por IP e timestamp, sem conseguir correlacionar as camadas.

## Decisão

- Todo request recebe `correlation_id` (UUID v4) no entrypoint; ele é propagado para logs, outbox, worker, chamadas a gateways, webhooks e MCP, e devolvido no header da resposta.
- Eventos técnicos estruturados serão persistidos em armazenamento próprio e consultáveis somente pelo superadmin. A implementação não reutiliza `telemetria_eventos`, que permanece exclusiva à telemetria de negócio.
- O evento técnico usa apenas campos allowlisted necessários ao diagnóstico, como correlation ID, chave de evento/rota, duração, status, severidade e horário UTC. Não registrar CPF, identificadores de titular/usuário, IP/UA, credenciais, query string, headers, bodies ou mensagem livre de exceção. O uso de IDs internos de Sede/unidade ainda aguarda confirmação registrada na [E08](https://github.com/agthinkindigital/IEPRCard/issues/93).
- A retenção dos eventos é finita e será expurgada pelo cron; o prazo exato ainda aguarda confirmação na [E08](https://github.com/agthinkindigital/IEPRCard/issues/93).
- Golden signals instrumentados no gateway/entrypoint:
  - **Latência:** p95/p99 por rota e por motor de render;
  - **Tráfego:** req/s por tenant, lotes/h, carteirinhas/h, leituras QR/h;
  - **Erros:** 5xx, `COTA_EXCEDIDA`, webhook rejeitado, falha de render, DLQ;
  - **Saturação:** disco, conexões MySQL, `pm.max_children`, backlog da fila, CPU.
- Telemetria de negócio, operacional e técnica ficam separadas: `telemetria_eventos` é de negócio; métricas de lote alimentam dashboards operacionais; eventos técnicos são acessíveis somente pelo superadmin. Dados e métricas obedecem à política de privacidade em `docs/01` e `docs/08`.
- Métricas operacionais de lote registram demanda diária, tempo em fila, duração, throughput, falhas/retries e saturação. `duracao_media_ms` usa média ponderada de milissegundos por registro processado. O painel mostra lote recomendado e pico previsto sobre janela X configurável; o cálculo inicial usa P95 dos picos diários, com 30 dias propostos como padrão e histórico insuficiente sinalizado abaixo de 7 dias.
- O Administrador edita lote e pico manualmente. A aplicação salva uma versão pendente; a ação `Reiniciar worker e aplicar` solicita a ativação. Em hospedagem compartilhada, a próxima execução agendada do worker aplica a versão; lotes em andamento mantêm a versão original. Ver ADR-0012 para o perfil de execução.
- Alertas iniciais: cota > 80%, DLQ > 0, 5xx sustentado, WebP > 80 ms, disco > 80%, trial expirando, token de suporte utilizado.
- OpenTelemetry fica como evolução; a base de correlação ponta-a-ponta será entregue pela E08 antes de afirmar rastreabilidade geral.

## Estado de implementação

- A telemetria de negócio e a telemetria operacional diária de lotes estão implementadas e documentadas em [`docs/08-telemetria.md`](../08-telemetria.md), incluindo painel por perfil, previsão configurável e aplicação de ajustes no início do worker.
- A observabilidade técnica não está implementada de forma geral: entrypoints HTTP não compartilham um `correlation_id` e o identificador não atravessa jobs/outbox e integrações. A auditoria MCP é local ao protocolo MCP e não comprova correlação ponta a ponta.
- O mantenedor escolheu persistência técnica separada e consulta exclusiva ao superadmin. A retenção proposta e os campos de escopo seguem em confirmação; mockup e guia da interface precisam de aprovação antes de implementar a tela. A fatia está registrada na [E08](https://github.com/agthinkindigital/IEPRCard/issues/93).

## Alternativas consideradas

| Alternativa | Prós | Contras | Veredito |
| --- | --- | --- | --- |
| Reaproveitar `telemetria_eventos` para tudo | Uma só tabela | Mistura métrica financeira com latência; risco de expor infraestrutura ao Subcliente e PII em metadados | Rejeitada |
| Log de texto livre com timestamp | Zero mudança | Impossível correlacionar lote assíncrono; alertas frágeis | Rejeitada |
| Eventos técnicos estruturados em armazenamento próprio, com correlation ID e consulta superadmin | Diagnóstico consultável em hospedagem compartilhada, separação da telemetria de negócio e controle de acesso central | Acrescenta escrita e retenção no banco; exige campos allowlisted, expurgo e paginação | Aceita |

## Consequências

**Positivas:** incidentes de emissão e integração são rastreáveis por um correlation ID comum; o superadmin pode consultar eventos sem depender dos logs do provedor.

**Negativas:** eventos técnicos aumentam I/O e ocupação do banco; a retenção limitada, paginação e proibição de PII/conteúdo livre são obrigatórias para manter o perfil PHP compartilhado.

## Como validar

- Spec: 100% dos logs de um fluxo de lote compartilham o mesmo `correlation_id`, do POST ao envio pela outbox.
- O painel de consulta é exclusivo ao superadmin e paginado; nenhum cliente, unidade ou tool MCP acessa eventos técnicos.
- Nenhum CPF, identificador de pessoa, token, IP/UA, query string ou payload aparece nos eventos; o expurgo remove registros fora do prazo configurado.
- O painel distingue versão pendente e ativa, exibe a janela do cálculo e aplica configurações somente após o pedido explícito de reinício do worker.
