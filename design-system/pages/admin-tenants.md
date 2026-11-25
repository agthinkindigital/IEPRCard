# Página: Sedes, empresas e acessos globais

Esta proposta cobre o provisionamento global que faltou à fatia backend [#17](https://github.com/agthinkindigital/IEPRCard/issues/17): Sedes, empresas/unidades, usuários e planos. O ciclo de usuários da Sede e das unidades segue o [guia de unidades, equipe e cotas](../../docs/userguide/unidades-equipe-cotas.md); o perfil Hospedado/Local Host segue o [guia de implantação](../../docs/userguide/admin-deployment-mode.md).

## Mockup

- [Administração global de Sedes, empresas e acessos](../mockups/admin-tenants-mockup.svg)

## Fluxo proposto

- O superadmin autenticado acessa o painel global mesmo antes de existir Sede, plano de cliente ou perfil de instalação. `Master` é o papel global do superadmin, não um plano atribuído à sua conta.
- **Cadastrar Sede** oferece dois caminhos: criar a Sede com seu primeiro usuário responsável na mesma transação; ou criar um usuário de cadastro, sem tenant, que poderá concluir uma única Sede no primeiro acesso. O segundo caminho precisa de convite de uso único, expiração, definição de senha pelo destinatário e conversão atômica para o responsável da Sede.
- O cadastro de Sede exige organização, plano ativo e estado inicial explícito (`pendente` ou `ativo`). O tenant raiz recebe cotas explícitas para unidades, usuários, titulares e emissões; a seleção do plano controla os recursos permitidos. Sem plano ativo, bloquear a conclusão sem deixar registros parciais.
- O painel global permite criar usuários, empresas/unidades (`subcliente`) e acessos de Sede/unidade para qualquer Sede. As mesmas cotas do plano continuam valendo; uma ação do superadmin não as ignora silenciosamente. A Sede pode criar seus próprios subusuários/unidades conforme os limites e permissões delegados já existentes.
- O sistema local atual tem zero planos ativos e não possui uma tela de gestão global de planos. A proposta inclui cadastro/ativação de planos de cliente e suas permissões; o cadastro de plano não inclui cobrança nem integração financeira. `Master` não é um plano de cliente.
- Senhas são persistidas somente por hash e nunca retornam à tela. Ativação de contas é manual e não há checkout, cobrança, repasse ou webhook.
- Em modo Hospedado, hostname/subdomínio e prova DNS/TLS seguem os fluxos existentes; criar uma Sede não marca um domínio como verificado. Em Local Host, não se exige hostname, e usuários institucionais ficam limitados à Sede selecionada e às suas unidades.
- O superadmin mantém navegação global sem Sede. Usuários institucionais só entram com conta existente e ativa vinculada a uma Sede; operadores de unidade ficam limitados à unidade e às permissões delegadas.

## Estados e segurança

- Vazio: explicar que o painel global e a criação de planos não exigem uma Sede prévia.
- Sem plano de cliente ativo: oferecer cadastro de plano e impedir apenas a criação da Sede, sem criar usuário ou tenant incompletos.
- Convite expirado, já consumido ou revogado: negar o cadastro e permitir somente gerar um novo convite pelo superadmin.
- Falha de validação: manter campos corrigíveis e não revelar se um e-mail pertence a outra conta.
- Sucesso pendente: mostrar a confirmação sem repetir a senha e orientar a entrega segura das credenciais.
- Todas as mutações exigem CSRF, revalidam a identidade superadmin e respeitam as cotas da Sede. Criação de Sede+responsável usa o serviço transacional existente; criação por convite precisa consumir o token uma vez e atrelar o usuário a exatamente uma Sede.
- Em 1440 px usar tabela e painel lateral; em 768/375 px empilhar o formulário e transformar linhas em cartões. Campos têm rótulos persistentes, foco visível e erros associados.
