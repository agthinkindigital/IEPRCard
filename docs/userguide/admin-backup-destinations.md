# Destinos externos de backup — guia funcional

Este painel é restrito ao superadmin e configura destinos externos com API S3, como AWS S3, Cloudflare R2 e serviços compatíveis. As chaves são cifradas ao salvar e não aparecem novamente na tela. A política de backup e restauração está no [ADR-0012](../adr/0012-ci-deploy-e-backup.md).

## Cadastrar e testar

1. Selecione **Adicionar destino** e informe um nome para reconhecer o destino, o bucket e a região solicitada pelo serviço.
2. Para AWS S3, deixe o endpoint personalizado vazio. Para outro serviço compatível, informe o endpoint HTTPS e marque path-style se o provedor exigir.
3. Informe access key e secret key. Os campos são enviados uma vez para serem cifrados; o sistema não os mostra depois de salvar.
4. Selecione **Salvar destino**. Ele começa inativo e aparece na lista sem teste registrado.
5. Na lista, selecione **Testar**. O sistema envia um objeto aleatório temporário, lê o conteúdo, confere a igualdade e comprova sua remoção permanente, incluindo versões quando o bucket tem versionamento.
6. Se o teste passar, o estado mostra quando ocorreu a última conexão bem-sucedida. Se falhar, reveja host, região, bucket, permissões e chaves e teste novamente.
7. Selecione **Ativar** no menu do destino após confirmar a configuração.

## Editar, ativar e remover

- Para trocar as chaves, digite as novas. Deixe os campos vazios para conservar as chaves atuais.
- **Ativo** prepara o destino para ser usado por um worker de backup. Até a rotina de snapshots e o agendamento serem liberados, isso não significa que cópias estejam sendo enviadas.
- **Testar** comprova conexão e purga permanente de um objeto temporário. A data exibida é do teste, não de um backup.
- **Remover** pede confirmação com o nome do destino e exclui somente essa configuração.

Os erros exibidos são resumidos e não incluem credenciais nem respostas brutas do serviço. O painel não mostra dados de clientes ou titulares.

## Restaurar snapshot por ZIP — aprovado em 2026-11-22

1. Acesse **Restaurar backup** e selecione um ZIP exportado pelo próprio IEPRCard. Pacotes de atualização de código não são aceitos.
2. Marque a confirmação para validar o arquivo sem alterar a base ativa e selecione **Validar arquivo**.
3. Confira o manifesto, checksum, versão do schema, ledger e disponibilidade da chave mestra. Uma falha bloqueia a restauração e exibe uma mensagem sem dados pessoais ou segredos.
4. Se a validação passar, selecione **Preparar isolada**. O sistema restaura sem tráfego, emissão, sessão de cliente ou chamadas MCP e aplica os tombstones de privacidade.
5. A liberação exige revisão humana posterior do resultado. O fluxo não restaura diretamente sobre produção.

O fluxo e o mockup correspondente foram aprovados em 2026-11-22. A implementação depende da conclusão da [#84](https://github.com/agthinkindigital/IEPRCard/issues/84) e segue o contrato de recuperação do [ADR-0012](../adr/0012-ci-deploy-e-backup.md).
