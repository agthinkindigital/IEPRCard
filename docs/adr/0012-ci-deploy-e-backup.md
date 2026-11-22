# ADR-0012: CI com quality gates, deploy atômico e backup testado

| Campo | Valor |
| --- | --- |
| Status | Proposta — perfis compartilhado PHP e VPS, objetivos internos aceitos e destinos externos configuráveis; validar operação antes do lançamento |
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
5. **Hospedagem:** suportar tanto PHP/MySQL compartilhado e econômico com tarefas curtas via cron, quanto VPS. O perfil compartilhado não depende de daemon, Redis, Docker ou root. `php cron.php --once` coordena em ordem estável um job de emissão, a limpeza limitada de staging e a anonimização limitada, com lock não bloqueante, execução isolada por tarefa e saída segura. Outbox e agregações entram no coordenador somente quando seus workers forem implementados e fatiados no GitHub. Extensões, cron, limites de execução, conexões MySQL, storage privado e backup variam por host e precisam ser verificados antes do deploy; o sistema não se prende a um plano ou provedor. Ver `docs/architecture/SAD.md` §7.1.
5a. **Migração inicial SQLite→MySQL:** o pedido único de cópia fica em `var/admin-migration-job.json`, fora da raiz pública, pois a operação precisa continuar disponível antes e durante a troca do banco ativo. A interface persiste somente metadados operacionais; o worker CLI do Cron usa o serviço E01.3, publica avanço por tabela após contagem/checksum e nunca persiste credenciais ou linhas de titulares.
6. **Destinos e recuperação:** a configuração deve aceitar um ou vários destinos externos e executar cópias independentes, com resultado e último sucesso por destino. O primeiro adaptador prioriza a API S3, configurável por endpoint e credenciais para AWS S3, Cloudflare R2 e serviços S3 compatíveis como MinIO self-hosted, Garage e Ceph RGW. Provedores sem API S3, como MEGA, exigem adaptador próprio e verificação de compatibilidade com PHP compartilhado; não se presume suporte por trocar apenas o endpoint. Cada destino pode ser ativado/desativado e testado sem exibir ou registrar segredo. O objetivo interno é disponibilidade de 99,5% mensal, RPO ≤ 1 h e RTO ≤ 4 h; não é SLA contratual nem garantia do provedor. Para RPO de uma hora, manter cópias incrementais cifradas externas em intervalos ≤ 15 min ou comprovar recurso equivalente. Testar restauração mensal; registrar cobertura, falhas e idade da cópia por destino.
7. **Privacidade nos backups:** cumprir a política de `docs/01-regras-de-negocio.md` também em cópias imutáveis. A estratégia precisa impedir que uma restauração reintroduza dados pessoais após anonimização. A gestão de chaves e a retenção devem ser validadas por destino; detalhes de criptografia não podem ficar embutidos no provedor nem expostos em logs.
8. **Changelog:** cada release atualiza `CHANGELOG.md` no padrão de `docs/15`, com Issue, Gate e estado do schema.

## Alternativas consideradas

| Alternativa | Prós | Contras | Veredito |
| --- | --- | --- | --- |
| Deploy in-place via FTP/rsync | Simples e barato | Sem rollback atômico, mistura versões de arquivo, risco de meia-release | Rejeitada |
| Kubernetes / ECS | Orquestração e escala | Complexidade e custo desproporcionais a um monólito em VPS | Rejeitada |
| Release atômica quando suportada, manutenção breve como fallback (escolhida) | Rollback previsível nos dois perfis | Hospedagem compartilhada pode ter curta indisponibilidade durante atualização | Aceita |

## Consequências

**Positivas:** Gates passam a ser evidência automática; o monólito pode rodar em VPS ou hospedagem compartilhada; deploy e recuperação têm critérios verificáveis.

**Negativas:** hosts compartilhados podem impor limites que inviabilizem cópia a cada 15 minutos, grandes volumes ou restauração em quatro horas. Destinos não S3 adicionam implementação e validação específicas. Cada configuração real precisa passar por teste de envio e restauração.

## Como validar

- Pipeline vermelho se qualquer Gate falhar; nenhum merge em `master` sem CI verde.
- Smoke test de deploy/rollback nos perfis VPS e PHP compartilhado; validar a janela de manutenção quando não houver troca atômica.
- Testar envio e restauração em cada destino habilitado, medindo RPO/RTO e verificando que dados anonimizados não voltam a ser acessíveis.
- Nenhum segredo detectado pelo scanner no histórico recente.

## Referências de compatibilidade S3

O adaptador S3 deve limitar-se às operações de objeto necessárias ao backup e ao restore; compatibilidade S3 não implica paridade completa entre fornecedores. Cloudflare R2 declara compatibilidade com SDKs S3 e documenta sua matriz de operações, enquanto MinIO documenta seu subconjunto compatível. Avaliar cada operação usada antes de certificar um destino: [Cloudflare R2 — S3 API](https://developers.cloudflare.com/r2/get-started/s3/), [R2 — compatibilidade](https://developers.cloudflare.com/r2/api/s3/api/), [MinIO — APIs S3](https://docs.min.io/aistor/developers/s3-api-compatibility/).
