# Telemetria

> Versão preliminar. Será refinada com os documentos de telemetria que você possui.

## Coleta
Tabela `telemetria_eventos` (append-only e sem PII): `id, ocorrido_em, cliente_id, subcliente_id, usuario_id, tipo, quantidade, meta_json`. Alimentada pelos listeners dos eventos do sistema. Agregação diária por `cron.php` em `telemetria_diaria`. A rotina não deve copiar nome, CPF, foto, token, conteúdo de mensagem ou outro identificador direto para `meta_json`.

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

