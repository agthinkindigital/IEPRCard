# ERD e Dicionário de Dados

**Escopo:** este arquivo preserva ERDs e referências anteriores. Para o MVP, prevalece o schema canônico de 27 tabelas descrito em `docs/adr/0005-schema-canonico-unificado.md`; tabelas, credenciais e fluxos de pagamento são futuros e não devem ser criados agora. O modelo consolidado de destino fica em `docs/architecture/SAD.md` §4.6.

## 2. ERD (Diagrama Entidade-Relacionamento) e Dicionário de Dados Dual

Para cumprir o requisito de compatibilidade **SQLite $\rightarrow$ MySQL/MariaDB**, o schema abole o tipo `ENUM` nativo do MySQL (usado nos arquivos legados) em favor de `VARCHAR(30)` com validação estrita na camada de domínio PHP, utiliza `TEXT` para estruturas JSON e centraliza a criação/atualização no padrão *Self-Healing* já validado em `motorPagtoGarantirEstrutura`, `pagamentoGarantirEstrutura` e `_bwGarantirTabelaSuporte`.

### 2.1. Diagrama Entidade-Relacionamento (Mermaid ERD)

```mermaid
erDiagram
    PLANOS ||--o{ TENANTS : "define_limites"
    TENANTS ||--o{ TENANTS : "cliente_possui_subclientes (parent_id)"
    TENANTS ||--o{ DOMINIOS_TENANT : "roteia_http_host"
    TENANTS ||--o{ USUARIOS : "possui_operadores"
    TENANTS ||--o{ DESIGNS_CARTEIRINHA : "possui_layouts"
    TENANTS ||--o{ LOTES_IMPORTACAO : "executa_importacoes"
    TENANTS ||--o{ TITULARES : "gerencia_membros"
    TENANTS ||--o{ CARTEIRINHAS_EMITIDAS : "emite"
    TENANTS ||--o{ PAGAMENTOS_SAAS : "paga_assinatura"
    TENANTS ||--o{ TELEMETRIA_EVENTOS : "gera_metricas"
    TENANTS ||--o{ ACESSOS_SUPORTE : "autoriza_suporte"

    DESIGNS_CARTEIRINHA ||--o{ LOTES_IMPORTACAO : "usa_layout"
    DESIGNS_CARTEIRINHA ||--o{ CARTEIRINHAS_EMITIDAS : "renderiza_com"
    LOTES_IMPORTACAO ||--o{ CARTEIRINHAS_EMITIDAS : "origina_em_lote"
    TITULARES ||--o{ CARTEIRINHAS_EMITIDAS : "pertence_a"
    TITULARES ||--o{ PAGAMENTOS_TITULAR : "paga_taxa"
    TITULARES ||--o{ MENSAGENS_WHATSAPP : "conversa"

    PLANOS {
        int id PK
        varchar nome
        decimal preco
        text permissoes_default_json
        int ativo
        int ordem
    }

    TENANTS {
        int id PK
        int parent_id FK "NULL=Cliente, ID=Subcliente"
        int plano_id FK
        varchar tipo "cliente | subcliente"
        varchar nome_fantasia
        varchar slug_subdominio
        varchar dominio_customizado
        varchar status_conta "ativo | pendente | vencido | bloqueado"
        text permissoes_json "Override de flags e cotas"
        varchar asaas_customer_id
        varchar mp_subscription_id
        varchar cliente_gateway_ativo "asaas | mercadopago"
        varchar cliente_asaas_api_key
        varchar cliente_asaas_ambiente "sandbox | producao"
        varchar cliente_mp_access_token
        varchar cliente_mp_ambiente "teste | producao"
        varchar cliente_webhook_token
        datetime criado_em
    }

    USUARIOS {
        int id PK
        int tenant_id FK "NULL=SuperAdmin SaaS"
        varchar papel "superadmin | admin_cliente | admin_subcliente | operador"
        varchar nome
        varchar email
        varchar senha_hash
        varchar cpf_cnpj
        int ativo
        datetime ultimo_login_em
    }

    DESIGNS_CARTEIRINHA {
        int id PK
        int tenant_id FK "Cliente dono da arte"
        varchar nome_layout
        varchar orientacao "horizontal | vertical"
        int largura_px "Padrao 1011 (CR80 300dpi)"
        int altura_px "Padrao 638 (CR80 300dpi)"
        varchar bg_frente_url
        varchar bg_verso_url
        text mapa_campos_json "Coordenadas X,Y, fontes, tipos"
        text mapeamento_fixo_json "De-Para padrao de colunas"
        int compartilhado_subclientes "0 | 1"
        int ativo
        datetime criado_em
    }

    TITULARES {
        int id PK
        int tenant_id FK "Cliente"
        int subcliente_id FK "Subcliente opcional"
        varchar nome_completo
        varchar documento_cpf
        varchar matricula_codigo
        varchar email
        varchar whatsapp
        varchar lid_vinculado
        varchar foto_url
        text dados_extras_json "Campos livres da planilha/form"
        varchar token_acesso_carteira UK
        varchar senha_portal_hash
        int acesso_online_liberado "0 | 1"
        int bloqueado "0 | 1"
        int ia_pausada "0 | 1"
        datetime ultima_interacao_em
        datetime criado_em
    }

    LOTES_IMPORTACAO {
        int id PK
        int tenant_id FK
        int subcliente_id FK
        int usuario_id FK
        int design_id FK
        varchar origem_tipo "csv | xlsx | json | manual_lote | manual_unico"
        varchar arquivo_original_url
        text colunas_detectadas_json
        text mapa_de_para_usado_json
        varchar modo_saida "pdf_apenas | pdf_e_webp_online | webp_apenas"
        int total_registros
        int processados_sucesso
        int processados_erro
        varchar status_lote "aguardando_mapa | na_fila | processando | concluido | falha"
        varchar pdf_consolidado_url
        datetime criado_em
        datetime finalizado_em
    }

    CARTEIRINHAS_EMITIDAS {
        int id PK
        int tenant_id FK
        int subcliente_id FK
        int titular_id FK
        int design_id FK
        int lote_id FK
        varchar codigo_validacao_qr UK
        varchar status_carteirinha "ativa | vencida | revogada"
        date data_validade
        text snapshot_dados_json "Dados exatos impressos"
        varchar webp_frente_url
        varchar webp_verso_url
        varchar pdf_individual_url
        int total_leituras_qr
        datetime emitida_em
    }

    TELEMETRIA_EVENTOS {
        int id PK
        int tenant_id FK
        int subcliente_id FK
        int usuario_id FK
        varchar categoria "uso | geracao | importacao | conversa | financeiro | mcp | seguranca"
        varchar evento_chave "ex: carteirinha.gerada_webp"
        int quantidade " Delta numerico"
        decimal valor_associado
        text metadados_json
        varchar ip_origem
        datetime criado_em
    }

```

### 2.2. Tabelas Adicionais de Infraestrutura, Pagamento e Suporte

Além das entidades principais do diagrama, o schema inclui as tabelas herdadas e padronizadas dos seus módulos atuais:

* **`configuracoes_pagamento`**: Linha única (`id = 1`) com credenciais globais do SaaS (`gateway_ativo`, `asaas_api_key`, `asaas_ambiente`, `asaas_webhook_token`, `mp_access_token`, `mp_ambiente`, `mp_webhook_secret`).


* **`pagamentos_saas`**: Histórico de cobranças das assinaturas dos Clientes (`tenant_id`, `valor`, `gateway`, `billing_type`, `status`, `id_transacao_gateway`, `id_evento_webhook` com índice único para idempotência).


* **`pagamentos_titular`**: Cobrança da emissão solicitada pelo titular, iniciada pela plataforma e vinculada ao tenant; a liquidação ao Cliente é registrada separadamente em `repasses_titular` (`tenant_id`, `titular_id`, `gateway`, `external_id`, `status`, `carteirinha_id`). Contas e credenciais de recebedores ficam em `contas_recebedoras`. Ver o fluxo financeiro canônico em `docs/07` e SAD §4.6.


* **`ia_config`**: Configuração centralizada da IA (`provedor` = `'openai'` ou `'gemini'`, `chave`, `modelo`, `ativo`).


* **`mensagens_whatsapp`**: Histórico de conversas e mídias por instância (`tenant_id`, `subcliente_id`, `telefone`, `direcao`, `tipo_mensagem`, `conteudo`, `id_mensagem_api`, `arquivo_url`).


* **`acessos_suporte`**: Tokens temporários de acesso remoto auditável (`tenant_id`, `usuario_id`, `token`, `tipo` = `'admin' | 'cliente' | 'subcliente'`, `criado_em`, `expira_em`, `usado_em`, `ip_usado`).


* **`mcp_api_keys` & `mcp_audit_logs**`: Controle de chaves agênticas, escopos permitidos e log de todas as chamadas de ferramentas via Model Context Protocol.

---



---

## Adendo: ERD atualizado (hierarquia, cargos e auditoria)


## 4. ERD Atualizado e Dicionário de Dados (Foco em Hierarquia, Cargos e Auditoria)

Abaixo está o diagrama atualizado contemplando **Sede vs. Subcliente**, **Perfis Granulares de Sub-Acesso**, **Categorias Eclesiásticas/Corporativas de Titulares** (para seleção automática da arte da carteirinha) e **Trilha de Auditoria Completa**.

```mermaid
erDiagram
    PLANOS ||--o{ TENANTS : "define_teto_padrao"
    TENANTS ||--o{ TENANTS : "sede_possui_subclientes (parent_id)"
    TENANTS ||--o{ PERFIS_ACESSO : "define_permissoes_granulares"
    TENANTS ||--o{ USUARIOS_EQUIPE : "aloca_operadores"
    PERFIS_ACESSO ||--o{ USUARIOS_EQUIPE : "aplica_rbac"
    TENANTS ||--o{ CATEGORIAS_TITULAR : "classifica_membros_obreiros"
    DESIGNS_CARTEIRINHA ||--o{ CATEGORIAS_TITULAR : "layout_padrao_da_categoria"
    TENANTS ||--o{ TITULARES : "vincula_a_sede_ou_subcliente"
    CATEGORIAS_TITULAR ||--o{ TITULARES : "define_cargo_perfil"
    TITULARES ||--o{ CARTEIRINHAS_EMITIDAS : "possui_carteirinha"
    TENANTS ||--o{ AUDITORIA_PROCESSOS : "registra_trilha"

    TENANTS {
        int id PK
        int parent_id FK "NULL = Cliente (Sede) | ID = Subcliente (Itaquera)"
        int plano_id FK
        varchar tipo "cliente | subcliente"
        varchar nome_organizacao "Ex: Remida de Cristo - Sede ou Itaquera"
        varchar nome_responsavel "Ex: Pr. Josue ou Pr. Flaviano"
        varchar cargo_responsavel "Ex: Pastor Presidente | Pastor Dirigente"
        varchar slug_subdominio "Ex: remida ou itaquera.remida"
        varchar dominio_customizado
        varchar regime_cobranca "assinatura | plano_unico | trial | cortesia_admin"
        int isento_pagamento "0 | 1 (Bypass do Admin)"
        datetime trial_expira_em
        varchar status_conta "ativo | trial | pendente | vencido | desativado_admin"
        int limite_subclientes "Apenas no Cliente Sede (Ex: 6)"
        int limite_usuarios_equipe "Teto global (Sede) ou alocado (Sub)"
        int limite_titulares "Teto global (Sede) ou alocado (Sub)"
        int limite_carteirinhas "Teto global (Sede) ou alocado (Sub)"
        varchar assinatura_responsavel_url "PNG transparente p/ carteirinha"
        text permissoes_unidade_json "O que o Subcliente pode fazer"
        datetime criado_em
    }

    PERFIS_ACESSO {
        int id PK
        int cliente_sede_id FK "Criado pelo Pr. Josue ou Admin"
        varchar nome_perfil "Ex: Dirigente Subcliente | Secretario | Tesoureiro"
        varchar escopo_permitido "sede_e_subclientes | apenas_sede | apenas_subcliente"
        text permissoes_acoes_json "ver_membros, criar_membros, importar_lote, gerar_pdf..."
    }

    USUARIOS_EQUIPE {
        int id PK
        int cliente_sede_id FK "Referencia da Sede"
        int subcliente_id FK "NULL = Atua na Sede | ID = Atua em Itaquera"
        int perfil_acesso_id FK
        int criado_por_usuario_id FK "Garante criacao pelo Pr. Josue ou Admin"
        varchar papel_sistema "superadmin | titular_cliente | responsavel_sub | subacesso"
        varchar nome
        varchar email
        varchar senha_hash
        int ativo "0 | 1"
        datetime ultimo_login_em
    }

    CATEGORIAS_TITULAR {
        int id PK
        int cliente_sede_id FK
        varchar nome_categoria "Ex: Membro | Obreiro | Diacono | Pastor | Aluno"
        int design_padrao_id FK "Qual arte de carteirinha usa por padrao"
        int ativo
    }

    TITULARES {
        int id PK
        int cliente_sede_id FK "Sempre preenchido (Ex: Sede Remida)"
        int subcliente_id FK "NULL se for da Sede | ID se for de Itaquera"
        int categoria_id FK "Ex: Membro, Corpo Eclesiastico"
        varchar nome_completo
        varchar cpf
        varchar rg
        varchar cargo_funcao "Ex: Evangelista, Secretario, Membro"
        varchar matricula
        date data_nascimento
        date data_batismo_ou_admissao
        varchar whatsapp
        varchar email_login
        varchar senha_portal_hash
        varchar token_unico_carteira UK
        varchar foto_url
        text dados_extras_json
        int acesso_app_liberado "0 | 1"
        int ativo "0 | 1"
    }

    AUDITORIA_PROCESSOS {
        int id PK
        int cliente_sede_id FK
        int subcliente_id FK
        int ator_usuario_id FK
        varchar ator_tipo "superadmin | cliente_master | subacesso | titular | webhook"
        varchar acao "Ex: subcliente.criado | cota.alterada | lote.emitido | conta.desativada"
        varchar entidade_alvo "Ex: titular#450 | lote#12"
        text antes_json
        text depois_json
        varchar ip_origem
        datetime criado_em
    }

```

---
