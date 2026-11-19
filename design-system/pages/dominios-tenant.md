# Página: domínios próprios da instituição

O mockup está em [`dominios-tenant-mockup.svg`](../mockups/dominios-tenant-mockup.svg). As regras de uso e os estados do fluxo estão no [guia funcional](../../docs/userguide/dominios-tenant.md). Tokens globais seguem [`MASTER.md`](../MASTER.md).

## Direção e composição

- Página do Cliente Master da Sede; domínio próprio é vinculado à raiz do tenant, nunca a uma unidade.
- Lista de hosts e situação textual; DNS/TLS usa rótulo e explicação além da cor.
- Detalhes do domínio pendente mostram o único registro TXT, valor copiável, aviso de exibição única e a etapa para conferir DNS.
- O painel informa que hosts pendentes ou sem HTTPS válido não resolvem tenant nem abrem a conta.
- Não há indicadores de capacidade, configuração de pagamento, nem controles de domínio de outras Sedes.

## Estados e ações

- `pendente_verificacao`: mostrar a instrução TXT recém-criada e a ação de conferir DNS enquanto o desafio em memória de sessão ainda estiver disponível.
- `tls_pendente`: prova DNS concluída; explicar que o HTTPS ainda não foi ativado. Não oferecer “Ativar HTTPS” até existir integração/configuração suportada pelo host.
- `ativo`: somente quando prova e TLS estiverem confirmados; ação de detalhes.
- Erro DNS/TLS: mensagem textual com orientação segura e sem revelar se o host já pertence a outro tenant.
- `revogado`/removido: apresentar como histórico sem resolver o host; remoção e recuperação precisam de serviço de backend próprio.

Os estados de TLS, remoção e recuperação são estados de destino do produto, não capacidades implementadas pelo serviço `domain_onboarding.php` atual. O mockup não simula sucesso para essas operações.

## Responsividade e acessibilidade

- 1440 px: navegação lateral, tabela e painel de detalhes em duas colunas.
- 1024 px: reduzir a navegação e manter as colunas se couberem; de outro modo, priorizar a lista e abrir detalhe abaixo.
- 768 px e 375 px: uma coluna; detalhes em página/painel abaixo da lista; registros DNS quebram linha e os controles de cópia permanecem associados a cada valor.
- Tabela deve ter cabeçalhos semânticos e ordenação anunciada se for interativa. Foco visível, rótulos persistentes, badges com texto e estados de loading/erro. Nenhuma informação depende somente da cor.
