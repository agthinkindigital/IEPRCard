# Quality Gates

## 8. Quality Gates (Portões de Validação entre Fases)

O agente só tem permissão para avançar da Fase $N$ para a Fase $N+1$ quando todos os critérios objetivos do respectivo **Quality Gate** retornarem `PASS` (código de saída `0`):

* **GATE 1 — Fundação & Dual DB (Após Épico 1):**
* [ ] `php -l` retorna zero erros de sintaxe em `config/*` e `includes/*`.
* [ ] Suíte `TDD-01` passa 100% em SQLite e MySQL/MariaDB.
* [ ] Ferramenta de migração copia 1.000 registros de teste do SQLite para o MySQL com `checksum` idêntico.


* **GATE 2 — Multi-Tenancy, Domínios & RBAC (Após Épico 2):**
* [ ] Suíte `TDD-02` passa 100%.
* [ ] Isolamento comprovado: um Subcliente autenticado não consegue ler nem editar designs, lotes ou titulares de outro Subcliente ou de outro Cliente mesmo manipulando IDs via `GET`/`POST`.
* [ ] Acesso de suporte remoto (`suporte.php`) gera e invalida tokens corretamente nos 3 níveis (`admin`, `cliente`, `subcliente`).




* **GATE 3 — Motor de Importação, Mapeamento & Renderização PDF/WebP (Após Épico 3 e 4):**
* [ ] Suítes `TDD-03` e `TDD-04` passam 100%.
* [ ] Tempo de geração médio em benchmark local: $\le 80\text{ ms}$ por carteirinha WebP (Frente+Verso) e $\le 1,5\text{ s}$ para um lote PDF de 50 carteirinhas.
* [ ] Portal do titular exibe a carteirinha online e o QR Code resolve na página pública de validação.


* **GATE 4 — Pagamentos, Financeiro, Telemetria & WhatsApp (Após Épico 5 e 6):**
* [ ] Suítes `TDD-05` e `TDD-06` passam 100%.
* [ ] Webhooks Asaas e Mercado Pago processam eventos reais/sandbox de pagamento e estorno sem duplicidade.


* [ ] Painéis de telemetria de Admin, Cliente e Subcliente exibem exatamente os recortes permitidos pelos Requisitos 14, 14.1 e 14.2.


* **GATE 5 — Servidor MCP & Prontidão de Produção (Após Épico 7):**
* [ ] Suíte `TDD-07` passa 100%.
* [ ] Um agente externo conecta via MCP, altera a posição `X,Y` de um campo no layout, gera um preview WebP e consulta a telemetria sem intervenção humana.



---
