(() => {
    'use strict';
    const state = window.designEditorInitial;
    if (!state) return;
    const map = state.map;
    const list = document.getElementById('element-list');
    const empty = document.getElementById('empty-elements');
    const stage = document.getElementById('canvas-elements');
    const canvas = document.getElementById('card-canvas');
    const fields = document.getElementById('property-fields');
    const sideButtons = [...document.querySelectorAll('[data-side]')];
    const activeSide = {value: 'frente'};
    const localBackgroundUrls = {};
    let selected = null;
    let drag = null;
    let dirty = false;
    let previewMode = false;

    function setDirty() { dirty = true; setPreviewMode(false); }
    function setPreviewMode(enabled) {
        previewMode = enabled;
        document.querySelectorAll('.background-preview').forEach((image) => { image.hidden = enabled || image.dataset.previewSide !== activeSide.value; });
        document.querySelectorAll('.rendered-preview').forEach((image) => { image.hidden = !enabled || image.dataset.renderedSide !== activeSide.value; });
        document.getElementById('canvas-elements').hidden = enabled;
        document.querySelector('.safe-area').hidden = enabled;
        document.getElementById('render-preview').hidden = enabled;
        document.getElementById('edit-canvas').hidden = !enabled;
    }

    function items() { return map.elementos; }
    function label(item) { return item.rotulo_amigavel || item.chave_variavel || item.id; }
    function box(item) {
        const node = document.createElement('button');
        node.type = 'button';
        node.className = `canvas-element type-${item.tipo}${selected === item.id ? ' selected' : ''}`;
        node.style.left = `${item.x / 10.11}%`;
        node.style.top = `${item.y / 6.38}%`;
        node.style.width = `${(item.largura_max || item.largura || item.tamanho) / 10.11}%`;
        node.style.height = `${(item.altura || item.tamanho || 42) / 6.38}%`;
        node.textContent = item.tipo === 'foto_titular' ? 'Foto fictícia' : (item.tipo === 'qrcode_validacao' ? 'QR fictício' : (item.tipo === 'codigo_barras' ? '|||| ||| ||' : 'Prévia fictícia'));
        node.setAttribute('aria-label', `${label(item)}, posição ${item.x}, ${item.y}`);
        node.setAttribute('aria-pressed', selected === item.id ? 'true' : 'false');
        node.dataset.elementId = item.id;
        node.addEventListener('click', () => { selected = item.id; render(); });
        node.addEventListener('keydown', (event) => {
            if (!state.editable) return;
            if (!['ArrowUp', 'ArrowDown', 'ArrowLeft', 'ArrowRight'].includes(event.key)) return;
            event.preventDefault();
            const step = event.shiftKey ? 10 : 1;
            if (event.key === 'ArrowUp') item.y = Math.max(0, item.y - step);
            if (event.key === 'ArrowDown') item.y = Math.min(638 - height(item), item.y + step);
            if (event.key === 'ArrowLeft') item.x = Math.max(0, item.x - step);
            if (event.key === 'ArrowRight') item.x = Math.min(1011 - width(item), item.x + step);
            setDirty();
            render();
            stage.querySelector(`[data-element-id="${CSS.escape(item.id)}"]`)?.focus();
        });
        node.addEventListener('pointerdown', (event) => {
            if (!state.editable) return;
            selected = item.id;
            drag = {id: item.id, x: event.clientX, y: event.clientY, itemX: item.x, itemY: item.y};
            node.setPointerCapture(event.pointerId);
            stage.querySelectorAll('.canvas-element').forEach((entry) => entry.classList.toggle('selected', entry === node));
            renderList(); renderProperties();
        });
        node.addEventListener('pointermove', (event) => {
            if (!drag || drag.id !== item.id) return;
            const rect = canvas.getBoundingClientRect();
            item.x = Math.round(Math.max(0, Math.min(1011 - width(item), drag.itemX + (event.clientX - drag.x) * 1011 / rect.width)));
            item.y = Math.round(Math.max(0, Math.min(638 - height(item), drag.itemY + (event.clientY - drag.y) * 638 / rect.height)));
            node.style.left = `${item.x / 10.11}%`;
            node.style.top = `${item.y / 6.38}%`;
            node.setAttribute('aria-label', `${label(item)}, posição ${item.x}, ${item.y}`);
            setDirty(); renderList(); renderProperties(); syncJson();
        });
        node.addEventListener('pointerup', () => { drag = null; render(); });
        return node;
    }
    function width(item) { return item.largura_max || item.largura || item.tamanho || 1; }
    function height(item) { return item.altura || item.tamanho || 42; }
    function renderList() {
        list.replaceChildren();
        empty.hidden = items().length > 0;
        for (const item of items().filter((entry) => entry.lado === activeSide.value)) {
            const li = document.createElement('li');
            const button = document.createElement('button');
            button.type = 'button'; button.textContent = `${label(item)} · ${item.tipo}`;
            button.className = selected === item.id ? 'element-row active' : 'element-row';
            button.setAttribute('aria-pressed', selected === item.id ? 'true' : 'false');
            button.addEventListener('click', () => { selected = item.id; render(); stage.querySelector(`[data-element-id="${CSS.escape(item.id)}"]`)?.focus(); });
            li.append(button); list.append(li);
        }
    }
    function drawCanvas() {
        stage.replaceChildren();
        for (const item of items().filter((entry) => entry.lado === activeSide.value)) stage.append(box(item));
        document.querySelectorAll('[data-preview-side]').forEach((image) => { image.hidden = image.dataset.previewSide !== activeSide.value; });
    }
    function renderProperties() {
        const item = items().find((entry) => entry.id === selected);
        fields.replaceChildren();
        if (!item) { const text = document.createElement('p'); text.className = 'muted'; text.textContent = 'Selecione um elemento.'; fields.append(text); return; }
        const definitions = [
            ['rótulo', 'rotulo_amigavel', 'text'], ['variável', 'chave_variavel', 'text'],
            ['X', 'x', 'number'], ['Y', 'y', 'number'],
            [item.tipo === 'texto_dinamico' ? 'largura máxima' : 'largura', item.tipo === 'texto_dinamico' ? 'largura_max' : 'largura', 'number'],
            ...(item.tipo === 'texto_dinamico' ? [['tamanho da fonte (pt)', 'tamanho_pt', 'number'], ['cor', 'cor_hex', 'color']] : []),
            ...(item.tipo === 'foto_titular' || item.tipo === 'codigo_barras' ? [['altura', 'altura', 'number']] : []),
            ...(item.tipo === 'qrcode_validacao' ? [['tamanho', 'tamanho', 'number']] : []),
        ];
        for (const [caption, key, type] of definitions) {
            if (key === 'largura' && item.tipo === 'qrcode_validacao') continue;
            const labelNode = document.createElement('label'); labelNode.textContent = caption;
            const input = document.createElement('input'); input.type = type;
            if (key === 'cor_hex' && !item[key]) item[key] = '#263247';
            input.value = item[key] ?? (type === 'color' ? '#263247' : ''); input.disabled = !state.editable;
            if (type === 'number') { input.min = key === 'x' || key === 'y' ? '0' : '1'; input.step = '1'; }
            input.addEventListener('input', () => {
                item[key] = type === 'number' ? Number(input.value) : input.value;
                drawCanvas(); syncJson();
            });
            labelNode.append(input); fields.append(labelNode);
        }
        if (item.tipo === 'texto_dinamico') {
            for (const [caption, key, options] of [
                ['alinhamento', 'alinhamento', [['left', 'Esquerda'], ['center', 'Centro'], ['right', 'Direita']]],
                ['transformação', 'transformacao', [['none', 'Original'], ['uppercase', 'Maiúsculas'], ['lowercase', 'Minúsculas'], ['capitalize', 'Iniciais maiúsculas']]],
            ]) {
                const labelNode = document.createElement('label'); labelNode.textContent = caption;
                const select = document.createElement('select'); select.disabled = !state.editable;
                for (const [value, text] of options) { const option = document.createElement('option'); option.value = value; option.textContent = text; select.append(option); }
                select.value = item[key] || options[0][0];
                select.addEventListener('change', () => { item[key] = select.value; syncJson(); });
                labelNode.append(select); fields.append(labelNode);
            }
        }
        if (item.tipo === 'codigo_barras') {
            const labelNode = document.createElement('label'); labelNode.textContent = 'formato';
            const select = document.createElement('select'); select.disabled = !state.editable;
            for (const value of ['CODE128', 'EAN13']) { const option = document.createElement('option'); option.value = value; option.textContent = value; select.append(option); }
            select.value = item.formato || 'CODE128';
            select.addEventListener('change', () => { item.formato = select.value; syncJson(); setDirty(); });
            labelNode.append(select); fields.append(labelNode);
        }
    }
    function render() { renderList(); drawCanvas(); renderProperties(); syncJson(); }
    function syncJson() { document.getElementById('map-json').value = JSON.stringify(map); }
    function add(type) {
        const base = {id: `${type.replace(/[^a-z]/g, '')}${Date.now().toString(36)}`, lado: activeSide.value, tipo: type, chave_variavel: type === 'qrcode_validacao' ? '_sistema_url_validacao' : `campo_${items().length + 1}`, x: 60, y: 70};
        const item = type === 'texto_dinamico' ? {...base, largura_max: 320, fonte_arquivo: 'Inter-Regular.otf', tamanho_pt: 14, cor_hex: '#263247', alinhamento: 'left', transformacao: 'none', auto_reduzir_fonte_se_longo: true}
            : type === 'foto_titular' ? {...base, chave_variavel: 'foto', largura: 180, altura: 220}
                : type === 'qrcode_validacao' ? {...base, tamanho: 120}
                    : {...base, largura: 260, altura: 70, formato: 'CODE128'};
        items().push(item); selected = item.id; setDirty(); render();
    }
    sideButtons.forEach((button) => button.addEventListener('click', () => {
        setPreviewMode(false);
        activeSide.value = button.dataset.side;
        sideButtons.forEach((entry) => { const active = entry === button; entry.classList.toggle('active', active); entry.setAttribute('aria-pressed', String(active)); });
        selected = null; render();
    }));
    document.querySelectorAll('[data-add]').forEach((button) => button.addEventListener('click', () => add(button.dataset.add)));
    const mappingEditor = document.getElementById('mapping-editor');
    const mappingError = document.getElementById('mapping-error');
    mappingEditor.value = JSON.stringify(state.mapping, null, 2);
    mappingEditor.addEventListener('input', () => {
        setDirty();
        try { document.getElementById('mapping-json').value = JSON.stringify(JSON.parse(mappingEditor.value)); mappingError.textContent = ''; }
        catch { mappingError.textContent = 'O JSON do mapeamento fixo não está válido.'; }
    });
    document.getElementById('design-form').addEventListener('submit', (event) => {
        syncJson();
        try { document.getElementById('mapping-json').value = JSON.stringify(JSON.parse(mappingEditor.value)); }
        catch { event.preventDefault(); mappingError.textContent = 'Corrija o JSON do mapeamento fixo antes de salvar.'; mappingEditor.focus(); }
        if (!event.defaultPrevented) dirty = false;
    });
    document.getElementById('design-form').addEventListener('input', (event) => {
        if (event.target !== document.getElementById('map-json') && event.target !== document.getElementById('mapping-json')) setDirty();
    });
    document.getElementById('design-form').addEventListener('change', () => { setDirty(); });
    window.addEventListener('beforeunload', (event) => {
        if (!dirty) return;
        event.preventDefault(); event.returnValue = '';
    });
    document.getElementById('render-preview').addEventListener('click', async () => {
        const status = document.getElementById('preview-status');
        status.textContent = 'Gerando prévia fictícia…';
        const data = new FormData(document.getElementById('design-form'));
        data.set('action', 'preview');
        try {
            const response = await fetch(window.location.href, {method: 'POST', body: data, credentials: 'same-origin', headers: {'Accept': 'application/json'}});
            const result = await response.json();
            if (!response.ok || !result.images) throw new Error(result.error || 'preview_failed');
            document.querySelectorAll('.rendered-preview').forEach((image) => { image.src = result.images[image.dataset.renderedSide]; });
            setPreviewMode(true);
            status.textContent = 'Prévia gerada pelo renderizador com dados fictícios.';
        } catch {
            status.textContent = 'Não foi possível gerar a prévia. Revise o mapa e os fundos enviados.';
        }
    });
    document.getElementById('edit-canvas').addEventListener('click', () => setPreviewMode(false));
    for (const side of ['frente', 'verso']) {
        const input = document.querySelector(`[name="fundo_${side}"]`);
        input?.addEventListener('change', () => {
            const file = input.files?.[0];
            if (!file) return;
            if (localBackgroundUrls[side]) URL.revokeObjectURL(localBackgroundUrls[side]);
            localBackgroundUrls[side] = URL.createObjectURL(file);
            const image = document.querySelector(`[data-preview-side="${side}"]`);
            image.src = localBackgroundUrls[side];
            setDirty();
        });
    }
    document.querySelectorAll('[data-editor-tab]').forEach((button) => button.addEventListener('click', () => {
        const tab = button.dataset.editorTab;
        document.querySelectorAll('[data-editor-panel]').forEach((panel) => { panel.hidden = panel.dataset.editorPanel !== tab; });
        document.querySelectorAll('[data-editor-tab]').forEach((entry) => { const active = entry === button; entry.classList.toggle('active', active); entry.setAttribute('aria-pressed', String(active)); });
        setPreviewMode(false);
    }));
    if (window.matchMedia('(max-width: 600px)').matches) {
        document.querySelector('[data-editor-tab="fields"]')?.click();
    }
    render();
})();
