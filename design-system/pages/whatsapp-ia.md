# Página: configuração de atendimento e IA

O mockup está em [`whatsapp-ia-mockup.svg`](../mockups/whatsapp-ia-mockup.svg) e o manual funcional em [`docs/userguide/whatsapp-ia.md`](../../docs/userguide/whatsapp-ia.md). A página segue os tokens de [`MASTER.md`](../MASTER.md). Regras de consentimento, retenção e escopo funcional ficam em [`docs/11-issues.md`, E05](../../docs/11-issues.md).

## Perfil e escopo

- A tela é exclusiva do superadmin e configura as credenciais globais de OpenAI e Google Gemini.
- A seleção vale para conversas dos clientes que usam o atendimento; clientes não consultam nem substituem chaves.
- Um provedor fica principal por vez. Fallback automático fica desligado no MVP para evitar encaminhamento de dados a outro serviço sem escolha explícita.
- As chamadas usam a conta do provedor configurado pela plataforma; a tela informa que o consumo é cobrado nessa conta.

## Configuração segura

- A chave de API é entrada de senha, nunca renderizada novamente após salvar. Mostrar apenas situação configurada/ausente e ação para substituir/remover.
- Não preencher exemplos com credenciais. O teste de conexão envia somente conteúdo sintético sem titular.
- Selecionar um modelo permitido pelo adaptador. Falha, chave inválida e limite do provedor aparecem sem mostrar resposta bruta, chave, prompt ou dados pessoais.
- Salvar exige CSRF e revalidação server-side do superadmin; se `IEPR_INTEGRATION_KEY` estiver ausente, bloquear a configuração e mostrar instrução operacional sem revelar detalhes do segredo. O contrato de chave fica em [ADR-0008](../../docs/adr/0008-webhooks-idempotentes-e-segredos.md).

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

Este mockup e o guia funcional aguardam aprovação explícita. Nenhuma tela ou configuração será implementada antes dessa aprovação. O contrato definitivo de armazenamento cifrado das credenciais e a chave da instalação devem permanecer fora da interface pública e dos logs.
