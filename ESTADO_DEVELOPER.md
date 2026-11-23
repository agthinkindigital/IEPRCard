# Estado do ciclo Developer

Este arquivo guarda somente o ponto de retomada operacional. Escopo, critérios e estados oficiais permanecem nas GitHub Issues e no roadmap canônico.

paralelismo: sequencial

- Framework `alltomatos/skills`: clone global em `C:\Users\leonardo\.codex\skill-sources\alltomatos-skills`, `main` em `2f6ae04`; verificação de 2026-11-23 encontrou a mesma revisão no GitHub.
- Verificações: a suíte completa local passou em MySQL 8.4.3 e SQLite; o CI [45104561162](https://github.com/agthinkindigital/IEPRCard/actions/runs/45104561162) passou em MySQL 8.4, MariaDB 10.11 e benchmark de PDF no commit `311acb1`. Os testes de snapshots cobrem envelopes e minimização de PII, ausência de chave/plaintext, checksum, checkpoint retomável sem publicação parcial, retry após confirmação ambígua, destinos independentes e retenção.
- Auditoria do tracker: 87 Issues; 81 fechadas sem checklists de aceite pendentes e 6 abertas com categoria/estado coerentes. E01 e E03–E06 estão fechadas; E02 aguarda #51 e E07 aguarda #79, #84 e #85. A #85 foi atualizada para `ready-for-agent` após as respostas do mantenedor, com brief publicado; sua execução continua sequencial após a validação de #84. Issues fechadas usam `CLOSED` como estado terminal, conforme `docs/agents/triage-labels.md`.
- Última fatia publicada em `DEV`: [#84](https://github.com/agthinkindigital/IEPRCard/issues/84), commit `311acb1` por Leonardo Nascimento. O contrato técnico e as evidências estão no ADR-0012.
- #84: implementação verificada e `ready-for-human` apenas para medir RPO ≤ 1 h com cron e S3 no perfil real de hospedagem. O fixture sintético (10.000 titulares/50.000 carteirinhas) publicou ao retomar checkpoint no SeaweedFS local; essa medição não representa execução desde o início nem latência de um host real. A prova S3 externa de purga da #79 continua pendente.
- Validações humanas abertas: #51 (teste local autorizado; DNS/TLS público pendente até existir hostname de staging sob controle do mantenedor) e #79 (prova de purga de versões num destino S3 real; o pacote AIStor do Scoop não comprova a exclusão exigida na camada gratuita).
- Pesquisa operacional de #79: a documentação atual do AIStor Free lista exclusão por versão como recurso Enterprise; SeaweedFS 4.54 foi usado somente como destino local para o benchmark sintético de #84. A prova de purga em provedor externo permanece pendente.
