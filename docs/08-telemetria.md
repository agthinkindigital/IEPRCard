# Telemetria

## Coleta
Telemetria de negócio usa `telemetria_eventos` (append-only) e `telemetria_diaria`. O contrato de colunas é mantido no [schema canônico](../database/schema/0001/03-operations.php) e suas extensões versionadas. A gravação recebe apenas categoria e chave permitidas, quantidade agregada e identidade autenticada do servidor; não aceita identificador de titular/usuário, valor livre ou metadados. A lista inicial de categoria/chave é `issuance / card.generated`, `messaging / whatsapp.sent`, `messaging / whatsapp.failed`, `import / rows.accepted`, `import / rows.rejected` e `validation / qr.validated`. Totais são agrupados por dia UTC. O escopo diário da Sede usa chave normalizada `0`, evitando que a semântica de `NULL` permita agregados duplicados em SQLite/MySQL.

As leituras aceitam janela UTC inclusiva de até 366 dias. Admin informa explicitamente a visão global ou o cliente/unidade alvo; Cliente vê a rede; Subcliente fica restrito à própria unidade. Esses serviços de domínio ainda não representam endpoints HTTP nem telas.

As métricas operacionais de lotes ficam separadas em `metricas_lotes_diarias`; seu cálculo e sua ativação seguem o [ADR-0011](adr/0011-observabilidade-golden-signals.md). A tabela operacional agrega por dia e ambiente e não guarda identificadores de tenant ou titular.

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
- Admin: visão global ou por cliente/unidade e estado operacional de lotes. A área operacional mostra pico previsto e lote recomendado com base no P95 dos picos diários completos da janela selecionada (padrão de 30 dias, mínimo de 7 dias observados), parâmetros ativos e alterações pendentes. O Administrador pode editar lote máximo, pico previsto e janela; salvar cria uma versão pendente. Após confirmação, a ação de reinício gracioso recarrega os parâmetros somente no worker de lotes, sem reiniciar o PHP-FPM nem interromper o lote em andamento; em hospedagem compartilhada, a versão pendente entra em vigor no próximo início agendado do worker. A execução em andamento conserva a configuração que carregou. Alertas ficam limitados a métricas que a coleta operacional disponibiliza, como falhas e fila.
- Cliente: visão agregada da própria rede, com filtro de período e unidade; não exibe infraestrutura nem permite alterar configuração operacional.
- Subcliente: visão da própria unidade, sem filtro para outros escopos, dados de infraestrutura ou controles operacionais.

Os filtros de período usam janelas suportadas pelo serviço de leitura. A previsão operacional aceita de 7 a 365 dias; dias sem registro não contam como observados, o dia UTC corrente é excluído e, com menos de 7 dias observados, o painel apresenta “Histórico insuficiente” sem inventar previsão. A previsão e a sugestão de lote são estimativas, não limites de cota nem promessa de capacidade.

### Guia funcional para aprovação da interface

- Uma única página de painel adapta título, métricas e filtros ao escopo resolvido pela sessão. A seleção do período e da unidade afeta apenas as leituras permitidas ao perfil; nenhum ID enviado pelo navegador amplia o escopo autorizado.
- A visão Admin separa métricas de negócio e operação. Permite escolher global, cliente ou unidade. A configuração de lotes aparece apenas para quem tem permissão administrativa: valores ativos e pendentes, versão, janela de previsão, valores recomendados/previstos e campos editáveis. Salvar exige confirmação e solicita o reinício gracioso somente do worker de lotes; informa que a alteração entra em vigor no próximo início do worker e não reinicia PHP-FPM nem interrompe ou altera lotes em andamento. No perfil compartilhado, o próximo início é a execução agendada seguinte.
- A visão Cliente resume a rede e permite filtrar uma unidade e o período. A visão Subcliente fixa a unidade no backend e não apresenta seletor de unidade.
- Estados cobertos: carregando, sem dados no período, histórico insuficiente para previsão, falha de leitura e configuração salva aguardando worker. Estado e orientação aparecem em texto, não apenas por cor.
- Em viewport móvel (390 px), empilhar métricas, previsão e campos de configuração em uma coluna; manter filtros, confirmação e ação de reinício acessíveis sem rolagem horizontal. Cliente e Subcliente seguem a mesma composição, sem controles operacionais.
- Métricas nunca mostram nomes, CPF, tokens, conteúdo livre ou identificadores de titulares. Dados agregados respeitam a retenção definida nesta documentação.
- Os serviços de domínio existentes ainda não têm rotas HTTP para o painel. A fatia de interface inclui handlers autenticados para consultar agregados e a série diária de demanda, selecionar unidades sob escopo do servidor, ler configuração ativa/pendente e salvar nova versão. Os handlers validam janela e autorização e expõem apenas as métricas listadas; gravar configuração requer permissão administrativa e CSRF.
- O mockup visual das três variações está em [`design-system/mockups/telemetry-dashboard-mockup.svg`](../design-system/mockups/telemetry-dashboard-mockup.svg) e aguarda aprovação antes de abrir a implementação das telas.

## Privacidade
Sem dados pessoais em `meta_json`. Métricas agregadas que não permitam reidentificação podem ser retidas por 24 meses. O ciclo de vida dos dados pessoais está definido em `docs/01-regras-de-negocio.md`.

