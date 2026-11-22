# Papéis de triagem do GitHub

Cada Issue triada recebe uma categoria e um estado, usando os nomes atuais do repositório:

| Papel | Labels |
| --- | --- |
| Categoria | `bug`, `enhancement` |
| Estado | `needs-triage`, `needs-info`, `ready-for-agent`, `ready-for-human`, `wontfix` |

`ready-for-agent` exige escopo, dependências, critérios de aceite e verificação claros. `ready-for-human` indica julgamento, acesso externo ou validação manual necessária. `needs-triage` e `needs-info` não autorizam implementação. Issues abertas devem ter exatamente um estado; em Issues encerradas, `CLOSED` no GitHub é o estado terminal e a label de estado ativa não é necessária. Toda Issue triada mantém exatamente uma categoria. O GitHub é a fonte dos estados; consulte a [skill de triagem](../../AGENTS.md) e a Issue antes de transições.
