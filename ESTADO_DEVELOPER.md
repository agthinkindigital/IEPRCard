# Estado do ciclo Developer

Este arquivo guarda apenas o ponto de retomada operacional. Escopo, critérios e evidências detalhadas permanecem nas [GitHub Issues](https://github.com/agthinkindigital/IEPRCard/issues), no roadmap canônico e nos ADRs.

paralelismo: sequencial

## Retomada — 2026-11-24

- O clone global de `alltomatos/skills` está atualizado: `main` e `origin/main` em `2f6ae04`.
- Tracker revisado: 88 Issues; 83 fechadas e cinco abertas, todas `ready-for-human`; nenhuma `ready-for-agent`. E01 e E03–E06 estão encerradas. E02 aguarda a prova operacional da #51; E07 aguarda #84 e, depois, #85.
- #79 foi concluída e encerrada após a prova local de purga com SeaweedFS 4.55. O escopo e os limites estão na [Issue #79](https://github.com/agthinkindigital/IEPRCard/issues/79) e no [ADR-0012](docs/adr/0012-ci-deploy-e-backup.md).
- #84 tem apenas a demonstração de RPO ≤ 1 h em hospedagem compartilhada e VPS pendente. A medição local e sua projeção estão no ADR-0012; não comprovam os hosts reais.
- #51 registra os itens locais já validados e mantém pendentes DNS/TLS de teste, redação de logs no host e limites reais de execução/storage/extensões. Os verificadores de renderização passaram em PHP 8.2.35, 8.3.36, 8.4.27 e 8.5.12; isso não substitui a prova em hospedagem.
- #85 depende da conclusão de #84. O fluxo aprovado usa ZIP exportado pelo IEPRCard, validação contra o snapshot/ledger S3 e restauração em MySQL isolado; mockup e guia estão nas referências da Issue.
- Nesta sessão, Nginx 1.31.10 foi instalado via Scoop para ensaio local; a política do executor bloqueou iniciar o serviço e validar a configuração. Nenhum serviço ficou ativo. O AIStor também não estava ativo na última verificação. Não repetir a inicialização enquanto o mantenedor prepara o ambiente.
- O workspace está em `DEV`. O arquivo não rastreado `cardsaas-planejamento.zip` é preexistente e não deve ser alterado.
