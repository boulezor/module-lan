// Confirmation des actions destructrices (page Résultats)
document.querySelectorAll('form[data-confirm]').forEach((form) => {
    form.addEventListener('submit', (event) => {
        if (!confirm(form.dataset.confirm)) event.preventDefault();
    });
});

// Recherche automatique sur la page Proposer
(() => {
    const form = document.getElementById('propose-form');
    if (!form) return;

    const $ = (id) => document.getElementById(id);
    const title = $('title');
    const status = $('lookup-status');
    const submit = $('submit-btn');
    let controller = null;
    let lastQuery = '';

    const escapeHtml = (s) => s.replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
    const setStatus = (type, html) => { status.innerHTML = html ? `<div class="alert alert-${type}">${html}</div>` : ''; };

    function fill(s) {
        $('description').value = s.description || '';
        $('video_url').value = s.video_url || '';
        $('store_url').value = s.store_url || '';
        $('image_url').value = s.image_url || '';
        $('tags').value = s.tags || '';
        $('steam_appid').value = s.steam_appid || '';

        const img = $('preview-img');
        img.hidden = !s.image_url;
        if (s.image_url) img.src = s.image_url;
        $('preview-tags').innerHTML = (s.tags ? s.tags.split(', ') : []).map((t) => `<span>${escapeHtml(t)}</span>`).join('');
        $('suggestion').hidden = !s.image_url && !s.tags;
    }

    async function lookup() {
        const q = title.value.trim();
        if (q.length < 2 || q === lastQuery) return;
        lastQuery = q;

        controller?.abort();
        controller = new AbortController();
        setStatus('info', 'Recherche d’informations sur le jeu…');
        submit.disabled = true;

        try {
            const res = await fetch('api/lookup.php?q=' + encodeURIComponent(q), { signal: controller.signal });
            const data = await res.json();
            if (data.error) { setStatus('warn', escapeHtml(data.error)); return; }

            if (data.duplicate) {
                fill({});
                setStatus('error', `« ${escapeHtml(data.duplicate.title)} » a déjà été proposé par ${escapeHtml(data.duplicate.proposed_by)}. Tu pourras voter pour lui !`);
                return; // le bouton reste désactivé
            }

            const s = data.suggestion;
            fill(s);
            const messages = [];
            if (s.source) {
                messages.push(`Infos trouvées via ${escapeHtml(s.source)}. Vérifie et modifie si besoin.`);
            } else {
                messages.push('Aucune info trouvée automatiquement : complète la description toi-même (un lien de recherche YouTube a été ajouté).');
            }
            if (s.title && s.title.toLowerCase() !== q.toLowerCase()) {
                messages.push(`Nom officiel : <strong>${escapeHtml(s.title)}</strong> <button type="button" class="btn-link" id="use-official">utiliser ce nom</button>`);
            }
            if (data.similar.length) {
                const list = data.similar.map((g) => `« ${escapeHtml(g.title)} » (${escapeHtml(g.proposed_by)})`).join(', ');
                messages.push(`⚠️ Jeu(x) au nom proche déjà proposé(s) : ${list}. Vérifie que ce n’est pas le même !`);
            }
            setStatus(data.similar.length ? 'warn' : 'success', messages.join('<br>'));
            $('use-official')?.addEventListener('click', () => { title.value = s.title; lastQuery = s.title; });
            submit.disabled = false;
        } catch (err) {
            if (err.name === 'AbortError') return;
            setStatus('warn', 'La recherche automatique a échoué, tu peux remplir les champs à la main.');
            submit.disabled = false;
        }
    }

    $('lookup-btn').addEventListener('click', () => { lastQuery = ''; lookup(); });
    title.addEventListener('change', lookup);
    title.addEventListener('keydown', (e) => {
        if (e.key === 'Enter') { e.preventDefault(); lookup(); }
    });
    // Nouveau nom tapé : on réactive l'envoi (la vérification serveur reste la référence)
    title.addEventListener('input', () => { if (title.value.trim() !== lastQuery) submit.disabled = false; });
})();
