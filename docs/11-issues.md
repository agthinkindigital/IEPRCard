# Backlog de Issues

## 9. Backlog Detalhado de Issues para Execução

### ÉPICO 1: Núcleo Monolítico e Banco de Dados Híbrido (Req 01, 02)

* **ISSUE-001: Camada de Conexão PDO Dual (SQLite + MySQL/MariaDB)**
* **Arquivos:** `config/conn.php`, `.env.example`
* **Descrição:** Criar a conexão PDO com suporte a `DB_DRIVER=sqlite` e `DB_DRIVER=mysql`, incluindo funções wrapper leves (`dbQuery`, `dbFetchOne`, `dbFetchAll`, `dbExecute`, `dbInsertId`, `dbAgora`) para manter a ergonomia do código consumidor. Valores externos devem usar prepared statements; não criar helper de escape para montar SQL.


* **Critério de Aceite:** Alternar a variável `DB_DRIVER` conecta nos dois bancos sem alterar uma única linha nos arquivos consumidores.


* **ISSUE-002: Schema canônico e migrações compatíveis com SQLite e MySQL**
* **Arquivos:** `config/schema_self_healing.php`, `tests/Integration/DualDatabaseSelfHealingTest.php`
* **Descrição:** Validar paridade do catálogo MVP nos dois drivers. Autocorreção estrutural fica restrita a dev/test; produção usa migrações versionadas, expand-only e compatíveis com ADR-0010. Tabelas financeiras adiadas não entram neste escopo.


* **Critério de Aceite:** Passar no `TDD-01`.


* **ISSUE-003: Utilitário de Migração automatizada SQLite $\rightarrow$ MySQL/MariaDB** — serviço e CLI entregues em [GitHub #10](https://github.com/agthinkindigital/IEPRCard/issues/10); interface administrativa E01.4 concluída em [GitHub #11](https://github.com/agthinkindigital/IEPRCard/issues/11). Destino permite apenas o catálogo `permissoes` pre-semeado e idêntico à origem; qualquer outro dado preexistente bloqueia.
* **Arquivos:** serviço `database/migration/sqlite_to_mysql.php`, comando CLI `database/migrate-sqlite-to-mysql.php` e interface administrativa protegida em `public/admin/migracao-banco.php`.
* **Descrição:** Serviço e script CLI transferem as tabelas SQLite para MySQL/MariaDB com verificação de integridade. A interface aprovada permite pré-verificar, enfileirar e acompanhar o worker sem repetir a lógica de migração.
* **Critério de Aceite:** Migrar o fixture de 1.000 registros em menos de 5 segundos no ambiente de referência, com contagens e checksums idênticos, chaves estrangeiras válidas e rollback integral se a transferência falhar.



### ÉPICO 2: Multi-Tenancy, Domínios, Subdomínios e Permissões (Req 03, 04, 05, 12, 13)

* **ISSUE-004: Resolvedor de Domínios (Clientes) e Subdomínios (Subclientes)** — fundação E02.2 concluída em [GitHub #13](https://github.com/agthinkindigital/IEPRCard/issues/13); serviço de cadastro/prova DNS concluído em [GitHub #14](https://github.com/agthinkindigital/IEPRCard/issues/14); criação backend de Subcliente com teto concluída na [GitHub #24](https://github.com/agthinkindigital/IEPRCard/issues/24). Mockup/guia aprovado em [#49](https://github.com/agthinkindigital/IEPRCard/issues/49) e tela autenticada concluída em [#54](https://github.com/agthinkindigital/IEPRCard/issues/54). Perfis de instalação aprovados: serviço/roteamento em [#86](https://github.com/agthinkindigital/IEPRCard/issues/86), tela superadmin após backend em [#87](https://github.com/agthinkindigital/IEPRCard/issues/87). Prova operacional TLS/aliases do perfil Hospedado em [#51](https://github.com/agthinkindigital/IEPRCard/issues/51).
* **Arquivos:** `config/tenant_resolver.php`, `index.php`
* **Descrição:** Identificar pelo `$_SERVER['HTTP_HOST']` se o acesso pertence ao Admin Principal, a um Cliente (via domínio próprio CNAME ou subdomínio de 1º nível) ou a um Subcliente (via subdomínio de 2º nível ou subdomínio do domínio do Cliente), carregando o branding e escopo de banco correspondente.
* **Critério de Aceite:** Passar no `TDD-02` (cenários de resolução de host).

* **ISSUE-018: Sessão administrativa, autenticação e CSRF** — E02.1 concluída em [GitHub #12](https://github.com/agthinkindigital/IEPRCard/issues/12); autenticação tenant E02.5 concluída em [GitHub #16](https://github.com/agthinkindigital/IEPRCard/issues/16). A configuração web do primeiro `superadmin`, que substitui a exigência CLI-only de #12, foi implementada em `DEV` e validada na [GitHub #62](https://github.com/agthinkindigital/IEPRCard/issues/62). Critérios executáveis e evidências ficam nas Issues.


* **ISSUE-005: Motor de Permissões em Cascata (Admin $\rightarrow$ Cliente $\rightarrow$ Subcliente)** — núcleo `pode()` e teto de plano concluídos na [GitHub #15](https://github.com/agthinkindigital/IEPRCard/issues/15), CI MySQL 8.4/MariaDB 10.11 aprovada; allowlist delegada pela Sede concluída na [GitHub #25](https://github.com/agthinkindigital/IEPRCard/issues/25), também validada contra adulteração direta do JSON. O provisionamento backend de operadores de unidade com cota foi concluído na [GitHub #26](https://github.com/agthinkindigital/IEPRCard/issues/26), com concorrência validada. O contrato está em [ADR-0004](adr/0004-rbac-teto-descendente-e-cotas.md#contrato-executável-do-mvp); cotas permanecem na fatia E02 separada.
* **Arquivos:** `includes/motor_permissoes.php`, `config/proteger_admin.php`, `config/proteger_painel.php`
* **Descrição:** Implementar controle granular de permissões onde o Admin define as *Feature Flags* e limites do Cliente (Req 13), e o Cliente define quais permissões e fatias de cota libera para cada um de seus Subclientes (Req 12).
* **Critério de Aceite:** Bloquear qualquer tentativa de escalonamento de privilégio (Subcliente tentando ativar permissão revogada pelo Cliente ou Admin).


* **ISSUE-006: Painel de Gestão de Clientes, Subclientes e Suporte Remoto Auditável** — mockups/guias de unidades, equipe e cotas aprovados na [GitHub #50](https://github.com/agthinkindigital/IEPRCard/issues/50); telas de unidades [#56](https://github.com/agthinkindigital/IEPRCard/issues/56), equipe/permissões [#55](https://github.com/agthinkindigital/IEPRCard/issues/55) e alocação de cotas [#57](https://github.com/agthinkindigital/IEPRCard/issues/57) concluídas em `DEV`. A validação operacional de DNS/TLS segue em [#51](https://github.com/agthinkindigital/IEPRCard/issues/51).
* **Arquivos:** `admin/clientes.php`, `painel/subclientes.php`, `includes/suporte.php`, `painel/suporte-remoto.php`


* **Descrição:** Interfaces CRUD completas para o Admin gerenciar Clientes/Subclientes e para o Cliente gerenciar seus Subclientes, integrando o sistema de tokens temporários de suporte de `suporte.php`.


* **Critério de Aceite:** Geração de token de suporte com expiração de 60 minutos e registro de auditoria (`usado_em`, `ip_usado`).





### ÉPICO 3: Importação em Lote, Preenchimento Manual e Gestão de Titulares (Req 06, 07)

* **ISSUE-007: Motor de Importação em Lote (CSV, XLS/XLSX e JSON) + Fotos em ZIP** — parser CSV/XLSX/JSON em [#28](https://github.com/agthinkindigital/IEPRCard/issues/28), staging privado/prévia/cancelamento/expiração em [#31](https://github.com/agthinkindigital/IEPRCard/issues/31), confirmação atômica com mapeamento em [#32](https://github.com/agthinkindigital/IEPRCard/issues/32), preservação da origem dos titulares em [#45](https://github.com/agthinkindigital/IEPRCard/issues/45), vínculo seguro do ZIP por CPF/matrícula em [#48](https://github.com/agthinkindigital/IEPRCard/issues/48) e `.xls` BIFF 8 no parser/staging em [#52](https://github.com/agthinkindigital/IEPRCard/issues/52) foram concluídos com CI SQLite, MySQL 8.4, MariaDB 10.11 e PDF. Consulta/reabertura autorizada foi concluída na [#59](https://github.com/agthinkindigital/IEPRCard/issues/59); a interface aprovada de consulta, cadastro individual e importação foi implementada e verificada na [#60](https://github.com/agthinkindigital/IEPRCard/issues/60), com CI SQLite, MySQL 8.4, MariaDB 10.11 e PDF aprovada.
* **Arquivos:** `config/batch_import_parser*.php`, `config/batch_import_staging*.php`, `includes/libs/SimpleXLS.php`, `painel/importar-dados.php`
* **Descrição:** Upload e leitura de arquivos `.csv`, `.xls`, `.xlsx` e `.json` com detecção automática de cabeçalhos, pré-visualização das primeiras 10 linhas, validação de CPF/Telefone e vínculo automático de fotos enviadas em pacote `.zip` (associadas por CPF ou matrícula).


* **Critério de Aceite:** Passar no `TDD-03` importando 500 registros em CSV, XLS, XLSX e JSON sem estourar memória.


* **ISSUE-008: Preenchimento Manual Individual (com Webcam/Crop) e em Lote (Grade Web)** — os serviços backend de cadastro individual com cota ([#27](https://github.com/agthinkindigital/IEPRCard/issues/27)), criação atômica em chunks de até 25 ([#29](https://github.com/agthinkindigital/IEPRCard/issues/29)) e persistência de WhatsApp ([#30](https://github.com/agthinkindigital/IEPRCard/issues/30)) foram concluídos. O cadastro individual e a importação atômica aprovados para o MVP estão implementados na [#60](https://github.com/agthinkindigital/IEPRCard/issues/60); webcam/crop e grade manual em lote não integram esse escopo.
* **Arquivos, descrição e critérios executáveis:** mantidos na Issue [#60](https://github.com/agthinkindigital/IEPRCard/issues/60) para evitar duplicar o guia aprovado e os critérios de implementação.



### ÉPICO 4: Editor de Layouts, Mapeamento Duplo e Renderização PDF/WebP (Req 08, 09, 10, 11, 17, 17.1)

O contrato do design e o motor puro de mapeamento foram concluídos em [GitHub #33](https://github.com/agthinkindigital/IEPRCard/issues/33) e [GitHub #34](https://github.com/agthinkindigital/IEPRCard/issues/34); a especificação canônica permanece em `docs/06-design-e-mapeamento.md`. Interfaces seguem o gate de aprovação explícita de mockup e guia.

O perfil GD/WebP/FreeType/`mbstring` foi validado na CI MySQL/MariaDB e concluído na [GitHub #35](https://github.com/agthinkindigital/IEPRCard/issues/35). O comando `php scripts/check-rendering-runtime.php` permite verificar o runtime antes da emissão.

O renderizador backend de frente/verso WebP, dependente dos três contratos concluídos e ainda sem interface, está fatiado na [GitHub #36](https://github.com/agthinkindigital/IEPRCard/issues/36).

* **ISSUE-009: Gestor de Múltiplos Designs e Editor Visual de Coordenadas** — mockup e guias aprovados; catálogo e editor implementados em `DEV` na [#61](https://github.com/agthinkindigital/IEPRCard/issues/61). Escopo e critérios de aceite permanecem na Issue.


* **ISSUE-010: Wizard de Mapeamento de Dados (Fixo vs Etapa da Geração - Req 17 e 17.1)**
* **Arquivos:** `includes/motor_mapeamento.php`, `painel/mapear-gerar.php`
* **Descrição:** Ao selecionar 1 titular ou um lote de dados, permitir usar o mapeamento fixo do layout ou abrir a etapa interativa que liga campos do titular a variáveis do design, combina campos em templates ou define valores fixos, com preview antes de confirmar. Persistência/aplicação do mapa por job e vínculo com idempotência foram concluídos na [GitHub #72](https://github.com/agthinkindigital/IEPRCard/issues/72). O mapa de colunas da importação permanece independente. Mockup e guia aprovados em 2026-11-20: [SVG](../design-system/mockups/emitir-carteirinhas-mockup.svg) e [guia funcional](userguide/emitir-carteirinhas.md). Emissão individual preserva a origem nula do titular manual e foi concluída na [#73](https://github.com/agthinkindigital/IEPRCard/issues/73). O wizard foi concluído em DEV na [#74](https://github.com/agthinkindigital/IEPRCard/issues/74), commits `573df5b` e `6143347`; testes HTTP/regressão, revisão desktop/390 px, teclado e CI completo passaram no run [44704215294](https://github.com/agthinkindigital/IEPRCard/actions/runs/44704215294).
* Critério de Aceite:Um mesmo lote de dados importados deve conseguir gerar carteirinhas em 2 designs diferentes do mesmo Cliente apenas trocando o layout selecionado.


* **ISSUE-011: Renderizador de Carteirinhas em WebP (Online) e PDF (Impressão CR80 / Folha A4)** — contratos/renderizadores puros concluídos em [#33](https://github.com/agthinkindigital/IEPRCard/issues/33)–[#38](https://github.com/agthinkindigital/IEPRCard/issues/38); persistência da emissão, reserva de cota e worker CLI/cron concluídos em [#46](https://github.com/agthinkindigital/IEPRCard/issues/46). QR, página pública e portal autenticado concluídos em [#53](https://github.com/agthinkindigital/IEPRCard/issues/53), [#65](https://github.com/agthinkindigital/IEPRCard/issues/65), [#68](https://github.com/agthinkindigital/IEPRCard/issues/68) e [#69](https://github.com/agthinkindigital/IEPRCard/issues/69). O contrato aprovado libera PDF por titular ativo: Sede habilita para seus titulares e unidade exige delegação explícita `carteirinha.pdf`; ver [RBAC](04-rbac-e-permissoes.md). Mockup e guia atualizados do portal aprovados em 2026-11-22; implementação visual está na [#80](https://github.com/agthinkindigital/IEPRCard/issues/80).
* **Arquivos:** `includes/motor_carteirinha_webp.php`, `includes/motor_carteirinha_pdf.php`, `painel/lotes.php`, `cron.php`
* **Descrição:** Gerar as imagens `.webp` de frente e verso otimizadas para web/WhatsApp e o arquivo `.pdf` em 300 DPI (modo página individual CR80 para impressora térmica PVC e modo folha A4 com 8/10 carteirinhas, marcas de corte e sangria). Inclui fila assíncrona em lotes para grandes volumes. O portal libera somente PDF ativo do próprio titular, sujeito ao contrato de `docs/04`.
* **Critério de Aceite:** Passar no `TDD-04`.


* **ISSUE-012: Modos de Entrega: Apenas PDF Impresso vs Portal Online do Titular + Validador QR (Req 09)**
* **Arquivos:** `carteira/index.php`, `carteira/validar.php`
* **Descrição:** Permitir escolher na geração se o lote será **"Apenas PDF Impresso"** (sem criar acesso externo) ou **"Com Acesso ao Dono da Carteirinha"** (gerando link/credencial para o titular visualizar sua carteirinha WebP online com giro 3D Frente/Verso e validar autenticidade via QR Code; baixar PDF apenas se a Sede habilitou e, para unidade, delegou `carteirinha.pdf`).
* **Critério de Aceite:** Carteirinha emitida no modo "Apenas PDF" retorna bloqueada se tentarem acessar o portal online; no modo "Com Acesso", abre normalmente e valida o QR Code.



### ÉPICO 5: Integrações (pagamentos adiados; WhatsApp sem cobrança)

**Fora do MVP:** ISSUE-013 e ISSUE-014 ficam adiadas. O código de webhook Asaas/Mercado Pago disponível como referência não deve ser integrado agora; reavaliar segurança e compatibilidade quando pagamentos forem priorizados.

* **ISSUE-013 (adiada): Integração Asaas/Mercado Pago e checkout de planos**
* **Arquivos:** `api/asaas.php`, `api/mercadopago.php`, `admin/integracao-pagamento.php`, `webhook_asaas.php`, `webhook_mercadopago.php`, `painel/assinatura.php`


* **Descrição futura:** Avaliar e adaptar o código de referência para PDO dual, checkout e atualização idempotente de contas. Não implementar no lançamento.


* **Critério futuro:** Passar no `TDD-05` após a retomada do módulo financeiro.


* **ISSUE-014 (adiada): Painel Financeiro do Administrador (Req 16)**
* **Arquivos:** `admin/financeiro.php`
* **Descrição futura:** Painel executivo após existir cobrança integrada; não criar telas, relatórios ou tabelas financeiras no MVP.
* **Critério futuro:** Conciliar indicadores com transações confirmadas pelo provedor.


* **ISSUE-015: Automação WhatsApp sem cobrança** — escopo confirmado: menu determinístico e atendimento com IA para o titular individual; o bot envia WebP após validação e autorização de emissão. O gateway do MVP é EditaCódigo/GOWA VIP. Como a referência não autentica o webhook, ADR-0008 define segredo bearer aleatório por instância como equivalente compatível, com hash no IEPRCard, rotação e idempotência por ID externo; a borda de webhook da [#78](https://github.com/agthinkindigital/IEPRCard/issues/78) está concluída. As referências de IA mostram OpenAI e Gemini; um provedor fica ativo por fluxo e não há fallback automático. A IA interpreta texto e extrai campos; o fluxo determinístico e o backend controlam estado, consentimento, autorização e emissão. Nenhuma parte de Asaas/Mercado Pago, cobrança ou repasse entra no escopo.
  * **Arquivos-alvo:** `public/webhook-gowa.php`, `public/webhook-gowa-validate.php`, serviços `config/whatsapp_gateway*.php` e catálogo/migração de schema; concluídos na [#78](https://github.com/agthinkindigital/IEPRCard/issues/78). O consumidor com consentimento e máquina de estados está na [#81](https://github.com/agthinkindigital/IEPRCard/issues/81). Projetos legados são referências de comportamento, não código-fonte para copiar/versionar.
  * **Credenciais globais:** o superadmin configura GOWA VIP e as chaves OpenAI/Gemini para toda a plataforma; clientes não cadastram chaves próprias. URL/token GOWA e chaves IA são cifrados com `sodium_crypto_secretbox` usando `IEPR_INTEGRATION_KEY` (32 bytes Base64) do ambiente PHP, distinta da chave de anonimização; ver [ADR-0008](adr/0008-webhooks-idempotentes-e-segredos.md). Persistência, webhook, fluxo com consentimento e painel superadmin foram concluídos nas Issues [#75](https://github.com/agthinkindigital/IEPRCard/issues/75), [#78](https://github.com/agthinkindigital/IEPRCard/issues/78), [#81](https://github.com/agthinkindigital/IEPRCard/issues/81) e [#83](https://github.com/agthinkindigital/IEPRCard/issues/83); CI completo passou em [45002874995](https://github.com/agthinkindigital/IEPRCard/actions/runs/45002874995). O Epic E05 está fechado.
* **Consentimento e minimização:** antes de pedir ou processar dados/fotos, informar finalidade e qual provedor externo pode recebê-los e obter consentimento explícito. Se a mensagem chegar antes do consentimento, não persistir mídia/payload nem encaminhá-la à IA; responder apenas com a etapa de consentimento. Registrar somente a evidência mínima do aceite, vinculada à versão do aviso, ao titular e à finalidade; seguir os prazos de `docs/01-regras-de-negocio.md`.
* **Critério de aceite:** fluxo ponta a ponta com menu e IA, webhook autenticado e idempotente; consentimento verificado antes de persistir ou encaminhar conteúdo; dados validados e emissão autorizada no backend; envio do WebP sem depender de pagamento. Testes cobrem falta/revogação do consentimento, replay, isolamento de tenant, provedor indisponível e ausência de PII/segredos em logs.



### ÉPICO 6: Telemetria Multi-Nível (Req 14, 14.1, 14.2)

* **ISSUE-016: Motor de Coleta e Painéis de Telemetria em 3 Camadas** — coleta, previsão e leituras com escopo concluídas nas [#40](https://github.com/agthinkindigital/IEPRCard/issues/40)–[#43](https://github.com/agthinkindigital/IEPRCard/issues/43) e [#63](https://github.com/agthinkindigital/IEPRCard/issues/63). O mockup e guia foram aprovados; a página e os handlers de telemetria da [#70](https://github.com/agthinkindigital/IEPRCard/issues/70) foram concluídos em `DEV` e aprovados pelo [CI](https://github.com/agthinkindigital/IEPRCard/actions/runs/44682841032). Os controles administrativos da [#71](https://github.com/agthinkindigital/IEPRCard/issues/71) também foram concluídos em `DEV` e aprovados pelo [CI](https://github.com/agthinkindigital/IEPRCard/actions/runs/44684813533). As métricas permitidas e os limites por perfil estão em [`docs/08-telemetria.md`](08-telemetria.md).


* **Critério de Aceite:** Passar no `TDD-06`.

* A exposição via MCP está fatiada na [#44](https://github.com/agthinkindigital/IEPRCard/issues/44). O painel e seus controles administrativos estão nas Issues [#70](https://github.com/agthinkindigital/IEPRCard/issues/70) e [#71](https://github.com/agthinkindigital/IEPRCard/issues/71).



### ÉPICO 7: Servidor MCP para Evolução e Edição Agêntica (Req 18)

* **ISSUE-017: Implementação do Servidor MCP (Streamable HTTP stateless) + Safety Guard** — E07.1–E07.8 e os destinos S3 da [#77](https://github.com/agthinkindigital/IEPRCard/issues/77) concluídos. A criptodestruição fora dos snapshots passou na matriz local da [#79](https://github.com/agthinkindigital/IEPRCard/issues/79), que aguarda comprovação de purga num provedor S3 real. O painel aprovado está concluído na [#82](https://github.com/agthinkindigital/IEPRCard/issues/82). A [#84](https://github.com/agthinkindigital/IEPRCard/issues/84) permanece `ready-for-agent`: inventário integral de PII e demonstração do RPO ≤ 1 h em volume representativo seguem pendentes. Testes de payload/ciphertext, retomada sem publicação parcial, checksum, retry após confirmação ambígua, isolamento entre destinos, ausência de chave e ausência de plaintext passaram localmente em SQLite/MySQL e no CI MySQL 8.4/MariaDB 10.11/PDF do run [45095818379](https://github.com/agthinkindigital/IEPRCard/actions/runs/45095818379), no commit `1a940c8`. A [#85](https://github.com/agthinkindigital/IEPRCard/issues/85) define restauração isolada via ZIP; mockup/guia aprovados e aguarda a conclusão/validação de #84.
* **Arquivos:** `mcp/server.php`, `mcp/tools_registry.php`, `mcp/safety_guard.php`, `admin/mcp-config.php`
* **Descrição:** Construir o servidor Model Context Protocol nativo em PHP e disponibilizá-lo desde o primeiro lançamento por HTTP stateless, sem processo residente. O protocolo, OAuth, escopos e limites de produção são definidos em [ADR-0009](adr/0009-mcp-escopos-e-patch-seguro.md). As Issues [#19](https://github.com/agthinkindigital/IEPRCard/issues/19) (cadastro OAuth), [#20](https://github.com/agthinkindigital/IEPRCard/issues/20) (token e descoberta), [#21](https://github.com/agthinkindigital/IEPRCard/issues/21) (transporte stateless), [#22](https://github.com/agthinkindigital/IEPRCard/issues/22) (registry e `system.read`), [#44](https://github.com/agthinkindigital/IEPRCard/issues/44) (`telemetry.read` limitada à rede da credencial) e [#47](https://github.com/agthinkindigital/IEPRCard/issues/47) (coordenador CLI de emissão, limpeza e anonimização; CI SQLite, MySQL 8.4, MariaDB 10.11 e PDF aprovada) foram concluídas. O contrato de origem da telemetria está na [#43](https://github.com/agthinkindigital/IEPRCard/issues/43); STDIO é opcional no ambiente local.
* **Critério de Aceite:** Passar no `TDD-07`; em produção, `tools/list` não anuncia `code.patch`, tools respeitam escopo/tenant e 100% das chamadas ficam auditadas sem PII ou segredos.



---



---

## Adendo: issues do modelo hierárquico


## 7. Complemento de Issues e Contratos TDD (Adendos ao Backlog)

Estes adendos atualizam diretamente o plano de execução do agente construtor para refletir as regras de negócio detalhadas acima:

### Novas Regras nas Issues Existentes + Novas Issues de Controle

* **Atualização na `ISSUE-005` (Motor RBAC e Sub-acessos Centralizados no Cliente Master):**
* **Regra de Código:** No controller de criação de usuários (`painel/equipe-acessos.php`), adicionar guard obrigatório: `if (!in_array($usuarioLogado['papel_sistema'], ['superadmin', 'titular_cliente'], true)) { bloquearAcesso('Apenas o responsável principal da conta pode criar ou alterar sub-acessos.'); }`.
* **Perfis Prontos (Presets):** Ao criar um Cliente novo, o sistema já semeia 4 perfis editáveis: *Dirigente de Subcliente*, *Secretário (Emissor de Carteirinhas)*, *Tesoureiro* e *Cadastrador de Membros*.


* **Nova `ISSUE-005B`: Cotas e ativação manual de contas pelo Admin (sem cobrança)** — provisionamento inicial da Sede e do titular foi entregue na [Issue #17](https://github.com/agthinkindigital/IEPRCard/issues/17); ciclo de vida manual da Sede, na [Issue #18](https://github.com/agthinkindigital/IEPRCard/issues/18); e configuração/distribuição backend de cotas, na [Issue #23](https://github.com/agthinkindigital/IEPRCard/issues/23). A aplicação dos limites nos fluxos backend de cadastro individual, importação confirmada e emissão foi entregue nas [Issues #27](https://github.com/agthinkindigital/IEPRCard/issues/27), [#32](https://github.com/agthinkindigital/IEPRCard/issues/32) e [#46](https://github.com/agthinkindigital/IEPRCard/issues/46). Sem cobrança, checkout ou automação por webhook.




* **Nova `ISSUE-012B`: Experiência de Login Direto do Titular e Variáveis Hierárquicas (`{{sede.*}}` / `{{unidade.*}}`)** — autenticação/consulta privada em [#66](https://github.com/agthinkindigital/IEPRCard/issues/66), entrega privada em [#67](https://github.com/agthinkindigital/IEPRCard/issues/67) e interfaces aprovadas e concluídas em [#68](https://github.com/agthinkindigital/IEPRCard/issues/68) e [#69](https://github.com/agthinkindigital/IEPRCard/issues/69). A regra de download opcional de PDF foi implementada e validada em [#80](https://github.com/agthinkindigital/IEPRCard/issues/80); a Sede habilita e a unidade requer delegação explícita. O contrato está em [RBAC](04-rbac-e-permissoes.md).
* **Arquivos:** portal do titular em `public/` e referências privadas servidas após autenticação; `includes/motor_mapeamento.php`
* **Descrição:** Autenticar o titular por CPF + data de nascimento ou token único e aterrissar na própria carteirinha da Sede ou do Subcliente, exibindo a referência da Sede matriz e metadados de Sede, unidade e categoria/cargo. A validação pública por QR permanece sem dados pessoais. A disponibilidade de PDF depende de contrato de permissão efetiva ainda não definido no schema.
