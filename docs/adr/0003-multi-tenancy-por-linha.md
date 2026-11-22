# ADR-0003: Multi-tenancy por linha com resolução por `HTTP_HOST`

| Campo | Valor |
| --- | --- |
| Status | Aceita (baseline `docs/00`, `docs/02`, `docs/11` ISSUE-004) |
| Data | 2026-11-18 |
| Decisores | Arquiteto + time de engenharia |
| Relacionados | [SAD §2.3, §4.6, §5.2](../architecture/SAD.md), ADR-0004, ADR-0005 |

## Contexto

A hierarquia é de 4 camadas lógicas (Admin → Cliente Sede → Subcliente → Titular). No perfil Hospedado, subdomínios e domínios próprios são resolvidos por `HTTP_HOST`; domínios próprios são requisito do lançamento. O requisito de isolamento do GATE 2 é explícito: um Subcliente não pode ler nem editar dados de outro Subcliente mesmo manipulando IDs via GET/POST. O produto também oferece perfil Local Host para uma instalação que atende uma única empresa e suas unidades.

## Decisão

- `config/tenant_resolver.php` produz um objeto de contexto imutável: `{tipo, tenant_id, subcliente_id, branding}` a partir de `HTTP_HOST` **cruzado com a sessão autenticada** (host sozinho nunca autoriza).
- O registro canônico `dominios_tenant` mantém o hostname normalizado, tenant, estado de verificação e estado do TLS. Ciclo de vida: `pendente_verificacao → verificado → tls_pendente → ativo`, com revogação explícita. Hostnames são únicos globalmente; só hosts ativos e verificados resolvem tenants.
- Verificar posse por desafio DNS antes de ativar. Rejeitar hosts desconhecidos, duplicados ou não verificados; validar o host contra a lista cadastrada e a sessão, sem confiar diretamente em `HTTP_HOST` para autorização. Credenciais/códigos do desafio não são tokens de sessão.
- A hospedagem precisa aceitar vários hostnames na mesma aplicação e emitir/renovar TLS para cada um. A compatibilidade deve ser demonstrada no plano contratado; suporte a domínio único ou múltiplos sites não prova, por si só, suporte a aliases ilimitados.
- O superadmin escolhe um perfil global da instalação, persistido como revisão append-only em `configuracoes_instalacao` (migration `0016_installation_profile`); cada revisão guarda modo, configuração, ator e data. A revisão mais recente é a ativa. Sem perfil inicial, somente o setup autenticado do superadmin fica disponível.
- Em **Hospedado**, os hostnames da plataforma e o domínio-base dos subdomínios são configurados na instalação. O roteamento continua exigindo hostname normalizado/permitido e identidade autenticada compatível; domínios próprios de clientes mantêm prova DNS e TLS.
- Em **Local Host**, o superadmin configura uma única Sede ativa. Páginas de cliente e portal resolvem essa Sede e suas unidades; a unidade efetiva vem do usuário/titular relido no banco pela sessão autenticada. Nenhum ID de tenant ou unidade enviado pelo navegador altera o escopo. Não há domínio próprio por cliente nesse perfil.
- Acesso Local Host só é reconhecido quando o host da requisição é loopback (`localhost`, `127.0.0.1` ou `::1`) e a conexão chega por interface loopback. Não confiar em `X-Forwarded-Host` nem aceitar `Host` de rede para ativar o perfil local.
- A troca de perfil não move dados. Mudanças são restritas ao superadmin, protegidas por CSRF, auditadas e passam a valer nas requisições seguintes. A configuração Local Host limita os fluxos de cliente à Sede escolhida, sem remover outros registros administrativos.
- `config/tenant_scope.php` constrói a cláusula obrigatória de escopo em toda query de negócio:
  - Admin: filtro explícito por tela, nunca ausência de `WHERE`;
  - Cliente: `tenant_id = :tid`;
  - Subcliente: `tenant_id = :tid AND subcliente_id = :sid`.
- `tenant_id`/`subcliente_id` recebidos de request são **apenas filtros dentro do escopo**, nunca fonte de autorização.
- Uploads ficam em `assets/uploads/tenants/{tenant_id}/`, com validação de `realpath()`.
- Toda leitura de telemetria e auditoria passa pelo mesmo mecanismo (recortes 14/14.1/14.2).

## Alternativas consideradas

| Alternativa | Prós | Contras | Veredito |
| --- | --- | --- | --- |
| Database per tenant | Isolamento forte, backup individual | Custo por tenant, migração N vezes, impossibilita visão consolidada da rede sem agregação | Rejeitada |
| Schema per tenant | Isolamento médio sem múltiplos bancos | Self-healing N vezes, consultas cross-tenant complexas | Rejeitada |
| RLS nativo do banco | Isolamento no motor de dados | Indisponível no SQLite; MySQL 8 não tem equivalente ao RLS do PostgreSQL; exigiria trocar o NFR-01 | Rejeitada |
| Row-level + TenantScope obrigatório (escolhida) | Um único schema, visão consolidada natural, compatível com SQLite/MySQL | Depende de disciplina de código; uma query fora do helper pode vazar | Aceita com testes negativos |

## Consequências

**Positivas:** modelo hierárquico e telemetria em 3 níveis saem naturalmente do mesmo filtro; baixo custo; domínios próprios podem apontar por CNAME para a aplicação compartilhada.

**Negativas e mitigação:** isolamento é lógico, não físico. Mitigação: helper obrigatório, TDD-02/TDD-02B, teste de manipulação de IDs no GATE 2, revisão de QA e spec de segurança cross-tenant.

## Como validar

- `TDD-02`: no perfil Hospedado, `painel.saas.com` → admin; `escolaX.com.br` → cliente 10; `polo1.escolaX.com.br` → subcliente 25 com `parent_id=10`. No perfil Local Host, `localhost` → somente a Sede configurada e suas unidades, com a identidade da sessão fixando o escopo.
- Domínio não verificado, TLS pendente, hostname duplicado ou `Host` desconhecido não ativa contexto de tenant; tentativa de host injection não contorna a sessão nem o TenantScope.
- Host `localhost` vindo de conexão não-loopback não ativa a instalação local; um perfil Local Host não disponibiliza a aplicação pela rede pública.
- GATE 2: tentativa de ler/editar design, lote ou titular de outro tenant retorna 403/404 sem vazar existência.
- Auditória de queries: nenhuma `SELECT` em tabelas com `tenant_id` fora do TenantScope.
