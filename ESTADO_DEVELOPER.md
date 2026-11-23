# Estado do ciclo Developer

Este arquivo guarda somente o ponto de retomada operacional. Escopo, critérios e estados oficiais permanecem nas GitHub Issues e no roadmap canônico.

paralelismo: sequencial

- Framework `alltomatos/skills`: clone global em `C:\Users\leonardo\.codex\skill-sources\alltomatos-skills`, `main` em `2f6ae04`; verificação de 2026-11-23 encontrou a mesma revisão no GitHub.
- Verificações: o CI [45099831886](https://github.com/agthinkindigital/IEPRCard/actions/runs/45099831886) passou em MySQL 8.4, MariaDB 10.11 e benchmark de PDF no commit `9d13862`. Os testes de snapshots cobrem envelopes e minimização de PII, ausência de chave/plaintext, checksum, checkpoint retomável sem publicação parcial, retry após confirmação ambígua, destinos independentes e retenção.
- Auditoria do tracker: 87 Issues; 81 fechadas sem checklists de aceite pendentes e 6 abertas com categoria/estado coerentes. E01 e E03–E06 estão fechadas; E02 aguarda #51 e E07 aguarda #79, #84 e #85. A #85 foi atualizada para `ready-for-agent` após as respostas do mantenedor, com brief publicado; sua execução continua sequencial após a validação de #84. Issues fechadas usam `CLOSED` como estado terminal, conforme `docs/agents/triage-labels.md`.
- Última fatia publicada em `DEV`: [#84](https://github.com/agthinkindigital/IEPRCard/issues/84), commit `9d13862` por Leonardo Nascimento. O resumo de escopo e as evidências estão na Issue e em ADR-0012.
- Retomada de #84: inventário de 37 tabelas, privacidade e checkpoints retomáveis revisados; o cron solicita até 4.000 linhas por chamada e persiste partes de até 50 linhas ou 8 MiB. A Issue segue aberta para demonstrar RPO ≤ 1 h em volume representativo com destino S3 funcional. O AIStor local do Scoop bloqueou operações sem licença; não serve como evidência. A prova S3 real de #79 continua pendente.
- Validações humanas abertas: #51 (teste local autorizado; DNS/TLS público pendente até existir hostname de staging sob controle do mantenedor) e #79 (prova de purga de versões num destino S3 real; o pacote AIStor do Scoop não comprova a exclusão exigida na camada gratuita).
- Pesquisa operacional de #79: a documentação atual do AIStor Free lista exclusão por versão como recurso Enterprise; SeaweedFS 4.54 documenta versionamento e remoção de versões S3, mas ainda não foi instalado nem validado neste projeto. A decisão atual mantém a prova externa pendente e prioriza #84.
