(() => {
    const form = document.querySelector('.installation-form');
    const hosted = document.getElementById('hosted-fields');
    const local = document.getElementById('local-fields');
    const unitSelect = document.getElementById('local-tenant');
    const unitCount = document.getElementById('active-unit-count');
    if (!form || !hosted || !local) return;

    const updateMode = () => {
        const mode = form.querySelector('input[name="modo"]:checked')?.value;
        hosted.hidden = mode !== 'hospedado';
        local.hidden = mode !== 'local_host';
        form.querySelectorAll('.mode-card').forEach((card) => {
            card.classList.toggle('selected', card.querySelector('input')?.checked === true);
        });
    };
    const updateUnitCount = () => {
        if (!unitSelect || !unitCount) return;
        const option = unitSelect.selectedOptions[0];
        const count = Number.parseInt(option?.dataset.units ?? '0', 10) || 0;
        unitCount.textContent = `${count} ${count === 1 ? 'unidade ativa' : 'unidades ativas'}`;
    };
    form.querySelectorAll('input[name="modo"]').forEach((radio) => radio.addEventListener('change', updateMode));
    unitSelect?.addEventListener('change', updateUnitCount);
    updateMode();
    updateUnitCount();
})();
