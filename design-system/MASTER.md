# Design system IEPRCard

## Direção visual

Painéis administrativos priorizam leitura, estado operacional e ação segura. Usar minimalismo funcional: fundo claro, superfícies brancas, bordas discretas e um azul primário. Evitar gradientes decorativos, animação contínua e informação transmitida apenas por cor.

## Tokens

| Token | Valor | Uso |
| --- | --- | --- |
| Fundo | `#F6F8FB` | Plano de fundo da aplicação |
| Superfície | `#FFFFFF` | Cartões, menus e áreas de formulário |
| Texto principal | `#0F172A` | Títulos e conteúdo principal |
| Texto secundário | `#475569` | Apoio e descrição |
| Borda | `#DCE3EC` | Divisão de áreas e controles |
| Primário | `#1D4ED8` | Ação principal, navegação ativa e foco |
| Sucesso | `#166534` | Estado aprovado acompanhado de rótulo |
| Atenção | `#92400E` | Avisos acompanhados de rótulo |
| Erro | `#B91C1C` | Falhas acompanhadas de rótulo e orientação |

- Tipografia: Inter quando disponível, com `system-ui`, `Segoe UI` e sans-serif como alternativas; não depender de fonte remota.
- Escala: 12, 14, 16, 20, 24 e 32 px. Texto corrido usa 14–16 px e line-height mínima de 1,4.
- Espaçamento: escala de 4 px; 8 px entre controles próximos, 16 px entre grupos e 24 px entre seções.
- Controles: foco visível, rótulo persistente, estados hover/active/disabled/loading e alvo de toque confortável.
- Movimento: transições curtas apenas para feedback; respeitar `prefers-reduced-motion`.
- Acessibilidade: contraste AA para texto normal, hierarquia semântica sequencial, navegação por teclado e texto junto a estados coloridos.

## Responsividade

- 1440 px ou mais: navegação lateral persistente e conteúdo em grade.
- 1024 px: reduzir a largura da navegação e manter cartões em duas colunas quando couberem.
- 768 px: navegação recolhida; cartões em uma coluna.
- 375 px: fluxo em coluna única, sem rolagem horizontal; tabela densa pode rolar dentro de uma região identificada.
