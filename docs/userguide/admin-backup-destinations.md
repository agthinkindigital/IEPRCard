# Destinos externos de backup — guia funcional

Este painel é restrito ao superadmin e configura destinos externos com API S3, como AWS S3, Cloudflare R2 e serviços compatíveis. As chaves são cifradas ao salvar e não aparecem novamente na tela. A política de backup e restauração está no [ADR-0012](../adr/0012-ci-deploy-e-backup.md).

## Cadastrar e testar

1. Selecione **Adicionar destino** e informe um nome para reconhecer o destino, o bucket e a região solicitada pelo serviço.
2. Para AWS S3, deixe o endpoint personalizado vazio. Para outro serviço compatível, informe o endpoint HTTPS e marque path-style se o provedor exigir.
3. Informe access key e secret key. Os campos são enviados uma vez para serem cifrados; o sistema não os mostra depois de salvar.
4. Selecione **Salvar destino**. Ele começa inativo e aparece na lista sem teste registrado.
5. Na lista, selecione **Testar**. O sistema envia um objeto aleatório temporário, lê e lista esse objeto, confere o metadado de teste e comprova sua remoção permanente, incluindo versões quando o bucket tem versionamento.
6. Se o teste passar, o estado mostra quando ocorreu a última conexão bem-sucedida. Se falhar, reveja host, região, bucket, permissões e chaves e teste novamente.
7. Selecione **Ativar** no menu do destino após confirmar a configuração.

## Editar, ativar e remover

- Para trocar as chaves, digite as novas. Deixe os campos vazios para conservar as chaves atuais.
- **Ativo** permite ao cron enviar snapshots periódicos para o destino. Se um envio ficar pendente, o cron confirma ou repete o mesmo snapshot antes de iniciar outro; enquanto isso, não troque nem remova o destino.
- **Testar** comprova conexão e purga permanente de um objeto temporário. A data exibida é do teste, não de um backup.
- **Remover** pede confirmação com o nome do destino e exclui somente essa configuração.

Os erros exibidos são resumidos e não incluem credenciais nem respostas brutas do serviço. O painel não mostra dados de clientes ou titulares.

## Restaurar snapshot por ZIP — aprovado em 2026-11-22

1. Acesse **Restaurar backup** e selecione um ZIP exportado pelo IEPRCard. Se ainda não tiver o arquivo, escolha um destino S3 e o snapshot completo mais recente e selecione **Exportar e baixar ZIP**. Pacotes de atualização de código não são aceitos.
2. Marque a confirmação para validar o arquivo sem alterar a base ativa e selecione **Validar arquivo**.
3. O sistema confere a origem e o checksum com o snapshot no destino S3 configurado e consulta nesse destino o ledger atual de tombstones e envelopes de chaves. Também verifica manifesto, versão do schema e disponibilidade da chave mestra. Uma falha bloqueia a restauração e exibe uma mensagem sem dados pessoais ou segredos.
4. Antes de preparar a restauração, provisiona-se um banco MySQL dedicado, distinto da base ativa, com conexão isolada configurada no ambiente. Se a validação passar, selecione **Preparar isolada**. O sistema restaura sem tráfego, emissão, sessão de cliente ou chamadas MCP e aplica os tombstones de privacidade.
5. A liberação exige revisão humana posterior do resultado. O fluxo não restaura diretamente sobre produção.

O upload/restauração e seu mockup-base foram aprovados em 2026-11-22. A ação de exportar/baixar pelo painel é uma extensão proposta e aguarda aprovação. A implementação depende da conclusão da [#84](https://github.com/agthinkindigital/IEPRCard/issues/84) e segue o contrato de recuperação do [ADR-0012](../adr/0012-ci-deploy-e-backup.md).

## Retenção por destino

A retenção é de 30 dias por padrão e pode ser definida entre 1 e 3650 dias para cada destino. Configure o cron do servidor para executar `php cron.php --once` pelo menos a cada 15 minutos. A tela separa o estado do teste de conexão do último snapshot, da fila pendente e da última execução de purga. O contador mostra quantos snapshots foram removidos na última execução, por destino. A purga remove snapshots vencidos e versões S3; envelopes de chaves ativos e tombstones/ledger ficam fora dessa rotina.
