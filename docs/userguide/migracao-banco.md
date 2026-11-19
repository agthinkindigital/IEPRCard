# Migração de banco — guia funcional

## Objetivo e acesso

A área administrativa oferece duas operações distintas: atualizar a estrutura do banco com migrations incluídas em uma release e copiar registros do SQLite para MySQL/MariaDB. Ambas são exclusivas do superadmin. Nenhuma tela apresenta dados pessoais, senhas ou credenciais.

## Atualização do schema de produção

A atualização usa apenas os arquivos de migration versionados que já fazem parte da release instalada. Você não precisa escrever SQL nem escolher arquivos ou comandos. A operação é diferente da cópia de dados SQLite → MySQL descrita adiante.

1. Abra **Atualização de schema** e confira se o ambiente, banco e pré-verificações correspondem à release.
2. Crie um backup restaurável pelo painel do provedor. A aplicação pede sua confirmação, mas não consegue verificar sozinha um backup externo.
3. Programe a execução para um horário de baixo uso: uma migration pode segurar brevemente tabelas enquanto o MySQL altera a estrutura.
4. Confirme o backup e selecione **Enfileirar atualização**. O worker agendado processa os arquivos em ordem e a tela acompanha o estado; não envie a mesma operação novamente enquanto estiver em fila ou execução.
5. Ao concluir, confira o resultado e a validação do schema. A auditoria registra a release, o administrador, os horários e o resultado.

As migrations de produção são versionadas, aditivas e idempotentes. Se uma execução falhar, alterações de schema já aplicadas não são desfeitas automaticamente. Leia o resultado e a pré-verificação antes de repetir; a operação só pode ser repetida porque os scripts existentes verificam o que já foi aplicado. Se o resultado indicar divergência não segura, interrompa o deploy e peça revisão técnica.

O painel não aceita SQL, nomes de arquivos ou comandos enviados pelo navegador. As migrations ficam no repositório, são revisadas junto com a release e não são executadas em requisições normais da aplicação. O histórico é separado dos registros migrados; não há cópia de dados pessoais neste fluxo.

## Cópia de dados SQLite → MySQL/MariaDB

Esta operação transfere registros entre bancos já preparados; ela não altera o schema. O destino precisa receber previamente a estrutura canônica usando **Atualização de schema** e não pode conter dados operacionais. A exceção é o catálogo global `permissoes` da migration `0002`: ele precisa existir idêntico na origem e no destino, é validado por checksum e preservado durante a cópia. Configure `MIGRATION_SOURCE_SQLITE_PATH` e as variáveis `MIGRATION_TARGET_*` no ambiente do PHP. Em hospedagem compartilhada, agende `php /caminho/privado/do-projeto/database/process-admin-migration.php` pelo Cron do servidor, por exemplo a cada minuto, e disponibilize ao processo Cron as mesmas variáveis de ambiente protegidas. O worker aceita um único job por vez; a interface mantém o estado disponível mesmo quando o Administrador fecha a página. A interface não mostra caminhos completos, registros ou credenciais.

### Fluxo principal

1. Confira a origem e o destino. A origem é identificada pelo nome do arquivo, sem expor seu caminho completo; o destino mostra o nome do banco e o tipo de servidor, com segredos ocultos.
2. Selecione **Validar novamente** para repetir as pré-verificações. A origem precisa ter o schema canônico e integridade referencial; o destino precisa ter o mesmo schema e nenhum dado operacional. Se já existir o catálogo de permissões da migration `0002`, ele deve ser idêntico ao da origem.
3. Leia a estimativa de registros e marque a confirmação de que revisou os dois bancos.
4. Selecione **Revisar e iniciar**. Uma confirmação final resume a operação e oferece **Cancelar** ou **Confirmar cópia**.
5. Acompanhe o estado da execução e o progresso verificável. O worker CLI pega o job enfileirado; sair da página não cancela o trabalho e, ao voltar, o Administrador consulta o estado atual.
6. Ao concluir, confira quantidade e checksum por tabela. A ativação do MySQL é uma etapa de implantação separada: esta operação apenas copia os dados.

### Estados e recuperação

- **Bloqueado:** uma pré-verificação falhou. O painel explica se o problema está no schema, integridade da origem, conexão ou conteúdo do destino. Corrija a causa e valide novamente.
- **Aguardando início:** as verificações passaram, mas a cópia não foi autorizada.
- **Em fila / Em execução:** a operação não deve ser enviada novamente; atualize a página para ver o estado mais recente.
- **Concluída:** as contagens, checksums e referências foram validados. A cópia não troca automaticamente o banco ativo.
- **Falha:** o painel informa que a transação do destino foi revertida. Corrija a causa e prepare um destino MySQL vazio antes de tentar outra vez.
- **Destino com dados:** a ferramenta recusa a execução para evitar duplicação ou mistura de bases. A exceção é o catálogo `permissoes` idêntico nas duas bases; qualquer divergência bloqueia a cópia. Não há modo de mesclagem nesta operação.

### Comportamento dos controles

- **Validar novamente:** executa apenas verificações; não copia dados.
- **Confirmação da cópia:** habilita a revisão final. Ausência de confirmação mantém o início desabilitado.
- **Revisar e iniciar:** abre o resumo final, sem iniciar imediatamente.
- **Cancelar:** fecha a revisão e preserva o estado anterior.
- **Confirmar cópia:** agenda a execução uma única vez; o botão fica indisponível durante o envio e enquanto houver uma execução ativa.

Os estados visuais também devem ter texto acessível, foco visível e ordem de teclado coerente. Em telas estreitas, origem, destino, pré-verificações e progresso aparecem em coluna única.
