# ADR-0008: Webhooks, credenciais e liquidação financeira idempotentes

| Campo | Valor |
| --- | --- |
| Status | Regras gerais de segredo/webhook ativas; decisões de cobrança e liquidação futuras, fora do MVP |
| Data | 2026-11-18 |
| Decisores | Arquiteto + time de engenharia |
| Relacionados | [SAD §5.4, §6.2, §6.4](../architecture/SAD.md), ADR-0006, ADR-0011 |

## Contexto

O produto dispõe de código de referência para webhooks Asaas e Mercado Pago, mas cobrança, checkout e processamento desses webhooks foram adiados e não serão implementados no MVP. Este ADR registra as garantias a reavaliar quando o módulo financeiro for priorizado. A validação de assinatura e idempotência continua aplicável a outros webhooks efetivamente incluídos no escopo, como WhatsApp.

## Decisão

1. **Autenticidade:** validar Asaas com token comparado por `hash_equals()` e Mercado Pago via HMAC conforme contrato vigente do provedor; ambos permanecem fora do MVP. Para WhatsApp, preferir assinatura do provedor. O GOWA VIP de referência permite configurar `webhook_url`, mas não assina nem autentica os POSTs recebidos. Para esse caso, usar um segredo bearer aleatório de 256 bits por instância, incluído na URL do webhook: persistir somente o hash no IEPRCard, comparar em tempo constante, nunca registrar a URL/token pela aplicação, e permitir rotação que invalide o anterior. Como o segredo está no query string, servidores HTTP, proxies e hospedagens podem registrar a URL completa em access logs; antes de produção, configurar e verificar a redação do parâmetro `token` em todos esses logs. Hospedagem que não permita essa proteção não deve receber uma instância GOWA produtiva. Exemplos de formatos que omitem a query: no Nginx, registrar método, `$uri`, protocolo e resultado, sem `$request`, `$request_uri` ou `$args`; no Apache, usar `%m`, `%U`, `%H` e status, sem `%r` ou `%q`. O host também pode aplicar redaction seletiva do parâmetro em seu painel. Conferir cada destino de log, inclusive proxy/CDN, logs PHP e exportações; se o host não permite configurar nem comprovar a redação, não ativar GOWA nele. Esse token autentica a posse da URL, não a origem criptográfica do payload; reduzir a superfície com TLS, rate limit, validação de schema e idempotência por ID externo da mensagem. Se o gateway passar a oferecer assinatura verificável ou credencial em header/body, preferi-la em uma revisão deste ADR.

   Exemplo para adaptar no servidor (não incluir `$request`, `$request_uri`, `$args`, `%r` ou `%q`):

   ```nginx
   log_format iepr_safe '$remote_addr [$time_local] "$request_method $uri $server_protocol" $status';
   access_log /var/log/nginx/access.log iepr_safe;
   ```

   ```apache
   LogFormat "%a %t %m %U %H %>s" iepr_safe
   CustomLog logs/access_log iepr_safe
   ```
2. **Idempotência:** para webhooks ativos, registrar evento antes de mutações e tornar repetição no-op. O vínculo com `pagamentos_saas` é exclusivamente futuro.
3. **Resposta rápida:** validar, registrar e responder `2xx`; efeitos não críticos vão para a outbox (ADR-0006).
4. **Estado financeiro:** transições automatizadas de conta por eventos de pagamento ficam adiadas junto com billing; no MVP, o Admin controla ativação manualmente.
5. **Sigilo:** segredos de integrações ativas, inclusive chaves globais de OpenAI/Gemini, são cifrados em repouso com `sodium_crypto_secretbox`; a chave dedicada `IEPR_INTEGRATION_KEY` (32 bytes, Base64) vem do ambiente do processo PHP, nunca do banco ou Git, e é distinta de `IEPR_ANONYMIZATION_KEY`. A configuração global de IA usa `ia_config_global`, sem vínculo a tenant; a tabela preexistente `ia_config` fica sem uso durante a janela de rollback, conforme ADR-0010. A interface administrativa nunca retorna o segredo salvo; logs e testes usam somente estado/operação sintética. Sem a chave mestra, bloquear cadastro, teste e uso de credenciais. Quando pagamentos forem priorizados, credenciais financeiras terão armazenamento próprio em `configuracoes_gateway`, sob as mesmas garantias de cifragem.
6. **Proteção de navegador:** sessão `HttpOnly`+`Secure`+`SameSite=Lax`, CSRF token em todo POST autenticado, CSP, HSTS, sanitização de saída e rate limiting em login/QR/checkout.
7. **Testes negativos futuros:** assinatura inválida, replay, payload alterado e tentativa de alterar estado comercial por webhook forjado, ao retomar pagamentos.
8. **Marketplace de titulares (futuro):** `pagamentos_titular`, `contas_recebedoras` e `repasses_titular` não entram no schema do MVP. Ao retomar pagamentos, usar registros separados para cobrança, recebedor e liquidação.
9. **Liquidação e reversões (futuro):** ao retomar pagamentos, confirmar split/liquidação junto ao provedor, reconciliar eventos e relatórios, e registrar estorno/chargeback e reversão do repasse. Provedor, comissões, calendário e responsabilidades serão decididos nessa fase.

## Alternativas consideradas

| Alternativa | Prós | Contras | Veredito |
| --- | --- | --- | --- |
| Confiar no provedor e processar sempre | Simples | Duplicidade e fraude; quebra NFR-07 | Rejeitada |
| Conferir apenas `event.id` em memória | Rápido | Perde idempotência após restart; sem auditoria | Rejeitada |
| Tabela de eventos + validação de assinatura + resposta rápida | Auditável e reiniciável | Persistência e limpeza precisam ser dimensionadas para cada integração | Referência futura; validar antes de implementar |
| Saldo genérico mantido pela aplicação e repasse manual | Controle de calendário | Aumenta risco de custódia e conciliação operacional | Não adotar sem nova decisão; marketplace/split é uma opção futura |

## Consequências

**Positivas:** preserva requisitos de segurança para futura integração financeira e orientação para webhooks ativos.

**Negativas:** cada gateway tem um formato de assinatura diferente (mantido em wrapper próprio); chaves cifradas exigem gerenciar a chave mestra fora do banco.

## Como validar

- `TDD-05` (fase futura): `PAYMENT_CONFIRMED`/`PAYMENT_OVERDUE`, replay, HMAC e eventos de split/estorno.
- `TDD-02B` não deve exigir webhook financeiro no MVP; validar controle manual de status até a integração ser retomada.
- `*.spec.sec.php`: CSRF, XSS em campo importado, escalonamento e tentativa de leitura de segredo via log/API.
