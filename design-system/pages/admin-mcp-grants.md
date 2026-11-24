# Página: concessões de manutenção MCP

## Referências

O mockup [`admin-mcp-grants-mockup.svg`](../mockups/admin-mcp-grants-mockup.svg) mostra a criação, o segredo de uso único e a lista de concessões. Usa os tokens de [`MASTER.md`](../MASTER.md). O contrato do transporte e a separação entre concessão temporária e OAuth estão no [ADR-0009](../../docs/adr/0009-mcp-escopos-e-patch-seguro.md); as tools disponíveis estão em [`docs/09-mcp.md`](../../docs/09-mcp.md).

## Estrutura e comportamento

- A página aparece somente na administração global do superadmin. Contas de tenant não recebem navegação nem endpoint de concessão.
- O formulário aceita rótulo, validade entre 30 minutos e 24 horas, múltiplos tenants e unidades, e capacidades permitidas. A seleção de uma Sede inclui todas as unidades subordinadas; o backend resolve e valida essa lista em cada operação.
- A concessão permite as tools selecionadas durante toda a janela, sem confirmação humana por chamada. O superadmin pode revogá-la a qualquer momento. Expiração e revogação negam chamadas imediatamente; uma nova janela exige nova concessão.
- O mockup marca manutenção de dados e publicação de código em `DEV` como pendentes da #89. O catálogo de ferramentas e a integração GitHub/gates ainda não estão aprovados para implementação. Nunca disponibilizar escrita MCP direta em `PROD`.
- O segredo da concessão aparece apenas após criação. A lista não permite recuperá-lo e mostra rótulo, criador, alvos, capacidades, criação, expiração, último uso, estado e ação de revogação.
- A auditoria registra metadados mínimos da operação e resultado; não apresenta nem persiste segredo, parâmetros livres, IP bruto ou PII desnecessária.

## Estados, segurança e acessibilidade

- Lista vazia explica como uma concessão funciona e oferece **Nova concessão**.
- Criação valida pelo menos um alvo e uma capacidade, exibe falhas junto ao campo e bloqueia envio duplicado durante processamento.
- O sucesso mostra o segredo uma única vez com ação de copiar e orientação para guardá-lo. Fechar ou sair da tela não permite recuperar o segredo.
- Confirmação de revogação identifica o rótulo e o escopo; revogação concluída muda o estado para **Revogada**.
- Rótulos de ativa, expirada e revogada usam texto junto à cor. Controles têm rótulos persistentes, teclado, foco visível e contraste AA; no celular, formulário e lista viram cartões em coluna única.
- A interface deixa claro que o prazo da concessão é diferente do access token OAuth de curta duração usado pelo transporte.

## Limite da proposta

O desenho não define formato ou armazenamento de credenciais, catálogo de tools de manutenção, autorização de escrita em repositório, CI, rollback nem schema. Esses itens permanecem na [Issue #89](https://github.com/agthinkindigital/IEPRCard/issues/89). O runtime atual segue read-only até conclusão do contrato e revisão de segurança. Implementação da tela depende de aprovação explícita deste mockup e guia.
