# Página: titulares e importação

## Referências

O mockup [`titulares-importacao-mockup.svg`](../mockups/titulares-importacao-mockup.svg) cobre consulta, cadastro individual e importação em lote. Usa os tokens de [`MASTER.md`](../MASTER.md). Campos, limites e resolução de erros seguem o backend de E03 e as regras de dados de [`docs/01-regras-de-negocio.md`](../../docs/01-regras-de-negocio.md); não duplicar o contrato de mapeamento de [`docs/06-design-e-mapeamento.md`](../../docs/06-design-e-mapeamento.md).

## Consulta e cadastro

- A consulta respeita o tenant e a unidade da sessão; operador de unidade nunca escolhe nem consulta outra unidade.
- A tabela mostra nome, CPF parcialmente oculto, matrícula, unidade, situação e origem. Para inativos, também mostra a data UTC de inativação. Busca e filtros permanecem dentro do escopo autorizado.
- Cadastro individual exige nome completo; CPF, matrícula, cargo/função, categoria e WhatsApp são opcionais conforme validação do backend.
- O cadastro individual pode incluir foto JPG/JPEG, PNG ou WebP. A interface de gestão permite substituir a foto e alterar o estado ativo/inativo quando a permissão `titular.editar` estiver disponível.
- A confirmação de cadastro informa sucesso ou erro estável sem ecoar dados pessoais em log ou auditoria.

## Importação em lote

### Staging protegido — aprovado em 2026-11-22

- Ao extrair o arquivo, cada linha e suas fotos correspondentes formam um sujeito provisório separado. Todos os valores da linha, inclusive colunas ainda não mapeadas, permanecem cifrados individualmente durante a revisão.
- CPF ou matrícula associa as fotos ZIP à linha. Linha ou foto sem associação, ambígua ou duplicada bloqueia a confirmação; não há importação parcial.
- Depois da extração, o arquivo original e o ZIP são removidos. O staging por linha continua disponível para revisar o mapeamento; confirmar vincula cada sujeito provisório ao titular criado, cancelar ou expirar remove suas chaves e arquivos. A expiração continua em 24 horas.
- A interface mostra a contagem de linhas protegidas e pendências, sem exibir referências de privacidade nem dados pessoais em estado técnico. O fluxo operacional está no [guia de importação](../../docs/userguide/titulares-importacao.md).

- Aceitar CSV, XLS, XLSX ou JSON, até 10 MB e 500 registros. O ZIP de fotos é opcional; requer arquivos no nível raiz com nome-base correspondente ao CPF ou matrícula, em JPG, JPEG, PNG ou WebP. Cada arquivo precisa corresponder a um registro sem ambiguidade.
- Exibir o escopo de destino de forma clara. O escopo vem da sessão e autorização; não aceitar identificadores de tenant/unidade definidos livremente pelo navegador.
- Após upload, mostrar colunas detectadas, amostra limitada e erros por linha/campo/código. Não apresentar a prévia como validação integral.
- Exigir mapeamento único de coluna para campo e mapear `nome_completo`. Os demais alvos permitidos são CPF, matrícula, cargo/função e WhatsApp.
- Antes de confirmar, explicar que a validação e gravação são atômicas: duplicidade, dado inválido, erro no ZIP ou cota insuficiente rejeita o lote inteiro; não há importação parcial.
- Desabilitar confirmação durante o envio para evitar repetição. Depois do sucesso, exibir o número importado e a referência do lote; em falha, preservar o staging quando permitido para correção ou cancelamento.
- Permitir cancelar enquanto o lote estiver aguardando mapeamento. Staging não confirmado expira em 24 horas e a interface informa essa validade.
- Não inventar progresso percentual: a confirmação é síncrona. Em mobile, transformar as etapas em fluxo vertical, manter ações finais acessíveis e evitar rolagem horizontal da página.

## Foto individual e estado do titular — mockup aprovado

- A foto individual aceita JPG/JPEG, PNG ou WebP, até 5 MB e 12 megapixels. Armazenar fora da raiz pública, com chave privada vinculada ao tenant; remover metadados ao recodificar a imagem.
- Apenas uma pessoa deve aparecer na foto. Não registrar bytes, nome original ou identificadores da foto em auditoria.
- A ação de inativar mostra a confirmação exigida pelas regras de ciclo de vida dos dados, grava o instante UTC e revoga o acesso ao portal do titular. A data não pode ser retroagida.
- Reativar cancela pedidos automáticos de retenção ainda pendentes e mantém o acesso ao portal revogado até uma liberação separada. Titular anonimizado não pode ser reativado. A regra canônica está em [`docs/01-regras-de-negocio.md`](../../docs/01-regras-de-negocio.md).
- A tela mostra confirmação antes de inativar e exibe “Inativo desde … UTC” na lista. Foto e controles respeitam o tenant/unidade e `titular.editar`.

O mockup atualizado está em [`titulares-importacao-mockup.svg`](../mockups/titulares-importacao-mockup.svg); retenção e exclusão seguem as [regras de negócio](../../docs/01-regras-de-negocio.md).

## Estados essenciais

Arquivo inválido ou incompatível, arquivo pronto, mapeamento incompleto, prévia com alertas, confirmação em andamento, importação concluída, falha integral, cancelado e expirado. Todo estado usa texto além de cor e preserva foco e navegação por teclado.

## Dependências para implementação

O serviço de E03 já cobre criação e confirmação, mas a consulta/listagem e a leitura de um lote preparado para reexibir o mapeamento precisam de rotas de leitura autorizadas. A implementação deve confirmar esse contrato em Issues antes de codificar essas partes da tela. O contrato não deve ser ampliado silenciosamente dentro da camada visual.
