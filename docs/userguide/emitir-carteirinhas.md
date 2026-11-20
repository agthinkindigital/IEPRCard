# Emissão de carteirinhas — guia funcional

## Objetivo e acesso

O fluxo prepara uma emissão para um lote importado ou um titular já cadastrado. Apenas usuários autenticados com a permissão `carteirinha.gerar` podem usá-lo. A Sede pode escolher uma unidade dentro do próprio tenant; usuários de Unidade ficam limitados aos dados e designs compartilhados com ela.

O fluxo segue o [contrato de mapeamento e renderização](../06-design-e-mapeamento.md). Este guia descreve a operação da tela, sem repetir o schema JSON.

Os dados exibidos dentro da imagem do mockup são ilustrativos; a prévia funcional usa um registro representativo da origem autorizada.

## Preparar uma emissão

1. Em **Origem e design**, selecione um lote concluído ou um titular disponível no seu escopo. Confira quantidade, origem e unidade. Selecione um design ativo disponível e o modo: **WebP digital**, **PDF CR80** ou **PDF A4** (8 ou 10 por folha).
2. Em **Mapeamento**, escolha **Usar mapeamento do design** ou **Personalizar para esta emissão**. A personalização vale apenas para o novo job. Ela não modifica o design, o mapa usado para importar o arquivo ou outros jobs.
3. Para cada variável de texto do design, selecione um campo do titular, monte um template com partes em ordem ou informe um valor fixo. O sistema vincula a foto ao campo de foto do titular e gera o QR de validação; esses campos não aceitam valor manual.
4. Revise a prévia com um registro representativo da origem e os avisos de campos obrigatórios. A prévia não cria job nem reserva cota. Se houver valor ausente ou incompatível, corrija o mapeamento ou selecione outra amostra antes de continuar.
5. Em **Revisão**, confira origem, design, quantidade, saída e cota disponível. **Adicionar à fila** confirma uma solicitação, reserva a cota na mesma operação e mostra o job assíncrono. WebP aceita até 500 titulares; cada PDF consolidado aceita até 50.

## Acompanhar e repetir

Após confirmar, acompanhe o estado do job — na fila, processando, concluído ou falhou — na lista de emissões. Uma tentativa repetida da mesma solicitação reutiliza a chave de idempotência e o job existente. Para emitir intencionalmente de novo, inicie uma nova solicitação e confirme outra vez; ela cria um job e uma reserva próprios.

## Falhas e regras visíveis

- Se não houver lote concluído ou titular disponível, volte ao cadastro/importação e finalize a origem antes de tentar emitir.
- Se não houver design ativo permitido para a origem, peça à Sede para publicar um design e compartilhá-lo com a unidade.
- Se faltar mapeamento ou valor obrigatório, a tela aponta a variável que precisa de correção sem incluir dados pessoais no erro técnico.
- Se a cota não cobrir a solicitação, nenhum job é criado; revise o saldo com o administrador da conta.
- Se uma falha ocorrer depois da confirmação, acompanhe o estado do job. Retentativas do worker preservam o mapa salvo e não duplicam carteirinhas; uma falha terminal informa erro acionável e libera a reserva correspondente.
- Valores de preview devem ser tratados como dados pessoais quando vierem de uma origem real. A consulta fica restrita ao mesmo escopo autorizado e não deve gravar a amostra em logs, telemetria, URL ou analytics.

## No celular e acessibilidade

Campos e preview aparecem em sequência vertical. Os rótulos permanecem visíveis, controles são utilizáveis por toque e teclado e estado/erro é escrito em texto, não indicado apenas por cor. Se uma tabela não couber, cada mapeamento aparece como cartão; não há rolagem horizontal da página.
