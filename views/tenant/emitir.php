<?php
declare(strict_types=1);
$h = static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$designPayload = [];
foreach ($designs as $design) {
    $map = json_decode((string) $design['mapa_campos_json'], true);
    $mapping = json_decode((string) $design['mapeamento_fixo_json'], true);
    $designPayload[(string) $design['id']] = [
        'name' => (string) $design['nome_layout'], 'map' => is_array($map) ? $map : [],
        'fixed' => is_array($mapping) ? $mapping : ['versao_schema' => '1.0', 'mapeamentos' => new stdClass()],
    ];
}
?>
<!doctype html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Emitir carteirinhas</title>
    <link rel="stylesheet" href="/assets/css/card-issuance.css">
</head>
<body>
<main class="issuance-page">
    <header class="page-heading">
        <div><p class="eyebrow">CARTEIRINHAS</p><h1>Emitir carteirinhas</h1><p>Prepare uma solicitação e acompanhe o processamento na fila.</p></div>
        <a class="secondary-button" href="/tenant/titulares.php">Voltar para titulares</a>
    </header>
    <?php if ($notice !== ''): ?><p class="notice" role="status"><?= $h($notice) ?></p><?php endif; ?>
    <?php if ($error !== ''): ?><p class="error" role="alert"><?= $h($error) ?></p><?php endif; ?>
    <nav class="steps" aria-label="Etapas da emissão"><span data-step-label="1" aria-current="step">1 · Origem e design</span><span data-step-label="2">2 · Mapeamento</span><span data-step-label="3">3 · Revisão e fila</span></nav>
    <form method="post" id="issuance-form" class="issuance-card">
        <input type="hidden" name="csrf_token" value="<?= $h($csrfToken) ?>">
        <input type="hidden" name="idempotency_key" value="<?= $h($idempotencyKey) ?>">
        <input type="hidden" name="mapping_json" id="mapping-json" value="">
        <?php if ($units !== []): ?>
            <label>Unidade
                <select name="subcliente_id" id="unit-select" onchange="location.href='/tenant/emitir.php'+(this.value?'?subcliente_id='+encodeURIComponent(this.value):'')">
                    <option value="">Sede</option>
                    <?php foreach ($units as $unit): ?><option value="<?= $unit['id'] ?>" <?= $selectedUnitId === $unit['id'] ? 'selected' : '' ?>><?= $h($unit['name']) ?></option><?php endforeach; ?>
                </select>
            </label>
        <?php endif; ?>
        <section aria-labelledby="source-heading" data-wizard-step="1">
            <div class="section-heading"><div><p class="eyebrow">ETAPA 1</p><h2 id="source-heading">Origem e design</h2></div><span class="quota-pill">Cota: <?= $quota['available'] === null ? 'não configurada' : number_format($quota['available'], 0, ',', '.') . ' disponíveis' ?></span></div>
            <div class="form-grid">
                <label>Origem
                    <select name="source_option" id="source-select" required>
                        <option value="">Selecione um lote ou titular</option>
                        <?php if ($sources['lots'] !== []): ?><optgroup label="Lotes concluídos"><?php foreach ($sources['lots'] as $lot): ?><option value="lot:<?= (int) $lot['id'] ?>" data-kind="lot" data-quantity="<?= (int) $lot['quantidade'] ?>">Lote #<?= (int) $lot['id'] ?> · <?= (int) $lot['quantidade'] ?> titulares</option><?php endforeach; ?></optgroup><?php endif; ?>
                        <?php if ($sources['holders'] !== []): ?><optgroup label="Titulares ativos"><?php foreach ($sources['holders'] as $holder): ?><option value="holder:<?= (int) $holder['id'] ?>" data-kind="holder" data-quantity="1"><?= $h((string) $holder['nome_completo']) ?><?= is_string($holder['matricula'] ?? null) && $holder['matricula'] !== '' ? ' · ' . $h((string) $holder['matricula']) : '' ?></option><?php endforeach; ?></optgroup><?php endif; ?>
                    </select>
                    <small>Até 100 origens recentes aparecem aqui. Titulares inativos não podem ser emitidos.</small>
                    <?php if ($sources['lots'] === [] && $sources['holders'] === []): ?><small>Nenhum lote concluído ou titular ativo está disponível. <a href="/tenant/titulares.php">Cadastre um titular ou conclua uma importação</a> antes de emitir.</small><?php endif; ?>
                </label>
                <label>Design ativo
                    <select name="design_id" id="design-select" required>
                        <option value="">Selecione um design</option>
                        <?php foreach ($designs as $design): ?><option value="<?= (int) $design['id'] ?>"><?= $h((string) $design['nome_layout']) ?> · <?= $h((string) $design['orientacao']) ?></option><?php endforeach; ?>
                    </select>
                    <?php if ($designs === []): ?><small>Nenhum design ativo está disponível neste escopo.</small><?php endif; ?>
                </label>
                <label>Formato de saída
                    <select name="output_mode" id="output-mode"><option value="webp">WebP digital</option><option value="pdf_cr80">PDF CR80</option><option value="pdf_a4_8up">PDF A4 · 8 por folha</option><option value="pdf_a4_10up">PDF A4 · 10 por folha</option></select>
                </label>
            </div>
            <div class="step-actions"><span></span><button type="button" class="primary-button" id="to-mapping">Continuar para mapeamento</button></div>
        </section>
        <section aria-labelledby="mapping-heading" data-wizard-step="2" hidden>
            <div class="section-heading"><div><p class="eyebrow">ETAPA 2</p><h2 id="mapping-heading">Mapeamento</h2></div></div>
            <fieldset class="mapping-choice"><legend>Como preencher os campos?</legend>
                <label><input type="radio" name="mapping_mode" value="fixed" checked> Usar mapeamento do design</label>
                <label><input type="radio" name="mapping_mode" value="custom"> Personalizar para esta emissão</label>
            </fieldset>
            <div id="mapping-fields" class="mapping-fields" aria-live="polite"><p class="muted">Selecione um design para ver seus campos de texto.</p></div>
            <div class="preview-row"><button type="button" class="secondary-button" id="preview-button">Pré-visualizar</button><p id="preview-status" role="status">A prévia não cria job nem reserva cota.</p></div>
            <div id="preview-images" class="preview-images" hidden>
                <figure><img id="preview-front" alt="Prévia da frente da carteirinha"><figcaption>Frente</figcaption></figure>
                <figure><img id="preview-back" alt="Prévia do verso da carteirinha"><figcaption>Verso</figcaption></figure>
            </div>
            <div class="step-actions"><button type="button" class="secondary-button" data-step-back="1">Voltar</button><button type="button" class="primary-button" id="to-review">Continuar para revisão</button></div>
        </section>
        <section aria-labelledby="review-heading" class="review-panel" data-wizard-step="3" hidden>
            <div class="section-heading"><div><p class="eyebrow">ETAPA 3</p><h2 id="review-heading">Revisão e fila</h2></div></div>
            <p id="review-summary">Selecione origem, design e saída para revisar a quantidade.</p>
            <ul class="limits"><li>WebP: até 500 titulares por job.</li><li>PDF consolidado: até 50 titulares por job.</li></ul>
            <div class="step-actions"><button type="button" class="secondary-button" data-step-back="2">Voltar ao mapeamento</button>
            <button type="submit" name="action" value="enqueue" class="primary-button" <?= $sources['lots'] === [] && $sources['holders'] === [] || $designs === [] ? 'disabled' : '' ?>>Adicionar à fila</button>
            </div>
        </section>
    </form>
    <section class="jobs-panel" aria-labelledby="jobs-heading">
        <div class="section-heading"><div><p class="eyebrow">ACOMPANHAMENTO</p><h2 id="jobs-heading">Emissões recentes</h2></div><span class="muted">Atualize a página para consultar o estado mais recente.</span></div>
        <?php if ($recentJobs === []): ?><p class="muted">Nenhuma emissão foi enfileirada neste escopo.</p><?php else: ?>
            <div class="job-list">
                <?php foreach ($recentJobs as $job):
                    $statusLabels = ['na_fila' => 'Na fila', 'processando' => 'Processando', 'concluido' => 'Concluída', 'falhou' => 'Falhou'];
                    $statusLabel = $statusLabels[$job['status']] ?? 'Em andamento';
                ?>
                    <article class="job-item"><div><strong>Job #<?= $job['id'] ?> · <?= $h($job['design']) ?></strong><span><?= $job['quantity'] ?> titular(es) · <?= $h($job['created']) ?></span></div><span class="job-status status-<?= $h($job['status']) ?>" role="status"><?= $h($statusLabel) ?></span><?php if ($job['status'] === 'processando' || $job['status'] === 'na_fila'): ?><small>Progresso: <?= $job['done'] ?> de <?= $job['quantity'] ?></small><?php elseif ($job['status'] === 'falhou'): ?><small>Falha registrada; a cota desta emissão foi liberada. Peça ao administrador para revisar a solicitação.</small><?php else: ?><small>Processamento concluído: <?= $job['done'] ?> carteirinha(s).</small><?php endif; ?></article>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </section>
</main>
<script type="application/json" id="design-data"><?= json_encode($designPayload, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?></script>
<script>
(() => {
    const data = JSON.parse(document.getElementById('design-data').textContent || '{}');
    const form = document.getElementById('issuance-form');
    const source = document.getElementById('source-select');
    const design = document.getElementById('design-select');
    const mappingFields = document.getElementById('mapping-fields');
    const mapInput = document.getElementById('mapping-json');
    const fields = <?= json_encode($fieldOptions, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE) ?>;
    const selectedMode = () => form.querySelector('input[name="mapping_mode"]:checked').value;
    const rebuild = () => {
        const item = data[design.value];
        mappingFields.replaceChildren();
        if (!item) { mappingFields.innerHTML = '<p class="muted">Selecione um design para ver seus campos de texto.</p>'; return; }
        const fixed = item.fixed?.mapeamentos || {};
        const elements = Array.isArray(item.map?.elementos) ? item.map.elementos : [];
        const textElements = elements.filter(element => element.tipo === 'texto_dinamico' && !String(element.chave_variavel || '').startsWith('_sistema_'));
        if (textElements.length === 0) { mappingFields.innerHTML = '<p class="muted">Este design não tem campos de texto para mapear. Foto e QR são gerados pelo sistema.</p>'; return; }
        for (const element of textElements) {
            const key = String(element.chave_variavel || '');
            const row = document.createElement('fieldset'); row.className = 'mapping-row';
            const legend = document.createElement('legend'); legend.textContent = String(element.rotulo_amigavel || key) + (element.obrigatorio ? ' · obrigatório' : ' · opcional'); row.append(legend);
            const modeLabel = document.createElement('label'); modeLabel.textContent = 'Tipo de valor';
            const select = document.createElement('select'); select.dataset.target = key;
            for (const [value, label] of [['fixed', 'Mapeamento fixo'], ['field', 'Campo do titular'], ['template', 'Template'], ['value', 'Valor fixo']]) { const option = document.createElement('option'); option.value=value; option.textContent=label; select.append(option); }
            const definition = fixed[key];
            if (definition?.tipo === 'campo') select.value = 'field'; else if (definition?.tipo === 'template') select.value = 'template'; else if (definition?.tipo === 'valor') select.value = 'value';
            modeLabel.append(select); row.append(modeLabel);
            const fieldLabel = document.createElement('label'); fieldLabel.textContent = 'Campo do titular';
            const field = document.createElement('select'); field.dataset.field = key;
            for (const [path, label] of Object.entries(fields)) { const option=document.createElement('option'); option.value=path; option.textContent=label; field.append(option); }
            if (definition?.origem) field.value = definition.origem; fieldLabel.append(field); row.append(fieldLabel);
            const valueLabel = document.createElement('label'); valueLabel.textContent='Texto literal'; const value=document.createElement('input'); value.maxLength=8192; value.dataset.value=key; if (definition?.valor) value.value=definition.valor; valueLabel.append(value); row.append(valueLabel);
            const templateLabel=document.createElement('label'); templateLabel.textContent='Template'; const template=document.createElement('textarea'); template.rows=2; template.dataset.template=key; template.placeholder='Nome: [titular.nome_completo] · Matrícula: [titular.matricula]';
            if(definition?.tipo==='template') template.value=definition.partes.map(part=>typeof part==='string'?part:`[${part.campo}]`).join('');
            const templateTools=document.createElement('div'); templateTools.className='template-tools'; const templateField=document.createElement('select'); templateField.setAttribute('aria-label','Campo para inserir no template'); for(const [path,label] of Object.entries(fields)){const option=document.createElement('option');option.value=path;option.textContent=label;templateField.append(option);} const insertField=document.createElement('button'); insertField.type='button'; insertField.className='secondary-button'; insertField.textContent='Inserir campo'; insertField.addEventListener('click',()=>{template.setRangeText(`[${templateField.value}]`,template.selectionStart,template.selectionEnd,'end');template.focus();}); templateTools.append(templateField,insertField); templateLabel.append(template,templateTools); row.append(templateLabel);
            const hint=document.createElement('small'); hint.textContent='Campos de sistema, como foto e QR, não são editáveis.'; row.append(hint); mappingFields.append(row);
            select.addEventListener('change', () => { fieldLabel.hidden=select.value!=='field'; valueLabel.hidden=select.value!=='value'; templateLabel.hidden=select.value!=='template'; }); select.dispatchEvent(new Event('change'));
        }
        if (selectedMode() === 'fixed') mappingFields.classList.add('is-fixed'); else mappingFields.classList.remove('is-fixed');
    };
    const buildMapping = () => {
        const result={versao_schema:'1.0',mapeamentos:{}};
        for (const select of mappingFields.querySelectorAll('select[data-target]')) {
            const key=select.dataset.target; let definition=null;
            if(select.value==='fixed') definition=data[design.value]?.fixed?.mapeamentos?.[key] || null;
            if(select.value==='field') definition={tipo:'campo',origem:mappingFields.querySelector(`[data-field="${CSS.escape(key)}"]`)?.value || ''};
            if(select.value==='value') definition={tipo:'valor',valor:mappingFields.querySelector(`[data-value="${CSS.escape(key)}"]`)?.value || ''};
            if(select.value==='template') { const source=mappingFields.querySelector(`[data-template="${CSS.escape(key)}"]`)?.value || ''; const parts=[]; const pattern=/\[([A-Za-z_][A-Za-z0-9_.-]{0,119})\]/g; let cursor=0,match; while((match=pattern.exec(source))!==null){if(match.index>cursor)parts.push(source.slice(cursor,match.index));parts.push({campo:match[1]});cursor=pattern.lastIndex;} if(cursor<source.length)parts.push(source.slice(cursor)); if(parts.length===0 || /[\[\]]/.test(parts.filter(part=>typeof part==='string').join(''))) throw new Error('Confira os campos entre colchetes no template.'); definition={tipo:'template',partes:parts}; }
            if(definition) result.mapeamentos[key]=definition;
        }
        return result;
    };
    const updateReview = () => {
        const chosen=source.options[source.selectedIndex]; const amount=Number(chosen?.dataset.quantity || 0); const mode=document.getElementById('output-mode').selectedOptions[0]?.textContent || '';
        document.getElementById('review-summary').textContent = `${chosen?.textContent || 'Origem não selecionada'} · ${design.selectedOptions[0]?.textContent || 'Design não selecionado'} · ${amount} titular(es) · ${mode}`;
    };
    const showStep = step => { for(const section of form.querySelectorAll('[data-wizard-step]')) section.hidden=section.dataset.wizardStep!==String(step); for(const label of document.querySelectorAll('[data-step-label]')) {if(label.dataset.stepLabel===String(step)) label.setAttribute('aria-current','step'); else label.removeAttribute('aria-current');} document.querySelector(`[data-wizard-step="${step}"]`)?.scrollIntoView({behavior:'smooth',block:'start'}); };
    document.getElementById('to-mapping').addEventListener('click',()=>{if(source.value && design.value) showStep(2); else document.getElementById('preview-status').textContent='Selecione uma origem e um design para continuar.';});
    document.getElementById('to-review').addEventListener('click',()=>{try{if(selectedMode()==='custom') mapInput.value=JSON.stringify(buildMapping()); showStep(3);}catch(error){document.getElementById('preview-status').textContent=error.message;}});
    form.querySelectorAll('[data-step-back]').forEach(button=>button.addEventListener('click',()=>showStep(button.dataset.stepBack)));
    design.addEventListener('change', () => { rebuild(); updateReview(); document.getElementById('preview-images').hidden=true; }); source.addEventListener('change', () => { updateReview(); document.getElementById('preview-images').hidden=true; }); document.getElementById('output-mode').addEventListener('change', updateReview);
    form.querySelectorAll('input[name="mapping_mode"]').forEach(input => input.addEventListener('change', () => { mappingFields.classList.toggle('is-fixed', selectedMode()==='fixed'); }));
    document.getElementById('preview-button').addEventListener('click', async () => {
        const chosen=source.options[source.selectedIndex]; if(!chosen?.value || !design.value) { document.getElementById('preview-status').textContent='Selecione uma origem e um design antes de pré-visualizar.'; return; }
        try {
            const [kind,id]=chosen.value.split(':'); const payload=new FormData(form); payload.set('action','preview'); payload.set('source_type',kind); payload.set('source_id',id); payload.set('mapping_json',selectedMode()==='custom'?JSON.stringify(buildMapping()):'');
            document.getElementById('preview-status').textContent='Gerando prévia…';
            const response=await fetch(location.pathname+location.search,{method:'POST',body:payload,headers:{'Accept':'application/json'}}); const result=await response.json();
            if(!response.ok || !result.valid) throw new Error(result.error || 'Prévia indisponível.');
            document.getElementById('preview-front').src=result.images.frente; document.getElementById('preview-back').src=result.images.verso; document.getElementById('preview-images').hidden=false; document.getElementById('preview-status').textContent=result.message;
        } catch(error) { document.getElementById('preview-status').textContent=error.message || 'Não foi possível gerar a prévia.'; }
    });
    form.addEventListener('submit', event => { const chosen=source.options[source.selectedIndex]; if(!chosen?.value) { event.preventDefault(); return; } const [kind,id]=chosen.value.split(':'); const sourceType=document.createElement('input'); sourceType.type='hidden'; sourceType.name='source_type'; sourceType.value=kind; form.append(sourceType); const sourceId=document.createElement('input'); sourceId.type='hidden'; sourceId.name='source_id'; sourceId.value=id; form.append(sourceId); if(selectedMode()==='custom') { try { mapInput.value=JSON.stringify(buildMapping()); } catch(error) { event.preventDefault(); document.getElementById('preview-status').textContent=error.message; } } });
    rebuild(); updateReview(); showStep(<?= $error !== '' ? '3' : '1' ?>);
})();
</script>
</body>
</html>
