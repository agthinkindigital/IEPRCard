# Estado do ciclo Developer

Este arquivo guarda somente o ponto de retomada operacional. Escopo, critérios e estados oficiais permanecem nas GitHub Issues e no roadmap canônico.

paralelismo: sequencial

- Framework alltomatos/skills: atualizado; `main` em `2f6ae04`.
- Última verificação global: 2026-11-22, `php tests/run_all.php` passou em SQLite e MySQL 9.7.4 via Scoop com banco temporário isolado; banco e variáveis de teste foram removidos. A integração específica de privacidade também passou após incluir identidade do tenant e evidências de consentimento/revogação WhatsApp nos envelopes.
- Auditoria do tracker: 87 Issues; 81 fechadas sem critérios pendentes e 6 abertas com labels coerentes. E01, E03–E06 estão fechadas; E02 aguarda #51 e E07 aguarda #79, #84 e a dependente #85. O critério de triagem para estado terminal `CLOSED` foi registrado em `docs/agents/triage-labels.md`.
- Última fatia concluída: [#87](https://github.com/agthinkindigital/IEPRCard/issues/87), commits `a93deb2` e cobertura de QA `397db9c`; CI completa aprovada em MySQL 8.4, MariaDB 10.11 e gate PDF no run [45041456021](https://github.com/agthinkindigital/IEPRCard/actions/runs/45041456021).
- Próxima fatia: continuar [#84](https://github.com/agthinkindigital/IEPRCard/issues/84), pronta para agente. Em `DEV`, migrations 0017/0018 criam o ledger opaco, ligam registros e mantêm a fila genérica de exclusão; lifecycle de 12 meses e conversas WhatsApp expiradas também estão implementados. Os snapshots incluem identidade de tenant e hostname, evidências de consentimento/revogação, relações e metadados de atividade/emissão e artefatos suportados em envelopes por sujeito; hostnames restaurados exigem nova verificação. Falta concluir o inventário integral, dar suporte a arquivos de importação/design, retomada em lotes, retenção de 30 dias e prova de RPO.
- Dependente: [#85](https://github.com/agthinkindigital/IEPRCard/issues/85), após #84.
- Validações humanas abertas: [#51](https://github.com/agthinkindigital/IEPRCard/issues/51) (teste local autorizado; DNS/TLS público pendente) e [#79](https://github.com/agthinkindigital/IEPRCard/issues/79) (destino/licença para validar exclusão de versões; AIStor via Scoop não comprova a operação exigida na licença gratuita).
