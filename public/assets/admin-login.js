(() => {
    const password = document.querySelector('#password');
    const toggle = document.querySelector('.password-toggle');

    if (!password || !toggle) return;

    toggle.addEventListener('click', () => {
        const reveal = password.type === 'password';
        password.type = reveal ? 'text' : 'password';
        toggle.textContent = reveal ? 'Ocultar' : 'Mostrar';
        toggle.setAttribute('aria-pressed', reveal ? 'true' : 'false');
        password.focus();
    });
})();
