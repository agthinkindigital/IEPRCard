# RBAC e Permissões

## Princípios
1. **Teto descendente:** Admin define o teto do plano → Cliente Master delega dentro do teto → Sub-acessos recebem subconjuntos. Nenhuma camada concede o que não possui.
2. **Criação centralizada:** apenas Admin SaaS e Cliente Master criam sub-acessos (da Sede e dos Subclientes).
3. **Escopo por linha:** toda consulta é filtrada por `cliente_id` e, quando aplicável, `subcliente_id` (middleware `TenantScope`).
4. **Negação por padrão:** permissão ausente = negada.

## Catálogo de permissões (slugs)
| Grupo | Permissões |
| --- | --- |
| Titulares | `titular.ver`, `titular.criar`, `titular.editar`, `titular.excluir`, `titular.importar`, `titular.exportar` |
| Carteirinhas | `carteirinha.gerar`, `carteirinha.lote`, `carteirinha.reimprimir`, `carteirinha.suspender`, `carteirinha.liberar_acesso`, `carteirinha.pdf` |
| Designs | `design.ver`, `design.criar`, `design.editar`, `design.mapear`, `design.publicar` |
| Unidades | `subcliente.ver`, `subcliente.criar`, `subcliente.editar`, `subcliente.desativar` |
| Equipe | `usuario.ver`, `usuario.criar`, `usuario.permissoes` |
| Cotas | `cota.ver`, `cota.distribuir` |
| Financeiro | `financeiro.ver`, `financeiro.plano` |
| Telemetria | `telemetria.unidade`, `telemetria.rede` |
| Sistema (Admin) | `admin.*`, `mcp.tokens`, `gateway.config`, `auditoria.global` |

## Matriz padrão por papel
| Permissão | Admin | Cliente Master | Sub-acesso Sede | Resp. Subcliente | Sub-acesso Sub | Titular |
| --- | --- | --- | --- | --- | --- | --- |
| Titulares (CRUD/importar) | Sim | Sim (rede) | Configurável | Sim (unidade) | Configurável | Só o próprio (ver) |
| Gerar carteirinha / lote | Sim | Sim | Configurável | Sim | Configurável | Não |
| Designs criar/editar | Sim | Sim | Configurável | Se delegado | Não | Não |
| Criar subclientes | Sim | Sim (até N) | Não | Não | Não | Não |
| Criar sub-acessos | Sim | **Sim (exclusivo)** | Não | **Não** | Não | Não |
| Distribuir cotas | Sim | Sim | Não | Não | Não | Não |
| Financeiro / plano | Sim (global) | Sim (próprio) | Configurável (ver) | Não | Não | Pagamentos próprios |
| Telemetria | Global | Rede | Sede | Unidade | Unidade (se delegado) | Não |
| Auditoria | Global | Rede | Não | Unidade (leitura) | Não | Não |
| Download de PDF pelo titular | Habilita para os titulares da própria Sede | Habilita para titulares da Sede e delega explicitamente para unidades | Conforme habilitação da Sede | Somente se a Sede delegou à unidade | Somente se a Sede delegou à unidade | Apenas o próprio PDF ativo quando liberado |

O PDF do titular é negado por padrão. A Sede pode habilitar o recurso para os próprios titulares; uma unidade só pode disponibilizá-lo depois de delegação explícita da Sede por `carteirinha.pdf`. O titular autenticado só baixa PDF ativo pertencente a si. A implementação e o gate visual do portal pertencem à [E04](https://github.com/agthinkindigital/IEPRCard/issues/4).

## Contrato executável

O formato canônico dos perfis, flags de plano, overrides, escopos e algoritmo de avaliação está em [ADR-0004](adr/0004-rbac-teto-descendente-e-cotas.md#contrato-executável-do-mvp). A implementação usa o schema canônico `perfis_acesso`, `permissoes`, `usuario_permissoes` e `plano_feature_flags`; as tabelas históricas `papeis`/`papel_permissoes` não são usadas.


## Telas do MVP

**Login e cadastro**

O acesso do Administrador SaaS segue o [mockup de login](../design-system/mockups/admin-login-mockup.svg) aprovado e o [guia funcional](userguide/admin-login.md). A autorização exige conta global ativa `superadmin`; o primeiro acesso é provisionado apenas pelo CLI.

![Login e cadastro](/telas/02-login.png)

**Seletor “Visualizar como” (persona)**

![Seletor “Visualizar como” (persona)](/telas/06-painel.png)

**Meu perfil**

![Meu perfil](/telas/13-perfil.png)

