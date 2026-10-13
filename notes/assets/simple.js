// Pages simples (administration, compte) : confirmations et bouton « Copier »
document.querySelectorAll('form[data-confirm]').forEach((form) => {
    form.addEventListener('submit', (event) => {
        if (!confirm(form.dataset.confirm)) event.preventDefault();
    });
});
document.querySelectorAll('[data-copy]').forEach((button) => {
    button.addEventListener('click', async () => {
        const input = document.getElementById(button.dataset.copy);
        input.select();
        try { await navigator.clipboard.writeText(input.value); } catch { document.execCommand('copy'); }
        button.textContent = 'Copié ✔';
    });
});
