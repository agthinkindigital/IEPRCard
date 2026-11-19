# Página: acesso administrativo

## Aplicação do design system

A tela segue os tokens globais de [`MASTER.md`](../MASTER.md), com formulário compacto, foco visível e mensagens de estado textuais. O mockup está em [`admin-login-mockup.svg`](../mockups/admin-login-mockup.svg).

## Estrutura e comportamento

- Apresentar uma das duas variações da mesma página, verificando no servidor se `usuarios` contém qualquer registro:
  - Sem usuários: redirecionar para a própria página com um token aleatório opaco, vinculado à sessão; exibir configuração do primeiro Administrador SaaS.
  - Com qualquer usuário: invalidar tokens de configuração pendentes e exibir somente o login. Acesso direto com token antigo também redireciona ao login.
- O primeiro cadastro cria um usuário global ativo com papel `superadmin`. Exigir nome, e-mail, senha e confirmação; senha com pelo menos 12 caracteres. Não oferecer cadastro após esse primeiro usuário.
- Token de configuração válido por até 10 minutos, de uso único, validado também no POST e consumido ao criar a conta. Proteger o POST com CSRF e revalidar, sob lock e transação, que nenhum usuário foi criado nesse intervalo.
- Após o cadastro, invalidar token/sessão de configuração e redirecionar para a URL limpa de login; o usuário autentica com as credenciais recém-criadas.
- Solicitar e-mail e senha, mantendo rótulos persistentes e navegação por teclado.
- Enviar credenciais por HTTPS; a aplicação valida conta ativa, papel `superadmin` e escopo global no servidor.
- Mensagens para credenciais inválidas ou conta indisponível não revelam se um e-mail existe.
- Após sucesso, regenerar o identificador de sessão e direcionar ao painel administrativo.
- Encerrar sessão por ação autenticada protegida contra CSRF.
- Não registrar senha, token de sessão ou payload do formulário em logs.
- Na variação de configuração, enviar `Cache-Control: no-store`, `Referrer-Policy: no-referrer` e `X-Robots-Tag: noindex, nofollow`; não registrar o token nos logs da aplicação e, quando o host permitir, omitir query strings dos logs HTTP.
- O token aleatório protege a URL contra adivinhação e replay fora da sessão, mas não identifica o proprietário do sistema: enquanto o banco estiver sem usuários, quem chegar primeiro pode reivindicar a configuração. A operação deve ser feita em janela controlada de implantação.
- Em telas menores, manter o formulário centralizado em uma coluna sem rolagem horizontal.

O mockup [`admin-login-mockup.svg`](../mockups/admin-login-mockup.svg) apresenta os dois estados para revisão; são estados alternativos, não formulários simultâneos no produto.
