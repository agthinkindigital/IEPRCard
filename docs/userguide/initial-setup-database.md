# Instalação inicial do banco

Este guia registra o fluxo aprovado para iniciar a instalação sem credenciais previamente definidas em um arquivo editado à mão. A tela de configuração do banco precisa funcionar antes do login administrativo. A implementação está pendente na [Issue #102](https://github.com/agthinkindigital/IEPRCard/issues/102).

## Conectar ao MySQL/MariaDB

1. No Laragon, confirme que MySQL/MariaDB está iniciado e confira host e porta. Em geral, a conexão local usa `127.0.0.1` e porta `3306`; use os valores reais da instalação.
2. Crie um banco vazio e um usuário com acesso a ele no Laragon ou no painel da hospedagem. O perfil compartilhado não exige permissão para criar bancos no servidor.
3. Informe host, porta, nome do banco, usuário e senha na tela. Pressione **Testar conexão**. A senha nunca é devolvida pela tela.
4. Com a conexão validada, selecione **Salvar e instalar schema**. O aplicativo guarda a conexão em configuração privada e aplica as migrações versionadas ao banco vazio.
5. Continue para criar o primeiro superadmin e selecionar o perfil Local Host ou Hospedado.

## Segurança e recuperação

- A configuração da conexão não fica no banco de dados, pois é necessária para conectar a ele. Ela fica em armazenamento privado fora de `public/`, não incluído em Git e inacessível por URL.
- Se o host não permitir gravar fora da área pública com isolamento seguro, o instalador interrompe a configuração e explica como cadastrar os valores no ambiente do PHP. Não grava credenciais em uma pasta pública com base apenas em `.htaccess`.
- Se a conexão ou instalação falhar, os dados já existentes no banco ficam intactos. A etapa de schema recusa bancos com tabelas desconhecidas ou dados preexistentes.
- Depois da instalação, o superadmin poderá verificar conexão e atualizar credenciais. A troca do nome do banco não transfere dados; para migrar SQLite → MySQL/MariaDB, use o [guia próprio](migracao-banco.md).
- Para evitar revelar valores, campos de senha aparecem vazios; insira nova senha apenas para substituí-la. Nunca registre credenciais em chamados, logs ou capturas de tela.

## Atualizar a conexão depois de instalar

Na área **Administração → Instalação**, consulte o estado do banco e use **Testar conexão** para validar alterações sem salvá-las. Ao aplicar nova configuração, informe a nova senha em campo vazio; o sistema não mostra a credencial atual. A tela exige superadmin e confirmação explícita.

Não use essa opção para transferir uma instalação para outro banco: trocar o destino não copia conteúdo. Use a [migração SQLite → MySQL/MariaDB](migracao-banco.md). Se o destino novo não for a mesma instalação, a aplicação não libera o assistente de primeiro superadmin.

Se variáveis de ambiente forem a fonte ativa, o painel identifica isso e orienta a alteração pelo ambiente do processo PHP. Não há precedência silenciosa do arquivo da interface sobre valores definidos pelo host.

## Próximos passos opcionais

Depois do login, um checklist de configuração pode encaminhar às telas próprias de domínios/perfil, destinos de backup, atendimento/IA e acesso MCP. Essas opções não são obrigatórias para inicializar o sistema e permanecem documentadas nos seus guias existentes.

## Estado

Fluxo e mockup aprovados em 2026-11-25; ainda não implementados. O contrato de banco é o [ADR-0002](../adr/0002-pdo-dual-sqlite-mysql.md); o primeiro superadmin e o perfil de implantação continuam descritos em [login administrativo](admin-login.md) e [Hospedado/Local Host](admin-deployment-mode.md).
