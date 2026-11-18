# ADR-0004: RBAC de teto descendente e cotas elásticas

| Campo | Valor |
| --- | --- |
| Status | Aceita com ajustes de nomenclatura (baseline `docs/01`, `docs/04`, `docs/11` ISSUE-005/005B) |
| Data | 2026-11-18 |
| Decisores | Arquiteto + dono do produto |
| Relacionados | [SAD §3.3, §5.3](../architecture/SAD.md), ADR-0003, ADR-0005 |

## Contexto

O modelo real é Sede + congregações/filiais. Apenas Admin SaaS e Cliente Master podem criar sub-acessos; o Subcliente nunca cria usuários administrativos. Existem 4 dimensões de cota (`limite_subclientes`, `limite_usuarios_equipe`, `limite_titulares`, `limite_carteirinhas`) que o Cliente pode distribuir entre unidades, com possibilidade de pool compartilhado (`NULL`) ou cota cravada, e override pelo Admin.

## Decisão

1. **Resolução de permissão:** `efetivas = (papel ∪ overrides) ∩ teto_plano ∩ teto_cliente`; negação por padrão. Um único motor `includes/motor_permissoes.php` expõe `pode(string $perm, ?int $subclienteId = null): bool` (unifica `rbac.php` e `motor_permissoes.php` de `docs/02`/`docs/04`).
2. **Criação centralizada:** guard obrigatório no controller de equipe — somente `superadmin` e `titular_cliente` criam/alteram sub-acessos da Sede e dos Subclientes.
3. **Perfis padrão semeados:** Dirigente de Subcliente, Secretário (emissor), Tesoureiro, Cadastrador de Membros — editáveis pelo Cliente Master.
4. **Cotas:** `Σ cota_filha ≤ teto_sede` validado no backend; `NULL` = pool compartilhado; Admin pode travar/bonificar qualquer nível.
5. **Débito linearizável:** `UPDATE tenants SET consumo = consumo + :n WHERE id = :id AND consumo + :n <= limite`; `rowCount() === 1` confirma reserva; índice único `(titular_id, design_id, lote_id)` impede duplicidade.
6. **Cache e auditoria:** cache de RBAC por requisição, invalidado pelo evento `permissao.alterada` (adicionado ao catálogo de `docs/05`); toda alteração grava `auditoria_processos` com antes/depois.

### Contrato executável do MVP

- `permissoes.chave` é o identificador canônico de uma permissão. `plano_feature_flags.feature_key` usa o mesmo valor; falta de linha ou `habilitado = 0` significa negado.
- `perfis_acesso.permissoes_acoes_json` é uma lista JSON de chaves de permissão, por exemplo `["titular.ver","titular.criar"]`. `escopo_permitido` aceita `sede`, `subcliente` ou `rede`: Sede limita ao próprio cadastro; subcliente limita à unidade associada ao usuário; rede permite ao subacesso delegado selecionar Sede ou unidades do mesmo tenant.
- `tenants.permissoes_unidade_json` é a lista JSON que a Sede delega a todos os operadores de seus Subclientes. Ela é um teto: papel, perfil e override nunca concedem permissão fora dessa lista ou do plano.
- A allowlist da unidade aceita somente permissões de negócio dos grupos `titular.*`, `carteirinha.*`, `design.*` e a chave `telemetria.unidade`. Cada chave precisa existir em `permissoes` e estar habilitada em `plano_feature_flags`; grupos administrativos, financeiros, de cota, equipe e gestão de Subclientes não são delegáveis. O motor aplica esse limite mesmo se o JSON for adulterado diretamente. A atualização é autorizada por `usuario.permissoes` na própria Sede ou por `admin.clientes` no contexto global, serializada pelo lock da Sede e auditada com as listas antes/depois sem dados pessoais.
- `usuario_permissoes` substitui a permissão do perfil no escopo da linha: um registro `permitido = 0` nega; `permitido = 1` permite se o teto do plano e, para Subcliente, o da Sede também permitem. Um override por unidade prevalece sobre o override geral do usuário.
- Antes de avaliar permissão, `pode()` relê o usuário e a hierarquia no banco; sessão, host e IDs do request não substituem identidade persistida nem `TenantContext`. Usuário inativo, conta suspensa, contexto incompatível, perfil de outro tenant, estado/escopo desconhecido ou JSON inválido falham fechados.
- `titular_cliente` atua em qualquer unidade da própria Sede conforme o plano. `responsavel_sub` só atua na própria unidade e dentro da lista delegada pela Sede. `subacesso` usa perfil e overrides dentro do `escopo_permitido`. `titular_final` não recebe permissões administrativas. `superadmin` só recebe `admin.*` em contexto global e sem tenant associado.
- A implementação canônica fica em `config/motor_permissoes.php`; o formato é especificado aqui para evitar cópias em backlog e telas.

## Alternativas consideradas

| Alternativa | Prós | Contras | Veredito |
| --- | --- | --- | --- |
| Permissões irrestritas por unidade | Simples de implementar | Viola regra de negócio central; permite que dirigente crie secretários sem consentimento da Sede | Rejeitada |
| Verificação distribuída em cada página | Flexível | Fácil esquecer uma rota; auditoria fragmentada | Rejeitada |
| Motor central + teto descendente (escolhida) | Regra única, auditável, testável, compatível com row-level do ADR-0003 | Mais uma tabela de override e um ponto de cache a invalidar | Aceita |

## Consequências

**Positivas:** escalonamento de privilégio bloqueado em um único lugar; cotas respeitam contrato mesmo sob concorrência; perfis padrão aceleram onboarding.

**Negativas:** resolução de permissão entra em toda requisição (mitigado por cache por request e índices); reserva de cota com TTL exige limpeza no worker.

## Como validar

- `TDD-02`: Subcliente receber permissão que o Cliente não possui → bloqueio.
- `TDD-02B`: 7º subcliente bloqueado; dirigente de Itaquera recebe 403 ao criar secretário; importação de 350 membros com cota 300 barrada antes da gravação; `cortesia_admin` ignora `PAYMENT_OVERDUE`.
- Spec de segurança: escalonamento via parâmetro `perfil_acesso_id` no POST.
