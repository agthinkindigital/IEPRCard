# Estado do ciclo Developer

Este arquivo guarda somente o ponto de retomada operacional. Escopo, critérios e estados oficiais permanecem nas GitHub Issues e no roadmap canônico.

paralelismo: sequencial

- Framework `alltomatos/skills`: atualizado e instalado globalmente; clone de referência em `C:\Users\leonardo\.codex\skill-sources\alltomatos-skills`, `main` em `2f6ae04`.
- Última verificação: `php tests/run_all.php` passou localmente em SQLite e MySQL 8.4.3 via Scoop; o CI [45092682503](https://github.com/agthinkindigital/IEPRCard/actions/runs/45092682503) passou em MySQL 8.4, MariaDB 10.11 e benchmark de PDF. O checkpoint v3 de snapshots foi exercitado nos dois drivers locais e no CI.
- Auditoria do tracker: 87 Issues; 81 fechadas sem checklists de aceite pendentes e 6 abertas com categoria/estado coerentes. E01 e E03–E06 estão fechadas; E02 aguarda #51 e E07 aguarda #79, #84 e #85. A #85 continua aguardando a conclusão e validação da dependência #84. Issues fechadas usam `CLOSED` como estado terminal, conforme `docs/agents/triage-labels.md`.
- Última fatia publicada em `DEV`: [#84](https://github.com/agthinkindigital/IEPRCard/issues/84), commit `c24df09` por Leonardo Nascimento. O resumo de escopo e as evidências estão na Issue e em ADR-0012.
- Retomada de #84: contrato em ADR-0012; formato v3 e retomada em checkpoints passaram no CI. #84 segue aberta para inventário integral de PII, testes de borda e demonstração de RPO ≤ 1 h em volume representativo. A prova S3 real de #79 continua pendente; #85 depende da conclusão e validação de #84.
- Validações humanas abertas: #51 (teste local autorizado; DNS/TLS público pendente até existir hostname de staging sob controle do mantenedor) e #79 (prova de purga de versões num destino S3 real; o pacote AIStor do Scoop não comprova a exclusão exigida na camada gratuita).
- Pesquisa operacional de #79: a documentação atual do AIStor Free lista exclusão por versão como recurso Enterprise; SeaweedFS 4.54 documenta versionamento e remoção de versões S3, mas ainda não foi instalado nem validado neste projeto. A decisão atual mantém a prova externa pendente e prioriza #84.
