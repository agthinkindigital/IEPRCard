# Visão e Idealização

O planejamento estratégico e arquitetônico do **SaaS Multi-Tenant de Confecção Automática de Carteirinhas (CardSaaS Monolith)** consolida a especificação técnica, modelagem de dados, contratos de eventos, suíte de testes orientados a TDD, backlog de issues, *Quality Gates* e regras de orquestração para execução por agentes autônomos de engenharia de software.

---

## 1. Idealização e Planejamento Estratégico

### 1.1. Visão do Produto e Proposta de Valor

O sistema é um **monólito PHP** que usa SQLite em desenvolvimento, testes e instalações locais de uma única organização; em hospedagem, o perfil previsto usa MySQL/MariaDB. A compatibilidade com cada host compartilhado ou VPS precisa passar pelos gates operacionais antes de ser declarada pronta para produção. O produto emite documentos de identificação em formato híbrido: **PDF vetorial para impressão** e **WebP para a carteira digital**.

### 1.2. Hierarquia Multi-Tenant (4 Camadas) e Isolamento Lógico

O isolamento de dados é baseado em *Row-Level Multi-Tenancy* controlado pelas chaves `cliente_id` (Tenant Principal) e `subcliente_id` (Sub-Tenant), resolvido dinamicamente via `HTTP_HOST`:

1. **Superadmin (Master):** Gerencia planos de recursos, Sedes e acessos, configuração global de IA, permissões, telemetria e grants do servidor MCP. `Master` é o papel global, sem plano de cliente. Cobranças, checkout, repasses e webhooks Asaas/Mercado Pago ficam fora do MVP; veja [pagamentos e financeiro](07-pagamentos-e-financeiro.md).


2. **Cliente (Tenant Nível 1 — Domínio Próprio ou Subdomínio SaaS):** Organizações matrizes, redes de ensino, federações ou gráficas parceiras. Criam e personalizam múltiplos designs de carteirinha, cadastram Subclientes (filiais/unidades), delegam permissões dentro do limite do seu plano, emitem carteirinhas individualmente ou em lote e visualizam a telemetria consolidada da sua rede.
3. **Subcliente (Tenant Nível 2 — Subdomínio Vinculado):** Unidades operacionais, polos ou filiais de um Cliente. Possuem painel restrito às permissões concedidas pelo Cliente pai, podendo importar planilhas (CSV, XLS/XLSX, JSON), digitar dados manualmente (individual ou grade em lote), mapear colunas nos designs autorizados e gerar carteirinhas (PDF/WebP).
4. **Titular (Dono da Carteirinha):** Usuário final que recebe o documento pelo operador ou acessa sua carteira digital, quando habilitada, para consultar WebP frente/verso, baixar o PDF autorizado e validar a carteirinha por QR Code. A interação por WhatsApp pode ser habilitada com consentimento; cobrança de taxas não faz parte do MVP.



---


## Telas do MVP

**Página inicial**

![Página inicial](/telas/01-landing.png)

