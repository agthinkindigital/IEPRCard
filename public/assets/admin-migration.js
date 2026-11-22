(() => {
    const body = document.body;
    const form = document.querySelector('#queue-form');
    const reviewed = document.querySelector('#reviewed');
    const confirmInput = document.querySelector('#confirm-copy');
    const dialog = document.querySelector('#confirm-dialog');
    const reviewButton = document.querySelector('#review-button');
    const confirmButton = document.querySelector('#confirm-button');
    const cancelButton = document.querySelector('#cancel-copy');

    reviewButton?.addEventListener('click', () => {
        if (!reviewed?.checked) {
            reviewed?.focus();
            return;
        }
        dialog?.showModal();
    });
    cancelButton?.addEventListener('click', () => dialog?.close());
    confirmButton?.addEventListener('click', () => {
        if (!reviewed?.checked || !confirmInput || !form) return;
        confirmInput.value = 'yes';
        form.requestSubmit();
    });

    const endpoint = body.dataset.stateEndpoint;
    const statusNode = document.querySelector('#job-status');
    const messageNode = document.querySelector('#job-message');
    const progressBar = document.querySelector('#progress-bar');
    const tableLabel = document.querySelector('#progress-tables');
    const recordLabel = document.querySelector('#progress-records');
    const errorNode = document.querySelector('.migration-error');
    const labels = {
        queued: ['Em fila', 'A cópia está na fila e será iniciada pelo worker CLI. Esta página pode ser fechada.'],
        running: ['Em execução', 'A cópia está em segundo plano. Esta página pode ser fechada; o estado é preservado.'],
        completed: ['Concluída', 'Cópia concluída e validada por contagem e checksum. A ativação do MySQL é uma etapa separada.'],
        failed: ['Falha', 'A execução foi encerrada. Confira a orientação abaixo e valide novamente antes de tentar outra vez.'],
        idle: ['Aguardando início', 'A cópia será executada em segundo plano. Esta página poderá ser fechada durante o processamento.'],
    };
    const format = (value) => new Intl.NumberFormat('pt-BR').format(Number(value) || 0);

    async function refreshStatus() {
        if (!endpoint || !['queued', 'running'].includes(body.dataset.state)) return;
        try {
            const response = await fetch(endpoint, { credentials: 'same-origin', headers: { Accept: 'application/json' } });
            if (!response.ok) return;
            const state = await response.json();
            body.dataset.state = state.status;
            if (statusNode) {
                const [label] = labels[state.status] || labels.idle;
                statusNode.textContent = label;
                statusNode.className = `job-chip state-${state.status}`;
            }
            if (messageNode) messageNode.textContent = (labels[state.status] || labels.idle)[1];
            if (progressBar) {
                const totalTables = Math.max(1, Number(state.total_tables) || 1);
                const doneTables = Math.max(0, Number(state.completed_tables) || 0);
                progressBar.max = totalTables;
                progressBar.value = doneTables;
                if (tableLabel) tableLabel.textContent = `${format(doneTables)} de ${format(totalTables)} tabelas verificadas`;
                if (recordLabel) recordLabel.textContent = `${format(state.completed_records)} de ${format(state.total_records)} registros processados`;
            }
            if (state.error && errorNode) errorNode.textContent = state.error;
            if (state.status === 'completed' || state.status === 'failed') window.location.reload();
        } catch {
            // A próxima consulta retoma a atualização sem alterar o estado do trabalho.
        }
    }

    if (['queued', 'running'].includes(body.dataset.state)) window.setInterval(refreshStatus, 5000);
})();
