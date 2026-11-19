# Telemetria

## Coleta
Telemetria de negócio usa `telemetria_eventos` (append-only) e `telemetria_diaria`. O contrato de colunas é mantido no [schema canônico](../database/schema/0001/03-operations.php) e suas extensões versionadas. A gravação recebe apenas categoria e chave permitidas, quantidade agregada e identidade autenticada do servidor; não aceita identificador de titular/usuário, valor livre ou metadados. A lista inicial de categoria/chave é `issuance / card.generated`, `messaging / whatsapp.sent`, `messaging / whatsapp.failed`, `import / rows.accepted`, `import / rows.rejected` e `validation / qr.validated`. Totais são agrupados por dia UTC. O escopo diário da Sede usa chave normalizada `0`, evitando que a semântica de `NULL` permita agregados duplicados em SQLite/MySQL.

As leituras aceitam janela UTC inclusiva de até 366 dias. Admin informa explicitamente a visão global ou o cliente/unidade alvo; Cliente vê a rede; Subcliente fica restrito à própria unidade. Esses serviços de domínio ainda não representam endpoints HTTP nem telas.

As métricas operacionais de lotes ficam separadas em `metricas_lotes_diarias`; seu cálculo e sua ativação seguem o [ADR-0011](adr/0011-observabilidade-golden-signals.md). A tabela operacional agrega por dia e ambiente e não guarda identificadores de tenant ou titular.

## Métricas por nível
| Métrica | Admin | Cliente | Subcliente |
| --- | --- | --- | --- |
| Carteirinhas geradas (PDF/WebP) | Global e por cliente | Rede e por unidade | Unidade |
| Titulares no banco / criados | Global | Rede | Unidade |
| Usuários e sub-acessos | Global | Rede | Unidade |
| Consumo de cota | Todos | Pool e distribuição | Própria |
| Conversas WhatsApp / IA (tokens) | Global e custo | Rede | Unidade (se delegado) |
| Importações (linhas ok/erro) | Global | Rede | Unidade |
| Validações por QR Code | Global | Rede | Unidade |
| Receita, MRR, inadimplência | Sim | Próprio plano | Não |
| Infraestrutura (fila, erros, disco) | Sim | Não | Não |

## Painéis
- Admin: visão global, ranking de clientes, alertas (cota > 80%, erros de fila, falhas de webhook).
- Cliente: visão da rede com filtro por unidade e período.
- Subcliente: visão restrita à unidade.

## Privacidade
Sem dados pessoais em `meta_json`. Métricas agregadas que não permitam reidentificação podem ser retidas por 24 meses. O ciclo de vida dos dados pessoais está definido em `docs/01-regras-de-negocio.md`.


## Telas do MVP

**Painel de telemetria**

![Painel de telemetria](/telas/06-painel.png)

