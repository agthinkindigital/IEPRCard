# Hooks e Eventos

## 4. Arquitetura de Hooks, Eventos e Ciclo de Vida

Para manter o código desacoplado e garantir que **toda ação relevante alimente a telemetria (Requisitos 14, 14.1 e 14.2)** sem poluir as regras de negócio, `includes/motor_eventos.php` implementa duas primitivas simples:

* `eventoDisparar(string $nomeEvento, array $contexto): void` (Notifica *listeners* e grava telemetria).
* `hookAplicarFiltro(string $nomeFiltro, mixed $valor, array $contexto): mixed` (Permite interceptar e transformar dados antes de persistir ou renderizar).

### 4.1. Catálogo de Eventos do Sistema e Efeitos Colaterais

| Evento (`eventoDisparar`) | Origem do Disparo | Listeners Automáticos Acionados | Escopo de Telemetria Gravado |
| --- | --- | --- | --- |
| `tenant.criado` | `admin/clientes.php` ou `painel/subclientes.php` | Cria diretórios isolados em `assets/uploads/tenants/{id}`, gera `cliente_webhook_token`, registra subdomínio.

 | Admin (sempre) + Cliente Pai (se for Subcliente) |
| `usuario.criado` | Cadastro de operadores ou auto-registro | Envia credenciais iniciais, incrementa contador de usuários no banco. | Admin + Cliente + Subcliente |
| `titular.importado_lote` | `includes/motor_importacao.php` | Normaliza telefone BR e valida CPF, vincula foto do ZIP se existir.

 | Admin + Cliente + Subcliente |
| `titular.criado_manual` | `painel/preencher-manual.php` | Gera `token_acesso_carteira` se o modo exigir acesso do titular. | Admin + Cliente + Subcliente |
| `design.mapa_salvo` | `painel/editor-design.php` ou `mcp/server.php` | Limpa cache de preview do design e valida coordenadas dentro dos limites da imagem. | Admin + Cliente |
| `carteirinha.gerada_webp` | `includes/motor_carteirinha_webp.php` | Debita 1 unidade da cota mensal do Cliente/Subcliente (se primeira emissão do titular no layout), calcula bytes em disco. | Admin + Cliente + Subcliente |
| `carteirinha.gerada_pdf` | `includes/motor_carteirinha_pdf.php` | Registra geração para impressão gráfica/PVC; se lote concluir, notifica operador. | Admin + Cliente + Subcliente |
| `carteirinha.acesso_enviado` | `painel/mapear-gerar.php` / `api/whatsapp.php` | Envia mensagem WhatsApp com WebP da carteirinha + link do Portal do Titular. | Admin + Cliente + Subcliente |
| `carteirinha.validada_qr` | `carteira/validar.php` | Incrementa `total_leituras_qr` e grava log de auditoria de portaria (IP/User-Agent). | Admin + Cliente + Subcliente |
| `pagamento.saas_confirmado` | `webhook_asaas.php` / `webhook_mercadopago.php`

 | Muda `tenants.status_conta = 'ativo'`, desbloqueia Subclientes filhos, renova franquia mensal.

 | Admin + Cliente |
| `pagamento.saas_vencido` | `webhook_asaas.php` / `webhook_mercadopago.php`

 | Muda `tenants.status_conta = 'vencido'`, suspende emissão de novas carteirinhas e bot WhatsApp.

 | Admin + Cliente |
| `pagamento.titular_confirmado` | `webhook_pagamento_cliente.php`<br> | Dispara geração imediata da carteirinha do titular e envia o WebP/PDF no WhatsApp. | Admin + Cliente + Subcliente |
| `conversa.mensagem_processada` | `webhook_whatsapp.php`<br> | Contabiliza mensagens enviadas/recebidas, transcrições Whisper e tokens de IA.

 | Admin + Cliente + Subcliente |
| `suporte.token_utilizado` | `includes/suporte.php`<br> | Grava `usado_em` e `ip_usado`, cria alerta de auditoria no painel da conta acessada.

 | Admin + Cliente + Subcliente |

### 4.2. Catálogo de Hooks de Filtro (`hookAplicarFiltro`)

1. `filtro.importacao.linha_normalizada`: Recebe o array bruto de uma linha do CSV/XLSX/JSON e aplica sanitização (trim, conversão de datas `DD/MM/YYYY` $\rightarrow$ `YYYY-MM-DD`, formatação de CPF e telefone) antes do mapeamento.
2. `filtro.mapeamento.valor_campo`: Intercepta o valor já mapeado para um campo do design (ex: aplica máscara de CPF, converte nome para `MB_CASE_UPPER` ou trunca nomes longos para caber na largura máxima da caixa de texto da arte).
3. `filtro.permissao.checar_cota`: Verifica em tempo real se o Subcliente possui saldo na cota delegada pelo Cliente e se o Cliente possui saldo na cota do Plano definido pelo Admin.

---


## Telas do MVP

**Validação pública por QR Code**

![Validação pública por QR Code](/telas/05-validacao-qr.png)

