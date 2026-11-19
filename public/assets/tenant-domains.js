(() => {
    const status = document.querySelector('[data-copy-status]');
    for (const button of document.querySelectorAll('[data-copy-target]')) {
        button.addEventListener('click', async () => {
            const target = document.getElementById(button.dataset.copyTarget || '');
            if (!target || !status) return;

            try {
                await navigator.clipboard.writeText(target.textContent || '');
                status.textContent = 'Valor copiado.';
            } catch {
                status.textContent = 'Não foi possível copiar automaticamente. Selecione e copie o valor.';
            }
        });
    }
})();
