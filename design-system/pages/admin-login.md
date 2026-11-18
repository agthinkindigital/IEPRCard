# Página: acesso administrativo

## Aplicação do design system

A tela segue os tokens globais de [`MASTER.md`](../MASTER.md), com formulário compacto, foco visível e mensagens de estado textuais. O mockup está em [`admin-login-mockup.svg`](../mockups/admin-login-mockup.svg).

## Estrutura e comportamento

- Apresentar somente o acesso do Administrador SaaS; não oferecer cadastro público.
- Solicitar e-mail e senha, mantendo rótulos persistentes e navegação por teclado.
- Enviar credenciais por HTTPS; a aplicação valida conta ativa, papel `superadmin` e escopo global no servidor.
- Mensagens para credenciais inválidas ou conta indisponível não revelam se um e-mail existe.
- Após sucesso, regenerar o identificador de sessão e direcionar ao painel administrativo.
- Encerrar sessão por ação autenticada protegida contra CSRF.
- Não registrar senha, token de sessão ou payload do formulário em logs.
- Em telas menores, manter o formulário centralizado em uma coluna sem rolagem horizontal.
