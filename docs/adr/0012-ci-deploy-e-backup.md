# ADR-0012: CI com quality gates, deploy atômico e backup testado

| Campo | Valor |
| --- | --- |
| Status | Proposta — inclui o perfil de hospedagem e os objetivos internos aceitos; validar no plano contratado |
| Data | 2026-11-18 |
| Decisores | Arquiteto + operações + `/qa-analyst` |
| Relacionados | [SAD §7, §9 R10, §10](../architecture/SAD.md), ADR-0010, ADR-0011 |

## Contexto

`docs/12` define Gates 1–5, mas o projeto ainda não tem pipeline, estratégia de deploy, compatibilidade verificada com hospedagem compartilhada ou recuperação testada. `docs/10` lista oito suítes TDD. O sistema deve funcionar em PHP/MySQL compartilhado sem processos residentes, mas os limites de execução e backup variam por plano.

## Decisão

1. **Suítes canônicas:** 8 suítes — TDD-01, TDD-02, TDD-02B, TDD-03, TDD-04, TDD-05, TDD-06, TDD-07. Atualizar `docs/14` e o runner `tests/run_all.php`.
2. **Pipeline:** `pre-commit` (php -l, secret scan, lint) → CI com `php -l` → suítes em SQLite efêmero → suítes de compatibilidade em MySQL (01/02/03/05/06) → specs de segurança → benchmark do Gate 3 → build do release. Gate N só passa com código de saída 0.
3. **Governança:** Conventional Commits, feature branches, proibição de commit direto em `master`, merge condicionado a testes verdes e aprovação do `/qa-analyst`. Nenhum `.env` ou segredo versionado.
4. **Deploy:** em VPS, releases imutáveis e troca atômica de symlink. Em hospedagem compartilhada, usar troca atômica se disponível; caso contrário, janela breve de manutenção e rollback documentado. Migrações são expand-only (ADR-0010).
5. **Hospedagem:** oferecer perfil PHP/MySQL compartilhado com tarefas curtas via cron, sem daemon, Redis, Docker ou root. `cron.php` coordena fila, outbox, agregações e retenção em uma invocação com limite de trabalho, checkpoint e idempotência. Confirmar extensões, cron, limites de execução, conexões MySQL, storage privado e backup antes do deploy. Versões e limitações estão resumidas em `docs/architecture/SAD.md` §7.1.
5a. **Migração inicial SQLite→MySQL:** o pedido único de cópia fica em `var/admin-migration-job.json`, fora da raiz pública, pois a operação precisa continuar disponível antes e durante a troca do banco ativo. A interface persiste somente metadados operacionais; o worker CLI do Cron usa o serviço E01.3, publica avanço por tabela após contagem/checksum e nunca persiste credenciais ou linhas de titulares.
6. **Recuperação:** objetivo interno aceito de 99,5% mensal, RPO ≤ 1 h e RTO ≤ 4 h; não é SLA contratual nem garantia do provedor. Para RPO de uma hora, manter cópia incremental cifrada externa em intervalos ≤ 15 min ou comprovar recurso equivalente do host. Testar restauração mensal no plano contratado. Se o plano não permitir esse fluxo, declarar a meta incompatível e escolher plano/provedor ou objetivo diferente antes do lançamento.
7. **Privacidade nos backups:** cumprir a política de `docs/01-regras-de-negocio.md` também em cópias imutáveis. Preferir chave individual por titular em serviço externo fora do domínio de backup para tornar dados inacessíveis após anonimização; se o host não permitir o acesso externo necessário, escolher outra estratégia ou hospedagem antes da implementação.
8. **Changelog:** cada release atualiza `CHANGELOG.md` no padrão de `docs/15`, com Issue, Gate e estado do schema.

## Alternativas consideradas

| Alternativa | Prós | Contras | Veredito |
| --- | --- | --- | --- |
| Deploy in-place via FTP/rsync | Simples e barato | Sem rollback atômico, mistura versões de arquivo, risco de meia-release | Rejeitada |
| Kubernetes / ECS | Orquestração e escala | Complexidade e custo desproporcionais a um monólito em VPS | Rejeitada |
| Release atômica quando suportada, manutenção breve como fallback (escolhida) | Rollback previsível nos dois perfis | Hospedagem compartilhada pode ter curta indisponibilidade durante atualização | Aceita |

## Consequências

**Positivas:** Gates passam a ser evidência automática; o monólito pode rodar em VPS ou hospedagem compartilhada; deploy e recuperação têm critérios verificáveis.

**Negativas:** hospedagem compartilhada pode não suportar backup incremental a cada 15 minutos nem restauração em quatro horas; sem storage privado e gestão externa de chaves, também pode não cumprir o prazo de anonimização. O plano real precisa passar pela pré-verificação.

## Como validar

- Pipeline vermelho se qualquer Gate falhar; nenhum merge em `master` sem CI verde.
- Teste de deploy e rollback no perfil VPS e no compartilhado escolhido; validar a janela de manutenção quando não houver troca atômica.
- Restore mensal documentado no plano contratado, medindo RPO/RTO e verificando que dados anonimizados não voltam a ser acessíveis.
- Nenhum segredo detectado pelo scanner no histórico recente.

## Referência do provedor de exemplo

As páginas oficiais atuais da Hostinger indicam que cron e limites de recursos dependem do plano. Backups semanais estão disponíveis na hospedagem; backups diários variam por plano ou contratação adicional, e os snapshots descritos têm retenção de sete dias sem exclusão manual. Esses dados devem ser conferidos novamente antes da contratação.

- [Cron jobs](https://www.hostinger.com/support/1583465-how-to-set-up-a-cron-job-at-hostinger/)
- [Limites dos planos](https://www.hostinger.com/support/6976044-parameters-and-limits-of-hosting-plans-in-hostinger/)
- [Política de backups](https://support.hostinger.com/en/articles/1665153-how-to-activate-daily-backups)
