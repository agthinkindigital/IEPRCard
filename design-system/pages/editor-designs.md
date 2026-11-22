# Página: designs de carteirinha

## Aplicação do design system

O editor segue os tokens de [`MASTER.md`](../MASTER.md) e o contrato funcional de [`docs/06-design-e-mapeamento.md`](../../docs/06-design-e-mapeamento.md). O mockup apresenta a edição em desktop e o mesmo fluxo adaptado ao celular em [`editor-designs-mockup.svg`](../mockups/editor-designs-mockup.svg).

### Privacidade dos fundos — aprovada em 2026-11-22

- Cada lado permite declarar arte decorativa sem pessoa identificável ou vincular a imagem a um titular.
- Imagens com mais de uma pessoa identificável não podem ser usadas como fundo. A foto individual deve usar o elemento dinâmico de foto.
- Texto fixo não recebe dados pessoais; dados do titular usam variáveis dinâmicas.
- Fundos antigos exigem revisão antes de novos snapshots. Regras de armazenamento e exclusão estão no [guia funcional](../../docs/userguide/designs-carteirinhas.md).

## Estrutura e comportamento

- Manter lista de elementos, arte do cartão e propriedades como áreas identificáveis; os lados frente/verso têm seleção explícita.
- Permitir mover o elemento selecionado no canvas ou ajustar coordenadas em campos numéricos. Teclado e toque devem alcançar as mesmas operações.
- Exibir dimensões CR80, guias de área segura e prévia com dados fictícios; não buscar titulares reais apenas para desenhar.
- Agrupar a edição no celular em abas `Campos`, `Fundo` e `Ajustes`, com ação `Salvar design` sempre acessível.
- Estados de rascunho, salvo, erro de validação e upload devem ter rótulo textual e foco visível; cor não é o único sinal.
- O mockup e o guia funcional foram aprovados pelo usuário em 2026-11-22.
