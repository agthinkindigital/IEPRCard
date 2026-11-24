# Concessões de manutenção pelo MCP

Esta página descreve como o superadmin autoriza suporte técnico por MCP. O contrato do transporte e das capabilities está no [ADR-0009](../adr/0009-mcp-escopos-e-patch-seguro.md) e em [`docs/09-mcp.md`](../09-mcp.md).

## Criar uma concessão

1. Acesse **Administração → Acesso MCP** com a conta superadmin.
2. Informe um rótulo que identifique a atividade e escolha `30m`, `1h`, `3h`, `6h`, `12h` ou `24h`.
3. Selecione uma ou mais Sedes e/ou unidades. Selecionar uma Sede inclui e congela no grant todas as unidades que existem naquele instante; uma unidade criada depois exige nova concessão.
4. Marque somente as capacidades necessárias. `repo.publish` só fica disponível depois que o PAT do repositório for configurado e testado. A concessão autoriza as ferramentas permitidas durante todo o período, sem confirmação por chamada.
5. Gere a concessão e copie o segredo exibido uma única vez. Se perder o segredo, revogue a concessão e crie outra.

Uma concessão expirada ou revogada deixa de autorizar chamadas. Para continuar o atendimento após a expiração, crie uma nova concessão com escopo e prazo atualizados. O prazo da concessão é separado do access token OAuth curto usado por cada conexão MCP.

## Acompanhar e revogar

A lista mostra quem criou cada concessão, os alvos e capacidades autorizados, prazo, último uso e estado. Selecione **Revogar**, confira o rótulo e confirme para bloquear novas chamadas imediatamente. A auditoria registra ferramenta, alvo validado, horário e resultado, sem segredo, IP bruto ou argumentos livres.

## Publicação de código

O superadmin configura um fine-grained PAT restrito ao repositório IEPRCard na área de integração da página. O sistema o cifra com `IEPR_INTEGRATION_KEY`, nunca permite recuperá-lo e não o apresenta à IA. A tela mostra apenas se a conexão está configurada e validada; para trocar, informe um novo token. A publicação identifica Leonardo como autor e só escreve em `DEV`. `PROD` exige CI aprovado, revisão e promoção pelo fluxo normal; se os checks ou a proteção da branch não estiverem ativos, a publicação deve ser recusada.

O catálogo de manutenção de dados continua desabilitado até haver operações tipadas, limitadas por alvo e auditáveis definidas na [Issue #89](https://github.com/agthinkindigital/IEPRCard/issues/89). O MCP não oferece SQL arbitrário, shell ou filesystem irrestrito.

## Limites operacionais

O segredo do grant é exibido em uma única resposta após a criação e removido da sessão na primeira visualização. Um snapshot restaura grants como revogados e não inclui o PAT GitHub nem os alvos congelados; eles precisam ser configurados novamente após uma restauração isolada. Tools mutáveis de dados continuam fora do catálogo até haver operações tipadas e delimitadas aprovadas na [Issue #89](https://github.com/agthinkindigital/IEPRCard/issues/89).
