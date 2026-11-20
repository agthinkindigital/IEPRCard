# Página: emissão de carteirinhas

## Aplicação do design system

A página segue [`MASTER.md`](../MASTER.md), o contrato de dados e emissão em [`docs/06-design-e-mapeamento.md`](../../docs/06-design-e-mapeamento.md) e o fluxo funcional em [`docs/userguide/emitir-carteirinhas.md`](../../docs/userguide/emitir-carteirinhas.md). O mockup em [`emitir-carteirinhas-mockup.svg`](../mockups/emitir-carteirinhas-mockup.svg) mostra a etapa de revisão do mapeamento em desktop e celular.

## Estrutura e comportamento

- Mostrar o fluxo em três passos: origem e design; mapeamento; revisão e fila. A pessoa pode voltar sem criar job ou consumir cota.
- Restringir lotes, titulares, designs e prévias ao tenant/unidade autorizados. Sede pode filtrar uma unidade; Subcliente permanece na própria unidade.
- Começar pelo mapeamento fixo do design. A opção de personalizar vale somente para o job atual e não altera o design nem o mapa da importação.
- Exibir campos do registro do titular, `dados_extras` e valores literais; representar template como combinação ordenada de campos/texto. QR de validação e foto usam seus vínculos de sistema.
- Mostrar campos obrigatórios, valores ausentes e erros junto ao campo; não colocar valores de titulares em URL, telemetria ou mensagens técnicas.
- A prévia usa um registro representativo já dentro do escopo e não cria job nem reserva cota. Exibir estado de carregamento, erro e alternativa de escolher outro registro.
- Apresentar limite do modo de saída antes da confirmação: WebP até 500 titulares; PDF consolidado até 50. A execução final segue pela fila assíncrona existente.
- No celular, converter a grade em cartões verticais; manter rótulos persistentes, foco visível e ação principal alcançável sem rolagem horizontal.
- Não implementar a tela sem aprovação explícita do usuário deste mockup e do guia funcional correspondente.
