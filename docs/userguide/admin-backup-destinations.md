# Destinos externos de backup — guia funcional

Este painel é restrito ao superadmin e configura destinos externos com API S3, como AWS S3, Cloudflare R2 e serviços compatíveis. As chaves são cifradas ao salvar e não aparecem novamente na tela. A política de backup e restauração está no [ADR-0012](../adr/0012-ci-deploy-e-backup.md).

## Cadastrar e testar

1. Selecione **Adicionar destino** e informe um nome para reconhecer o destino, o bucket e a região solicitada pelo serviço.
2. Para AWS S3, deixe o endpoint personalizado vazio. Para outro serviço compatível, informe o endpoint HTTPS e marque path-style se o provedor exigir.
3. Informe access key e secret key. Os campos são enviados uma vez para serem cifrados; o sistema não os mostra depois de salvar.
4. Selecione **Salvar destino**. Ele começa inativo e aparece na lista sem teste registrado.
5. Na lista, selecione **Testar**. O sistema envia um objeto aleatório temporário, lê o conteúdo de volta, confere a igualdade e remove o objeto.
6. Se o teste passar, o estado mostra quando ocorreu a última conexão bem-sucedida. Se falhar, reveja host, região, bucket, permissões e chaves e teste novamente.
7. Selecione **Ativar** no menu do destino após confirmar a configuração.

## Editar, ativar e remover

- Para trocar as chaves, digite as novas. Deixe os campos vazios para conservar as chaves atuais.
- **Ativo** prepara o destino para ser usado por um worker de backup. Até a rotina de snapshots e o agendamento serem liberados, isso não significa que cópias estejam sendo enviadas.
- **Testar** faz apenas a prova temporária de conexão. A data exibida é do teste, não de um backup.
- **Remover** pede confirmação com o nome do destino e exclui somente essa configuração.

Os erros exibidos são resumidos e não incluem credenciais nem respostas brutas do serviço. O painel não mostra dados de clientes ou titulares.
