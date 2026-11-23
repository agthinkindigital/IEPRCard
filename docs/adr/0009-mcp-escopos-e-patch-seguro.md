# ADR-0009: MCP stateless com OAuth, escopos e patch restrito a dev

| Campo | Valor |
| --- | --- |
| Status | Aceita para MVP: MCP 2026-07-28 stateless + OAuth 2.1 para agentes machine-to-machine |
| Data | 2026-11-18 |
| Decisores | Arquiteto + dono do produto + segurança |
| Relacionados | [SAD §6.3, §9 R2](../architecture/SAD.md), ADR-0001, ADR-0011 |

## Contexto

As propostas históricas de `code.patch` e tools de escrita MCP implicam acesso remoto a filesystem, banco ou designs. Em um servidor que atende Clientes reais, uma chave vazada ou prompt injection poderia alterar o sistema ou expor dados. A implementação atual mantém tools read-only com escopo explícito; o contrato/runtime atual está em [`docs/09-mcp.md`](../09-mcp.md).

## Decisão

- O endpoint MCP estará disponível desde o primeiro lançamento, inclusive em produção. O protocolo MCP `2026-07-28` é a versão inicial fixada; seu contrato oficial define Streamable HTTP sem handshake nem sessão, metadados por requisição e cabeçalhos espelhados validados contra o corpo ([transport](https://modelcontextprotocol.io/specification/2026-07-28/basic/transports/streamable-http), [tools](https://modelcontextprotocol.io/specification/2026-07-28/server/tools)).
- O transporte remoto é Streamable HTTP stateless em um endpoint POST, sem daemon residente. Inicialmente as respostas são JSON; GET/DELETE e sessões não são implementados. Cada requisição inclui `MCP-Protocol-Version`, `Mcp-Method` e, quando exigido, `Mcp-Name`, validados contra o JSON-RPC. `Origin` presente é validado contra allowlist configurada; a aplicação nunca usa `tenant_id` enviado pelo cliente.
- Autorização remota usa OAuth 2.1 como resource server, com `client_credentials` para agentes machine-to-machine e clientes pré-cadastrados pelo Admin. O MVP não implementa consentimento interativo nem registro dinâmico. Publica Protected Resource Metadata e OAuth Authorization Server Metadata, exige `resource` canônico, aceita client authentication `client_secret_basic` no token endpoint e Bearer token em cada POST MCP. `MCP_PUBLIC_BASE_URL` define issuer e recurso canônicos; nenhum endpoint deriva esses valores de `Host` ou de headers de proxy. Tokens HS256 usam `MCP_OAUTH_SIGNING_KEY` externa com pelo menos 256 bits, expiram em cinco minutos e vinculam cliente, escopos, tenant e audiência; trocar a chave invalida tokens ainda vigentes. Toda chamada revalida cliente e Sede ativos para revogação e suspensão imediatas. Ver [Authorization](https://modelcontextprotocol.io/specification/2026-07-28/basic/authorization).
- `mcp_api_keys` é o registro de clientes OAuth: `client_id` público aleatório, segredo criptograficamente aleatório armazenado somente como hash, tenant, escopos explícitos, limite por minuto e estado/expiração. Os escopos provisionáveis são `system.read`, `telemetry.read`, `db.inspect`, `design.read` e `batch.dry_run`; só tools presentes no runtime são expostas. Hoje, `tools/list` contém `system.read`, `telemetry.read` e `db.inspect` quando cada escopo consta no token.
- O runtime atual não tem tools de escrita. `design.write` aguarda decisão de escopo; `code.patch` não está implementado em nenhum ambiente. Se uma futura decisão aprovar escrita de design, ela deve ser limitada a dev/staging e exigir autorização humana conforme o escopo aprovado. Qualquer eventual patch de código exigiria nova decisão e revisão de segurança antes de implementação.
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

**Negativas:** manutenção de código em produção passa a exigir deploy via pipeline (ADR-0012), não patch remoto; é uma mudança cultural intencional.

## Como validar

- `TDD-07`: `tools/list` filtrada por escopo; `system.read`, `telemetry.read` e `db.inspect`; argumentos inválidos, minimização, isolamento, rate limit e auditoria. Preview e patch remoto não fazem parte do runtime atual.
- Escopos de ferramentas não implementadas não devem ser expostos em `tools/list` nem permitir chamadas.
- `mcp_audit_logs` cobre 100% das chamadas, sem segredos nos parâmetros.
- Transporte stateless funciona com duas requisições concorrentes em processos PHP distintos, sem estado em memória; cabeçalhos `Origin`, versão e metadados espelhados inválidos são rejeitados. Token OAuth com audiência incorreta, assinatura inválida, expiração ou cliente revogado é negado.
