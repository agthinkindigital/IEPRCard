# ADR-0009: Servidor MCP com escopos e patch restrito a dev

| Campo | Valor |
| --- | --- |
| Status | Proposta (refina `docs/09`, `docs/11` ISSUE-017) |
| Data | 2026-11-18 |
| Decisores | Arquiteto + dono do produto + segurança |
| Relacionados | [SAD §6.3, §9 R2](../architecture/SAD.md), ADR-0001, ADR-0011 |

## Contexto

`docs/09` define a tool `mcp.code.safe_patch`, que recebe `arquivo_relativo` e `conteudo_novo`, cria backup, roda `php -l`, aplica a alteração e executa testes com rollback automático. Em um servidor que atende Clientes reais, isso é efetivamente execução remota de código com acesso ao filesystem e ao banco — qualquer chave vazada ou prompt injection em um agente permite alterar o sistema em produção. As demais tools (`mcp.db.inspect`, `mcp.telemetry.query`, `mcp.design.inspect_and_map`, `mcp.batch.dry_run`) também expõem dados sensíveis se não houver escopo.

## Decisão

- O endpoint MCP estará disponível desde o primeiro lançamento, inclusive no ambiente de produção, para que inspeção e telemetria sejam capacidades iniciais do produto.
- O transporte remoto de produção é Streamable HTTP stateless: um endpoint HTTP, cada chamada em um POST independente, sem sessão em memória nem daemon MCP residente. A resposta pode ser JSON; tools longas iniciam trabalho idempotente e devolvem um `job_id` consultável em outra chamada. STDIO fica opcional para desenvolvimento local. O servidor valida `Origin`, autentica cada requisição, valida versão/cabeçalhos do protocolo e não confia no `tenant_id` fornecido pelo cliente.
- JSON-RPC 2.0 mantido, com chaves em `mcp_api_keys` e escopos explícitos: `system.read`, `telemetry.read`, `db.inspect`, `design.read`, `design.write`, `batch.dry_run`, `code.patch`.
- **Em produção, apenas tools de leitura são registradas** (`system.read`, `telemetry.read`, `db.inspect`, `design.read`, `batch.dry_run`). `design.write` exige aprovação humana; `code.patch` não é registrado de forma alguma.
- `code.patch` existe somente quando `APP_ENV=dev|staging`, com allowlist de diretórios, backup, `php -l`, suíte TDD correspondente e rollback — exatamente como especificado, mas cercado por ambiente.
- Toda chamada grava `mcp_audit_logs` com chave, escopo, tool, parâmetros sanitizados, IP, `correlation_id` e resultado; logs de telemetria respeitam o recorte do ADR-0003.
- Rate limit por chave e validação de schema de entrada (tipos e limites de tamanho) antes de qualquer execução.
- A autenticação e autorização são avaliadas por chamada; cada chave tem escopo mínimo, tenant permitido e revogação imediata. Tools de leitura retornam somente campos explicitamente permitidos, nunca consultas SQL arbitrárias ou PII do titular.
- Nunca expor valores de `configuracoes_gateway`, senhas ou CPF em resposta de tool.

## Alternativas consideradas

| Alternativa | Prós | Contras | Veredito |
| --- | --- | --- | --- |
| Liberar todas as tools em produção com chave forte | Facilita manutenção remota | RCE, vazamento e violação de auditoria com uma única chave comprometida | Rejeitada |
| Desativar MCP em produção | Segurança máxima | Perde telemetria agêntica e inspeção remota previstas no produto | Rejeitada |
| Escopos + somente leitura em prod + patch em dev (escolhida) | Mantém valor do MCP sem RCE em produção | Duas configurações de registry a testar | Aceita |

## Consequências

**Positivas:** risco R2 eliminado; agentes continuam capazes de inspecionar schema, telemetria e prévias; auditoria completa.

**Negativas:** manutenção de código em produção passa a exigir deploy via pipeline (ADR-0012), não patch remoto; é uma mudança cultural intencional.

## Como validar

- `TDD-07`: `tools/list`, `tools/call`, preview WebP atualizado e patch com erro de sintaxe rejeitado com rollback.
- Spec de segurança: com `APP_ENV=production`, chave com escopo `code.patch` recebe `-32601 Method not found` e nenhum arquivo é alterado.
- `mcp_audit_logs` cobre 100% das chamadas, sem segredos nos parâmetros.
- Transporte stateless funciona com duas requisições concorrentes em processos PHP distintos, sem estado em memória; cabeçalhos `Origin` e de versão MCP inválidos são rejeitados.
