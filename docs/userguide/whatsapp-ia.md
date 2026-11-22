# Atendimento e IA — guia funcional

Este guia descreve a proposta de configuração global de IA pelo Administrador SaaS e o consentimento apresentado ao titular no WhatsApp. O mockup associado é [`whatsapp-ia-mockup.svg`](../../design-system/mockups/whatsapp-ia-mockup.svg); a especificação visual fica em [`design-system/pages/whatsapp-ia.md`](../../design-system/pages/whatsapp-ia.md). A implementação depende de aprovação do mantenedor.

## Configurar provedor — superadmin

1. Antes de abrir a tela, configure `IEPR_INTEGRATION_KEY` no ambiente do processo PHP. Gere uma chave aleatória de 32 bytes em Base64 e não reutilize a chave de anonimização.
2. Acesse **Administração → Atendimento e IA**. Essa tela é exclusiva do superadmin.
3. Cadastre a chave e o modelo para OpenAI e/ou Google Gemini. Os clientes não cadastram chaves próprias.
4. Use **Testar conexão** para validar a configuração com uma chamada sintética, sem dados de titulares.
5. Escolha um provedor configurado para novas conversas e salve. No MVP, um provedor é selecionado e não há fallback automático.
6. A plataforma usará sua conta desse provedor, e o consumo será cobrado nessa conta. Após salvar, a chave não volta a ser exibida; para trocá-la, informe uma nova.

Se o teste falhar, revise a chave, o modelo permitido e a conectividade externa. A configuração ativa anterior permanece selecionada até que uma nova escolha válida seja salva.

## Consentimento do titular

1. Antes de pedir dados ou foto, o bot informa a finalidade e nomeia o provedor de IA ativo, que poderá processar o texto e a imagem enviados.
2. **Concordo e continuar** registra o aceite para a finalidade e a versão do aviso mostradas e inicia o menu determinístico.
3. A IA pode interpretar texto e extrair os campos solicitados. A aplicação valida os dados e a autorização antes de emitir; o modelo não aprova a emissão.
4. **Não concordo** encerra o fluxo. Mensagens ou mídias que tenham chegado antes do aceite não são salvas nem enviadas à IA.
5. O titular pode pedir para encerrar/revogar o consentimento. O sistema interrompe novas etapas e aplica as regras de exclusão/anonimização de [`docs/01-regras-de-negocio.md`](../01-regras-de-negocio.md).

O atendimento e a emissão não envolvem pagamento, checkout ou repasse no MVP. O tratamento de mensagens deve evitar registrar conteúdo, foto, telefone, chaves ou respostas brutas da IA em logs.
