# Regras de Negócio e Hierarquia

O ajuste no modelo de negócio refina a arquitetura do **CardSaaS Monolith** para refletir exatamente a operação de organizações hierárquicas reais (como **Sede + Congregações**, **Matriz + Filiais**, **Faculdade + Polos** ou **Designer + Empresas Atendidas**).

Abaixo está a atualização estratégica e arquitetônica incorporando o controle centralizado de sub-acessos pelo Cliente titular, a distribuição elástica de cotas, as modalidades comerciais do Admin (assinatura, plano único, *trial* e *bypass*/cortesia) e o roteamento inteligente do membro/titular.

---

## 1. Nova Modelagem de Domínio e Hierarquia de Acessos

O subcliente não é um ente financeiro separado, mas uma **Unidade Operacional / Sub-congregação / Filial** provisionada dentro da conta do **Cliente Pagante (Sede / Matriz)**.

### 1.1. Mapa de Atores e Regra de Ouro de Criação de Sub-Acessos

| Camada | Exemplo Real (Igreja / Ensino / Empresa) | Quem Cria a Conta | Escopo de Visão e Ação |
| --- | --- | --- | --- |
| **1. Administrador SaaS** | Dono da Plataforma | Nativo do Sistema | Controle total. Define planos, preços, libera *trials* ou contas cortesia (sem vínculo de pagamento), ativa/desativa qualquer cadastro, controla limites globais/por unidade e audita todos os processos.

 |
| **2. Cliente Master (Sede)** | **Igreja Remida de Cristo - Sede** (*Responsável: Pr. Josué*) | Administrador SaaS ou Checkout Público | É quem contrata o plano (Assinatura ou Plano Único). Cria até $N$ Subclientes (ex: 6 congregações), **é o único (além do Admin) que cria e gerencia os Sub-acessos (equipe)** tanto da Sede quanto dos Subclientes, distribui cotas de produção/membros e vê os dados da Sede + todos os Subclientes com filtros limpos. |
| **3. Sub-Acessos da Sede** | Secretário da Sede, Tesoureiro, Equipe de Comunicação | **Apenas Cliente Master (Pr. Josué)** ou Admin SaaS | Acessam o painel da Sede com permissões granulares (ex: o Secretário pode cadastrar membros/obreiros e gerar carteirinhas, mas não pode alterar planos nem excluir congregações). |
| **4. Responsável do Subcliente** | **Remida de Cristo - Itaquera** (*Dirigente: Pr. Flaviano*) | **Apenas Cliente Master (Pr. Josué)** ou Admin SaaS | Gerencia a unidade de Itaquera. Visualiza e filtra **exclusivamente** os dados, membros, obreiros e carteirinhas de Itaquera. Não cria novos sub-acessos sem passar pelo Pr. Josué. |
| **5. Sub-Acessos do Subcliente** | Secretário de Itaquera, Tesoureiro Local | **Apenas Cliente Master (Pr. Josué)** ou Admin SaaS | Operam exclusivamente dentro do painel de Itaquera conforme as permissões granulares definidas pelo Pr. Josué (ex: importar planilha de membros de Itaquera e emitir lotes). |
| **6. Titular Final (Dono da Carteirinha)** | Membro da Sede, Obreiro de Itaquera, Pastor, Aluno | Equipe da Sede ou do Subcliente (via Lote, Manual ou Auto-cadastro) | Ao fazer login (quando tem acesso online liberado), **cai diretamente na tela da sua própria carteirinha**, já renderizada com o layout do seu cargo/categoria e os dados da sua respectiva congregação (Sede ou Itaquera) + referência à Sede matriz. |

---

## 2. Motor de Cotas e Limites Distribuíveis (Pool Global vs. Alocação por Subcliente)

Para atender ao controle de volumes pelo Administrador e à distribuição arbitrária pelo Cliente Master (Pr. Josué), o sistema trabalha com **4 dimensões de limite** governadas em dois níveis:

### 2.1. As 4 Dimensões de Cota

1. **`limite_subclientes`**: Quantidade máxima de sub-congregações/unidades que o Cliente pode criar (ex: `6`).
2. **`limite_usuarios_equipe`**: Quantidade máxima de sub-acessos operacionais somando Sede + Subclientes (ex: `15` usuários entre dirigentes, secretários e tesoureiros).
3. **`limite_titulares`**: Quantidade máxima de cadastros ativos no banco (Membros, Corpo Eclesiástico, Alunos, Leads).
4. **`limite_carteirinhas`**: Volume máximo de emissões de carteirinhas (mensal ou total do pacote contratado).

### 2.2. Como Funciona a Distribuição de Volumes (`includes/motor_cotas.php`)

O Cliente Master pode operar o limite das suas sub-congregações de duas formas (configurável na tela da Sede ou imposto pelo Administrador):

* **Modo 1 — Distribuição Arbitrária por Subcliente (Cota Cravada):**
* O Admin concede ao Pr. Josué um teto global de **5.000 membros** e **5.000 carteirinhas**.
* Na tela de gestão de Subclientes da Sede, o Pr. Josué aloca:
* **Sede:** Reserva de `2.000` membros / `2.000` carteirinhas;
* **Subcliente Itaquera (Pr. Flaviano):** Limite fixo de `800` membros / `800` carteirinhas;
* **Demais 5 Congregações:** `440` para cada uma.


* *Regra Matemática Validada no Backend:* A soma das cotas alocadas aos Subclientes nunca pode ultrapassar o teto do Cliente ($\sum \text{Cota}_{\text{Subcliente}} \le \text{Teto}_{\text{Cliente}}$).


* **Modo 2 — Pool Compartilhado com Teto Opcional:**
* Se o Pr. Josué deixar o limite de Itaquera como `NULL` (Compartilhado), Itaquera consome diretamente do saldo disponível da Sede até o limite global do Cliente acabar.


* **Override do Administrador SaaS:**
* O Administrador pode, a qualquer momento, editar tanto o teto global do Cliente quanto travar diretamente o limite de um Subcliente específico, além de adicionar **bônus avulso de produção** sem precisar mudar o plano.



---

## 3. Ativação de contas no MVP

O MVP não implementa cobrança, checkout nem integração financeira. O Administrador SaaS atribui plano/limites e ativa, suspende ou encerra a conta manualmente; o status propaga para os Subclientes. As modalidades comerciais, webhooks Asaas/Mercado Pago e automatizações de inadimplência descritos em documentos históricos ficam adiados para uma fase futura. Ver `docs/07` e ADR-0008.

---


## 5. Inteligência de Contexto na Carteirinha e Login Direto do Membro/Titular

### 5.1. Roteamento Direto do Membro ao Fazer Login

Quando a opção **"Gerar acesso ao dono da carteirinha"** (Requisito 09) está marcada para um membro da Sede ou de Itaquera:

1. O titular pode acessar pelo domínio da Sede, pelo subdomínio de Itaquera ou pelo link direto enviado no WhatsApp.


2. Ao autenticar (via CPF + Data de Nascimento/Senha ou Token Único), o sistema identifica que a sessão é do tipo `titular_final` e **redireciona diretamente para a tela exclusiva da sua carteirinha (`carteira/minha-carteirinha.php`)**, sem exibir menus administrativos.
3. Nessa tela, o membro vê:
* O cabeçalho com o logotipo e nome da sua congregação (**"Igreja Remida de Cristo — Congregação Itaquera"**) e a chancela da Matriz (**"Vinculada à Sede — Pres. Pr. Josué"**);
* Sua carteirinha digital interativa em alta resolução (**WebP Frente e Verso** com botão de virar o cartão);
* Seu QR Code dinâmico de validação ministerial/institucional;
* Botão para baixar a versão em **PDF para impressão** (caso o Cliente/Subcliente tenha liberado essa permissão no perfil dele).



### 5.2. Injeção Automática de Variáveis Hierárquicas no Mesmo Design (Requisitos 10, 11 e 17)

Para que o Pr. Josué não precise desenhar 6 artes diferentes para cada uma das 6 sub-congregações, o motor de mapeamento (`includes/motor_mapeamento.php`) injeta variáveis nativas da hierarquia **Sede $\rightarrow$ Subcliente $\rightarrow$ Categoria $\rightarrow$ Titular**:

| Variável no Mapa do Design | Valor Renderizado para Membro da **Sede** | Valor Renderizado para Membro de **Itaquera (Subcliente)** |
| --- | --- | --- |
| `{{sede.nome}}` | Igreja Remida de Cristo - Sede | Igreja Remida de Cristo - Sede |
| `{{sede.responsavel}}` | Pr. Josué (Presidente) | Pr. Josué (Presidente) |
| `{{sede.assinatura_img}}` | Assinatura digital do Pr. Josué | Assinatura digital do Pr. Josué |
| `{{unidade.nome}}` | **Sede Principal** | **Congregação Itaquera** |
| `{{unidade.responsavel}}` | **Pr. Josué** | **Pr. Flaviano (Dirigente)** |
| `{{unidade.assinatura_img}}` | Assinatura do Pr. Josué | Assinatura do Pr. Flaviano |
| `{{titular.categoria}}` | Membro / Corpo Eclesiástico | Membro / Corpo Eclesiástico |
| `{{titular.cargo}}` | Presbítero / Secretário / Membro | Diácono / Obreiro / Membro |

> **Múltiplos Designs por Categoria (Requisito 11):** Além de usar variáveis dinâmicas, o Pr. Josué pode criar na Sede um design **"Credencial de Obreiro / Corpo Eclesiástico"** (ex: arte dourada) e outro **"Carteirinha de Membro"** (ex: arte azul). Ao importar um lote em Itaquera ou na Sede, o sistema pode gerar todas as carteirinhas de Membro com o layout azul e todas as de Obreiro com o layout dourado automaticamente!

---

## 6. Matriz de Visibilidade, Filtros Organizacionais e Auditoria

Para garantir que o Cliente tenha visão total organizada da sua rede e o Subcliente veja estritamente a sua própria congregação/unidade:

| Tela / Módulo | Visão do **Administrador SaaS** | Visão do **Cliente Master (Pr. Josué - Sede)** | Visão do **Subcliente (Pr. Flaviano / Secretário Itaquera)** |
| --- | --- | --- | --- |
| **Filtro de Unidades** | Dropdown com **Todos os Clientes** + Dropdown encadeado com **Todos os Subclientes**. | Dropdown limpo: `[ Todas as Unidades (Consolidado) ]`, `[ Apenas Sede ]`, `[ Sub: Itaquera ]`, `[ Sub: Penha ]`... | **Travado no Backend** (`WHERE subcliente_id = ID_ITAQUERA`). Não vê nem sabe quantos membros existem nas outras congregações. |
| **Filtros Operacionais Internos** | Status da Conta (`ativo`, `trial`, `cortesia`, `vencido`), Plano, Gateway, Consumo de Cota.

 | **Categoria/Cargo** (`Membro`, `Obreiro`, `Pastor`), **Design de Carteirinha**, **Lote de Importação**, **Status da Carteirinha** (`Emitida`, `Pendente Foto`, `Vencida`), **Busca por Nome/CPF/Matrícula**. | Mesmos filtros operacionais limpos (**Categoria/Cargo**, **Design**, **Lote**, **Status da Carteirinha**, **Busca por Nome/CPF**), aplicados exclusivamente sobre os dados de Itaquera. |
| **Gestão de Equipe (Sub-acessos)** | Cria/edita/desativa qualquer usuário do sistema. | **Exclusivo:** Cria/edita/desativa sub-acessos da Sede e das Sub-congregações (secretários, tesoureiros, dirigentes) e define suas permissões. | **Somente Leitura ou Oculto:** O Dirigente ou Secretário de Itaquera não pode criar novos usuários administrativos; solicita ao Pr. Josué. |
| **Auditoria de Processos** | Log completo de todo o SaaS (quem gerou lote, quem mudou permissão, webhooks das integrações ativas, acessos de suporte e chamadas MCP). Eventos financeiros Asaas/MP só entram quando o módulo futuro for priorizado.

 | Log de auditoria da Sede e de todas as suas Sub-congregações (ex: *"Secretário de Itaquera importou 120 membros às 14:30"*). | Resumo de atividades realizadas dentro da própria unidade de Itaquera. |

---


## 7. Ciclo de Vida dos Dados Pessoais

- Enquanto a pessoa mantiver uma conta ou vínculo ativo e os dados forem necessários à operação, mantê-los com acesso restrito. Isso inclui titulares, usuários/equipe e responsáveis de tenant.
- Para um titular, registrar automaticamente a data e hora UTC ao inativar; essa data inicia o prazo de 12 meses para anonimização. Não permitir retroagir a data. Reativar cancela a retenção automática ainda pendente; o portal continua bloqueado até nova liberação. Ao receber uma solicitação de exclusão, concluir a anonimização em até 72 horas. Para os demais vínculos e dados substituídos, anonimizar em até 12 meses conforme o respectivo evento de inativação, expiração ou substituição.
- A regra cobre dados cadastrais e cópias derivadas: fotos, conversas e mensagens WhatsApp, snapshots, PDFs/WebPs, tokens, cache, índices e campos livres capazes de identificar alguém. Conversas WhatsApp sem vínculo a titular mantêm a expiração curta definida pelo fluxo (atualmente 24 horas); não são estendidas pela retenção geral.
- Auditoria, telemetria e métricas podem permanecer somente sem identificadores que permitam reidentificação. Conteúdo livre nesses registros é proibido ou deve passar pela proteção do sujeito de privacidade correspondente.
- A política de backup e a proteção contra restauração de dados anonimizados estão definidas em `docs/adr/0012-ci-deploy-e-backup.md`.

## Telas do MVP

**Clientes e unidades (cotas)**

![Clientes e unidades (cotas)](/telas/07-clientes.png)

**Titulares**

![Titulares](/telas/08-titulares.png)

**Carteirinhas emitidas**

![Carteirinhas emitidas](/telas/11-carteirinhas.png)

