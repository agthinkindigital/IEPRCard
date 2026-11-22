# Designs de carteirinha — guia funcional

## Objetivo e acesso

O editor organiza os layouts que a Sede usa para produzir carteirinhas. `design.ver` permite consultar; `design.criar`, criar; `design.editar`, alterar; `design.mapear`, vincular campos; e `design.publicar`, publicar. Salvar mantém o layout como rascunho; publicação é uma ação separada. Unidades usam os designs compartilhados pela Sede. O acesso é limitado ao tenant resolvido pelo sistema.

## Criar ou editar um design

1. Em **Designs**, abra um layout existente ou selecione **Novo design**. Informe um nome que ajude a identificar o público ou a finalidade, por exemplo “Credencial de membro”.
2. Escolha **Frente** ou **Verso** e selecione a imagem de fundo daquele lado, se houver. A prévia mantém o tamanho CR80 de 1011 × 638 px.
3. Use **Adicionar elemento** para inserir texto, foto do titular, QR de validação ou código de barras. Selecione um item na lista ou no cartão para editar suas propriedades.
4. Vincule campos dinâmicos às variáveis do titular ou da hierarquia. Posicione o elemento arrastando no cartão ou informando X/Y; ajuste tamanho, fonte, cor e alinhamento no painel de propriedades.
5. Revise frente e verso em **Pré-visualizar**. A amostra usa dados fictícios, não dados reais de titulares.
6. Selecione **Salvar design**. As alterações permanecem em rascunho até serem salvas; uma validação informa o campo que precisa de correção e mantém o restante do rascunho disponível.

O formato do mapa, os tipos de elemento e as variáveis disponíveis seguem o contrato em [`docs/06-design-e-mapeamento.md`](../06-design-e-mapeamento.md); este guia não duplica essa especificação.

## No celular

As áreas de elementos, fundo e propriedades ficam nas abas **Campos**, **Fundo** e **Ajustes**. Use os controles numéricos para posicionar com precisão. **Salvar design** permanece fixo na parte inferior.

## Falhas e cuidados

- Se o arquivo de fundo não puder ser enviado ou validado, corrija o formato ou o tamanho indicado pela tela e tente novamente; o rascunho não deve ser descartado.
- Mantenha textos e QR dentro da área segura para evitar corte na impressão.
- Se sair antes de salvar, a tela avisa que há alterações pendentes e permite voltar ao editor.
- O preview usa conteúdo fictício. Não use dados reais de titulares para testar o layout.

## Proposta de privacidade em revisão

O mockup e a especificação visual propõem classificar cada fundo como arte sem pessoa identificável ou vinculá-lo a um único titular. Fundos com várias pessoas seriam bloqueados; a foto individual usaria o campo dinâmico de foto. Texto fixo não conteria dados pessoais; dados do titular usariam variáveis dinâmicas. A proposta ainda não está implementada nem aprovada; consulte a [especificação da página](../../design-system/pages/editor-designs.md).
