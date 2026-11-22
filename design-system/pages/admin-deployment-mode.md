# Modo de hospedagem da instalação

## Referências

O mockup [`admin-deployment-mode-mockup.svg`](../mockups/admin-deployment-mode-mockup.svg) e o [guia funcional](../../docs/userguide/admin-deployment-mode.md) apresentam a configuração global do perfil Hospedado ou Local Host. As regras de roteamento e isolamento estão em [ADR-0003](../../docs/adr/0003-multi-tenancy-por-linha.md); compatibilidade e requisitos operacionais permanecem em [ADR-0012](../../docs/adr/0012-ci-deploy-e-backup.md).

## Estrutura e estados

- Acesso exclusivo do superadmin, em **Administração → Instalação**.
- Dois cartões selecionáveis: **Hospedado** atende múltiplos tenants por host validado; **Local Host** atende uma única Sede ativa e suas unidades.
- Em **Hospedado**, mostrar hostname da plataforma e domínio base para subdomínios, com instrução de apontamento DNS e TLS. Domínios próprios de clientes continuam na tela de domínio da Sede.
- Em **Local Host**, ocultar os campos de hostname e exigir a escolha de uma única Sede ativa; exibir a contagem das unidades ativas vinculadas.
- Estado inicial sem perfil deve orientar o superadmin a escolher um modo antes de liberar rotas administrativas e de tenant.
- Ao trocar o modo, apresentar resumo do impacto e exigir confirmação; registrar ator e valores anterior/novo na auditoria.
- Erros de validação preservam a configuração anterior. Salvar exige sessão ativa, autorização superadmin e CSRF.

## Segurança e responsividade

O perfil não concede acesso por si só. Hospedado mantém o host validado cruzado com a sessão; Local Host limita a instalação à Sede configurada e deriva o escopo do usuário autenticado. Host Local só é aceito com conexão loopback; URLs locais não ficam acessíveis pela internet. Nenhum `tenant_id` enviado por query, formulário ou cabeçalho vira autorização. Em viewport estreita, cartões e campos ficam em coluna única sem rolagem horizontal. Controles usam rótulos persistentes, foco visível e texto junto aos estados.

O mockup e esta especificação são uma proposta e aguardam aprovação explícita antes da Issue de interface ou implementação.
