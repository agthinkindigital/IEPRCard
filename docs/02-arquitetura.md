# Arquitetura e Relacionamento de Arquivos

## 3. Relacionamento de Arquivos e Grafo de Dependências

O monólito segue uma arquitetura em camadas estritas (**Config $\rightarrow$ Core Includes $\rightarrow$ API Wrappers $\rightarrow$ Motors $\rightarrow$ Entrypoints/Views**) para evitar dependências circulares.

```text
card-saas/
├── .env.example                           # Variáveis DB_DRIVER (sqlite|mysql), chaves base
├── index.php                              # Roteador público (Landing Page, Login ou Portal por Host)
├── cron.php                               # Processador assíncrono de lotes PDF/WebP e agregação de telemetria
├── webhook_asaas.php                      # [Herdado/Adaptado] Webhook SaaS Asaas
├── webhook_mercadopago.php                # [Herdado/Adaptado] Webhook SaaS Mercado Pago
├── webhook_pagamento_cliente.php          # [Herdado/Adaptado] Webhook Cobrança de Titular (Asaas/MP)
├── webhook_whatsapp.php                   # [Herdado/Adaptado] Webhook WhatsApp Multi-Instância
│
├── config/
│   ├── conn.php                           # Wrapper PDO Dual (SQLite/MySQL) + polyfill de helpers db*
│   ├── schema_self_healing.php            # Migrações idempotentes automáticas para SQLite e MySQL
│   ├── tenant_resolver.php                # Resolve HTTP_HOST -> Admin, Cliente (Domínio) ou Subcliente
│   ├── proteger_admin.php                 # Guard de sessão do SuperAdmin SaaS
│   ├── proteger_painel.php                # Guard de sessão de Cliente e Subcliente + RBAC
│   └── config_whatsapp.php                # Constantes de prefixo de instância e endpoints WhatsApp
│
├── api/
│   ├── asaas.php                          # [Herdado] SDK Asaas (Checkout, Customers, Pix QR, Auto-Webhook)
│   ├── mercadopago.php                    # [Herdado] SDK Mercado Pago (Preapproval, Preferences, HMAC)
│   ├── openai.php                         # [Herdado] Wrapper OpenAI (Chat JSON + Whisper Audio)
│   ├── gemini.php                         # [Novo] Wrapper Google Gemini Multimodal (JSON + Audio/Vision)
│   └── whatsapp.php                       # Wrapper de envio de texto, imagem WebP, documento PDF e enquete
│
├── includes/
│   ├── motor_eventos.php                  # Event Bus síncrono/assíncrono (Hooks & Listeners internos)
│   ├── motor_telemetria.php               # Coletor e agregador de métricas (Admin, Cliente, Subcliente)
│   ├── motor_permissoes.php               # Validador hierárquico (Admin -> Plano -> Cliente -> Subcliente)
│   ├── motor_importacao.php               # Parser de CSV, XLS/XLSX e JSON + Validador de Colunas
│   ├── motor_mapeamento.php               # Resolvedor De->Para (Fixo por Layout ou Dinâmico por Lote)
│   ├── motor_carteirinha_webp.php         # Renderizador GD/Imagick (Frente/Verso WebP + QR Code + Crop)
│   ├── motor_carteirinha_pdf.php          # Gerador PDF Vetorial (CR80 Individual e Folha A4 com sangria)
│   ├── motor_pagamento_cliente.php        # [Herdado/Adaptado] Cobrança do titular final via WhatsApp/Link
│   ├── motor_conversa_menu.php            # [Herdado/Adaptado] Fluxo numérico/enquete para coleta de foto/dados
│   ├── motor_ia_atendimento.php           # [Herdado/Adaptado] Atendimento IA para titulares
│   ├── suporte.php                        # [Herdado/Adaptado] Geração e auditoria de tokens de suporte
│   ├── telefone.php                       # [Herdado] Normalização BR e tratamento de LID WhatsApp
│   ├── header.php / footer.php            # Layout base responsivo Dark/Light com CSS Variables
│   ├── menu_admin.php                     # Navegação do SuperAdmin
│   └── menu_painel.php                    # Navegação dinâmica de Cliente e Subcliente (baseada em RBAC)
│
├── admin/                                 # Painel do Administrador Principal (SaaS)
│   ├── index.php                          # Dashboard Global de Telemetria (Uso, Consumo, Conversas, Banco)
│   ├── financeiro.php                     # Acompanhamento Financeiro Completo (MRR, Inadimplência, Extrato)
│   ├── clientes.php                       # CRUD de Clientes, Domínios, Subclientes e Override de Permissões
│   ├── planos.php                         # Gestão de Planos, Cotas de Carteirinhas e Feature Flags
│   ├── integracao-pagamento.php           # [Herdado] Configuração de Tokens Asaas / Mercado Pago
│   ├── ia-config.php                      # [Herdado] Configuração Centralizada OpenAI / Gemini
│   └── mcp-config.php                     # Gestão de Tokens do MCP Server e Auditoria Agêntica
│
├── painel/                                # Painel Operacional (Clientes e Subclientes)
│   ├── index.php                          # Dashboard de Telemetria Filtrado (Visão 14.1 Cliente / 14.2 Sub)
│   ├── subclientes.php                    # [Apenas Cliente] CRUD de Subclientes, Subdomínios e Permissões
│   ├── designs.php                        # Galeria e Configuração de Layouts de Carteirinha
│   ├── editor-design.php                  # Editor Visual Drag-and-Drop de Coordenadas e Mapeamento Fixo
│   ├── importar-dados.php                 # Upload CSV/XLS/XLSX/JSON + ZIP de Fotos
│   ├── preencher-manual.php               # Cadastro Individual (com Webcam/Crop) e Grade Manual em Lote
│   ├── mapear-gerar.php                   # Wizard de Mapeamento Dinâmico (Req 17.1) + Preview + Disparo
│   ├── lotes.php                          # Fila de Processamento, Download de PDFs e Reemissão
│   ├── titulares.php                      # Gestão de Titulares, Liberação de Acesso Online e Disparo WhatsApp
│   ├── integracao-pagamento.php           # Configuração de Asaas/MP próprio do Cliente
│   └── suporte-remoto.php                 # Geração de Token Temporário de Suporte
│
├── carteira/                              # Portal do Titular e Validação Pública
│   ├── index.php                          # Visualização Online (WebP Frente/Verso Flip 3D + Baixar PDF)
│   └── validar.php                        # Validação Pública por leitura de QR Code
│
├── mcp/                                   # Camada Agêntica (Model Context Protocol - Req 18)
│   ├── server.php                         # Entrypoint JSON-RPC 2.0 (STDIO e HTTP SSE/POST)
│   ├── tools_registry.php                 # Definição de Schemas e Execução das Tools MCP
│   └── safety_guard.php                   # Backup automático e lint (`php -l`) antes de patches
│
└── tests/                                 # Suíte Automatizada TDD
    ├── bootstrap.php                      # Inicializa banco SQLite em memória/arquivo temporário
    ├── run_all.php                        # Runner nativo zero-dependência com relatório JSON/CLI
    ├── Unit/                              # Testes de unidade (CPF, Telefone, Permissoes, Mapeamento)
    ├── Integration/                       # Testes de banco Dual, Importação, Webhooks, Telemetria
    └── Visual/                            # Testes de renderização WebP, PDF e QR Code

```

### Grafo de Dependências Críticas (`require_once`)

* **Qualquer Entrypoint Web (`admin/*`, `painel/*`, `carteira/*`)**:
`config/conn.php` $\rightarrow$ `config/schema_self_healing.php` $\rightarrow$ `config/tenant_resolver.php` $\rightarrow$ `includes/motor_eventos.php` $\rightarrow$ `includes/motor_telemetria.php` $\rightarrow$ `includes/motor_permissoes.php`.
* **Webhooks de Pagamento (`webhook_asaas.php`, `webhook_mercadopago.php`)**:
`config/conn.php` $\rightarrow$ `api/asaas.php` $\rightarrow$ `api/mercadopago.php` $\rightarrow$ `includes/motor_eventos.php` $\rightarrow$ `includes/motor_telemetria.php`.


* **Pipeline de Geração (`painel/mapear-gerar.php` e `cron.php`)**:
`config/conn.php` $\rightarrow$ `includes/motor_mapeamento.php` $\rightarrow$ `includes/motor_carteirinha_webp.php` $\rightarrow$ `includes/motor_carteirinha_pdf.php` $\rightarrow$ `includes/motor_telemetria.php`.

---
