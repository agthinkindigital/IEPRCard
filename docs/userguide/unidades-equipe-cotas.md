# Guia funcional: unidades, equipe e cotas

Este guia cobre a administração da rede por uma Sede. Os três fluxos têm mockups próprios: [unidades](../../design-system/mockups/unidades-tenant-mockup.svg), [equipe](../../design-system/mockups/equipe-tenant-mockup.svg) e [cotas](../../design-system/mockups/cotas-tenant-mockup.svg). As páginas `/tenant/unidades.php`, `/tenant/equipe.php` e `/tenant/cotas.php` estão disponíveis após autenticação do tenant; domínios próprios seguem em [guia específico](dominios-tenant.md).

## Quem pode administrar

O Cliente Master da Sede administra suas unidades, operadores e alocações dentro dos limites definidos para a conta. O Admin SaaS administra contas no contexto global. Um operador de unidade não cria operadores nem amplia permissões. Todo acesso é verificado pelo servidor com tenant, sessão e papel persistidos.

O painel global do superadmin funciona antes de existir uma Sede ou plano de cliente. `Master` é o papel global do superadmin, sem plano de cliente. O superadmin pode criar uma Sede com responsável ou emitir convite de uso único para criar uma Sede; pode também criar unidades e acessos diretamente. Todas as operações continuam sujeitas às cotas e permissões do plano. Os fluxos, estados de ativação e convite estão detalhados em [Sedes, empresas e acessos](../../design-system/pages/admin-tenants.md).

## Unidades

1. A lista mostra somente unidades da Sede, com situação, responsável e dados agregados da própria unidade.
2. **Nova unidade** solicita nome e identificador de subdomínio. O sistema valida formato, unicidade e cota disponível antes de criar.
3. Se o limite de unidades da Sede foi atingido, a criação é recusada com indicação do limite atual. O estado de conta `ativo` é apresentado como informação textual.
4. Edição de dados, suspensão e encerramento são fluxos do produto ainda sem serviço backend correspondente. A tela não confirma essas ações enquanto não forem aprovadas e implementadas em uma fatia própria.

## Equipe e permissões

1. A lista mostra operador, unidade associada, perfil e estado de acesso. A Sede pode consultar o acesso efetivo e criar um responsável restrito à unidade selecionada.
2. A criação de um responsável de unidade solicita os dados definidos pelo fluxo autenticado; senha e dados de acesso não aparecem em listas nem telemetria.
3. O contador de operadores considera o limite total da Sede. Quando uma unidade tem limite fixo de equipe, o limite local também precisa estar disponível.
4. **Permissões delegadas às unidades** é uma allowlist comum, configurada pela Sede. A tela apresenta somente permissões de negócio habilitadas no plano; administração, financeiro, equipe e cotas ficam fora.
5. Perfis e overrides individuais podem restringir o acesso delegado. Nunca concedem algo que o teto da Sede ou do plano não autoriza.
6. Suspensão de conta de usuário, edição completa do perfil e recuperação de credencial exigem serviços próprios se ainda não estiverem disponíveis. Mensagens distinguem falta de permissão, conflito de e-mail e cota esgotada sem expor dados de outras unidades.

## Cotas e pool compartilhado

1. O painel mostra os tetos da Sede para unidades, equipe, titulares ativos e emissões consumidas. O consumo de emissões é cumulativo conforme o contador usado pelo serviço de emissão; não representa um período mensal até existir uma regra de renovação de cotas.
2. A Sede pode reservar cotas fixas de equipe, titulares e emissões para cada unidade; o limite de número de unidades é definido no nível da Sede.
3. Uma unidade com `Pool` não tem reserva fixa naquela dimensão e consome do saldo compartilhado da Sede, sempre respeitando o teto global.
4. Antes de salvar uma alocação, o sistema valida em transação que a soma das cotas fixas das unidades não ultrapassa o teto da Sede. Valores nulos representam Pool; zero representa nenhuma cota reservada.
5. Uma reserva fixa não pode ultrapassar o teto nem ficar abaixo do uso atual da unidade. A mensagem identifica a dimensão e o limite relevante; o servidor revalida tudo em transação.

## Estados e falhas

- **Vazio:** orientar a cadastrar a primeira unidade ou operador quando a permissão e o limite permitirem.
- **Sem permissão:** ocultar ações indevidas e, em chamada direta, recusar no servidor.
- **Limite atingido:** mostrar uso e teto da Sede; não sugerir que a unidade possa elevar o limite.
- **Conflito de subdomínio ou e-mail:** pedir outro valor sem revelar conta ou tenant que já utiliza aquele dado.
- **Unidade suspensa/inativa:** mostrar situação e bloquear novos acessos ou provisionamentos conforme o backend; não prometer reativação se não existir serviço.
- **Erro de salvamento:** manter a entrada corrigível, apresentar mensagem ao lado do campo e não exibir caminhos internos ou SQL.

## Privacidade e auditoria

Listas permanecem no tenant autorizado. Resumos não misturam Sedes. Mudanças de cota e permissão são auditadas com valores antes/depois, sem senhas, tokens ou dados pessoais que não sejam necessários.
