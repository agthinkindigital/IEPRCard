# ADR-0009: MCP stateless com OAuth, escopos e patch restrito a dev

| Campo | Valor |
| --- | --- |
| Status | Aceita para MVP: MCP 2026-07-28 stateless + OAuth 2.1 para agentes machine-to-machine |
| Data | 2026-11-18 |
| Decisores | Arquiteto + dono do produto + segurança |
| Relacionados | [SAD §6.3, §9 R2](../architecture/SAD.md), ADR-0001, ADR-0011 |

## Contexto

`docs/09` define a tool `mcp.code.safe_patch`, que recebe `arquivo_relativo` e `conteudo_novo`, cria backup, roda `php -l`, aplica a alteração e executa testes com rollback automático. Em um servidor que atende Clientes reais, isso é efetivamente execução remota de código com acesso ao filesystem e ao banco — qualquer chave vazada ou prompt injection em um agente permite alterar o sistema em produção. As demais tools (`mcp.db.inspect`, `mcp.telemetry.query`, `mcp.design.inspect_and_map`, `mcp.batch.dry_run`) também expõem dados sensíveis se não houver escopo.

## Decisão

- O endpoint MCP estará disponível desde o primeiro lançamento, inclusive em produção. O protocolo MCP `2026-07-28` é a versão inicial fixada; seu contrato oficial define Streamable HTTP sem handshake nem sessão, metadados por requisição e cabeçalhos espelhados validados contra o corpo ([transport](https://modelcontextprotocol.io/specification/2026-07-28/basic/transports/streamable-http), [tools](https://modelcontextprotocol.io/specification/2026-07-28/server/tools)).
- O transporte remoto é Streamable HTTP stateless em um endpoint POST, sem daemon residente. Inicialmente as respostas são JSON; GET/DELETE e sessões não são implementados. Cada requisição inclui `MCP-Protocol-Version`, `Mcp-Method` e, quando exigido, `Mcp-Name`, validados contra o JSON-RPC. `Origin` presente é validado contra allowlist configurada; a aplicação nunca usa `tenant_id` enviado pelo cliente.
- Autorização remota usa OAuth 2.1 como resource server, com `client_credentials` para agentes machine-to-machine e clientes pré-cadastrados pelo Admin. O MVP não implementa consentimento interativo nem registro dinâmico. Publica Protected Resource Metadata, exige `resource` canônico, aceita client authentication `client_secret_basic` no token endpoint e Bearer token em cada POST MCP. Tokens de acesso são assinados pelo servidor, curtos e vinculados ao cliente, escopo, tenant e audiência; toda chamada revalida cliente e Sede ativos para revogação e suspensão imediatas. Ver [Authorization](https://modelcontextprotocol.io/specification/2026-07-28/basic/authorization).
- `mcp_api_keys` é o registro de clientes OAuth: `client_id` público aleatório, segredo criptograficamente aleatório armazenado somente como hash, tenant, escopos explícitos, limite por minuto e estado/expiração. Escopos iniciais: `system.read`, `telemetry.read`, `db.inspect`, `design.read`, `design.write`, `batch.dry_run`, `code.patch`.
- **Em produção, apenas tools de leitura são registradas** (`system.read`, `telemetry.read`, `db.inspect`, `design.read`, `batch.dry_run`). `design.write` exige aprovação humana; `code.patch` não é registrado de forma alguma.
- `code.patch` existe somente quando `APP_ENV=dev|staging`, com allowlist de diretórios, backup, `php -l`, suíte TDD correspondente e rollback — exatamente como especificado, mas cercado por ambiente.
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

**Positivas:** risco R2 eliminado; agentes continuam capazes de inspecionar schema, telemetria e prévias; auditoria completa.

**Negativas:** manutenção de código em produção passa a exigir deploy via pipeline (ADR-0012), não patch remoto; é uma mudança cultural intencional.

## Como validar

- `TDD-07`: `tools/list`, `tools/call`, preview WebP atualizado e patch com erro de sintaxe rejeitado com rollback.
- Spec de segurança: com `APP_ENV=production`, chave com escopo `code.patch` recebe `-32601 Method not found` e nenhum arquivo é alterado.
- `mcp_audit_logs` cobre 100% das chamadas, sem segredos nos parâmetros.
- Transporte stateless funciona com duas requisições concorrentes em processos PHP distintos, sem estado em memória; cabeçalhos `Origin`, versão e metadados espelhados inválidos são rejeitados. Token OAuth com audiência incorreta, assinatura inválida, expiração ou cliente revogado é negado.
