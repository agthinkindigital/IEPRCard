# Página: portal do titular e validação QR

## Referências

O mockup [`portal-titular-mockup.svg`](../mockups/portal-titular-mockup.svg) reúne os estados de acesso, carteirinha digital e validação pública. Usa os tokens de [`MASTER.md`](../MASTER.md). As regras de negócio, os dados expostos e os limites de acesso permanecem em [`docs/01-regras-de-negocio.md`](../../docs/01-regras-de-negocio.md) e no [SAD](../../docs/architecture/SAD.md); este documento especifica apenas a apresentação e os estados visíveis.

## Acesso do titular

- O portal atende somente pessoas com `titular_final` e acesso online liberado. Não exibe navegação administrativa, cadastro, recuperação de senha ou dados de outros titulares.
- Segue as formas previstas no domínio: CPF + data de nascimento ou link com token único enviado pela organização.
- Mensagens de falha são genéricas e não confirmam se CPF, data de nascimento ou cadastro existem.
- A sessão autenticada abre diretamente a área da carteirinha, sem dashboard intermediário.

## Carteirinha digital

- Mostrar a organização/unidade emissora e a vinculação à Sede conforme os dados permitidos para o vínculo.
- Exibir frente e verso com controle explícito de lado; não expor CPF na área de consulta.
- Se houver mais de uma emissão ativa e acessível, permitir selecionar uma delas; caso contrário, não mostrar seletor.
- A ação de baixar PDF só aparece quando a permissão efetiva da Sede/unidade liberar o arquivo. Quando negada, explicar que a organização não habilitou o download.
- A validação QR abre uma página pública separada; nenhum dado administrativo ou identificador interno é enviado ao navegador.

## Validação pública

- Exibir estado com rótulo e texto além da cor: ativa, suspensa, vencida, revogada ou código inválido.
- Para cartão ativo, mostrar nome, foto quando disponível, unidade emissora e validade aplicável. Nunca mostrar CPF.
- Para estados inativos ou código inválido, usar orientação genérica sem expor motivo operacional privado.
- Disponibilizar uma ação para validar outro código, sem criar sessão nem revelar se um titular existe.

## Responsividade e acessibilidade

- Desktop: limitar a largura da área de conteúdo e manter o cartão legível sem ampliar a navegação.
- Celular: cabeçalho compacto, conteúdo em coluna, frente/verso e ações em sequência; a tela não exige gesto de arrastar para descobrir ações.
- Rótulos persistentes, foco visível, operação por teclado e anúncios de loading/erro. Estados nunca dependem apenas de cor.
- Respeitar `prefers-reduced-motion`; o giro visual do cartão deve ter alternativa por botão de texto e controle de lado.

## Dependências para implementação

O mockup e o comportamento precisam de aprovação explícita antes de criar Issues de frontend ou implementar telas. A Issue [#66](https://github.com/agthinkindigital/IEPRCard/issues/66) rastreia os serviços backend de autenticação e consulta privada; a rota QR minimizada está na [#65](https://github.com/agthinkindigital/IEPRCard/issues/65). O contrato de permissão para download de PDF ainda não está implementado no schema. Não adicionar essas regras silenciosamente à camada visual.
