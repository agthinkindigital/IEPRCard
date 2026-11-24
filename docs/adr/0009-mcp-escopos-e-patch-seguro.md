# ADR-0009: MCP stateless com OAuth, escopos e patch restrito a dev

| Campo | Valor |
| --- | --- |
| Status | Transporte, grants temporários e publicação limitada a DEV implementados; revisão de segurança e CI continuam obrigatórios antes da promoção |
| Data | 2026-11-18 |
| Decisores | Arquiteto + dono do produto + segurança |
| Relacionados | [SAD §6.3, §9 R2](../architecture/SAD.md), ADR-0001, ADR-0011 |

## Contexto

As propostas históricas de `code.patch` e tools de escrita MCP implicam acesso remoto a filesystem, banco ou designs. Em um servidor que atende Clientes reais, uma chave vazada ou prompt injection poderia alterar o sistema ou expor dados. A implementação atual mantém tools read-only com escopo explícito; o contrato/runtime atual está em [`docs/09-mcp.md`](../09-mcp.md).

### Aditivo de produto — 2026-11-24

O mantenedor determinou que o MCP apoie suporte interno e correção assistida por IA, sem acesso dos clientes. O superadmin gera grants de `30m`, `1h`, `3h`, `6h`, `12h` ou `24h`, escolhe várias Sedes/unidades e autoriza as tools selecionadas durante toda a janela sem confirmação por operação. Ao incluir uma Sede, o servidor congela no grant o conjunto de unidades existentes naquele instante; cada chamada revalida grant, tool e alvos. O grant é separado do access token OAuth de cinco minutos, e expiração/revogação interrompe chamadas imediatamente. A tela, o guia MCP e a extensão visual para configurar o PAT foram aprovados em 2026-11-24.

O mantenedor autorizou correções de código via MCP, com publicação somente em `DEV` e autoria Leonardo. A integração usa fine-grained PAT restrito ao repositório, cifrado em repouso com `sodium_crypto_secretbox` e `IEPR_INTEGRATION_KEY` (ADR-0008); o segredo nunca é retornado nem enviado à IA. A escrita deve ocorrer pela API do GitHub em `DEV`, disparar e reportar CI, e não incluir execução de shell ou acesso ao filesystem da hospedagem. `PROD` não recebe escrita MCP e requer branch protection efetiva, checks aprovados e promoção/revisão conforme ADR-0012. Em 2026-11-24, a proteção de `PROD` foi ativada: PR obrigatório, os três checks de CI requeridos, enforcement de administradores, sem force-push ou exclusão; a capability de publicação deve validar essa proteção antes de operar. O primeiro catálogo de manutenção de dados permanece somente leitura até operações tipadas e seus limites serem definidos na [Issue #89](https://github.com/agthinkindigital/IEPRCard/issues/89); não inferir autorização para SQL arbitrário, shell ou filesystem irrestrito.

## Decisão

- O endpoint MCP estará disponível desde o primeiro lançamento, inclusive em produção. O protocolo MCP `2026-07-28` é a versão inicial fixada; seu contrato oficial define Streamable HTTP sem handshake nem sessão, metadados por requisição e cabeçalhos espelhados validados contra o corpo ([transport](https://modelcontextprotocol.io/specification/2026-07-28/basic/transports/streamable-http), [tools](https://modelcontextprotocol.io/specification/2026-07-28/server/tools)).
- O transporte remoto é Streamable HTTP stateless em um endpoint POST, sem daemon residente. Inicialmente as respostas são JSON; GET/DELETE e sessões não são implementados. Cada requisição inclui `MCP-Protocol-Version`, `Mcp-Method` e, quando exigido, `Mcp-Name`, validados contra o JSON-RPC. `Origin` presente é validado contra allowlist configurada; a aplicação nunca usa `tenant_id` enviado pelo cliente.
- Autorização remota usa OAuth 2.1 como resource server, com `client_credentials` para agentes machine-to-machine e clientes pré-cadastrados pelo Admin. O MVP não implementa consentimento interativo nem registro dinâmico. Publica Protected Resource Metadata e OAuth Authorization Server Metadata, exige `resource` canônico, aceita client authentication `client_secret_basic` no token endpoint e Bearer token em cada POST MCP. `MCP_PUBLIC_BASE_URL` define issuer e recurso canônicos; nenhum endpoint deriva esses valores de `Host` ou de headers de proxy. Tokens HS256 usam `MCP_OAUTH_SIGNING_KEY` externa com pelo menos 256 bits, expiram em cinco minutos e vinculam cliente, escopos, tenant e audiência; trocar a chave invalida tokens ainda vigentes. Toda chamada revalida cliente e Sede ativos para revogação e suspensão imediatas. Ver [Authorization](https://modelcontextprotocol.io/specification/2026-07-28/basic/authorization).
- `mcp_api_keys` é o registro de clientes OAuth: `client_id` público aleatório, segredo criptograficamente aleatório armazenado somente como hash, tenant, escopos explícitos, limite por minuto e estado/expiração. Os escopos provisionáveis são `system.read`, `telemetry.read`, `db.inspect`, `design.read` e `batch.dry_run`; só tools presentes no runtime são expostas. Hoje, `tools/list` contém `system.read`, `telemetry.read` e `db.inspect` quando cada escopo consta no token.
- O runtime ainda não implementa grants nem escrita. O contrato aprovado exige autorização de suporte separada do OAuth de clientes: validade total de 30 minutos a 24 horas, várias alvos e capabilities, unidades da Sede congeladas na criação, segredo apresentado uma única vez, hash persistido, revogação imediata e validação por chamada. A implementação de publicação usa GitHub API para `DEV`, fine-grained PAT cifrado com `IEPR_INTEGRATION_KEY`, autoria Leonardo e evidência CI; não executa shell nem grava no filesystem instalado. `PROD` exige proteção de branch, CI e promoção revisada. Até a tela estendida e #89 passarem revisão/gates, `design.write`, `code.patch` e tools de manutenção de dados continuam ausentes.
- Toda chamada grava `mcp_audit_logs` com cliente, escopo, método/tool, `correlation_id` e resultado; não persiste segredo, token, IP bruto, parâmetros livres ou PII. Logs e respostas de telemetria respeitam o recorte do ADR-0003.
- Rate limit por chave e validação de schema de entrada (tipos e limites de tamanho) antes de qualquer execução.
- A autenticação e autorização são avaliadas por chamada; cada chave tem escopo mínimo, tenant permitido e revogação imediata. Tools de leitura retornam somente campos explicitamente permitidos, nunca consultas SQL arbitrárias ou PII do titular.
- Nunca expor valores de `configuracoes_gateway`, senhas ou CPF em resposta de tool.

## Alternativas consideradas

| Alternativa | Prós | Contras | Veredito |
| --- | --- | --- | --- |
| Liberar todas as tools em produção com chave forte | Facilita manutenção remota | RCE, vazamento e violação de auditoria com uma única chave comprometida | Rejeitada |
| API key estática enviada diretamente em cada POST MCP | Implementação inicial menor | Não segue o modelo OAuth de discovery/resource audience esperado por clientes interoperáveis | Rejeitada |
| Desativar MCP em produção | Segurança máxima | Perde telemetria agêntica e inspeção remota previstas no produto | Rejeitada |
| OAuth client credentials + escopos + somente leitura em produção (escolhida) | Interoperável, stateless e compatível com cliente de agente em hospedagem sem daemon | Exige token endpoint, metadata OAuth e rotação de segredo | Aceita |

## Consequências

**Positivas:** reduz a superfície de escrita remota; agentes autorizados podem consultar prontidão, schema e telemetria, com auditoria minimizada.

**Negativas:** manutenção de código exige publicar em `DEV` e depois promover via fluxo revisado (ADR-0012); a integração depende de segredo de aplicação externo, branch protection de `PROD` e evidência de CI. Grants multi-target adicionam tabelas, autorização e auditoria que devem ser testadas nas matrizes SQLite/MySQL/MariaDB.

## Como validar

- `TDD-07`: `tools/list` filtrada por escopo; `system.read`, `telemetry.read` e `db.inspect`; argumentos inválidos, minimização, isolamento, rate limit e auditoria. A evolução de manutenção privilegiada requer testes próprios para validade/revogação da autorização temporária, ferramentas permitidas e negadas, escopo de alvo, auditoria de mutações, repetição e reversão conforme o contrato da #89.
- Escopos de ferramentas não implementadas não devem ser expostos em `tools/list` nem permitir chamadas.
- `mcp_audit_logs` cobre 100% das chamadas, sem segredos nos parâmetros.
- Transporte stateless funciona com duas requisições concorrentes em processos PHP distintos, sem estado em memória; cabeçalhos `Origin`, versão e metadados espelhados inválidos são rejeitados. Token OAuth com audiência incorreta, assinatura inválida, expiração ou cliente revogado é negado.
