# ADR-0009: MCP stateless com OAuth, escopos e patch restrito a dev

| Campo | Valor |
| --- | --- |
| Status | Transporte, grants temporários e publicação limitada a DEV implementados; CI passou em 2026-11-24, revisão humana de segurança continua obrigatória antes da promoção |
| Data | 2026-11-18 |
| Decisores | Arquiteto + dono do produto + segurança |
| Relacionados | [SAD §6.3, §9 R2](../architecture/SAD.md), ADR-0001, ADR-0011 |

## Contexto

Tools MCP com escrita podem alterar código ou dados em produção se credenciais, grants ou validações forem comprometidos. O desenho separa leitura de suporte, publicação de código em `DEV` e mudanças de dados: cada superfície exige autorização explícita e limites próprios. O contrato operacional está em [`docs/09-mcp.md`](../09-mcp.md).

### Aditivo de produto — 2026-11-24

O mantenedor determinou que o MCP apoie suporte interno e correção assistida por IA, sem acesso dos clientes. O superadmin gera grants de `30m`, `1h`, `3h`, `6h`, `12h` ou `24h`, escolhe várias Sedes/unidades e autoriza as tools selecionadas durante toda a janela sem confirmação por operação. Ao incluir uma Sede, o servidor congela no grant o conjunto de unidades existentes naquele instante; cada chamada revalida grant, tool e alvos. O grant é separado do access token OAuth de cinco minutos, e expiração/revogação interrompe chamadas imediatamente. A tela, o guia MCP e a extensão visual para configurar o PAT foram aprovados em 2026-11-24.

O mantenedor autorizou correções de código via MCP, com publicação somente em `DEV` e autoria Leonardo. A integração usa fine-grained PAT cifrado em repouso com `sodium_crypto_secretbox` e `IEPR_INTEGRATION_KEY` (ADR-0008); o segredo não é retornado nem enviado à IA. A publicação usa GitHub API, exige arquivos de texto já rastreados, compara o `HEAD` esperado e reporta o estado dos três checks obrigatórios. Não executa shell nem acessa o filesystem da hospedagem. `PROD` não recebe escrita MCP: a branch exige PR, checks requeridos, enforcement de administradores, sem force-push ou exclusão; a capacidade de publicar falha fechada se essa proteção não for confirmada. A proteção atual não exige aprovação formal de outro colaborador; o mantenedor revisa o PR antes de mesclar, conforme ADR-0012. A primeira entrega de suporte permite leitura delimitada e publicação do código em `DEV`; tools para alterar dados de clientes continuam ausentes até operações tipadas e seus limites serem aprovados na [Issue #89](https://github.com/agthinkindigital/IEPRCard/issues/89). Não há SQL arbitrário, shell ou filesystem irrestrito.

## Decisão

- O endpoint MCP estará disponível desde o primeiro lançamento, inclusive em produção. O protocolo MCP `2026-07-28` é a versão inicial fixada; seu contrato oficial define Streamable HTTP sem handshake nem sessão, metadados por requisição e cabeçalhos espelhados validados contra o corpo ([transport](https://modelcontextprotocol.io/specification/2026-07-28/basic/transports/streamable-http), [tools](https://modelcontextprotocol.io/specification/2026-07-28/server/tools)).
- O transporte remoto é Streamable HTTP stateless em um endpoint POST, sem daemon residente. Inicialmente as respostas são JSON; GET/DELETE e sessões não são implementados. Cada requisição inclui `MCP-Protocol-Version`, `Mcp-Method` e, quando exigido, `Mcp-Name`, validados contra o JSON-RPC. `Origin` presente é validado contra allowlist configurada; a aplicação nunca usa `tenant_id` enviado pelo cliente.
- Autorização remota usa OAuth 2.1 como resource server, com `client_credentials` para agentes machine-to-machine e clientes pré-cadastrados pelo Admin. O MVP não implementa consentimento interativo nem registro dinâmico. Publica Protected Resource Metadata e OAuth Authorization Server Metadata, exige `resource` canônico, aceita client authentication `client_secret_basic` no token endpoint e Bearer token em cada POST MCP. `MCP_PUBLIC_BASE_URL` define issuer e recurso canônicos; nenhum endpoint deriva esses valores de `Host` ou de headers de proxy. Tokens HS256 usam `MCP_OAUTH_SIGNING_KEY` externa com pelo menos 256 bits, expiram em cinco minutos e vinculam cliente, escopos, tenant e audiência; trocar a chave invalida tokens ainda vigentes. Toda chamada revalida cliente e Sede ativos para revogação e suspensão imediatas. Ver [Authorization](https://modelcontextprotocol.io/specification/2026-07-28/basic/authorization).
- `mcp_api_keys` é o registro de clientes OAuth: `client_id` público aleatório, segredo criptograficamente aleatório armazenado somente como hash, tenant, escopos explícitos, limite por minuto e estado/expiração. Os escopos provisionáveis são `system.read`, `telemetry.read`, `db.inspect`, `design.read` e `batch.dry_run`; só tools presentes no runtime são expostas. Hoje, `tools/list` contém `system.read`, `telemetry.read` e `db.inspect` quando cada escopo consta no token.
- Grants de suporte são separados do OAuth de clientes: validade de 30 minutos a 24 horas, vários alvos/capabilities, unidades da Sede congeladas na criação, segredo mostrado uma única vez e persistido como hash, revogação imediata e validação em cada chamada. A interface superadmin e a publicação GitHub limitada a `DEV` estão implementadas; o PAT é cifrado e a autoria é Leonardo. A ferramenta não aceita arquivos novos, caminhos fora da allowlist de arquivos rastreados, configuração de CI, SQL ou shell. `PROD` exige branch protection e promoção revisada. A revisão de segurança da #89 cobre esses limites; `design.write`, `code.patch` genérico e tools de manutenção de dados permanecem ausentes.
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

- `TDD-07` e #89: `tools/list` filtrada por capability; grants válidos/expirados/revogados, congelamento e autorização de alvos; publicação idempotente apenas em `DEV`, caminhos permitidos/negados, segredo no PAT, proteção de `PROD` e revisão do mantenedor no PR, três checks, concorrência, auditoria e falha/repetição da API. CI deve cobrir SQLite, MySQL e MariaDB.
- Escopos de ferramentas não implementadas não devem ser expostos em `tools/list` nem permitir chamadas.
- `mcp_audit_logs` cobre 100% das chamadas, sem segredos nos parâmetros.
- Transporte stateless funciona com duas requisições concorrentes em processos PHP distintos, sem estado em memória; cabeçalhos `Origin`, versão e metadados espelhados inválidos são rejeitados. Token OAuth com audiência incorreta, assinatura inválida, expiração ou cliente revogado é negado.
