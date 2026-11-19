# Página: titulares e importação

## Referências

O mockup [`titulares-importacao-mockup.svg`](../mockups/titulares-importacao-mockup.svg) cobre consulta, cadastro individual e importação em lote. Usa os tokens de [`MASTER.md`](../MASTER.md). Campos, limites e resolução de erros seguem o backend de E03 e as regras de dados de [`docs/01-regras-de-negocio.md`](../../docs/01-regras-de-negocio.md); não duplicar o contrato de mapeamento de [`docs/06-design-e-mapeamento.md`](../../docs/06-design-e-mapeamento.md).

## Consulta e cadastro

- A consulta respeita o tenant e a unidade da sessão; operador de unidade nunca escolhe nem consulta outra unidade.
- A tabela mostra nome, CPF parcialmente oculto, matrícula, unidade, situação e origem. Busca e filtros permanecem dentro do escopo autorizado.
- Cadastro individual exige nome completo; CPF, matrícula, cargo/função, categoria e WhatsApp são opcionais conforme validação do backend.
- O cadastro individual não envia foto. Para fotos, o usuário inclui ZIP opcional na importação em lote.
- A confirmação de cadastro informa sucesso ou erro estável sem ecoar dados pessoais em log ou auditoria.

## Importação em lote

- Aceitar CSV, XLS, XLSX ou JSON, até 10 MB e 500 registros. O ZIP de fotos é opcional; requer arquivos no nível raiz com nome-base correspondente ao CPF ou matrícula, em JPG, JPEG, PNG ou WebP. Cada arquivo precisa corresponder a um registro sem ambiguidade.
- Exibir o escopo de destino de forma clara. O escopo vem da sessão e autorização; não aceitar identificadores de tenant/unidade definidos livremente pelo navegador.
- Após upload, mostrar colunas detectadas, amostra limitada e erros por linha/campo/código. Não apresentar a prévia como validação integral.
- Exigir mapeamento único de coluna para campo e mapear `nome_completo`. Os demais alvos permitidos são CPF, matrícula, cargo/função e WhatsApp.
- Antes de confirmar, explicar que a validação e gravação são atômicas: duplicidade, dado inválido, erro no ZIP ou cota insuficiente rejeita o lote inteiro; não há importação parcial.
- Desabilitar confirmação durante o envio para evitar repetição. Depois do sucesso, exibir o número importado e a referência do lote; em falha, preservar o staging quando permitido para correção ou cancelamento.
- Permitir cancelar enquanto o lote estiver aguardando mapeamento. Staging não confirmado expira em 24 horas e a interface informa essa validade.
- Não inventar progresso percentual: a confirmação é síncrona. Em mobile, transformar as etapas em fluxo vertical, manter ações finais acessíveis e evitar rolagem horizontal da página.

## Estados essenciais

Arquivo inválido ou incompatível, arquivo pronto, mapeamento incompleto, prévia com alertas, confirmação em andamento, importação concluída, falha integral, cancelado e expirado. Todo estado usa texto além de cor e preserva foco e navegação por teclado.

## Dependências para implementação

O serviço de E03 já cobre criação e confirmação, mas a consulta/listagem e a leitura de um lote preparado para reexibir o mapeamento precisam de rotas de leitura autorizadas. A implementação deve confirmar esse contrato em Issues antes de codificar essas partes da tela. O contrato não deve ser ampliado silenciosamente dentro da camada visual.
