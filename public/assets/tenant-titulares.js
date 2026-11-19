'use strict';

document.querySelectorAll('form[data-disable-on-submit]').forEach((form) => {
    form.addEventListener('submit', (event) => {
        if (form.dataset.submitted === 'true') {
            event.preventDefault();
            return;
        }

        const targets = [...form.querySelectorAll('[data-mapping-target]')];
        if (targets.length > 0) {
            const chosen = targets.filter((target) => target.value !== '');
            const seen = new Set();
            let invalid = false;
            targets.forEach((target) => target.setCustomValidity(''));
            chosen.forEach((target) => {
                if (seen.has(target.value)) {
                    target.setCustomValidity('Escolha cada campo de destino uma única vez.');
                    invalid = true;
                }
                seen.add(target.value);
            });
            if (!chosen.some((target) => target.value === 'nome_completo')) {
                const first = targets[0];
                if (first) first.setCustomValidity('Mapeie uma coluna para Nome completo.');
                invalid = true;
            }
            if (invalid) {
                event.preventDefault();
                form.reportValidity();
                return;
            }
        }

        form.dataset.submitted = 'true';
        form.querySelectorAll('button[type="submit"]').forEach((button) => {
            button.disabled = true;
            button.dataset.originalText = button.textContent || '';
            button.textContent = 'Processando…';
        });
    });

    form.querySelectorAll('[data-mapping-target]').forEach((target) => {
        target.addEventListener('change', () => target.setCustomValidity(''));
    });
});
