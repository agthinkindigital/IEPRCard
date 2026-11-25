# Página: Sedes, empresas e acessos globais

Esta interface cobre o provisionamento global que faltou à fatia backend [#17](https://github.com/agthinkindigital/IEPRCard/issues/17): Sedes, empresas/unidades, usuários e planos. O ciclo de usuários da Sede e das unidades segue o [guia de unidades, equipe e cotas](../../docs/userguide/unidades-equipe-cotas.md); o perfil Hospedado/Local Host segue o [guia de implantação](../../docs/userguide/admin-deployment-mode.md). Mockup e fluxo aprovados pelo mantenedor; implementação em `DEV` na #101.

## Mockup

- [Administração global de Sedes, empresas e acessos](../mockups/admin-tenants-mockup.svg)

## Fluxo proposto

- O superadmin autenticado acessa o painel global mesmo antes de existir Sede, plano de cliente ou perfil de instalação. `Master` é o papel global do superadmin, não um plano atribuído à sua conta.
- **Cadastrar Sede** oferece dois caminhos: criar a Sede com seu primeiro responsável na mesma transação; ou gerar convite para o responsável criar uma única Sede. Convites expiram em 72 horas, são vinculados ao e-mail informado, de uso único e exibidos uma vez para entrega manual. O sistema guarda somente hashes do token e do e-mail; o verificador do e-mail é apagado ao consumir, revogar ou expirar o convite. Concluir o convite cria Sede e responsável pendentes na mesma transação; ambos aguardam ativação manual pelo superadmin.
- O cadastro exige organização e plano ativo. O cadastro direto também inicia Sede e responsável como pendentes. O tenant raiz recebe inicialmente os tetos definidos no plano para unidades, equipe, titulares e emissões. O superadmin pode reduzir os tetos depois, mas não ultrapassar o plano nem o uso/alocação existentes. Sem plano ativo, bloquear a criação sem registros parciais.
- O painel global permite criar acessos de Sede com permissões explícitas, empresas/unidades (`subcliente`) e responsáveis de unidade. Permissões selecionadas precisam estar no plano; a equipe global e as demais cotas são revalidadas no servidor. Unidades novas começam usando cotas do pool da Sede e recebem acesso responsável por ação separada.
- O painel permite cadastrar planos sem cobrança e ativar/desativar sua disponibilidade. Não permite desativar plano associado a Sede ativa. Alterações de permissões ou cotas de planos existentes não fazem parte deste formulário; a conta `Master` continua sem plano de cliente.
- Senhas são persistidas somente por hash e nunca retornam à tela. Ativação de contas é manual e não há checkout, cobrança, repasse ou webhook.
- Em modo Hospedado, hostname/subdomínio e prova DNS/TLS seguem os fluxos existentes; criar uma Sede não marca um domínio como verificado. Em Local Host, não se exige hostname, e usuários institucionais ficam limitados à Sede selecionada e às suas unidades.
- O superadmin mantém navegação global sem Sede. Usuários institucionais só entram com conta existente e ativa vinculada a uma Sede; operadores de unidade ficam limitados à unidade e às permissões delegadas.

## Estados e segurança

- Vazio: explicar que o painel global e a criação de planos não exigem uma Sede prévia.
- Sem plano de cliente ativo: oferecer cadastro de plano e impedir a criação da Sede, sem registros parciais.
- Convite expirado, já consumido, e-mail divergente ou revogado: negar genericamente o cadastro; o superadmin pode revogar convites pendentes e emitir outro.
- Falha de validação: manter campos corrigíveis e não revelar se um e-mail pertence a outra conta.
- Sucesso pendente: mostrar a confirmação sem repetir a senha; o usuário aguarda ativação manual do superadmin.
- Todas as mutações exigem CSRF, revalidam a identidade superadmin e respeitam as cotas da Sede. Criação de Sede+responsável usa o serviço transacional existente; criação por convite precisa consumir o token uma vez e atrelar o usuário a exatamente uma Sede.
- Convites de Sede não são restaurados por snapshots: são autorizações temporárias e devem ser emitidas novamente após restauração. As decisões de backup permanecem no [SAD](../../docs/architecture/SAD.md) e no ADR-0012.
- Em 1440 px usar tabela e painel lateral; em 768/375 px empilhar o formulário e transformar linhas em cartões. Campos têm rótulos persistentes, foco visível e erros associados.
