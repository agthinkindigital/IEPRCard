# Página: concessões de manutenção MCP

## Referências

O mockup [`admin-mcp-grants-mockup.svg`](../mockups/admin-mcp-grants-mockup.svg) mostra a criação, o segredo de uso único e a lista de concessões. Usa os tokens de [`MASTER.md`](../MASTER.md). O contrato do transporte e a separação entre concessão temporária e OAuth estão no [ADR-0009](../../docs/adr/0009-mcp-escopos-e-patch-seguro.md); as tools disponíveis estão em [`docs/09-mcp.md`](../../docs/09-mcp.md).

## Estrutura e comportamento

- A página aparece somente na administração global do superadmin. Contas de tenant não recebem navegação nem endpoint de concessão.
- O formulário aceita rótulo, validade entre 30 minutos e 24 horas, múltiplos tenants e unidades, e capacidades permitidas. A seleção de uma Sede congela no grant todas as unidades subordinadas existentes naquele instante; novas unidades exigem outra concessão. O servidor valida o conjunto congelado em cada operação.
- A concessão permite as tools selecionadas durante toda a janela, sem confirmação humana por chamada. O superadmin pode revogá-la a qualquer momento. Expiração e revogação negam chamadas imediatamente; uma nova janela exige nova concessão.
- A capacidade de publicação de código em `DEV` só aparece quando a integração GitHub está configurada e validada. O fine-grained PAT fica cifrado no servidor com `IEPR_INTEGRATION_KEY`, nunca é lido de volta nem enviado ao contexto MCP, e deve ser restrito ao repositório IEPRCard. CI e proteção de `PROD` são pré-requisitos; nenhuma tool escreve em `PROD`.
- A manutenção de dados permanece desabilitada até existir um catálogo de operações tipadas e limitadas aprovado na #89. Não há SQL arbitrário, execução de shell nem acesso irrestrito ao filesystem.
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

O desenho exibe o estado da integração GitHub e permite testar, definir/substituir ou remover o PAT sem revelá-lo após salvar. O token fica cifrado com a chave definida em [ADR-0008](../../docs/adr/0008-webhooks-idempotentes-e-segredos.md). A tela, o guia e a extensão visual do controle de PAT foram aprovados em 2026-11-24. A implementação fica em `/admin/acesso-mcp.php`; as ferramentas mutáveis de dados continuam limitadas ao escopo aprovado na [Issue #89](https://github.com/agthinkindigital/IEPRCard/issues/89).
