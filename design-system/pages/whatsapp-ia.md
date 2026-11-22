# Página: configuração de atendimento e IA

O mockup está em [`whatsapp-ia-mockup.svg`](../mockups/whatsapp-ia-mockup.svg) e o manual funcional em [`docs/userguide/whatsapp-ia.md`](../../docs/userguide/whatsapp-ia.md). A página segue os tokens de [`MASTER.md`](../MASTER.md). Regras de consentimento, retenção e escopo funcional ficam em [`docs/11-issues.md`, E05](../../docs/11-issues.md).

## Perfil e escopo

- A tela é exclusiva do superadmin e configura a conexão global GOWA VIP e as credenciais globais de OpenAI e Google Gemini.
- GOWA VIP é o gateway WhatsApp do MVP; a URL da API e o token global conectam a plataforma. Cada instância de cliente recebe URL de webhook própria e segredo aleatório exclusivo.
- A seleção vale para conversas dos clientes que usam o atendimento; clientes não consultam nem substituem chaves.
- Um provedor fica principal por vez. Fallback automático fica desligado no MVP para evitar encaminhamento de dados a outro serviço sem escolha explícita.
- As chamadas usam a conta do provedor configurado pela plataforma; a tela informa que o consumo é cobrado nessa conta.

## Configuração segura

- Tokens GOWA e chaves de IA são entradas de senha, nunca renderizadas novamente após salvar. Mostrar apenas situação configurada/ausente e ação para substituir/remover.
- Não preencher exemplos com credenciais reais. O teste GOWA verifica apenas a conexão da API; o teste de IA usa conteúdo sintético sem titular.
- Selecionar um modelo permitido pelo adaptador. Falha, chave inválida e limite do provedor aparecem sem mostrar resposta bruta, chave, prompt ou dados pessoais.
- Salvar exige CSRF e revalidação server-side do superadmin; se `IEPR_INTEGRATION_KEY` estiver ausente, bloquear a configuração e mostrar instrução operacional sem revelar detalhes do segredo. O contrato de chave e webhook fica em [ADR-0008](../../docs/adr/0008-webhooks-idempotentes-e-segredos.md).
- A URL de webhook por instância contém um segredo bearer aleatório, revelado apenas no provisionamento/rotação; depois, o sistema mostra somente o estado. Nunca registrar URL/token em logs ou telemetria.

## Fluxo de conversa

- O bot apresenta finalidade e o provedor de IA ativo antes de solicitar dados ou fotos.
- Só o botão/texto afirmativo explícito libera a etapa seguinte; recusa encerra o fluxo sem penalidade.
- Mensagens ou mídias recebidas antes do aceite não são persistidas nem enviadas à IA. Responder pedindo consentimento sem ecoar o conteúdo recebido.
- O titular pode revogar o aceite e encerrar. O sistema para o processamento futuro e encaminha a remoção/anonimização aos fluxos canônicos quando aplicável.
- A IA apenas interpreta texto e extrai campos estruturados. Máquina de estados e backend validam valores, cota, titular, autorização e emissão.

## Estados, responsividade e acessibilidade

- Mostrar separadamente chave ausente, chave salva, conexão aprovada e erro de conexão.
- Uma falha de teste não troca o provedor ativo nem apaga configuração anterior.
- Rótulos persistentes, foco visível, confirmação textual e operação por teclado; nenhum estado é indicado somente por cor.
- Em 375 px, empilhar os cartões dos provedores e o preview do consentimento em coluna única, sem rolagem horizontal.

## Gate

Mockup e guia funcional aprovados pelo mantenedor em 2026-11-22. A tela de configuração está fatiada na [#83](https://github.com/agthinkindigital/IEPRCard/issues/83); o fluxo backend de conversa e consentimento está na [#81](https://github.com/agthinkindigital/IEPRCard/issues/81). O contrato de armazenamento cifrado das credenciais e a chave da instalação permanecem definidos em [ADR-0008](../../docs/adr/0008-webhooks-idempotentes-e-segredos.md), fora da interface pública e dos logs.
