# Atendimento e IA — guia funcional

Este guia descreve a configuração global de IA pelo Administrador SaaS e o consentimento apresentado ao titular no WhatsApp. O mockup associado é [`whatsapp-ia-mockup.svg`](../../design-system/mockups/whatsapp-ia-mockup.svg); a especificação visual fica em [`design-system/pages/whatsapp-ia.md`](../../design-system/pages/whatsapp-ia.md). Os artefatos foram aprovados em 2026-11-22; a implementação está fatiada nas Issues [#81](https://github.com/agthinkindigital/IEPRCard/issues/81) (conversa e consentimento) e [#83](https://github.com/agthinkindigital/IEPRCard/issues/83) (painel superadmin).

## Configurar conexão — superadmin

1. Antes de abrir a tela, configure `IEPR_INTEGRATION_KEY` no ambiente do processo PHP. Gere uma chave aleatória de 32 bytes em Base64 e não reutilize a chave de anonimização.
2. Acesse **Administração → Atendimento e IA**. Essa tela é exclusiva do superadmin.
3. Informe a URL da API GOWA VIP e seu token global; use **Testar GOWA** para validar somente a conexão. A credencial global permite que a plataforma crie e atualize instâncias de WhatsApp.
4. Cadastre a chave e o modelo para OpenAI e/ou Google Gemini. Os clientes não cadastram chaves próprias.
5. Use **Testar conexão** para validar IA com uma chamada sintética, sem dados de titulares.
6. Escolha um provedor de IA configurado para novas conversas e salve. No MVP, um provedor é selecionado e não há fallback automático.
7. A plataforma usará sua conta GOWA e a conta do provedor de IA ativo; o consumo de IA será cobrado nessa conta global. Após salvar, os tokens/chaves não voltam a ser exibidos; para trocá-los, informe novos valores.

Cada instância de cliente recebe uma URL de webhook exclusiva com segredo aleatório. O segredo é enviado ao GOWA ao provisionar/rotacionar; a tela mostra apenas o estado e nunca o token novamente. A rotação invalida a URL anterior. A autenticação por token de URL é o mecanismo compatível com o GOWA das referências fornecidas; ver [ADR-0008](../adr/0008-webhooks-idempotentes-e-segredos.md).

Se o teste falhar, revise a chave, o modelo permitido e a conectividade externa. A configuração ativa anterior permanece selecionada até que uma nova escolha válida seja salva.

## Consentimento do titular

1. O fluxo só começa quando o superadmin o habilita explicitamente na instância GOWA. Antes de pedir dados ou foto, o bot informa a finalidade e nomeia o provedor de IA ativo.
2. **Concordo e continuar** registra o aceite para a finalidade e a versão do aviso mostradas e inicia o menu determinístico.
3. Após o aceite, o menu de cadastro é determinístico. No MVP, a IA interpreta somente o texto do nome; CPF é validado pela aplicação e categoria, design e confirmação não dependem da IA. A foto é opcional, processada localmente para remover metadados e não é enviada ao provedor de IA. A aplicação valida os dados e a autorização antes de emitir; o modelo não aprova a emissão.
4. **Não concordo** encerra o fluxo. Mensagens ou mídias que tenham chegado antes do aceite não são salvas nem enviadas à IA.
5. O titular pode pedir para encerrar/revogar o consentimento. O sistema interrompe novas etapas, pausa a IA para um titular já vinculado e aplica as regras de exclusão/anonimização de [`docs/01-regras-de-negocio.md`](../01-regras-de-negocio.md). Conversas sem titular são removidas após a expiração; a anonimização do titular também remove o vínculo e o HMAC do contato.

O atendimento e a emissão não envolvem pagamento, checkout ou repasse no MVP. O tratamento de mensagens deve evitar registrar conteúdo, foto, telefone, chaves ou respostas brutas da IA em logs.
