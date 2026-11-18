# Migração de banco — guia funcional

## Objetivo e acesso

A página administrativa prepara e acompanha uma cópia de dados do arquivo SQLite para um banco MySQL/MariaDB já configurado. O acesso é exclusivo do Administrador do sistema. A tela nunca apresenta registros pessoais, senhas ou credenciais.

## Fluxo principal

1. Confira a origem e o destino. A origem é identificada pelo nome do arquivo, sem expor seu caminho completo; o destino mostra o nome do banco e o tipo de servidor, com segredos ocultos.
2. Selecione **Validar novamente** para repetir as pré-verificações. A origem precisa ter o schema canônico e integridade referencial; o destino precisa ter o mesmo schema e zero registros.
3. Leia a estimativa de registros e marque a confirmação de que revisou os dois bancos.
4. Selecione **Revisar e iniciar**. Uma confirmação final resume a operação e oferece **Cancelar** ou **Confirmar cópia**.
5. Acompanhe o estado da execução e o progresso verificável. Sair da página não cancela o trabalho; ao voltar, o Administrador consulta o estado atual.
6. Ao concluir, confira quantidade e checksum por tabela. A ativação do MySQL é uma etapa de implantação separada: esta operação apenas copia os dados.

## Estados e recuperação

- **Bloqueado:** uma pré-verificação falhou. O painel explica se o problema está no schema, integridade da origem, conexão ou conteúdo do destino. Corrija a causa e valide novamente.
- **Aguardando início:** as verificações passaram, mas a cópia não foi autorizada.
- **Em fila / Em execução:** a operação não deve ser enviada novamente; atualize a página para ver o estado mais recente.
- **Concluída:** as contagens, checksums e referências foram validados. A cópia não troca automaticamente o banco ativo.
- **Falha:** o painel informa que a transação do destino foi revertida. Corrija a causa e prepare um destino MySQL vazio antes de tentar outra vez.
- **Destino com dados:** a ferramenta recusa a execução para evitar duplicação ou mistura de bases. Não há modo de mesclagem nesta operação.

## Comportamento dos controles

- **Validar novamente:** executa apenas verificações; não copia dados.
- **Confirmação da cópia:** habilita a revisão final. Ausência de confirmação mantém o início desabilitado.
- **Revisar e iniciar:** abre o resumo final, sem iniciar imediatamente.
- **Cancelar:** fecha a revisão e preserva o estado anterior.
- **Confirmar cópia:** agenda a execução uma única vez; o botão fica indisponível durante o envio e enquanto houver uma execução ativa.

Os estados visuais também devem ter texto acessível, foco visível e ordem de teclado coerente. Em telas estreitas, origem, destino, pré-verificações e progresso aparecem em coluna única.
