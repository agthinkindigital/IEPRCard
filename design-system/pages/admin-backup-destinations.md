# Página: destinos externos de backup

## Referências

O mockup [`admin-backup-destinations-mockup.svg`](../mockups/admin-backup-destinations-mockup.svg) mostra a lista, o formulário e a adaptação mobile. Usa os tokens globais de [`MASTER.md`](../MASTER.md). Este documento define hierarquia e estados visuais; regras de criptografia, retenção e restauração continuam no [ADR-0012](../../docs/adr/0012-ci-deploy-e-backup.md).

## Estrutura visual

- Navegação administrativa global com a seção **Backup externo** selecionada.
- Aviso persistente diferencia configuração/teste de conexão da execução real de backups.
- Resumo e lista mostram cada destino S3 compatível, bucket/endpoint, estado de conexão, envio do último snapshot, estado de retenção/purga e ações.
- Formulário aceita nome, tipo compatível, bucket, região, endpoint opcional, opção path-style e credenciais. Um destino novo é salvo antes de poder ser testado; destinos existentes podem ser testados na lista ou no estado de edição.
- Em edição, credenciais nunca são pré-preenchidas. Os campos vazios preservam as chaves existentes; novas chaves substituem as atuais.
- Estados de validação, teste bem-sucedido, falha, destino inativo e confirmação de remoção usam rótulo e texto junto à cor.
- A tabela torna-se uma lista de cartões em celular, sem rolagem horizontal.

## Estados e acessibilidade

- Inicial sem destinos: explicar que nenhum destino foi cadastrado e oferecer **Adicionar destino**.
- Salvando/testando: mostrar progresso e impedir duplicação da mesma ação.
- Sucesso: indicar apenas que escrita, leitura, listagem, metadado de teste e purga permanente do objeto temporário (incluindo versões) funcionaram.
- Falha: exibir erro seguro e próximo passo genérico; nunca renderizar credenciais ou mensagem bruta do SDK.
- Remoção: pedir confirmação usando o nome do destino; cancelar mantém a configuração.
- Retry pendente: mostrar estado aguardando confirmação; bloquear troca de armazenamento/credenciais e remoção até resolver o snapshot.
- Controles têm rótulos persistentes, foco visível, teclado e contraste AA; status não depende só de cor.

## Limites deste painel

O teste confirma conexão e purga permanente do objeto temporário; não confirma a integridade de um snapshot, uma restauração ou a execução de cron. Esses estados só devem aparecer após seus workers e fluxos serem implementados. Mockup e guia foram aprovados em 2026-11-22; a tela está na [#82](https://github.com/agthinkindigital/IEPRCard/issues/82). Não oferecer opção MEGA até existir um adaptador próprio validado.

## Restauração isolada por ZIP — aprovada em 2026-11-22

O mockup [`admin-backup-restore-mockup.svg`](../mockups/admin-backup-restore-mockup.svg) propõe ao superadmin enviar um snapshot ZIP ou exportar e baixar o snapshot completo mais recente de um destino S3 configurado. A exportação não altera o backup nem descriptografa os dados. O operador valida manifesto, integridade, schema, ledger e chave mestra e só então solicita a preparação em banco MySQL isolado. A aplicação ativa continua separada; a liberação exige aplicar tombstones e revisar o resultado. ZIPs de atualização de código não são snapshots de dados e devem ser rejeitados.

A base do fluxo ZIP foi aprovada pelo mantenedor em 2026-11-22. A extensão para exportar e baixar pelo painel foi aprovada em 2026-11-23. Regras de recuperação, privacidade e retenção permanecem no ADR-0012; a implementação depende da conclusão da #84.

## Retenção de snapshots por destino — aprovada em 2026-11-22

O painel permite configurar retenção por destino, com padrão de 30 dias e faixa de 1 a 3650 dias, e exibe a última purga, quantidade removida e falha segura. O estado de backup permanece separado do teste de conexão. A purga abrange versões e delete markers, sem remover envelopes de chaves ativos nem tombstones/ledger; o contrato está no ADR-0012. O mantenedor aprovou esta extensão, junto às propostas de privacidade dos fundos de design e de staging de importação, em 2026-11-22. A implementação das três interfaces foi publicada em DEV no commit `fc0add6`.
