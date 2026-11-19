# ADR-0013: Parser single-file para planilhas `.xls` legadas

| Campo | Valor |
| --- | --- |
| Status | Aceita |
| Data | 2026-11-19 |
| Decisores | Arquiteto + dono do produto |
| Relacionados | ADR-0001, [SAD §3.2](../architecture/SAD.md), TDD-03, GitHub #28 |

## Contexto

O parser de importação atende CSV, XLSX e JSON; o requisito histórico de `.xls` binário ficou fora da #28 até existir uma opção compatível com PHP puro e hospedagem compartilhada. O formato BIFF 8 usa contêiner OLE, diferente do ZIP/XML do XLSX. A arquitetura aceita bibliotecas PHP single-file embutidas e exige ADR para adicionar dependências.

## Decisão

Usar `SimpleXLS` do projeto `shuchkin/simplexls`, versão `1.0.0` fixada no commit `0aad1dd0c8703c8fcc3234eda9d22d08e25b6579`, em `includes/libs/SimpleXLS.php`. Incluir a licença MIT original junto ao código. Não adicionar Composer nem carregar a classe fora do adaptador do parser.

O adaptador lê somente a primeira planilha e normaliza os valores por meio do contrato existente do parser. Aplicam-se os limites existentes de 10 MiB e 500 registros, além do limite de colunas e células. Fórmulas são importadas apenas pelo valor armazenado no arquivo; macros e fórmulas não são executadas.

## Alternativas consideradas

| Alternativa | Prós | Contras | Veredito |
| --- | --- | --- | --- |
| SimpleXLS single-file | Leitor BIFF 8 em PHP; sem Composer nem extensões adicionais; versão e commit fixáveis; licença MIT | Dependência de mantenedor externo e memória proporcional ao arquivo | Escolhida, com limites e fixture de regressão |
| PhpSpreadsheet | Suporte amplo a formatos e BIFF 5/8 | Dependências Composer e maior superfície/runtime do que o escopo precisa | Rejeitada para este parser |
| Parser BIFF/OLE próprio | Nenhuma dependência externa | Complexidade alta e risco de segurança/cobertura incompatíveis com a necessidade | Rejeitada |

## Consequências

- `.xls` passa a compartilhar validação, normalização, prévia e erros sem PII com CSV/XLSX/JSON.
- Atualizações do parser exigem revisar release/commit, licença e fixture; atualizar a cópia vendorizada e os testes no mesmo change.
- Arquivos com mais de 500 registros são rejeitados. O parser não promete suporte a formatos anteriores a BIFF 8.

## Referências

- Repositório e API: <https://github.com/shuchkin/simplexls/tree/0aad1dd0c8703c8fcc3234eda9d22d08e25b6579>
- Licença MIT: <https://github.com/shuchkin/simplexls/blob/0aad1dd0c8703c8fcc3234eda9d22d08e25b6579/LICENSE>
- Fixture `books.xls`: <https://github.com/shuchkin/simplexls/blob/0aad1dd0c8703c8fcc3234eda9d22d08e25b6579/examples/books.xls>
