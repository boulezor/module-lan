// Confirmation des actions destructrices
document.querySelectorAll('form[data-confirm]').forEach((form) => {
    form.addEventListener('submit', (event) => {
        if (!confirm(form.dataset.confirm)) event.preventDefault();
    });
});

// Recherche automatique dans le formulaire « Ma liste »
(() => {
    const form = document.getElementById('game-form');
    if (!form) return;

    const $ = (id) => document.getElementById(id);
    const editing = form.dataset.mode === 'edit';
    const title = $('title');
    const status = $('lookup-status');
    const submit = $('submit-btn');
    let controller = null;
    let lastQuery = editing ? title.value.trim() : '';

    const escapeHtml = (s) => String(s).replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
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
            const url = 'api/lookup.php?q=' + encodeURIComponent(q) + '&exclude=' + encodeURIComponent($('game-id').value);
            const data = await (await fetch(url, { signal: controller.signal })).json();
            if (data.error) { setStatus('warn', escapeHtml(data.error)); submit.disabled = false; return; }

            if (data.in_list) {
                setStatus('error', `« ${escapeHtml(data.in_list.title)} » est déjà dans ta liste.`);
                return; // le bouton reste désactivé
            }

            const s = data.suggestion;
            fill(s);
            const messages = [];
            messages.push(s.source
                ? `Infos trouvées via ${escapeHtml(s.source)}. Vérifie et modifie si besoin.`
                : 'Aucune info trouvée automatiquement : complète la description toi-même (un lien de recherche YouTube a été ajouté).');
            if (s.title && s.title.toLowerCase() !== q.toLowerCase()) {
                messages.push(`Nom officiel : <strong>${escapeHtml(s.title)}</strong> <button type="button" class="btn-link" id="use-official">utiliser ce nom</button>`);
            }
            for (const p of data.proposed) {
                messages.push(`ℹ️ Déjà dans le pot commun de « ${escapeHtml(p.lan)} » (proposé par ${escapeHtml(p.proposed_by)}). Tu peux quand même le garder dans ta liste.`);
            }
            if (data.similar.length) {
                messages.push(`⚠️ Nom proche déjà dans ta liste : ${data.similar.map((g) => `« ${escapeHtml(g.title)} »`).join(', ')}.`);
            }
            setStatus(data.similar.length || !s.source ? 'warn' : 'success', messages.join('<br>'));
            $('use-official')?.addEventListener('click', () => { title.value = s.title; lastQuery = s.title; });
            submit.disabled = false;
        } catch (err) {
            if (err.name === 'AbortError') return;
            setStatus('warn', 'La recherche automatique a échoué, tu peux remplir les champs à la main.');
            submit.disabled = false;
        }
    }

    $('lookup-btn').addEventListener('click', () => { lastQuery = ''; lookup(); });
    // En modification, on ne relance pas la recherche toute seule (ça écraserait la description)
    if (!editing) title.addEventListener('change', lookup);
    title.addEventListener('keydown', (e) => {
        if (e.key === 'Enter') { e.preventDefault(); lookup(); }
    });
    title.addEventListener('input', () => { if (title.value.trim() !== lastQuery) submit.disabled = false; });
})();
