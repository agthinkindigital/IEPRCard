# Telemetria

## Coleta
Telemetria de negócio usa `telemetria_eventos` (append-only) e `telemetria_diaria`. O contrato de colunas é mantido no [schema canônico](../database/schema/0001/03-operations.php): escopo de Cliente/Subcliente, categoria, chave de evento, quantidade e metadados sem PII. Nome, CPF, foto, token, conteúdo de mensagem e outros identificadores diretos nunca entram nos metadados.

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

