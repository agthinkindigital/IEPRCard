# Página: migração de banco

## Aplicação do design system

Esta página de uso restrito combina pré-verificação, consentimento administrativo e acompanhamento de uma cópia de dados. Segue os tokens globais de [`MASTER.md`](../MASTER.md) e o mockup [`migracao-banco-mockup.svg`](../mockups/migracao-banco-mockup.svg).

## Estrutura e comportamento

- Manter origem e destino lado a lado em desktop, com nomes técnicos identificáveis e credenciais sempre ocultas.
- Listar pré-verificações por requisito com rótulo textual `Aprovado`, `Atenção` ou `Bloqueado`; não depender de cor ou ícone.
- A ação principal permanece desabilitada enquanto houver falha ou confirmação ausente. Clique abre confirmação final revisável; não começa a cópia imediatamente.
- Mostrar progresso como operação em segundo plano; preservar estado ao sair e voltar à página. Não inventar percentual quando o worker não fornecer avanço verificável.
- No destino, distinguir o catálogo global de permissões já validado dos demais dados operacionais; qualquer outro registro bloqueia a cópia.
- Exibir resultado por tabela somente após a execução, com quantidades e checksum; nunca mostrar registros ou credenciais.
- Falha deve explicar o pré-requisito que faltou ou confirmar rollback, além de oferecer um próximo passo seguro.
- Em larguras menores, empilhar cartões e colapsar a tabela de resultados em linhas legíveis sem rolagem horizontal da página.
