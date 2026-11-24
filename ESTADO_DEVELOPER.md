# Estado do ciclo Developer

Este arquivo guarda apenas o ponto de retomada operacional. Escopo, critérios e evidências detalhadas permanecem nas [GitHub Issues](https://github.com/agthinkindigital/IEPRCard/issues), no roadmap canônico e nos ADRs.

paralelismo: sequencial

## Retomada — 2026-11-24

- O clone global de `alltomatos/skills` está atualizado: `main` e `origin/main` em `2f6ae04`.
- Tracker revisado: 91 Issues; 85 fechadas e seis abertas; nenhuma `ready-for-agent`. E01 e E03–E06 estão encerradas. E02 aguarda #51 e #89; E07 aguarda #84 e, depois, #85. A #89 está `needs-info`; as outras cinco Issues abertas estão `ready-for-human`.
- O mantenedor confirmou que suporte remoto auditável em três níveis permanece no MVP. Em 2026-11-24, ampliou o requisito: o MCP deve apoiar manutenção/correção internas por IA, sem acesso para clientes, com autorização gerada pelo superadmin e janela de 30 min, 1 h, 3 h, 6 h, 12 h ou 24 h. A [#89](https://github.com/agthinkindigital/IEPRCard/issues/89) registra a decisão e aguarda o catálogo de operações, autonomia/aprovação, limites de tenant/alvo, auditoria e reversão; o runtime continua read-only até essa definição.
- #79 foi concluída e encerrada após a prova local de purga com SeaweedFS 4.55. O escopo e os limites estão na [Issue #79](https://github.com/agthinkindigital/IEPRCard/issues/79) e no [ADR-0012](docs/adr/0012-ci-deploy-e-backup.md).
- #84 tem apenas a demonstração de RPO ≤ 1 h em hospedagem compartilhada e VPS pendente. A medição local e sua projeção estão no ADR-0012; não comprovam os hosts reais.
- #51 registra os itens locais já validados e mantém pendentes DNS/TLS de teste, redação de logs no host e limites reais de execução/storage/extensões. Os verificadores de renderização passaram em PHP 8.2.35, 8.3.36, 8.4.27 e 8.5.12; isso não substitui a prova em hospedagem. Em 2026-11-24, `nginx -t` passou para o log seguro do ADR-0008; a política do executor bloqueou iniciar o proxy para a sondagem HTTP, então não há prova de redação em runtime.
- #85 depende da conclusão de #84. O fluxo aprovado usa ZIP exportado pelo IEPRCard, validação contra o snapshot/ledger S3 e restauração em MySQL isolado; mockup e guia estão nas referências da Issue.
- A ADR-0009 mantém a base read-only comprovada pela #90 e agora registra a extensão de manutenção privilegiada requerida para o MVP, ainda não implementada. O cliente externo oficial validou OAuth client credentials, escopos e tools read-only em loopback/SQLite isolado. O ensaio automatizado do provider OAuth TypeScript não passou por limitação conhecida do SDK quando o metadata correto omite `authorization_endpoint`; o teste de interoperabilidade usou o token emitido pelo endpoint e o transport do SDK Python.
- `McpOAuthTest.php` passou em PHP nativo Windows 8.5.12. A falha anterior foi falso positivo: o diretório de rate limit havia sido pré-criado pelo PowerShell, com proprietário diferente do processo PHP; o diretório criado pelo PHP passou. A #91 foi encerrada sem mudança de produto.
- Nesta sessão, Nginx 1.31.10 foi instalado via Scoop para ensaio local; a política do executor bloqueou iniciar o serviço e validar a configuração. Nenhum serviço ficou ativo. O AIStor também não estava ativo na última verificação. Não repetir a inicialização enquanto o mantenedor prepara o ambiente.
- O workspace está em `DEV`. O arquivo não rastreado `cardsaas-planejamento.zip` é preexistente e não deve ser alterado.
