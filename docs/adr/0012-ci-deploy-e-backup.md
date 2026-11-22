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
7. **Privacidade nos backups:** cumprir a política de `docs/01-regras-de-negocio.md` também em cópias imutáveis. Snapshot cifrado por uma chave única não permite anonimizar um titular dentro de backups antigos; portanto, anonimização apenas no banco ativo ou replay de tombstone não bastam. Cada titular terá uma referência aleatória e imutável, sem PII, criada antes da primeira cópia. No formato de backup, seus dados pessoais, snapshots de carteirinha e artefatos derivados ficam em um envelope cifrado com chave aleatória própria do titular. O envelope e os arquivos de chave são separados: cada chave de titular é cifrada com `IEPR_ANONYMIZATION_KEY` e guardada em registro externo excluído dos snapshots. A tabela local `titular_chaves_backup` guarda apenas HMAC da referência, destino e estado de provisionamento/purga; ela não contém o envelope. Ao anonimizar, o worker publica tombstone pseudônimo e remove permanentemente todas as versões das chaves nos destinos registrados, inclusive destinos desativados, antes de declarar o pedido concluído. Destinos não podem ser apagados enquanto houver chaves registradas. Sem a chave, os bytes pessoais em snapshots antigos ficam criptograficamente irrecuperáveis; a base ativa continua sendo limpa pelo worker atual.
   - **Compatibilidade do destino:** só ativar para backups um serviço que prove exclusão permanente das chaves do titular. Se bucket versioning estiver habilitado, a exclusão precisa remover também versões e delete markers; retention lock ou permissão insuficiente torna o destino inválido. O teste de conexão deve criar objeto sintético, listar versões e comprovar a purga antes de permitir ativação. Destinos desativados permanecem no registro enquanto guardarem envelopes; mudanças de endpoint/bucket/credenciais e remoção ficam bloqueadas até as chaves registradas serem purgadas.
   - **Defesa de restauração:** manter tombstones pseudônimos em ledger externo como segunda barreira. Restaurar sempre em isolamento, verificar integridade e aplicar tombstones antes de liberar tráfego ou MCP; remover também linhas derivadas e artefatos. Ledger ausente/divergente, chave mestra ausente ou chave necessária inconsistente bloqueia a liberação. Um snapshot registra sua versão do ledger.
   - **Falhas e prazo de exclusão:** falha na publicação de tombstone ou destruição de chave é incidente prioritário, gera alerta sem PII e mantém o pedido aberto, medindo o limite de 72 h desde a solicitação. O worker alerta antes do vencimento e tenta novamente de modo idempotente. Só marcar como concluído depois da limpeza ativa e da confirmação de todas as exclusões externas.
   - **Retenção:** a retenção/purga dos snapshots e ledger será configurável por destino e ainda precisa ser definida antes do uso em produção. Ela limita armazenamento e metadados pseudônimos; a destruição da chave do titular é o mecanismo para tornar seus dados irrecuperáveis dentro de cópias que ainda existem.
8. **Changelog:** cada release atualiza `CHANGELOG.md` no padrão de `docs/15`, com Issue, Gate e estado do schema.

## Alternativas consideradas

| Alternativa | Prós | Contras | Veredito |
| --- | --- | --- | --- |
| Deploy in-place via FTP/rsync | Simples e barato | Sem rollback atômico, mistura versões de arquivo, risco de meia-release | Rejeitada |
| Kubernetes / ECS | Orquestração e escala | Complexidade e custo desproporcionais a um monólito em VPS | Rejeitada |
| Release atômica quando suportada, manutenção breve como fallback (escolhida) | Rollback previsível nos dois perfis | Hospedagem compartilhada pode ter curta indisponibilidade durante atualização | Aceita |

## Consequências

**Positivas:** Gates passam a ser evidência automática; o monólito pode rodar em VPS ou hospedagem compartilhada; deploy e recuperação têm critérios verificáveis.

**Negativas:** hosts compartilhados podem impor limites que inviabilizem cópia a cada 15 minutos, grandes volumes ou restauração em quatro horas. Destinos não S3 adicionam implementação e validação específicas. Cada configuração real precisa provar exclusão permanente das chaves, além de passar por teste de envio e restauração; perda do registro de chaves ou de `IEPR_ANONYMIZATION_KEY` pode tornar dados ativos irrecuperáveis. Falha no ledger bloqueia o restore.

## Como validar

- Pipeline vermelho se qualquer Gate falhar; nenhum merge em `master` sem CI verde.
- Smoke test de deploy/rollback nos perfis VPS e PHP compartilhado; validar a janela de manutenção quando não houver troca atômica.
- Testar envio e restauração em cada destino habilitado, medindo RPO/RTO; verificar que anonimizar destrói as chaves de todos os destinos e torna envelopes de snapshots antigos irrecuperáveis; verificar que tombstones posteriores limpam dados/artefatos antes da liberação e que chave/ledger ausentes mantêm o sistema bloqueado.
- Nenhum segredo detectado pelo scanner no histórico recente.

## Referências de compatibilidade S3

O adaptador S3 deve limitar-se às operações de objeto necessárias ao backup e ao restore; compatibilidade S3 não implica paridade completa entre fornecedores. Cloudflare R2 declara compatibilidade com SDKs S3 e documenta sua matriz de operações, enquanto MinIO documenta seu subconjunto compatível. Avaliar cada operação usada antes de certificar um destino: [Cloudflare R2 — S3 API](https://developers.cloudflare.com/r2/get-started/s3/), [R2 — compatibilidade](https://developers.cloudflare.com/r2/api/s3/api/), [MinIO — APIs S3](https://docs.min.io/aistor/developers/s3-api-compatibility/).
