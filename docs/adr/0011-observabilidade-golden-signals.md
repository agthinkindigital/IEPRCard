# ADR-0011: Observabilidade com `correlation_id` e golden signals

| Campo | Valor |
| --- | --- |
| Status | Correlação HTTP e persistência minimizada implementadas; consulta superadmin e propagação assíncrona pendentes |
| Data | 2026-11-18 |
| Decisores | Arquiteto + operações |
| Relacionados | [SAD §8](../architecture/SAD.md), ADR-0006, ADR-0009, ADR-0012 |

## Contexto

`docs/08` descreve telemetria de **negócio** e o painel de lotes. A fatia #94 gera e devolve correlation ID em todos os entrypoints HTTP e o reutiliza na auditoria MCP. A #95 persiste um evento minimizado no encerramento de cada request PHP coberto; ainda falta propagar o ID para jobs, outbox e integrações.

## Decisão

- Todo request recebe `correlation_id` (UUID v4) gerado no servidor e devolvido no header. A cobertura dos 30 entrypoints HTTP e a reutilização pelo MCP estão implementadas em #94; propagação para logs técnicos persistidos, outbox, worker, gateways e webhooks continua pendente.
- Eventos técnicos estruturados são persistidos em armazenamento próprio e serão consultáveis somente pelo superadmin. A implementação não reutiliza `telemetria_eventos`, que permanece exclusiva à telemetria de negócio.
- O evento técnico usa apenas campos allowlisted necessários ao diagnóstico: correlation ID, categoria, chave de evento/rota, duração, status HTTP e horário UTC. Não registrar CPF, identificadores de titular/usuário, `tenant_id`, `subcliente_id`, IP/UA, credenciais, query string, headers, bodies ou mensagem livre de exceção.
- A retenção padrão dos eventos técnicos é de 30 dias, configurável pelo superadmin entre 1 e 3650 dias no painel de telemetria existente; a nova versão entra em vigor no início da próxima execução agendada. O cron expurga registros vencidos em lotes limitados.
- Golden signals planejados no gateway/entrypoint:
  - **Latência:** p95/p99 por rota e por motor de render;
  - **Tráfego:** requests/h por rota, lotes/h, carteirinhas/h e leituras QR/h; eventos técnicos não carregam IDs de tenant/unidade;
  - **Erros:** 5xx, `COTA_EXCEDIDA`, webhook rejeitado, falha de render, DLQ;
  - **Saturação:** sinais acessíveis à aplicação, como conexões MySQL e backlog da fila, quando houver fonte confiável. CPU, disco e `pm.max_children` não serão afirmados em hospedagem compartilhada sem fonte disponível.
- Telemetria de negócio, operacional e técnica ficam separadas: `telemetria_eventos` é de negócio; métricas de lote alimentam dashboards operacionais; eventos técnicos são acessíveis somente pelo superadmin. Dados e métricas obedecem à política de privacidade em `docs/01` e `docs/08`.
- Métricas operacionais de lote registram demanda diária, tempo em fila, duração, throughput, falhas/retries e saturação. `duracao_media_ms` usa média ponderada de milissegundos por registro processado. O painel mostra lote recomendado e pico previsto sobre janela X configurável; o cálculo inicial usa P95 dos picos diários, com 30 dias propostos como padrão e histórico insuficiente sinalizado abaixo de 7 dias.
- O Administrador edita lote e pico manualmente. A aplicação salva uma versão pendente; a ação `Reiniciar worker e aplicar` solicita a ativação. Em hospedagem compartilhada, a próxima execução agendada do worker aplica a versão; lotes em andamento mantêm a versão original. Ver ADR-0012 para o perfil de execução.
- Alertas iniciais: cota > 80%, DLQ > 0, 5xx sustentado, WebP > 80 ms, disco > 80%, trial expirando, token de suporte utilizado.
- OpenTelemetry fica como evolução; a correlação HTTP está na #94, e a E08 precisa propagar o ID para os fluxos assíncronos antes de afirmar rastreabilidade ponta a ponta.

## Estado de implementação

- A telemetria de negócio e a telemetria operacional diária de lotes estão implementadas e documentadas em [`docs/08-telemetria.md`](../08-telemetria.md), incluindo painel por perfil, previsão configurável e aplicação de ajustes no início do worker.
- A #94 implementa correlation ID server-side nos 30 entrypoints PHP, ignora valores recebidos do cliente e compartilha o UUIDv4 entre resposta MCP e auditoria; commit `dd34c1d`, CI [45424728843](https://github.com/agthinkindigital/IEPRCard/actions/runs/45424728843).
- A #95 adiciona `eventos_tecnicos`, persiste um evento allowlisted por request em modo best-effort, e expurga registros em lotes limitados usando retenção configurável (padrão de 30 dias, 1–3650) no início da execução agendada. O painel `/telemetria` mantém o valor ativo/pendente na configuração versionada existente. Não armazena PII, IDs internos, IP/UA, rota dinâmica, query string, headers, body ou erro livre. Persistência usa rota canônica de uma lista permitida. CI #45470771467 passou em MySQL 8.4, MariaDB 10.11 e PDF; revisão QA aprovada.
- O mantenedor escolheu consulta exclusiva ao superadmin e omissão de IDs internos; mockup e guia da consulta foram aprovados em 2026-11-24. A tela está na [#96](https://github.com/agthinkindigital/IEPRCard/issues/96), dependente da #95. A propagação assíncrona continua na E08.4 da [E08](https://github.com/agthinkindigital/IEPRCard/issues/93).

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
