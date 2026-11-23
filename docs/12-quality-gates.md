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
* [ ] Suporte remoto auditável em três níveis não tem implementação/testes nem Issue filha ativa; aguardando decisão do mantenedor sobre permanecer no MVP.




* **GATE 3 — Motor de Importação, Mapeamento & Renderização PDF/WebP (Após Épico 3 e 4):**
* [x] Suítes `TDD-03`, `TDD-04` e `TDD-10` passaram no CI SQLite/MySQL/MariaDB: [run 45198416904](https://github.com/agthinkindigital/IEPRCard/actions/runs/45198416904).
* [ ] WebP 1011×638: ADR-0007 e o teste definem ≤80 ms por face. Em PHP 8.2.35 local, o par levou 105,47 ms (média 52,74 ms/face); o teste ainda não impõe o limite nem mede cada face separadamente, então o gate permanece aberto.
* [x] PDF de 50 carteirinhas: 913,60 ms local em PHP 8.2.35 e limite de 3,5 s imposto no CI: [run 45198416904](https://github.com/agthinkindigital/IEPRCard/actions/runs/45198416904).
* [x] Portal do titular e validação pública de QR passaram nos testes HTTP da suíte CI [45198416904](https://github.com/agthinkindigital/IEPRCard/actions/runs/45198416904).


* **GATE 4 — WhatsApp sem cobrança & Telemetria (Após Épicos 5 e 6):**
* [x] Fluxos WhatsApp/IA autenticados, idempotentes e com consentimento passaram na suíte CI [45198416904](https://github.com/agthinkindigital/IEPRCard/actions/runs/45198416904); não dependem de pagamento.
* [x] `TDD-06` e recortes por perfil passaram na suíte CI [45198416904](https://github.com/agthinkindigital/IEPRCard/actions/runs/45198416904).
* [x] `TDD-05`, pagamentos, webhooks Asaas/Mercado Pago e liquidação permanecem fora do MVP, conforme `AGENTS.md` e `docs/07-pagamentos-e-financeiro.md`.


* **GATE 5 — Servidor MCP & Prontidão de Produção (Após Épico 7):**
* [x] `TDD-07` passou na suíte CI SQLite/MySQL/MariaDB: [run 45198416904](https://github.com/agthinkindigital/IEPRCard/actions/runs/45198416904).
* [ ] Não há evidência E2E de um agente externo realizando alteração X/Y, preview WebP e consulta de telemetria sem intervenção humana; manter o gate aberto até essa validação.



---
