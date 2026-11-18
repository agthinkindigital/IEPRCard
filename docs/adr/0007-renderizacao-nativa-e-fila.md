# ADR-0007: Renderização WebP/PDF nativa e fila em banco

| Campo | Valor |
| --- | --- |
| Status | Aceita (baseline `docs/06`, `docs/11` ISSUE-009 a 012, `docs/12` GATE 3) |
| Data | 2026-11-18 |
| Decisores | Arquiteto + time de engenharia |
| Relacionados | [SAD §4.3, §5.3](../architecture/SAD.md), ADR-0001, ADR-0004 |

## Contexto

Cada carteirinha precisa de duas representações: WebP 1011×638 px (CR80 a 300 DPI) para web/WhatsApp e PDF vetorial 300 DPI para gráfica (CR80 individual ou A4 com 8/10 unidades, sangria e marcas de corte). O GATE 3 exige ≤ 80 ms por WebP e ≤ 1,5 s para um lote PDF de 50. Lotes de 1.000+ registros não podem bloquear o request HTTP.

## Decisão

- `motor_carteirinha_webp.php` usa GD/Imagick nativo com FreeType para fontes TTF; saída `image/webp`, dimensões exatas validadas em teste.
- `motor_carteirinha_pdf.php` gera PDF vetorial via biblioteca single-file em `includes/libs/`, com cabeçalho `%PDF-1.4`, CR80 e A4, marcas de corte e QR Code vetorial.
- Contrato único de layout `mapa_campos_json` (`versao_schema`, `dimensoes`, `elementos`) compartilhado por ambos os renderizadores; variáveis hierárquicas `{{sede.*}}`, `{{unidade.*}}`, `{{titular.categoria}}` resolvidas pelo `motor_mapeamento.php` antes da renderização (Modo A fixo e Modo B De→Para).
- Fila em banco: `lotes_importacao.status_lote` + processamento em chunks de 25 via AJAX, com `cron.php` como fallback em hospedagem compartilhada.
- Snapshot exato dos dados impressos em `snapshot_dados_json` para reimpressão fiel; versionamento de design registrado na carteirinha.

## Alternativas consideradas

| Alternativa | Prós | Contras | Veredito |
| --- | --- | --- | --- |
| Headless browser (Puppeteer/Playwright) para PDF | CSS completo, layout flexível | Dependência pesada, uso de memória alto, viola ADR-0001, difícil de atingir 80 ms | Rejeitada |
| Serviço externo de renderização | Escala e qualidade | Custo por carteirinha, latência de rede, dados pessoais saindo do tenant | Rejeitada |
| Somente raster (PNG/JPEG) | Implementação simples | Sem vetor 300 DPI para gráfica; falha requisito de impressão PVC | Rejeitada |
| GD/Imagick + PDF single-file + fila em banco (escolhida) | Nativo, rápido, portável, sem dados fora | Requer gestão de fontes e cache de preview; worker único em SQLite | Aceita |

## Consequências

**Positivas:** atende benchmarks e modo híbrido impresso/digital; preview do editor reusa o mesmo renderizador, evitando divergência entre tela e arte final.

**Negativas:** bibliotecas single-file precisam de auditoria e pinned version; falhas de fonte/estouro de texto exigem `dry_run` do MCP (docs/09) e validação no GATE 3.

## Como validar

- `TDD-03`: 500 registros nos 3 formatos, mesma saída normalizada.
- `TDD-04`: WebP com mime `image/webp` e 1011×638; PDF com `%PDF-1.4`, contagem de páginas e QR presente.
- GATE 3: benchmark de 80 ms/WebP e 1,5 s/PDF-50; QR do portal resolve na página pública.
