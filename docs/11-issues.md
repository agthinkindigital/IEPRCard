# Backlog de Issues

## 9. Backlog Detalhado de Issues para Execução

### ÉPICO 1: Núcleo Monolítico e Banco de Dados Híbrido (Req 01, 02)

* **ISSUE-001: Camada de Conexão PDO Dual (SQLite + MySQL/MariaDB)**
* **Arquivos:** `config/conn.php`, `.env.example`
* **Descrição:** Criar a conexão PDO com suporte a `DB_DRIVER=sqlite` e `DB_DRIVER=mysql`, incluindo funções wrapper leves (`dbQuery`, `dbFetchOne`, `dbFetchAll`, `dbExecute`, `dbInsertId`, `dbAgora`) para manter a ergonomia do código consumidor. Valores externos devem usar prepared statements; não criar helper de escape para montar SQL.


* **Critério de Aceite:** Alternar a variável `DB_DRIVER` conecta nos dois bancos sem alterar uma única linha nos arquivos consumidores.


* **ISSUE-002: Schema canônico e migrações compatíveis com SQLite e MySQL**
* **Arquivos:** `config/schema_self_healing.php`, `tests/Integration/DualDatabaseSelfHealingTest.php`
* **Descrição:** Validar paridade do schema mínimo de 27 tabelas nos dois drivers. Autocorreção estrutural fica restrita a dev/test; produção usa migrações versionadas, expand-only e compatíveis com ADR-0010. Tabelas financeiras adiadas não entram neste escopo.


* **Critério de Aceite:** Passar no `TDD-01`.


* **ISSUE-003: Utilitário de Migração automatizada SQLite $\rightarrow$ MySQL/MariaDB** — serviço e CLI entregues em [GitHub #10](https://github.com/agthinkindigital/IEPRCard/issues/10); interface administrativa E01.4 concluída em [GitHub #11](https://github.com/agthinkindigital/IEPRCard/issues/11). Destino permite apenas o catálogo `permissoes` pre-semeado e idêntico à origem; qualquer outro dado preexistente bloqueia.
* **Arquivos:** serviço `database/migration/sqlite_to_mysql.php`, comando CLI `database/migrate-sqlite-to-mysql.php` e interface administrativa protegida em `public/admin/migracao-banco.php`.
* **Descrição:** Serviço e script CLI transferem as tabelas SQLite para MySQL/MariaDB com verificação de integridade. A interface aprovada permite pré-verificar, enfileirar e acompanhar o worker sem repetir a lógica de migração.
* **Critério de Aceite:** Migrar o fixture de 1.000 registros em menos de 5 segundos no ambiente de referência, com contagens e checksums idênticos, chaves estrangeiras válidas e rollback integral se a transferência falhar.



### ÉPICO 2: Multi-Tenancy, Domínios, Subdomínios e Permissões (Req 03, 04, 05, 12, 13)

* **ISSUE-004: Resolvedor de Domínios (Clientes) e Subdomínios (Subclientes)** — fundação E02.2 concluída em [GitHub #13](https://github.com/agthinkindigital/IEPRCard/issues/13); serviço de cadastro/prova DNS concluído em [GitHub #14](https://github.com/agthinkindigital/IEPRCard/issues/14); criação backend de Subcliente com teto concluída na [GitHub #24](https://github.com/agthinkindigital/IEPRCard/issues/24). Mockup/guia aprovado em [#49](https://github.com/agthinkindigital/IEPRCard/issues/49) e tela autenticada concluída em [#54](https://github.com/agthinkindigital/IEPRCard/issues/54); prova operacional TLS/aliases no host em [#51](https://github.com/agthinkindigital/IEPRCard/issues/51).
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
* **Descrição:** Ao selecionar 1 titular ou um lote de dados no banco/importação, permitir usar o mapeamento fixo do layout (1 clique) ou abrir a etapa interativa de mapeamento De$\rightarrow$Para onde o operador liga cada coluna importada ao respectivo espaço do design escolhido, com preview real antes de confirmar.
* Critério de Aceite:Um mesmo lote de dados importados deve conseguir gerar carteirinhas em 2 designs diferentes do mesmo Cliente apenas trocando o layout selecionado.


* **ISSUE-011: Renderizador de Carteirinhas em WebP (Online) e PDF (Impressão CR80 / Folha A4)** — contratos/renderizadores puros concluídos em [#33](https://github.com/agthinkindigital/IEPRCard/issues/33)–[#38](https://github.com/agthinkindigital/IEPRCard/issues/38); persistência da emissão, reserva de cota e worker CLI/cron concluídos em [#46](https://github.com/agthinkindigital/IEPRCard/issues/46). Códigos QR imprevisíveis implementados na [#53](https://github.com/agthinkindigital/IEPRCard/issues/53), commit `23e819a`, CI aprovada. Rota pública e serviço de validação minimizada implementados em `DEV` na [#65](https://github.com/agthinkindigital/IEPRCard/issues/65); o estado e os artefatos do portal do titular estão no [roadmap E04](14-roadmap.md#epics-rastreaveis-no-github).
* **Arquivos:** `includes/motor_carteirinha_webp.php`, `includes/motor_carteirinha_pdf.php`, `painel/lotes.php`, `cron.php`
* **Descrição:** Gerar as imagens `.webp` de frente e verso otimizadas para web/WhatsApp e o arquivo `.pdf` em 300 DPI (modo página individual CR80 para impressora térmica PVC e modo folha A4 com 8/10 carteirinhas, marcas de corte e sangria). Inclui fila assíncrona em lotes para grandes volumes.
* **Critério de Aceite:** Passar no `TDD-04`.


* **ISSUE-012: Modos de Entrega: Apenas PDF Impresso vs Portal Online do Titular + Validador QR (Req 09)**
* **Arquivos:** `carteira/index.php`, `carteira/validar.php`
* **Descrição:** Permitir escolher na geração se o lote será **"Apenas PDF Impresso"** (sem criar acesso externo) ou **"Com Acesso ao Dono da Carteirinha"** (gerando link/credencial para o titular visualizar sua carteirinha WebP online com giro 3D Frente/Verso, baixar PDF e validar autenticidade via QR Code).
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


* **ISSUE-015: Automação WhatsApp sem cobrança**
* **Arquivos:** `webhook_whatsapp.php`, `api/openai.php`, `api/gemini.php`, `admin/ia-config.php`


* **Descrição:** Integrar o bot de WhatsApp (numérico, enquete ou IA multimodal OpenAI/Gemini) para permitir que o titular envie sua foto/dados pelo WhatsApp e receba a carteirinha pronta em WebP direto na conversa, sem cobrança ou checkout.


* **Critério de Aceite:** Fluxo ponta a ponta no webhook simulado envia o WebP após validação dos dados e autorização de emissão, sem depender de pagamento.



### ÉPICO 6: Telemetria Multi-Nível (Req 14, 14.1, 14.2)

* **ISSUE-016: Motor de Coleta e Painéis de Telemetria em 3 Camadas**
* **Arquivos:** `includes/motor_telemetria.php`, `admin/index.php`, `painel/index.php`
* **Descrição:** Implementar a coleta em tempo real e os painéis visuais de telemetria respeitando a visibilidade de cada nível:
* **Admin (Req 14):** Uso de disco/banco, consumo de cota/IA, conversas totais, carteirinhas geradas (PDF/WebP), usuários criados, titulares totais no banco, performance de lotes e saúde de webhooks.
* **Cliente (Req 14.1):** Carteirinhas geradas por ele e por cada Subcliente, consumo do seu limite mensal, titulares na sua base, lotes importados, conversas de suas instâncias e leituras de QR Code.
* **Subcliente (Req 14.2):** Carteirinhas emitidas na sua unidade, saldo da sua cota alocada, seus lotes importados e seus titulares cadastrados.


* **Critério de Aceite:** Passar no `TDD-06`.

* O contrato backend de gravação agregada, leitura com escopo e concorrência nas tabelas canônicas foi concluído na [GitHub #43](https://github.com/agthinkindigital/IEPRCard/issues/43); telas aguardam mockup/guia aprovados. A exposição via MCP está fatiada na [#44](https://github.com/agthinkindigital/IEPRCard/issues/44).



### ÉPICO 7: Servidor MCP para Evolução e Edição Agêntica (Req 18)

* **ISSUE-017: Implementação do Servidor MCP (Streamable HTTP stateless) + Safety Guard** — E07.1–E07.8 concluídas; atualização simplificada de schema em [#58](https://github.com/agthinkindigital/IEPRCard/issues/58) aprovada por CI em SQLite, MySQL 8.4, MariaDB 10.11 e PDF.
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




* **Nova `ISSUE-012B`: Experiência de Login Direto do Titular e Variáveis Hierárquicas (`{{sede.*}}` / `{{unidade.*}}`)** — serviços backend de autenticação e consulta privada implementados em [#66](https://github.com/agthinkindigital/IEPRCard/issues/66), com CI MySQL 8.4/MariaDB 10.11 e gate PDF/WebP aprovado no run [44666160178](https://github.com/agthinkindigital/IEPRCard/actions/runs/44666160178); as telas continuam dependentes da aprovação explícita do mockup e do guia funcional.
* **Arquivos:** portal do titular em `public/` e referências privadas servidas após autenticação; `includes/motor_mapeamento.php`
* **Descrição:** Autenticar o titular por CPF + data de nascimento ou token único e aterrissar na própria carteirinha da Sede ou do Subcliente, exibindo a referência da Sede matriz e metadados de Sede, unidade e categoria/cargo. A validação pública por QR permanece sem dados pessoais. A disponibilidade de PDF depende de contrato de permissão efetiva ainda não definido no schema.
