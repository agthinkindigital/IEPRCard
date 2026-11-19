# Plano de TDD

## 6. Plano de TDD (Test-Driven Development)

Nenhum módulo de negócio será considerado pronto sem passar pela suíte automatizada em `tests/run_all.php`. Os testes rodam inicialmente sobre um banco **SQLite efêmero** (`/tmp/test_cardsaas.sqlite`) e depois são reexecutados contra **MySQL/MariaDB** para comprovar a paridade dual (Requisito 02).

### Matriz de Contratos de Teste (Escrever o Teste ANTES da Implementação)

| ID da Suíte | Arquivo de Teste | O que é Testado (Asserções Obrigatórias) |
| --- | --- | --- |
| **TDD-01** | `tests/Integration/DualDatabaseSelfHealingTest.php`, `tests/Integration/MySqlConnectionSmokeTest.php` | 1. Confirma seleção do driver por `DB_DRIVER`, contrato dos helpers com parâmetros preparados e timestamp ISO UTC; em SQLite, confirma foreign keys e WAL.<br>2. Em SQLite vazio, aplica o schema canônico e verifica as 27 tabelas do MVP, sem as cinco tabelas financeiras reservadas para fase futura.<br>3. Em `APP_ENV=dev|test`, simula colunas ausentes e confirma *self-healing* sem perda de dados; em produção, confirma que a verificação de drift não executa DDL.<br>4. Repete os cenários de driver, schema e *self-healing* no MySQL/MariaDB. |
| **TDD-02** | `tests/Integration/TenantResolverTest.php`, `tests/Integration/TenantScopeTest.php`, `tests/Integration/PermissionEngineTest.php` | 1. Resolve somente hosts explícitos, ativos, verificados e com TLS; tenants pendentes, inativos ou desconhecidos são negados.<br>

<br>2. Simula `HTTP_HOST = escolaX.com.br` $\rightarrow$ resolve `cliente` (`id=10`).<br>

<br>3. Simula `HTTP_HOST = polo1.escolaX.com.br` $\rightarrow$ resolve `subcliente` (`id=25`, `parent_id=10`).<br>

<br>4. Revalida usuário/papel/escopo persistidos, intersecta perfil e overrides com as flags do plano e a delegação da Sede; negativa prevalece e adulteração de sessão/IDs não amplia acesso. |
| **TDD-03** | `tests/Integration/BatchImportParserTest.php`, `tests/Integration/BatchImportStagingTest.php`, `tests/Integration/BatchImportStagingCliTest.php`, `tests/Integration/BatchImportConfirmationTest.php`, `tests/Integration/TitularProvisioningTest.php`, `tests/Integration/TitularBatchProvisioningTest.php`, `tests/Integration/TitularBatchProvisioningConcurrencyTest.php` | Lê 500 registros CSV (`;`, UTF-8/ISO-8859-1), XLSX e JSON; compara saída normalizada; valida CPF/telefone BR, prévia, erros sem PII e staging privado. Cobre confirmação atômica de até 500 titulares, escopo/mapeamento, rollback, retry, cota e cleanup terminal, além do cadastro individual e chunks de até 25 com WhatsApp e concorrência. Contratos: [issues #27](https://github.com/agthinkindigital/IEPRCard/issues/27) a [#32](https://github.com/agthinkindigital/IEPRCard/issues/32). |
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
| **TDD-08** | `tests/Integration/TitularAnonymizationTest.php`, `tests/Integration/TitularAnonymizationRaceWorker.php`, `tests/Integration/DualDatabaseSelfHealingTest.php`, `tests/Integration/MySqlConnectionSmokeTest.php` | Confirma referência cifrada em repouso, idempotência antes e depois da conclusão, processamento imediato com prazo máximo de 72 h, anonimização após 12 meses da inativação, fronteira de ano bissexto, remoção da referência concluída, ausência de reprocessamento e serialização concorrente. Repete os contratos de banco em SQLite e MySQL/MariaDB. |

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
