# Roadmap, Recursos e Prazos

## 10. Roadmap Executivo, Previsão de Recursos e Prazos

As fases agrupam as issues existentes. Prazo e custo serão estimados após o fatiamento e um benchmark no perfil de hospedagem escolhido; ainda não há base para prometer dias de execução ou capacidade mensal.

## Epics rastreáveis no GitHub

- [**[E01] Fundação técnica, banco dual e migrações**](https://github.com/agthinkindigital/IEPRCard/issues/1) - `done`
- [**[E02] Multi-tenancy, domínios e controle de acesso**](https://github.com/agthinkindigital/IEPRCard/issues/2) - `in_progress` (E02.1–E02.9 concluídas, incluindo criação de unidade com cota em [Issue #24](https://github.com/agthinkindigital/IEPRCard/issues/24); E02.10 [#25](https://github.com/agthinkindigital/IEPRCard/issues/25) está em execução; ainda faltam aplicar limites nos fluxos de equipe/titulares/emissão, interfaces e gate operacional TLS/aliases)
- [**[E03] Cadastro, importação e gestão de titulares**](https://github.com/agthinkindigital/IEPRCard/issues/3) - `todo`
- [**[E04] Designs, mapeamento e emissão de carteirinhas**](https://github.com/agthinkindigital/IEPRCard/issues/4) - `todo`
- [**[E05] WhatsApp e IA sem cobrança**](https://github.com/agthinkindigital/IEPRCard/issues/5) - `todo`
- [**[E06] Telemetria e painel operacional**](https://github.com/agthinkindigital/IEPRCard/issues/6) - `todo`
- [**[E07] MCP, segurança e prontidão operacional**](https://github.com/agthinkindigital/IEPRCard/issues/7) - `in_progress` (E07.1–E07.4 concluídas em [Issues #19](https://github.com/agthinkindigital/IEPRCard/issues/19) a [#22](https://github.com/agthinkindigital/IEPRCard/issues/22); ferramenta MCP de telemetria depende do recorte por Sede definido no E06)

Os issues GitHub acima são as fontes de escopo dos Epics; este arquivo continua sendo o roadmap canônico do repositório.

| Fase / Sprint | Escopo Entregue (Épicos & Issues) | Prazo Humano | Prazo com Agente IA | Recursos de Infraestrutura Necessários |
| --- | --- | --- | --- | --- |
| **Sprint 1: Fundação & Multi-Tenancy** | **Épicos 1 e 2** (`ISSUE-001` a `ISSUE-006`) + schema de operação/privacidade + núcleo MCP read-only do `ISSUE-017` + **Gate 1 & 2** | A estimar após fatiamento | A estimar | PHP 8.2+, SQLite para dev/test, MySQL no host e cron; confirmar DNS/TLS e armazenamento privado do plano. |
| **Sprint 2: Importação & Motor Gráfico** | **Épicos 3 e 4** (`ISSUE-007` a `ISSUE-012`) + **Gate 3** | A estimar após fatiamento | A estimar | Extensões `php-gd` (FreeType/WebP), `php-zip` e `php-mbstring`; confirmar limites de memória e execução. |
| **Sprint 3: Integrações sem cobrança & Telemetria** | Parte ativa do **Épico 5** (`ISSUE-015` WhatsApp/IA sem cobrança) + Épico 6 (`ISSUE-016`) + **Gate 4**; `ISSUE-013` e `ISSUE-014` adiadas | A estimar após fatiamento | A estimar | Validar conexões de saída e cron para integrações ativas; sem sandbox, credencial ou webhook Asaas/Mercado Pago no MVP. |
| **Sprint 4: MCP avançado & Hardening** | Conclusão do **Épico 7** (`ISSUE-017`), tools avançadas e **Gate 5** | A estimar após fatiamento | A estimar | Plano compartilhado ou VPS validado; cópia externa cifrada, gestão de chaves e restore testados. Binlogs só se o host os oferecer. |
| **TOTAL DO PROJETO** | **17 Issues + 8 Suítes TDD + 5 Quality Gates** | A estimar após fatiamento e benchmark | A estimar | Custo depende do plano, storage externo e gestão de chaves. |

---
