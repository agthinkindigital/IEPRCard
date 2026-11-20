document.querySelectorAll('[data-card-artifact]').forEach((image) => {
    const status = image.closest('[data-card-image]')?.parentElement?.querySelector('[data-card-status]');
    if (!status) return;

    image.addEventListener('load', () => {
        status.textContent = image.alt + ' carregada.';
    }, { once: true });
    image.addEventListener('error', () => {
        status.textContent = 'Não foi possível carregar a imagem da carteirinha. Atualize a página para tentar novamente.';
    }, { once: true });

    if (image.complete) {
        status.textContent = image.naturalWidth > 0
            ? image.alt + ' carregada.'
            : 'Não foi possível carregar a imagem da carteirinha. Atualize a página para tentar novamente.';
    }
});
