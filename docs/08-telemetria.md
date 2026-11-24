# Telemetria

## Coleta
Telemetria de negócio usa `telemetria_eventos` (append-only) e `telemetria_diaria`. O contrato de colunas é mantido no [schema canônico](../database/schema/0001/03-operations.php) e suas extensões versionadas. A gravação recebe apenas categoria e chave permitidas, quantidade agregada e identidade autenticada do servidor; não aceita identificador de titular/usuário, valor livre ou metadados. A lista inicial de categoria/chave é `issuance / card.generated`, `messaging / whatsapp.sent`, `messaging / whatsapp.failed`, `import / rows.accepted`, `import / rows.rejected` e `validation / qr.validated`. Totais são agrupados por dia UTC. O escopo diário da Sede usa chave normalizada `0`, evitando que a semântica de `NULL` permita agregados duplicados em SQLite/MySQL.

As leituras aceitam janela UTC inclusiva de até 366 dias. Admin informa explicitamente a visão global ou o cliente/unidade alvo; Cliente vê a rede; Subcliente fica restrito à própria unidade. Esses serviços de domínio ainda não representam endpoints HTTP nem telas.

As métricas operacionais de lotes ficam separadas em `metricas_lotes_diarias`; seu cálculo e sua ativação seguem o [ADR-0011](adr/0011-observabilidade-golden-signals.md). A tabela operacional agrega por dia e ambiente e não guarda identificadores de tenant ou titular.

Eventos técnicos de requests e jobs terão armazenamento separado da telemetria de negócio e consulta exclusiva pelo superadmin. A primeira fatia da [E08.1](https://github.com/agthinkindigital/IEPRCard/issues/94) padroniza o correlation ID HTTP; a coleta persistida e a consulta seguem as demais fatias da [E08](https://github.com/agthinkindigital/IEPRCard/issues/93). O contrato e as regras de minimização estão no [ADR-0011](adr/0011-observabilidade-golden-signals.md). Prazo de retenção e uso de IDs internos de Sede/unidade aguardam confirmação.

### Consulta técnica superadmin — mockup aprovado; implementação pendente

O [mockup da consulta técnica](../design-system/mockups/admin-technical-events-mockup.svg) e este guia foram aprovados em 2026-11-24. A implementação está na [#96](https://github.com/agthinkindigital/IEPRCard/issues/96) e depende da persistência da [#95](https://github.com/agthinkindigital/IEPRCard/issues/95). A tela é exclusiva ao superadmin e não altera a visão existente de Cliente/Subcliente.

- Filtrar por período, categoria, rota e correlation ID; pesquisar e navegar pelos resultados em páginas de até 50 eventos, mais recentes primeiro. Selecionar um correlation ID reaplica o filtro correspondente.
- Exibir data/hora UTC, chave de evento, rota, resultado, duração e parte do correlation ID; os resultados só poderão usar campos allowlisted do [ADR-0011](adr/0011-observabilidade-golden-signals.md).
- Mostrar estados textuais para lista vazia, nenhum resultado e falha de leitura. A interface não oferece exportação, alteração de eventos, SQL, IP/UA ou visualização de headers/bodies.
- Em telas estreitas, apresentar cada resultado como cartão com os mesmos campos essenciais, sem rolagem horizontal.
- O prazo de retenção e o uso de IDs internos de Sede/unidade continuam pendentes; por isso, a proposta não fixa um número de dias nem inclui filtro de tenant/unidade.

## Métricas por nível
| Métrica agregada | Admin | Cliente | Subcliente |
| --- | --- | --- | --- |
| Carteirinhas geradas | Global ou escopo selecionado | Rede e unidade selecionada | Própria unidade |
| Mensagens WhatsApp enviadas/falhas | Global ou escopo selecionado | Rede e unidade selecionada | Própria unidade |
| Linhas de importação aceitas/rejeitadas | Global ou escopo selecionado | Rede e unidade selecionada | Própria unidade |
| Validações por QR Code | Global ou escopo selecionado | Rede e unidade selecionada | Própria unidade |
| Demanda, duração, fila e falhas de lotes | Global, apenas Admin | Não | Não |

Contagens de titulares/usuários, consumo de cotas, tokens/custos de IA, receita e métricas de infraestrutura não entram neste painel até existir coleta e leitura próprias compatíveis com a privacidade e o escopo. Pagamentos e dados financeiros continuam fora do MVP.

## Painéis
- Admin: visão global ou por cliente/unidade e estado operacional de lotes. A área operacional mostra pico previsto e lote recomendado com base no P95 dos picos diários completos da janela selecionada (padrão de 30 dias, mínimo de 7 dias observados), parâmetros ativos e alterações pendentes. O Administrador pode editar lote máximo, pico previsto e janela; confirmar e salvar cria uma versão pendente. O worker de lotes carrega os parâmetros no próximo início, sem reiniciar PHP-FPM nem interromper o lote em andamento. Em hospedagem compartilhada, a versão pendente entra em vigor na próxima execução agendada do worker. A execução em andamento conserva a configuração que carregou. Alertas ficam limitados a métricas que a coleta operacional disponibiliza, como falhas e fila.
- Cliente: visão agregada da própria rede, com filtro de período e unidade; não exibe infraestrutura nem permite alterar configuração operacional.
- Subcliente: visão da própria unidade, sem filtro para outros escopos, dados de infraestrutura ou controles operacionais.

Os filtros de período usam janelas suportadas pelo serviço de leitura. A previsão operacional aceita de 7 a 365 dias; dias sem registro não contam como observados, o dia UTC corrente é excluído e, com menos de 7 dias observados, o painel apresenta “Histórico insuficiente” sem inventar previsão. A previsão e a sugestão de lote são estimativas, não limites de cota nem promessa de capacidade.

### Guia funcional para aprovação da interface

- Uma única página de painel adapta título, métricas e filtros ao escopo resolvido pela sessão. A seleção do período e da unidade afeta apenas as leituras permitidas ao perfil; nenhum ID enviado pelo navegador amplia o escopo autorizado.
- A série diária de emissão lista até os 30 dias mais recentes com emissão dentro do período selecionado; datas sem emissão não aparecem. Os cartões de métricas continuam resumindo todo o período.
- A visão Admin separa métricas de negócio e operação. Permite escolher global, cliente ou unidade. A configuração de lotes aparece apenas para quem tem permissão administrativa: valores ativos e pendentes, versão, janela de previsão, valores recomendados/previstos e campos editáveis. Salvar exige confirmação e cria uma versão pendente. A alteração entra em vigor no próximo início do worker, não reinicia PHP-FPM nem interrompe ou altera lotes em andamento; em hospedagem compartilhada, o próximo início é a execução agendada seguinte.
- A visão Cliente resume a rede e permite filtrar uma unidade e o período. A visão Subcliente fixa a unidade no backend e não apresenta seletor de unidade.
- Estados visíveis após a resposta: sem dados no período, histórico insuficiente para previsão, falha de leitura e configuração salva aguardando worker. O painel é renderizado no servidor e não possui estado de carregamento próprio; estado e orientação aparecem em texto, não apenas por cor.
- Em viewport móvel (390 px), empilhar métricas, previsão e campos de configuração em uma coluna; manter filtros, confirmação e salvamento acessíveis sem rolagem horizontal. Cliente e Subcliente seguem a mesma composição, sem controles operacionais.
- Métricas nunca mostram nomes, CPF, tokens, conteúdo livre ou identificadores de titulares. Dados agregados respeitam a retenção definida nesta documentação.
- A Issue [#70](https://github.com/agthinkindigital/IEPRCard/issues/70) concluiu em `DEV` a página HTTP autenticada `/telemetria/`, os filtros de escopo/período, a série diária e a previsão Admin configurável entre 7 e 365 dias. Os handlers validam janela e autorização no servidor; métricas operacionais não aparecem para perfis de Cliente ou Subcliente. A suíte local passou em SQLite e MySQL 9.7.4, e o [CI](https://github.com/agthinkindigital/IEPRCard/actions/runs/44682841032) passou em MySQL 8.4, MariaDB 10.11 e renderização PDF. A Issue [#71](https://github.com/agthinkindigital/IEPRCard/issues/71) concluiu os controles Admin com CSRF e confirmação para criar uma versão pendente, aplicada pelo worker no próximo início; a suíte local e o [CI](https://github.com/agthinkindigital/IEPRCard/actions/runs/44684813533) passaram em SQLite, MySQL 9.7.4, MySQL 8.4, MariaDB 10.11 e renderização PDF.
- O mockup das três variações em [`design-system/mockups/telemetry-dashboard-mockup.svg`](../design-system/mockups/telemetry-dashboard-mockup.svg) e este guia foram aprovados pelo mantenedor em 2026-11-20.

## Privacidade
Sem dados pessoais em `meta_json`. Métricas agregadas que não permitam reidentificação podem ser retidas por 24 meses. O ciclo de vida dos dados pessoais está definido em `docs/01-regras-de-negocio.md`.

