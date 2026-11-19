<?php
declare(strict_types=1);
$h = static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$currentName = (string) ($_POST['nome_layout'] ?? ($design['nome_layout'] ?? ''));
$readonly = !$canSave;
?>
<!doctype html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Designs da carteirinha</title>
    <link rel="stylesheet" href="/assets/css/design-editor.css">
</head>
<body>
<main class="design-page">
    <header class="page-heading">
        <div><p class="eyebrow">ÁREA DA SEDE</p><h1>Designs da carteirinha</h1><p>Crie rascunhos no padrão CR80 e confira uma prévia com dados fictícios.</p></div>
        <a class="button secondary" href="/tenant/designs.php">Novo design</a>
    </header>
    <?php if ($notice !== ''): ?><p class="notice" role="status"><?= $h($notice) ?></p><?php endif; ?>
    <?php if ($error !== ''): ?><p class="error" role="alert"><?= $h($error) ?></p><?php endif; ?>
    <div class="design-layout">
        <aside class="catalog" aria-labelledby="catalog-title">
            <div class="section-heading"><h2 id="catalog-title">Seus designs</h2><?php if ($canCreate): ?><a href="/tenant/designs.php" aria-label="Criar novo design">＋ Novo</a><?php endif; ?></div>
            <?php if ($designs === []): ?><p class="muted">Ainda não há designs.</p><?php endif; ?>
            <ul class="design-list">
                <?php foreach ($designs as $item): ?>
                    <li><a class="design-link<?= $design !== null && (int) $design['id'] === (int) $item['id'] ? ' selected' : '' ?>" href="/tenant/designs.php?id=<?= (int) $item['id'] ?>">
                        <span><?= $h((string) $item['nome_layout']) ?></span><small><?= (int) $item['ativo'] === 1 ? 'Ativo' : 'Rascunho' ?></small>
                    </a></li>
                <?php endforeach; ?>
            </ul>
        </aside>
        <section class="editor" aria-labelledby="editor-title">
            <form method="post" enctype="multipart/form-data" id="design-form">
                <input type="hidden" name="csrf_token" value="<?= $h($csrfToken) ?>">
                <?php if ($design !== null): ?><input type="hidden" name="design_id" value="<?= (int) $design['id'] ?>"><?php endif; ?>
                <input type="hidden" name="mapa_campos_json" id="map-json" value="<?= $h(json_encode($map, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}') ?>">
                <input type="hidden" name="mapeamento_fixo_json" id="mapping-json" value="<?= $h(json_encode($mapping, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}') ?>">
                <div class="editor-top"><div><p class="eyebrow">EDITOR DE RASCUNHOS</p><h2 id="editor-title"><?= $design === null ? 'Novo design' : $h($currentName) ?></h2></div><span class="status-pill"><?= $design !== null && (int) $design['ativo'] === 1 ? 'Publicado · somente leitura' : 'Rascunho' ?></span></div>
                <label class="name-field">Nome do design<input name="nome_layout" maxlength="120" required value="<?= $h($currentName) ?>" <?= $readonly ? 'disabled' : '' ?>></label>
                <div class="canvas-toolbar">
                    <div class="side-switch" role="group" aria-label="Lado do cartão">
                        <button type="button" class="side-button active" data-side="frente" aria-pressed="true">Frente</button>
                        <button type="button" class="side-button" data-side="verso" aria-pressed="false">Verso</button>
                    </div>
                    <span class="canvas-size">CR80 · 1011 × 638 px · 300 DPI</span>
                    <div class="add-controls" aria-label="Adicionar elemento">
                        <button type="button" data-add="texto_dinamico" <?= $readonly ? 'disabled' : '' ?>>＋ Texto</button>
                        <button type="button" data-add="foto_titular" <?= $readonly ? 'disabled' : '' ?>>＋ Foto</button>
                        <button type="button" data-add="qrcode_validacao" <?= $readonly ? 'disabled' : '' ?>>＋ QR</button>
                        <button type="button" data-add="codigo_barras" <?= $readonly ? 'disabled' : '' ?>>＋ Barras</button>
                    </div>
                    <button type="button" class="preview-button" id="render-preview">Pré-visualizar</button>
                    <button type="button" class="preview-button" id="edit-canvas" hidden>Voltar ao editor</button>
                </div>
                <nav class="mobile-tabs" aria-label="Seções do editor">
                    <button type="button" class="mobile-tab active" data-editor-tab="fields" aria-pressed="true">Campos</button>
                    <button type="button" class="mobile-tab" data-editor-tab="background" aria-pressed="false">Fundo</button>
                    <button type="button" class="mobile-tab" data-editor-tab="settings" aria-pressed="false">Ajustes</button>
                </nav>
                <div class="workspace" data-editor-panel="fields">
                    <div class="canvas-column">
                        <div class="canvas" id="card-canvas" tabindex="0" role="group" aria-label="Canvas CR80. Selecione um elemento e use as setas para movê-lo.">
                            <?php foreach ($backgroundPreview as $side => $src): ?><img class="canvas-preview background-preview" data-preview-side="<?= $h($side) ?>" src="<?= $h(is_string($src) ? $src : 'data:image/gif;base64,R0lGODlhAQABAAD/ACwAAAAAAQABAAACADs=') ?>" alt=""<?= $side === 'verso' ? ' hidden' : '' ?>><?php endforeach; ?>
                            <?php foreach ($previewData as $side => $src): ?><img class="canvas-preview rendered-preview" data-rendered-side="<?= $h($side) ?>" src="<?= $h((string) $src) ?>" alt="Resultado do renderizador com dados fictícios, lado <?= $h($side) ?>" hidden><?php endforeach; ?>
                            <div class="safe-area" aria-hidden="true"></div><div id="canvas-elements"></div>
                        </div>
                        <p class="canvas-help">Arraste um elemento ou selecione e use as setas do teclado. Shift + seta move 10 px.</p>
                        <p id="preview-status" class="muted" role="status" aria-live="polite">A prévia do renderizador usa somente dados fictícios.</p>
                    </div>
                    <div class="properties">
                        <section><h3>Elementos</h3><ul id="element-list" class="element-list"></ul><p id="empty-elements" class="muted">Adicione um elemento para começar.</p></section>
                        <section><h3>Propriedades</h3><div id="property-fields" class="property-fields"><p class="muted">Selecione um elemento.</p></div></section>
                    </div>
                </div>
                <details class="advanced" data-editor-panel="settings"><summary>Mapeamento fixo (JSON)</summary><p>Opcional: variáveis podem receber valores fixos ou campos de origem conforme o contrato de mapeamento.</p><textarea id="mapping-editor" spellcheck="false" <?= $readonly ? 'disabled' : '' ?>></textarea><p id="mapping-error" class="field-error" aria-live="polite"></p></details>
                <div class="background-fields" data-editor-panel="background"><label>Fundo da frente <input type="file" name="fundo_frente" accept="image/png,image/jpeg,image/webp" <?= $readonly ? 'disabled' : '' ?>></label><label>Fundo do verso <input type="file" name="fundo_verso" accept="image/png,image/jpeg,image/webp" <?= $readonly ? 'disabled' : '' ?>></label><p>PNG, JPEG ou WebP · até 10 MB e 4 megapixels. Arquivos ficam em armazenamento privado.</p></div>
                <div class="form-actions"><span><?php if ($design !== null && (int) $design['ativo'] === 1): ?>Design ativo não pode ser alterado.<?php elseif ($hasPendingDesignJobs): ?>Aguarde as emissões deste design terminarem para editá-lo.<?php else: ?>Salvar mantém o design como rascunho.<?php endif; ?></span><?php if ($canSave): ?><button class="button primary" type="submit">Salvar design</button><?php endif; ?></div>
            </form>
        </section>
    </div>
</main>
<script>window.designEditorInitial = <?= json_encode(['map' => $map, 'mapping' => $mapping, 'editable' => !$readonly], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE) ?>;</script>
<script src="/assets/js/design-editor.js" defer></script>
</body>
</html>
