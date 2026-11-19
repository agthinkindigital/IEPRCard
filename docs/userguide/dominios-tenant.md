# Guia funcional: domínios próprios

Este guia descreve o onboarding de um domínio próprio para a Sede de uma instituição. O [mockup da tela](../../design-system/mockups/dominios-tenant-mockup.svg) mostra a lista e o detalhe da prova DNS. A tela ainda não foi implementada.

## Quem pode usar

O Cliente Master da Sede pode cadastrar um hostname para a própria instituição. Subclientes e operadores de unidades não cadastram domínios. A identidade e o tenant vêm da sessão autenticada; um `tenant_id` enviado pelo navegador não concede acesso.

## Cadastrar e comprovar o domínio

1. Escolha **Adicionar domínio** e informe apenas o hostname, como `instituto-exemplo.org.br`; não inclua `https://`, caminho ou porta.
2. O sistema normaliza o nome e recusa formatos inválidos, endereços IP, hosts reservados da plataforma e nomes já vinculados. A mensagem não informa se outro cliente usa o hostname.
3. Após salvar, a tela apresenta o registro TXT `_ieprcard-verification.<domínio>` e seu valor. O desafio é mostrado uma única vez; copie os dois campos para o painel DNS que administra o domínio e mantenha a etapa aberta.
4. Aguarde a publicação do DNS. Depois escolha **Conferir registro DNS**. Até encontrar o valor exato, a situação continua **Aguardando DNS** e a tela orienta revisar nome e valor do TXT.
5. Quando a prova é confirmada, o serviço invalida o desafio e muda o domínio para **TLS pendente**. Isso confirma controle do DNS; ainda não autoriza o domínio a abrir a conta.

O serviço existente mostra o desafio apenas no cadastro e requer seu valor para conferência. Se a etapa for fechada antes da prova, o mockup não promete uma recuperação automática do desafio; será necessário definir e implementar um fluxo seguro de nova prova.

## Estados de TLS e ativação

- **TLS pendente:** a propriedade do domínio foi provada, mas `tls_validado_em` ainda não está preenchido. Oriente a equipe a configurar alias e HTTPS no plano de hospedagem. A aplicação só aceita o hostname quando estiver ativo, com prova DNS e TLS válidos.
- **Ativo:** prova DNS e HTTPS válidos; o hostname pode ser resolvido para a Sede.
- **Falha de TLS:** exibir a orientação técnica e o próximo passo sem ativar o domínio. A detecção, retentativa e detalhes operacionais dependem de integração com o host ainda não presente no backend.
- **Removido/revogado:** interrompe a resolução do hostname. Confirmação, retenção do histórico, liberação do nome e eventual novo cadastro precisam de um serviço backend próprio.

Na versão atual, o backend cobre cadastro e transição para **TLS pendente**. Não há serviço de validação/renovação TLS, remoção, nova prova ou estados de erro operacionais. Esses controles do destino visual só poderão funcionar após serem fatiados, aprovados e implementados; nenhum sucesso deve ser indicado enquanto faltarem esses serviços.

## Privacidade e segurança

- O domínio é único globalmente; um host não verificado nunca seleciona um tenant.
- O token da prova é segredo temporário de onboarding, não credencial de login. O banco guarda apenas o hash e o serviço o invalida após sucesso.
- Erros não mostram IDs, dados de outro cliente, token ou registros de sessão. Não copiar o desafio para logs ou telemetria.

## Estados de falha

- Host inválido, reservado, duplicado ou conta inativa: informar que o domínio não está disponível e permitir corrigir a entrada.
- TXT ausente/incorreto: manter pendente, explicar que o registro deve corresponder exatamente ao exibido e permitir nova conferência enquanto o desafio estiver disponível.
- DNS confirmado com TLS pendente: mostrar o próximo passo de configuração do host; não tratar como falha de verificação DNS.
- Remoção: antes de disponibilizar a ação, confirmar que o usuário entende que o host deixa de direcionar à instituição; o fluxo requer serviço backend e política de reutilização do hostname.
