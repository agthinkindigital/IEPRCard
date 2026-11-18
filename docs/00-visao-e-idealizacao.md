# Visão e Idealização

O planejamento estratégico e arquitetônico do **SaaS Multi-Tenant de Confecção Automática de Carteirinhas (CardSaaS Monolith)** consolida a especificação técnica, modelagem de dados, contratos de eventos, suíte de testes orientados a TDD, backlog de issues, *Quality Gates* e regras de orquestração para execução por agentes autônomos de engenharia de software.

---

## 1. Idealização e Planejamento Estratégico

### 1.1. Visão do Produto e Proposta de Valor

O sistema é um **monólito PHP de alta performance e zero dependências complexas**, projetado para operar inicialmente em **SQLite** (facilitando *deploy* instantâneo, testes automatizados e portabilidade) e escalar transparentemente para **MySQL/MariaDB** em produção. Ele resolve a emissão automatizada e em massa de documentos de identificação (carteirinhas estudantis, crachás corporativos, carteiras de clubes, sindicatos, igrejas e associações) em formato híbrido: **PDF vetorial de alta definição para gráfica/impressora térmica PVC** e **WebP leve para carteira digital online e entrega via WhatsApp**.

### 1.2. Hierarquia Multi-Tenant (4 Camadas) e Isolamento Lógico

O isolamento de dados é baseado em *Row-Level Multi-Tenancy* controlado pelas chaves `cliente_id` (Tenant Principal) e `subcliente_id` (Sub-Tenant), resolvido dinamicamente via `HTTP_HOST`:

1. **Administrador Principal (SaaS Owner):** Gerencia planos e preços, credenciais globais de pagamento (Asaas e Mercado Pago), configuração centralizada de IA (OpenAI e Google Gemini), tetos de permissão (*Feature Flags*), auditoria financeira global, telemetria completa de infraestrutura/uso e chaves do servidor MCP.


2. **Cliente (Tenant Nível 1 — Domínio Próprio ou Subdomínio SaaS):** Organizações matrizes, redes de ensino, federações ou gráficas parceiras. Criam e personalizam múltiplos designs de carteirinha, cadastram Subclientes (filiais/unidades), delegam permissões dentro do limite do seu plano, emitem carteirinhas individualmente ou em lote e visualizam a telemetria consolidada da sua rede.
3. **Subcliente (Tenant Nível 2 — Subdomínio Vinculado):** Unidades operacionais, polos ou filiais de um Cliente. Possuem painel restrito às permissões concedidas pelo Cliente pai, podendo importar planilhas (CSV, XLS/XLSX, JSON), digitar dados manualmente (individual ou grade em lote), mapear colunas nos designs autorizados e gerar carteirinhas (PDF/WebP).
4. **Titular (Dono da Carteirinha):** Usuário final que recebe apenas o PDF impresso (via operador) ou obtém credenciais/link único para acessar sua Carteira Digital Online (WebP interativo frente/verso, download de PDF e QR Code de validação anti-fraude), além de poder interagir e pagar taxas de emissão pelo bot de WhatsApp.



---


## Telas do MVP

**Página inicial**

![Página inicial](/telas/01-landing.png)

