# Concessões de manutenção pelo MCP

Esta proposta descreve como o superadmin autoriza suporte técnico por MCP. A página e as tools de manutenção ainda não estão implementadas; o runtime atual continua read-only. O contrato do MCP está no [ADR-0009](../adr/0009-mcp-escopos-e-patch-seguro.md) e as capacidades disponíveis estão em [`docs/09-mcp.md`](../09-mcp.md).

## Criar uma concessão

1. Acesse **Administração → Segurança → Acesso MCP** com a conta superadmin.
2. Informe um rótulo que identifique a atividade e escolha `30m`, `1h`, `3h`, `6h`, `12h` ou `24h`.
3. Selecione um ou mais tenants e/ou unidades. Selecionar uma Sede inclui todas as unidades subordinadas. Confira o resumo antes de continuar.
4. Marque somente as capacidades necessárias. A concessão autoriza as ferramentas permitidas durante todo o período, sem confirmação por chamada.
5. Gere a concessão e copie o segredo exibido uma única vez. Se perder o segredo, revogue a concessão e crie outra.

Uma concessão expirada ou revogada deixa de autorizar chamadas. Para continuar o atendimento após a expiração, crie uma nova concessão com escopo e prazo atualizados. O prazo da concessão é separado do access token OAuth curto usado por cada conexão MCP.

## Acompanhar e revogar

A lista mostra quem criou cada concessão, os alvos e capacidades autorizados, prazo, último uso e estado. Selecione **Revogar**, confira o rótulo e confirme para bloquear novas chamadas imediatamente. A auditoria registra ferramenta, alvo validado, horário e resultado, sem segredo, IP bruto ou argumentos livres.

## Publicação de código

O requisito aprovado permite preparar mudanças de código via MCP e publicá-las somente em `DEV`. A integração GitHub, preservação da autoria Leonardo, execução de CI e gates, catálogo de operações e reversão ainda precisam ser definidos na [Issue #89](https://github.com/agthinkindigital/IEPRCard/issues/89). `PROD` não é gravada pelo MCP; mudanças só chegam lá após verificação e revisão pelo fluxo normal do projeto.

## Aguardando implementação

O superadmin ainda não pode criar concessões nesta versão. As ferramentas de leitura listadas em [`docs/09-mcp.md`](../09-mcp.md) são o contrato atualmente implementado. A tela e as capacidades de manutenção só devem ser desenvolvidas após aprovação deste mockup/guia e conclusão da #89.
