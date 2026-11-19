# Página: migração de banco

## Aplicação do design system

O painel administrativo oferece dois fluxos separados: atualizar o schema por migrations versionadas e copiar dados de SQLite para MySQL/MariaDB. Atualização de schema segue o mockup [`schema-update-mockup.svg`](../mockups/schema-update-mockup.svg); cópia de dados segue [`migracao-banco-mockup.svg`](../mockups/migracao-banco-mockup.svg). Ambos usam os tokens globais de [`MASTER.md`](../MASTER.md), mas mantêm pré-verificações, confirmações e resultados próprios.

## Atualização de schema

- Disponível apenas para `superadmin`, com sessão protegida e CSRF.
- Mostra o ambiente, banco e divergências identificadas pela inspeção do catálogo. Lista somente os arquivos versionados incluídos na release atual.
- Exige confirmação explícita de backup restaurável feito no provedor e recomenda executar em horário de baixo uso.
- A ação enfileira o trabalho para o worker agendado; a interface acompanha estado sem manter a requisição aberta.
- A ordem e o hash dos arquivos vêm do repositório. Não há campo para SQL, caminho, comando ou nome de migration.
- O resultado mostra a release, administrador, horários e inspeção posterior do schema. O histórico usa auditoria operacional já existente, sem criar tabela de negócio para controle de versões.
- Estados devem distinguir pré-verificação necessária, pronta, backup não confirmado, em fila, em execução, concluída e falha. A falha apresenta orientação segura e informa que mudanças expand-only podem permanecer no banco.
- Não prometer rollback automático de DDL MySQL. Repetição é permitida apenas porque as migrations são idempotentes e pulam estruturas que já existem.
- A manutenção é uma janela breve de baixo uso, com trava para impedir outra migration simultânea; a tela não mistura esta ação com cópia de dados.

## Cópia de dados SQLite → MySQL/MariaDB

O fluxo de transferência de registros permanece independente e segue o mockup [`migracao-banco-mockup.svg`](../mockups/migracao-banco-mockup.svg) e o guia funcional de banco. Não reutilizar a confirmação de schema, estimativa ou resultado de checksum da cópia como se fossem estado de migration.

## Estrutura e comportamento

- Manter origem e destino lado a lado em desktop, com nomes técnicos identificáveis e credenciais sempre ocultas.
- Listar pré-verificações por requisito com rótulo textual `Aprovado`, `Atenção` ou `Bloqueado`; não depender de cor ou ícone.
- A ação principal permanece desabilitada enquanto houver falha ou confirmação ausente. Clique abre confirmação final revisável; não começa a cópia imediatamente.
- Mostrar progresso como operação em segundo plano; preservar estado ao sair e voltar à página. Não inventar percentual quando o worker não fornecer avanço verificável.
- No destino, distinguir o catálogo global de permissões já validado dos demais dados operacionais; qualquer outro registro bloqueia a cópia.
- Exibir resultado por tabela somente após a execução, com quantidades e checksum; nunca mostrar registros ou credenciais.
- Falha deve explicar o pré-requisito que faltou ou confirmar rollback, além de oferecer um próximo passo seguro.
- Em larguras menores, empilhar cartões e colapsar a tabela de resultados em linhas legíveis sem rolagem horizontal da página.
