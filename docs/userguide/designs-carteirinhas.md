# Designs de carteirinha — guia funcional

## Objetivo e acesso

O editor organiza os layouts que a Sede usa para produzir carteirinhas. `design.ver` permite consultar; `design.criar`, criar; `design.editar`, alterar; `design.mapear`, vincular campos; e `design.publicar`, publicar. Salvar mantém o layout como rascunho; publicação é uma ação separada. Unidades usam os designs compartilhados pela Sede. O acesso é limitado ao tenant resolvido pelo sistema.

## Criar ou editar um design

1. Em **Designs**, abra um layout existente ou selecione **Novo design**. Informe um nome que ajude a identificar o público ou a finalidade, por exemplo “Credencial de membro”.
2. Escolha **Frente** ou **Verso** e selecione a imagem de fundo daquele lado, se houver. Imagens PNG, JPEG e WebP são aceitas. Para PDF de até 10 MB, o navegador converte somente a primeira página em PNG de 1011 × 638 px e envia essa imagem; o PDF original permanece no computador e não é armazenado. O conversor PDF.js 6.5.438 é incluído localmente, sem CDN. A prévia mantém o tamanho CR80.
3. Use **Adicionar elemento** para inserir texto, foto do titular, QR de validação ou código de barras. Selecione um item na lista ou no cartão para editar suas propriedades.
4. Vincule campos dinâmicos às variáveis do titular ou da hierarquia. Posicione o elemento arrastando no cartão ou informando X/Y; ajuste tamanho, fonte, cor e alinhamento no painel de propriedades.
5. Revise frente e verso em **Pré-visualizar**. A amostra usa dados fictícios, não dados reais de titulares.
6. Selecione **Salvar design**. As alterações permanecem em rascunho até serem salvas; uma validação informa o campo que precisa de correção e mantém o restante do rascunho disponível.

O formato do mapa, os tipos de elemento e as variáveis disponíveis seguem o contrato em [`docs/06-design-e-mapeamento.md`](../06-design-e-mapeamento.md); este guia não duplica essa especificação.

## No celular

As áreas de elementos, fundo e propriedades ficam nas abas **Campos**, **Fundo** e **Ajustes**. Use os controles numéricos para posicionar com precisão. **Salvar design** permanece fixo na parte inferior.

## Falhas e cuidados

- Se o arquivo de fundo não puder ser enviado ou validado, corrija o formato ou o tamanho indicado pela tela e tente novamente; o rascunho não deve ser descartado.
- O PDF precisa ser válido, ter até 10 MB e não pode exigir senha. Um erro de leitura não envia o documento.
- Mantenha textos e QR dentro da área segura para evitar corte na impressão.
- Se sair antes de salvar, a tela avisa que há alterações pendentes e permite voltar ao editor.
- O preview usa conteúdo fictício. Não use dados reais de titulares para testar o layout.

## Privacidade dos fundos

Ao enviar um fundo, escolha **Arte decorativa sem pessoa identificável** ou **Imagem vinculada a um titular**. Imagens com mais de uma pessoa identificável não podem ser usadas como fundo; para uma foto individual, use o elemento dinâmico de foto. Texto fixo não deve conter dados pessoais: use variáveis dinâmicas.

Fundos decorativos ficam associados ao sujeito de privacidade da Sede. Um fundo individual fica associado ao titular escolhido e é armazenado no envelope removível desse sujeito nos snapshots. Fundos legados aparecem como **Selecione a classificação**; classifique cada um antes de gerar novos snapshots. Até essa revisão, a geração de snapshots falha de forma segura. A interface aprovada está descrita na [especificação visual](../../design-system/pages/editor-designs.md).
