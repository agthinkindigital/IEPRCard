# Quality Gates

## 8. Quality Gates (Portões de Validação entre Fases)

O agente só tem permissão para avançar da Fase $N$ para a Fase $N+1$ quando todos os critérios objetivos do respectivo **Quality Gate** retornarem `PASS` (código de saída `0`):

* **GATE 1 — Fundação & Dual DB (Após Épico 1):**
* [x] `php -l` retornou zero erros em 105 arquivos de `config/` e `includes/` com PHP 8.2.35 em 2026-11-23.
* [x] Suíte `TDD-01` passou no CI SQLite/MySQL 8.4/MariaDB 10.11: [run 45198416904](https://github.com/agthinkindigital/IEPRCard/actions/runs/45198416904).
* [x] A migração transferiu 1.000 registros SQLite→MySQL com contagem e SHA-256 iguais; CI [45198416904](https://github.com/agthinkindigital/IEPRCard/actions/runs/45198416904).


* **GATE 2 — Multi-Tenancy, Domínios & RBAC (Após Épico 2):**
* [x] Suíte `TDD-02` passou no CI SQLite/MySQL/MariaDB: [run 45198416904](https://github.com/agthinkindigital/IEPRCard/actions/runs/45198416904).
* [x] Isolamento por tenant/unidade e negação de acesso por ID adulterado passaram em testes de escopo e rotas HTTP no CI [45198416904](https://github.com/agthinkindigital/IEPRCard/actions/runs/45198416904).
* [ ] Suporte remoto MCP permanece exclusivo do superadmin, com grants de 30 min a 24 h, múltiplos alvos congelados e publicação de código somente em `DEV`. A implementação está em validação na [Issue #89](https://github.com/agthinkindigital/IEPRCard/issues/89); aprovação do mockup não substitui revisão de segurança nem CI.




* **GATE 3 — Motor de Importação, Mapeamento & Renderização PDF/WebP (Após Épico 3 e 4):**
* [x] Suítes `TDD-03`, `TDD-04` e `TDD-10` passaram no CI SQLite/MySQL/MariaDB: [run 45198416904](https://github.com/agthinkindigital/IEPRCard/actions/runs/45198416904).
* [x] WebP 1011×638: ≤80 ms por face em média no benchmark frente/verso definido na [Issue #36](https://github.com/agthinkindigital/IEPRCard/issues/36); CI PHP 8.2 mediu 49,55 ms (MySQL) e 49,29 ms (MariaDB) por face no [run 45198416904](https://github.com/agthinkindigital/IEPRCard/actions/runs/45198416904).
* [x] PDF de 50 carteirinhas: 3.065,89 ms no job de benchmark PHP 8.2, abaixo do limite de 3,5 s imposto no CI [45198416904](https://github.com/agthinkindigital/IEPRCard/actions/runs/45198416904).
* [x] Portal do titular e validação pública de QR passaram nos testes HTTP da suíte CI [45198416904](https://github.com/agthinkindigital/IEPRCard/actions/runs/45198416904).


* **GATE 4 — WhatsApp sem cobrança & Telemetria (Após Épicos 5 e 6):**
* [x] Fluxos WhatsApp/IA autenticados, idempotentes e com consentimento passaram na suíte CI [45198416904](https://github.com/agthinkindigital/IEPRCard/actions/runs/45198416904); não dependem de pagamento.
* [x] `TDD-06` e recortes por perfil passaram na suíte CI [45198416904](https://github.com/agthinkindigital/IEPRCard/actions/runs/45198416904).
* [x] `TDD-05`, pagamentos, webhooks Asaas/Mercado Pago e liquidação permanecem fora do MVP, conforme `AGENTS.md` e `docs/07-pagamentos-e-financeiro.md`.


* **GATE 5 — Servidor MCP & Prontidão de Produção (Após Épico 7):**
* [x] `TDD-07`, incluindo `db.inspect`, passou na suíte CI SQLite/MySQL/MariaDB e no benchmark PDF: [run 45207558966](https://github.com/agthinkindigital/IEPRCard/actions/runs/45207558966), commit `39bc8b6`.
* [x] O runtime registra `system.read`, `telemetry.read` e `db.inspect`; o teste HTTP da #88 cobre escopo, schema/drift estrutural e ausência de mutação. Cliente externo oficial `mcp==2.3.0` completou descoberta e chamadas read-only com OAuth client credentials em SQLite isolado; credencial limitada não descobriu nem executou `telemetry.read`. Evidência e limite do cliente TypeScript estão na [Issue #90](https://github.com/agthinkindigital/IEPRCard/issues/90). Essa prova cobre somente a superfície read-only atual.
* [ ] Revisar segurança e executar o CI completo da manutenção MCP privada e publicação limitada a `DEV`, conforme a [Issue #89](https://github.com/agthinkindigital/IEPRCard/issues/89). A implementação local inclui grants revogáveis, destinos congelados, PAT cifrado e testes obrigatórios de branch/CI; `design.write`, SQL arbitrário, escrita irrestrita no filesystem e `code.patch` genérico seguem fora do escopo.
* [ ] Demonstrar RPO ≤ 1 h e RTO ≤ 4 h para snapshot/restauração isolada no perfil contratado, conforme [#84](https://github.com/agthinkindigital/IEPRCard/issues/84) e [#85](https://github.com/agthinkindigital/IEPRCard/issues/85).



---
