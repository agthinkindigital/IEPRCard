# Páginas: unidades, equipe e cotas

Esta especificação agrupa três telas do Cliente Master porque compartilham navegação, tenant e os limites da mesma Sede. Os fluxos de uso estão no [guia funcional](../../docs/userguide/unidades-equipe-cotas.md); os tokens vêm de [`MASTER.md`](../MASTER.md).

## Mockups

- [Unidades da rede](../mockups/unidades-tenant-mockup.svg)
- [Equipe e acessos](../mockups/equipe-tenant-mockup.svg)
- [Cotas por unidade](../mockups/cotas-tenant-mockup.svg)

## Regras de interface

- O contexto é sempre a Sede autenticada; IDs recebidos da tela não trocam tenant.
- Unidades, usuários, limites e consumo são separados por unidade, com totais consolidados da própria Sede.
- A Sede cria os acessos administrativos. Responsáveis de unidade não criam usuários.
- A lista de permissões delegadas é um teto; o perfil de cada operador pode restringir mais, nunca ampliar.
- `Pool` significa sem reserva fixa daquela dimensão; o consumo continua sob o teto global da Sede.
- Erros de cota mostram o limite e o total já reservado naquele tenant, sem dados de outra Sede.
- Campos desativados, pendentes ou ainda não cobertos por serviço backend não podem sugerir ação concluída.

## Responsividade

- 1440 px: tabela com colunas e resumo da rede.
- 1024 px: manter contexto e filtros; quebrar colunas menos importantes em segunda linha.
- 768 px e 375 px: tabelas viram cartões com rótulos por campo; formulários de criação/edição ficam em uma coluna e confirmam antes de ações destrutivas.
- Estados sempre incluem texto, foco visível, mensagem junto ao campo e anúncio de loading/erro.
