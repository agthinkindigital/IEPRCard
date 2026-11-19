(() => {
    const body = document.body;
    const checkbox = document.querySelector('#confirm-backup');
    const button = document.querySelector('#queue-button');
    const statusNode = document.querySelector('#job-status');
    const messageNode = document.querySelector('#job-message');
    const endpoint = body.dataset.stateEndpoint;
    const canQueue = body.dataset.canQueue === 'true';
    const labels = {
        idle: ['Aguardando início', 'Confirme o backup restaurável para enfileirar esta release.'],
        queued: ['Em fila', 'A execução será iniciada pelo worker agendado; você pode sair desta página.'],
        running: ['Em execução', 'A atualização está em segundo plano; o estado permanece disponível ao retornar.'],
        completed: ['Concluída', 'As migrations foram executadas e o schema canônico foi verificado.'],
        failed: ['Falha', 'A execução não foi concluída. Revise o resultado e confirme o schema antes de repetir.'],
        unknown: ['Estado indisponível', 'Não foi possível ler o estado operacional da atualização.'],
    };

    function updateButton() {
        if (button && checkbox) button.disabled = !canQueue || !checkbox.checked;
    }
    checkbox?.addEventListener('change', updateButton);
    updateButton();

    async function refreshStatus() {
        if (!endpoint || !['queued', 'running'].includes(body.dataset.state)) return;
        try {
            const response = await fetch(endpoint, { credentials: 'same-origin', headers: { Accept: 'application/json' } });
            if (!response.ok) return;
            const state = await response.json();
            body.dataset.state = state.status;
            if (statusNode) {
                statusNode.textContent = (labels[state.status] || labels.unknown)[0];
                statusNode.className = `job-chip state-${state.status}`;
            }
            if (messageNode) messageNode.textContent = (labels[state.status] || labels.unknown)[1];
            if (state.status === 'completed' || state.status === 'failed') window.location.reload();
        } catch {
            // The next poll reads the persisted status again.
        }
    }

    if (['queued', 'running'].includes(body.dataset.state)) window.setInterval(refreshStatus, 5000);
})();
