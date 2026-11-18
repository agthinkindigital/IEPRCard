# Acesso administrativo — guia funcional

## Objetivo e acesso

Esta tela inicia uma sessão do Administrador SaaS para acessar ferramentas globais, incluindo a migração de banco. Não há cadastro público. A conta precisa estar ativa, ter papel `superadmin` e pertencer ao escopo global do sistema.

## Fluxo

1. Informe o e-mail e a senha da conta administrativa.
2. Selecione **Mostrar** para conferir a senha antes do envio; o controle não altera nem registra o valor.
3. Selecione **Entrar**. O sistema valida as credenciais no servidor, aplica limite de tentativas e cria uma nova sessão protegida.
4. Em caso de falha, corrija as credenciais e tente novamente. A mensagem não confirma se o e-mail está cadastrado.
5. Se a sessão expirar, autentique-se novamente. Ações que alteram dados exigem token CSRF válido.

## Privacidade e recuperação

Senhas são armazenadas somente como hash e nunca são exibidas ou incluídas em logs. Recuperação de senha e cadastro público não fazem parte deste fluxo. Antes do primeiro acesso, aplique o schema e execute `php database/create-superadmin.php` no terminal do servidor com `IEPRCARD_INITIAL_ADMIN_NAME`, `IEPRCARD_INITIAL_ADMIN_EMAIL` e `IEPRCARD_INITIAL_ADMIN_PASSWORD` definidos somente no ambiente desse processo. Use senha longa, remova as variáveis do ambiente ao terminar e não as coloque em scripts versionados. O comando cria apenas o primeiro administrador global; uma segunda execução é recusada.
