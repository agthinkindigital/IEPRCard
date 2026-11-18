# ADR-0007: Renderização WebP/PDF nativa e fila em banco

| Campo | Valor |
| --- | --- |
| Status | Aceita (baseline `docs/06`, `docs/11` ISSUE-009 a 012, `docs/12` GATE 3) |
| Data | 2026-11-18 |
| Decisores | Arquiteto + time de engenharia |
| Relacionados | [SAD §4.3, §5.3](../architecture/SAD.md), ADR-0001, ADR-0004 |

## Contexto

Cada carteirinha precisa de WebP 1011×638 px (CR80 a 300 DPI) para canais digitais e PDF com texto/códigos vetoriais para impressão CR80. O GATE 3 exige ≤ 80 ms por WebP e ≤ 3,5 s para 50 carteirinhas em PDF, aferidos no runner PHP 8.2 isolado do banco. O alvo de PDF foi ajustado após medições de 2,08–2,99 s para 100 páginas com 50 fotos e códigos distintos. Lotes grandes não podem bloquear o request HTTP; a emissão assíncrona permanece no escopo da fila descrita neste ADR.

## Decisão

- `config/card_webp_renderer.php` usa GD e FreeType para gerar `image/webp` em 1011×638; o contrato e o runtime são compartilhados com a renderização PDF.
- O gerador single-file `kreativekorp/barcode.php` é fixado no commit `dac436dc29d6fedd1e994c8778f0b53892e1f739`, acompanhado pela licença MIT e incluído fora da raiz pública em `includes/libs/`. A integração chama a API em memória, sem expor o arquivo como endpoint. A coleção interna de fontes contém Inter v3.19 em OTF sob SIL Open Font License 1.1.
- QR Code usa nível de correção Q, preserva módulos inteiros no redimensionamento e uma quiet zone mínima de quatro módulos; texto, foto e barras são compostos dentro do canvas CR80. EAN13 valida o dígito verificador antes da renderização.
- `includes/card_pdf_renderer.php` usa FPDF 1.9.0, single-file fixado no commit `051b70e4c57dedc88df41b1eff1c62894e5f9ed0`, para gerar duas páginas CR80 paisagem a 85,6×53,98 mm. A licença MIT acompanha a cópia em `includes/libs/`; o runtime não requer Composer nem daemon.
- `cardRenderPdfBatch()` limita cada PDF a 50 carteirinhas (100 páginas) e reutiliza buffers e imagens de fundo no escopo do documento. Um erro em qualquer cartão descarta a saída inteira.
- Fundos e fotos continuam raster; texto, QR, CODE128 e EAN13 são vetoriais. Inter Regular/Bold estática derivada da versão variável 3.19 é incorporada ao PDF com métricas e subconjuntos locais gerados para FPDF. O texto usa Windows-1252 e qualquer caractere não representável gera erro estável sem PDF parcial. A licença SIL OFL 1.1 original está em `assets/fonts/OFL-1.1.txt`.
- A4, imposição 8/10 por página, sangria e marcas de corte são decisão e fatia posteriores; não fazem parte do contrato CR80 atual.
- Contrato único de layout `mapa_campos_json` (`versao_schema`, `dimensoes`, `elementos`) compartilhado pelos renderizadores; `config/design_mapping.php` resolve variáveis hierárquicas antes da renderização.
- Fila em banco: `lotes_importacao.status_lote` + processamento em chunks de 25 via AJAX, com `cron.php` como fallback em hospedagem compartilhada.
- Snapshot exato dos dados impressos em `snapshot_dados_json` para reimpressão fiel; versionamento de design registrado na carteirinha.

O runtime PHP que executa emissão deve oferecer as capacidades registradas em `config/rendering_capabilities.php`, incluindo GD, FreeType e `mbstring`. Verificar o CLI do host com `php scripts/check-rendering-runtime.php` e confirmar que a mesma versão e extensões estão ativas no PHP-FPM/Apache; a CI exige o mesmo perfil.

## Alternativas consideradas

| Alternativa | Prós | Contras | Veredito |
| --- | --- | --- | --- |
| Headless browser (Puppeteer/Playwright) para PDF | CSS completo, layout flexível | Dependência pesada, uso de memória alto, viola ADR-0001, difícil de atingir 80 ms | Rejeitada |
| Serviço externo de renderização | Escala e qualidade | Custo por carteirinha, latência de rede, dados pessoais saindo do tenant | Rejeitada |
| Somente raster (PNG/JPEG) | Implementação simples | Sem vetor 300 DPI para gráfica; falha requisito de impressão PVC | Rejeitada |
| GD + FPDF single-file + fila em banco (escolhida) | Nativo, rápido, portável, sem dados fora e compatível com hospedagem compartilhada | Requer gestão de fontes; texto limitado a Windows-1252; imposição A4 fica separada | Aceita |

## Consequências

**Positivas:** atende benchmarks e modo híbrido impresso/digital; preview do editor reusa o mesmo renderizador, evitando divergência entre tela e arte final.

**Negativas:** bibliotecas single-file precisam de auditoria e pinned version; falhas de fonte/estouro de texto exigem `dry_run` do MCP (docs/09) e validação no GATE 3.

## Como validar

- `CardWebpRenderTest.php`: WebP com mime `image/webp`, 1011×638 e QR/CODE128/EAN13 verificados por scanner independente.
- `CardPdfRenderTest.php`: tamanho/páginas CR80, texto português extraível por Poppler, foto recortada, QR e barras vetoriais; Poppler rasteriza a 300 DPI e `zbarimg` confirma a decodificação.
- GATE 3: benchmark de 80 ms/WebP e 3,5 s/PDF-50; QR do portal resolve na página pública. Revalidar o perfil no host de produção antes de definir lote operacional.
