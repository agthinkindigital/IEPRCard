# Acesso administrativo — guia funcional

## Objetivo e acesso

Esta tela inicia uma sessão do Administrador SaaS para acessar ferramentas globais, incluindo a migração de banco. Ela só responde no hostname da plataforma definido por `PLATFORM_HOSTNAME`; domínios de clientes não expõem as rotas administrativas. A conta precisa estar ativa, ter papel `superadmin` e pertencer ao escopo global do sistema. O mockup [`admin-login-mockup.svg`](../../design-system/mockups/admin-login-mockup.svg) mostra as duas variações da página.

## Fluxo

1. Ao abrir `/admin/login.php`, o sistema verifica no servidor se existe qualquer usuário. Se ainda não existir, redireciona para a mesma página com um token aleatório e mostra a configuração inicial. Se já existir usuário, mostra somente o login, mesmo que o endereço recebido contenha um token.
2. Na configuração inicial, informe nome, e-mail, senha e confirmação. Use uma senha com pelo menos 12 caracteres. O primeiro registro se torna o Administrador SaaS global (`superadmin`).
3. Depois de criar a conta, o token expira e o sistema redireciona para o login. Informe o e-mail e a senha recém-criados.
4. No login, selecione **Mostrar** para conferir a senha antes do envio; o controle não altera nem registra o valor. Selecione **Entrar**. O sistema valida as credenciais no servidor, aplica limite de tentativas e cria uma nova sessão protegida.
5. Em caso de falha, corrija as credenciais e tente novamente. A mensagem não confirma se o e-mail está cadastrado. Se a sessão expirar, autentique-se novamente. Ações que alteram dados exigem token CSRF válido.

Configure `PLATFORM_HOSTNAME` no ambiente do processo PHP, por exemplo `admin.ieprcard.com.br`. Para roteamento de subdomínios de clientes, configure também `TENANT_BASE_DOMAIN`. Em desenvolvimento local, use o hostname efetivamente usado no navegador, como `localhost` ou `127.0.0.1`.

## Privacidade e recuperação

Senhas são armazenadas somente como hash e nunca são exibidas ou incluídas em logs. Recuperação de senha e cadastro público não fazem parte deste fluxo. O token de configuração fica vinculado à sessão, expira em até 10 minutos, vale uma vez e é protegido contra cache e envio como referenciador. A aplicação confere novamente, sob lock e transação, que ainda não existe usuário antes de salvar o primeiro administrador.

Esse token torna a URL difícil de adivinhar e impede seu uso fora da sessão que a recebeu; ele não comprova a identidade de quem acessou o sistema. Enquanto o banco estiver vazio, qualquer visitante que alcançar a página poderá receber seu próprio token e tentar criar o primeiro usuário. Faça essa etapa durante a implantação controlada e só exponha o sistema quando estiver pronto. Se outra pessoa criar a primeira conta, a recuperação operacional acordada é recriar o banco antes de colocar o sistema em uso.
