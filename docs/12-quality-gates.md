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
* [ ] Suítes `TDD-03`, `TDD-04` e `TDD-10` passam 100%.
* [ ] Medição no runner PHP 8.2 isolado do banco: $\le 80\text{ ms}$ por carteirinha WebP (Frente+Verso) e $\le 3,5\text{ s}$ para um lote PDF de 50 carteirinhas, conforme o [ADR-0007](adr/0007-renderizacao-nativa-e-fila.md).
* [ ] Portal do titular exibe a carteirinha online e o QR Code resolve na página pública de validação.


* **GATE 4 — WhatsApp sem cobrança & Telemetria (Após Épicos 5 e 6):**
* [ ] Fluxos WhatsApp/IA incluídos no MVP processam eventos com validação de assinatura e idempotência, sem checkout ou dependência de pagamento.
* [ ] `TDD-06` passa 100%; painéis de telemetria exibem os recortes permitidos para Admin, Cliente e Subcliente.
* [ ] `TDD-05`, webhooks Asaas/Mercado Pago e requisitos de liquidação ficam fora deste gate e só serão retomados ao priorizar o módulo financeiro futuro.


* **GATE 5 — Servidor MCP & Prontidão de Produção (Após Épico 7):**
* [ ] Suíte `TDD-07` passa 100%.
* [ ] Um agente externo conecta via MCP, altera a posição `X,Y` de um campo no layout, gera um preview WebP e consulta a telemetria sem intervenção humana.



---
