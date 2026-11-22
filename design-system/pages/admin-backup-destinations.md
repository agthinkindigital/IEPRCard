# Página: destinos externos de backup

## Referências

O mockup [`admin-backup-destinations-mockup.svg`](../mockups/admin-backup-destinations-mockup.svg) mostra a lista, o formulário e a adaptação mobile. Usa os tokens globais de [`MASTER.md`](../MASTER.md). Este documento define hierarquia e estados visuais; regras de criptografia, retenção e restauração continuam no [ADR-0012](../../docs/adr/0012-ci-deploy-e-backup.md).

## Estrutura visual

- Navegação administrativa global com a seção **Backup externo** selecionada.
- Aviso persistente diferencia configuração/teste de conexão da execução real de backups.
- Resumo e lista mostram cada destino S3 compatível, bucket/endpoint, estado de conexão, data do último teste bem-sucedido e ações.
- Formulário aceita nome, tipo compatível, bucket, região, endpoint opcional, opção path-style e credenciais. Um destino novo é salvo antes de poder ser testado; destinos existentes podem ser testados na lista ou no estado de edição.
- Em edição, credenciais nunca são pré-preenchidas. Os campos vazios preservam as chaves existentes; novas chaves substituem as atuais.
- Estados de validação, teste bem-sucedido, falha, destino inativo e confirmação de remoção usam rótulo e texto junto à cor.
- A tabela torna-se uma lista de cartões em celular, sem rolagem horizontal.

## Estados e acessibilidade

- Inicial sem destinos: explicar que nenhum destino foi cadastrado e oferecer **Adicionar destino**.
- Salvando/testando: mostrar progresso e impedir duplicação da mesma ação.
- Sucesso: indicar apenas que escrita, leitura e purga permanente do objeto temporário (incluindo versões) funcionaram.
- Falha: exibir erro seguro e próximo passo genérico; nunca renderizar credenciais ou mensagem bruta do SDK.
- Remoção: pedir confirmação usando o nome do destino; cancelar mantém a configuração.
- Controles têm rótulos persistentes, foco visível, teclado e contraste AA; status não depende só de cor.

## Limites deste painel

O teste confirma conexão e purga permanente do objeto temporário; não confirma a integridade de um snapshot, uma restauração ou a execução de cron. Esses estados só devem aparecer após seus workers e fluxos serem implementados. Mockup e guia foram aprovados em 2026-11-22; a tela está na [#82](https://github.com/agthinkindigital/IEPRCard/issues/82). Não oferecer opção MEGA até existir um adaptador próprio validado.
