# Modo de hospedagem da instalação

## Referências

O mockup [`admin-deployment-mode-mockup.svg`](../mockups/admin-deployment-mode-mockup.svg) e o [guia funcional](../../docs/userguide/admin-deployment-mode.md) apresentam a configuração global do perfil Hospedado ou Local Host. As regras de roteamento e isolamento estão em [ADR-0003](../../docs/adr/0003-multi-tenancy-por-linha.md); compatibilidade e requisitos operacionais permanecem em [ADR-0012](../../docs/adr/0012-ci-deploy-e-backup.md).

## Estrutura e estados

- Acesso exclusivo do superadmin, em **Administração → Instalação**.
- Dois cartões selecionáveis: **Hospedado** atende múltiplos tenants por host validado; **Local Host** atende usuários de uma Sede selecionada e suas unidades.
- Em **Hospedado**, mostrar hostname da plataforma e domínio base para subdomínios, com instrução de apontamento DNS e TLS. Domínios próprios de clientes continuam na tela de domínio da Sede.
- Em **Local Host**, ocultar os campos de hostname e permitir salvar sem Sede. A escolha de uma Sede ativa é opcional e exibe a contagem das unidades ativas vinculadas.
- O superadmin mantém acesso ao painel global em ambos os modos e antes de configurar um perfil ou Sede. As áreas institucionais só aceitam uma Sede selecionada e uma conta ativa existente; a unidade efetiva vem da conta autenticada.
- Ao trocar o modo, apresentar resumo do impacto e exigir confirmação; registrar ator e valores anterior/novo na auditoria.
- Erros de validação preservam a configuração anterior. Salvar exige sessão ativa, autorização superadmin e CSRF.

## Segurança e responsividade

O perfil não concede acesso por si só. Hospedado mantém o host validado cruzado com a sessão; Local Host limita a instalação à Sede configurada e deriva o escopo do usuário autenticado. Host Local só é aceito com conexão loopback; URLs locais não ficam acessíveis pela internet. Nenhum `tenant_id` enviado por query, formulário ou cabeçalho vira autorização. Em viewport estreita, cartões e campos ficam em coluna única sem rolagem horizontal. Controles usam rótulos persistentes, foco visível e texto junto aos estados.

Mockup e guia funcional aprovados pelo mantenedor em 2026-11-22. A Issue [#87](https://github.com/agthinkindigital/IEPRCard/issues/87) implementa a interface após o backend [#86](https://github.com/agthinkindigital/IEPRCard/issues/86).
