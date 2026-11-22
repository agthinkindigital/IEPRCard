# Titulares e importação — guia funcional

Esta área permite cadastrar titulares individualmente ou importar uma lista com até 500 registros. A consulta e as ações seguem o tenant e a unidade aos quais sua sessão tem acesso. O ciclo de vida dos dados pessoais está em [`docs/01-regras-de-negocio.md`](../01-regras-de-negocio.md).

## Cadastro individual

1. Abra **Titulares** e selecione **Novo titular**.
2. Informe o nome completo. CPF, matrícula, cargo/função, categoria e WhatsApp são opcionais.
3. Confira a unidade indicada pela tela e selecione **Salvar titular**.
4. Se a validação falhar, corrija o campo indicado. O cadastro não é criado parcialmente.

O formulário individual não recebe foto. Para associar fotos, use o arquivo ZIP opcional na importação.

## Importar uma lista

1. Prepare CSV, XLS, XLSX ou JSON com no máximo 500 registros e 10 MB. Mantenha uma coluna que possa ser mapeada para **Nome completo**.
2. Em **Titulares**, selecione **Importar lista** e envie o arquivo. A tela mostra o escopo de destino, as colunas encontradas e uma amostra.
3. Se tiver fotos, anexe um ZIP opcional com imagens JPG, JPEG, PNG ou WebP diretamente na raiz do arquivo compactado. Use CPF ou matrícula como nome do arquivo, por exemplo `M-1042.jpg`; não crie pastas dentro do ZIP. Cada imagem deve corresponder a um titular do lote sem ambiguidade; remova arquivos extras.
4. Associe cada coluna de origem ao campo correspondente. O nome completo é obrigatório; CPF, matrícula, cargo/função e WhatsApp são opcionais. Não associe duas colunas ao mesmo campo.
5. Revise o resumo e selecione **Revisar e confirmar**. A confirmação valida e grava o lote inteiro em uma operação atômica: se houver dado inválido, CPF duplicado, foto incompatível ou cota insuficiente, nenhum registro do lote é importado.
6. Aguarde a resposta e confira a quantidade criada. Não reenvie o formulário enquanto estiver processando.

## Cancelar ou corrigir

- Um lote em **Aguardando mapeamento** pode ser cancelado antes da confirmação.
- Se um erro impedir a confirmação, leia o código e o campo apontados. Corrija a planilha ou o ZIP e envie novamente; não há modo de ignorar linhas inválidas.
- Após o envio, as linhas são armazenadas cifradas uma a uma, incluindo colunas ainda não mapeadas. Se houver fotos, o ZIP é extraído, cada imagem é cifrada junto à linha correspondente e os uploads originais não ficam armazenados.
- Um lote não confirmado expira após 24 horas. Cancelamento ou expiração apaga as linhas cifradas e solicita a purga de suas chaves em backups externos.
- Se a página não confirmar o resultado, consulte o estado do lote antes de tentar de novo. O estado final é registrado no banco para impedir uma segunda confirmação.

O formato e as regras técnicas de mapeamento seguem [`docs/06-design-e-mapeamento.md`](../06-design-e-mapeamento.md). A tela não deve incluir dados pessoais em mensagens técnicas ou registros de auditoria.

## Proteção do staging

Cada linha importada recebe um sujeito provisório de privacidade. Ao confirmar, ele é associado ao titular criado e os campos não mapeados são preservados em dados extras do titular. Arquivos ou fotos sem associação inequívoca bloqueiam o lote inteiro. A proposta aprovada da interface e os estados de revisão estão na [especificação visual](../../design-system/pages/titulares-importacao.md).
