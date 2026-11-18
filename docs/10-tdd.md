# Plano de TDD

## 6. Plano de TDD (Test-Driven Development)

Nenhum módulo de negócio será considerado pronto sem passar pela suíte automatizada em `tests/run_all.php`. Os testes rodam inicialmente sobre um banco **SQLite efêmero** (`/tmp/test_cardsaas.sqlite`) e depois são reexecutados contra **MySQL/MariaDB** para comprovar a paridade dual (Requisito 02).

### Matriz de Contratos de Teste (Escrever o Teste ANTES da Implementação)

| ID da Suíte | Arquivo de Teste | O que é Testado (Asserções Obrigatórias) |
| --- | --- | --- |
| **TDD-01** | `tests/Integration/DualDatabaseSelfHealingTest.php` | 1. Conecta em SQLite vazio, roda `schemaGarantirEstrutura($db)` e verifica a existência de todas as 15 tabelas.<br>

<br>2. Simula uma tabela antiga sem 2 colunas, roda o *self-healing* e confirma que as colunas foram adicionadas sem perda de dados.

<br>

<br>3. Repete os mesmos 2 passos usando driver MySQL/MariaDB. |
| **TDD-02** | `tests/Unit/TenantResolverAndRbacTest.php` | 1. Simula `HTTP_HOST = painel.saas.com` $\rightarrow$ resolve contexto `admin`.<br>

<br>2. Simula `HTTP_HOST = escolaX.com.br` $\rightarrow$ resolve `cliente` (`id=10`).<br>

<br>3. Simula `HTTP_HOST = polo1.escolaX.com.br` $\rightarrow$ resolve `subcliente` (`id=25`, `parent_id=10`).<br>

<br>4. Tenta conceder a um Subcliente uma permissão que o Cliente pai não possui $\rightarrow$ deve lançar bloqueio de segurança. |
| **TDD-03** | `tests/Integration/BatchImportParserTest.php` | 1. Importa um arquivo `.csv` (separado por `;` e encoding ISO-8859-1), um `.xlsx` e um `.json` contendo 50 titulares cada.<br>

<br>2. Verifica que todos os 3 formatos produzem exatamente o mesmo array normalizado de colunas e linhas.<br>

<br>3. Valida CPF via `motorPagtoValidarCpf` e normalização de telefone via `normalizarTelefoneBR`.

 |
| **TDD-04** | `tests/Visual/CardMappingAndRenderTest.php` | 1. Testa o Mapeamento Fixo e o Mapeamento Dinâmico De$\rightarrow$Para (Req 17.1).<br>

<br>2. Renderiza uma carteirinha Frente/Verso em **WebP** e verifica: arquivo existe, mime-type é `image/webp`, dimensões exatas `1011x638` px.<br>

<br>3. Renderiza um lote de 8 carteirinhas em **PDF** (CR80 e A4) e verifica cabeçalho `%PDF-1.4`, número de páginas esperado e presença do QR Code. |
| **TDD-05 (fase futura; fora do MVP)** | `tests/Integration/PaymentWebhooksTest.php` | Ao priorizar pagamentos: simular payload do Asaas (`PAYMENT_CONFIRMED` e `PAYMENT_OVERDUE`) em `webhook_asaas.php`, validar token via `hash_equals`, transições de conta e idempotência do mesmo `event.id`.

<br>

<br>Também validar payload do Mercado Pago em `webhook_mercadopago.php` com assinatura HMAC válida e inválida (`mpValidarAssinaturaWebhook`). Essa suíte não bloqueia o MVP enquanto pagamentos estiverem adiados.

 |
| **TDD-06** | `tests/Integration/TelemetryVisibilityTest.php` | 1. Dispara 10 eventos de geração de carteirinhas e 5 mensagens de WhatsApp distribuídos entre 2 Clientes e 3 Subclientes.<br>

<br>2. Consulta telemetria como Admin (Req 14) $\rightarrow$ vê 100% dos dados.<br>

<br>3. Consulta como Cliente 1 (Req 14.1) $\rightarrow$ vê apenas seus dados e de seus Subclientes.<br>

<br>4. Consulta como Subcliente 1A (Req 14.2) $\rightarrow$ vê estritamente os dados da sua unidade. |
| **TDD-07** | `tests/Integration/McpServerContractTest.php` | 1. Envia requisição JSON-RPC `tools/list` e `tools/call` para `mcp/server.php`.<br>

<br>2. Testa `mcp.design.inspect_and_map` e verifica se o preview WebP é atualizado.<br>

<br>3. Testa `mcp.code.safe_patch` com um erro proposital de sintaxe PHP e confirma que o *safety guard* rejeita o patch e mantém o arquivo original intacto. |

---



---

## Adendo: testes do modelo hierárquico


### Novos Testes Automatizados Obrigatórios na Suíte TDD

* **`TDD-02B` (`tests/Unit/SubAccessAndQuotaDistributionTest.php`):**
1. Cria o Cliente "Remida de Cristo - Sede" (Pr. Josué) com limite de `6` Subclientes e `1.000` membros.
2. Verifica que ao tentar criar o 7º Subcliente, o sistema bloqueia com mensagem clara de limite atingido.
3. Faz login como Pr. Flaviano (Dirigente de Itaquera) e tenta criar um usuário "Secretário" $\rightarrow$ **deve falhar (`403 Forbidden`)**, comprovando que só o Pr. Josué (Cliente Master) ou o Admin podem criar sub-acessos.
4. Pr. Josué aloca `300` membros para Itaquera. Quando Itaquera tenta importar uma planilha com `350` membros, o sistema barra antes da gravação informando o saldo restante (`300`).
5. Admin ativa, suspende e reativa manualmente o Cliente $\rightarrow$ confirma a propagação do status aos Subclientes; nenhum webhook financeiro é necessário no MVP.





---

Com esse refinamento, todos os requisitos (do modelo eclesiástico/corporativo real aos controles de cota, filtros limpos, sub-acessos e isenção pelo Admin) estão blindados na arquitetura.
