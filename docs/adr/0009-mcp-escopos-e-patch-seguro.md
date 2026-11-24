# ADR-0009: MCP stateless com OAuth, escopos e patch restrito a dev

| Campo | Valor |
| --- | --- |
| Status | Transporte e leitura aceitos; manutenção privilegiada no MVP requerida em 2026-11-24, com contrato de escrita pendente na #89 |
| Data | 2026-11-18 |
| Decisores | Arquiteto + dono do produto + segurança |
| Relacionados | [SAD §6.3, §9 R2](../architecture/SAD.md), ADR-0001, ADR-0011 |

## Contexto

As propostas históricas de `code.patch` e tools de escrita MCP implicam acesso remoto a filesystem, banco ou designs. Em um servidor que atende Clientes reais, uma chave vazada ou prompt injection poderia alterar o sistema ou expor dados. A implementação atual mantém tools read-only com escopo explícito; o contrato/runtime atual está em [`docs/09-mcp.md`](../09-mcp.md).

### Aditivo de produto — 2026-11-24

O mantenedor determinou que o MCP também deve apoiar manutenção interna e correção de problemas pela IA, sem acesso para clientes. O superadmin gera a autorização de controle e escolhe sua janela de `30m`, `1h`, `3h`, `6h`, `12h` ou `24h`; após expirar, uma nova autorização deve ser gerada. Cada autorização pode selecionar vários tenants e/ou unidades; cada tool deve validar o alvo contra essa lista explícita. Ainda falta decidir se selecionar uma Sede inclui suas unidades ou se elas precisam ser selecionadas individualmente. Isso supersede a decisão de excluir qualquer escrita do MVP, mas não define quais mutações a IA pode executar. A autorização temporária de controle não deve ser confundida com o access token OAuth curto já usado pelo transporte. A [Issue #89](https://github.com/agthinkindigital/IEPRCard/issues/89) precisa fechar herança de escopo, ferramentas, autonomia/aprovação, auditoria, reversão e tratamento de código/schema antes de implementação. Até lá, o runtime continua read-only; essa decisão não autoriza SQL arbitrário, filesystem irrestrito ou `code.patch`.

## Decisão

- O endpoint MCP estará disponível desde o primeiro lançamento, inclusive em produção. O protocolo MCP `2026-07-28` é a versão inicial fixada; seu contrato oficial define Streamable HTTP sem handshake nem sessão, metadados por requisição e cabeçalhos espelhados validados contra o corpo ([transport](https://modelcontextprotocol.io/specification/2026-07-28/basic/transports/streamable-http), [tools](https://modelcontextprotocol.io/specification/2026-07-28/server/tools)).
- O transporte remoto é Streamable HTTP stateless em um endpoint POST, sem daemon residente. Inicialmente as respostas são JSON; GET/DELETE e sessões não são implementados. Cada requisição inclui `MCP-Protocol-Version`, `Mcp-Method` e, quando exigido, `Mcp-Name`, validados contra o JSON-RPC. `Origin` presente é validado contra allowlist configurada; a aplicação nunca usa `tenant_id` enviado pelo cliente.
- Autorização remota usa OAuth 2.1 como resource server, com `client_credentials` para agentes machine-to-machine e clientes pré-cadastrados pelo Admin. O MVP não implementa consentimento interativo nem registro dinâmico. Publica Protected Resource Metadata e OAuth Authorization Server Metadata, exige `resource` canônico, aceita client authentication `client_secret_basic` no token endpoint e Bearer token em cada POST MCP. `MCP_PUBLIC_BASE_URL` define issuer e recurso canônicos; nenhum endpoint deriva esses valores de `Host` ou de headers de proxy. Tokens HS256 usam `MCP_OAUTH_SIGNING_KEY` externa com pelo menos 256 bits, expiram em cinco minutos e vinculam cliente, escopos, tenant e audiência; trocar a chave invalida tokens ainda vigentes. Toda chamada revalida cliente e Sede ativos para revogação e suspensão imediatas. Ver [Authorization](https://modelcontextprotocol.io/specification/2026-07-28/basic/authorization).
- `mcp_api_keys` é o registro de clientes OAuth: `client_id` público aleatório, segredo criptograficamente aleatório armazenado somente como hash, tenant, escopos explícitos, limite por minuto e estado/expiração. Os escopos provisionáveis são `system.read`, `telemetry.read`, `db.inspect`, `design.read` e `batch.dry_run`; só tools presentes no runtime são expostas. Hoje, `tools/list` contém `system.read`, `telemetry.read` e `db.inspect` quando cada escopo consta no token.
- O runtime atual não tem tools de escrita. A autorização temporária de manutenção descrita no aditivo ainda não tem catálogo de operações nem política de aprovação implementados. `design.write` e `code.patch` continuam ausentes; a #89 deve definir se correções incluem código, schema ou somente ferramentas de domínio predefinidas e exigir revisão de segurança antes da implementação.
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

- `TDD-07`: `tools/list` filtrada por escopo; `system.read`, `telemetry.read` e `db.inspect`; argumentos inválidos, minimização, isolamento, rate limit e auditoria. A evolução de manutenção privilegiada requer testes próprios para validade/revogação da autorização temporária, ferramentas permitidas e negadas, escopo de alvo, auditoria de mutações, repetição e reversão conforme o contrato da #89.
- Escopos de ferramentas não implementadas não devem ser expostos em `tools/list` nem permitir chamadas.
- `mcp_audit_logs` cobre 100% das chamadas, sem segredos nos parâmetros.
- Transporte stateless funciona com duas requisições concorrentes em processos PHP distintos, sem estado em memória; cabeçalhos `Origin`, versão e metadados espelhados inválidos são rejeitados. Token OAuth com audiência incorreta, assinatura inválida, expiração ou cliente revogado é negado.
