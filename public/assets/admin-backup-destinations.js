document.addEventListener('DOMContentLoaded', () => {
  const status = document.getElementById('operation-status');
  document.querySelectorAll('form[data-pending-message]').forEach((form) => {
    form.addEventListener('submit', () => {
      status.textContent = form.dataset.pendingMessage || 'Processando…';
      form.setAttribute('aria-busy', 'true');
      form.querySelectorAll('button[type="submit"]').forEach((button) => {
        button.disabled = true;
        button.setAttribute('aria-disabled', 'true');
      });
    }, { once: true });
  });
});
