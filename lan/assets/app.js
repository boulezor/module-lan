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
        $('pros').value = s.pros || '';
        $('cons').value = s.cons || '';
        // Nombre de joueurs : on ne remplace pas ce que la personne a déjà tapé
        if (s.players && !$('players').value.trim()) $('players').value = s.players;

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

// Vote : compteur « jeux notés » en direct et rappel des jeux oubliés
(() => {
    const form = document.getElementById('vote-form');
    const progress = document.getElementById('vote-progress');
    if (!form || !progress) return;

    const total = Number(progress.dataset.total);
    const update = () => {
        const done = new Set([...form.querySelectorAll('input[type=radio]:checked')].map((i) => i.name)).size;
        progress.innerHTML = `Jeux notés : <strong>${done}</strong> / ${total}` + (done < total ? ` — encore ${total - done} à noter` : ' ✔');
    };
    form.addEventListener('change', update);
    update();
})();

// Admin : tout cocher / décocher dans « Jeux de toutes les listes »
document.querySelectorAll('#lists-form [data-check]').forEach((button) => {
    button.addEventListener('click', () => {
        const checked = button.dataset.check === 'all';
        button.form.querySelectorAll('input[name="games[]"]:not(:disabled)').forEach((box) => { box.checked = checked; });
    });
});

// Descriptions longues : « Voir plus » seulement si le texte dépasse 6 lignes
const checkClamps = () => document.querySelectorAll('.desc-wrap').forEach((wrap) => {
    const desc = wrap.querySelector('.desc');
    if (desc.classList.contains('expanded')) return;
    wrap.querySelector('.more').hidden = desc.scrollHeight <= desc.clientHeight + 2;
});
checkClamps();
window.addEventListener('load', checkClamps);   // après images et polices
window.addEventListener('resize', checkClamps);
document.addEventListener('click', (event) => {
    const button = event.target.closest('.more');
    if (!button) return;
    event.preventDefault();
    const desc = button.closest('.desc-wrap').querySelector('.desc');
    const open = desc.classList.toggle('expanded');
    button.textContent = open ? 'Voir moins ▴' : 'Voir plus ▾';
});

// Admin : régénération des fiches du pot commun, une requête par jeu avec barre de progression
(() => {
    const box = document.getElementById('regen');
    if (!box) return;
    const button = box.querySelector('button');
    const progress = box.querySelector('.regen-progress');
    const bar = progress.querySelector('.bar span');
    const status = progress.querySelector('.regen-status');
    const ids = JSON.parse(box.dataset.ids);
    const titles = JSON.parse(box.dataset.titles);

    button.addEventListener('click', async () => {
        if (!confirm(button.dataset.confirmText)) return;
        button.disabled = true;
        progress.hidden = false;
        let done = 0;
        const failed = [];
        for (const [i, id] of ids.entries()) {
            status.textContent = `${i + 1} / ${ids.length} — ${titles[i]}…`;
            try {
                const body = new FormData();
                body.append('csrf', box.dataset.csrf);
                body.append('id', id);
                const res = await (await fetch('api/regenerate.php', { method: 'POST', body })).json();
                res.ok ? done++ : failed.push(`${titles[i]} (${res.error})`);
            } catch (err) {
                failed.push(`${titles[i]} (erreur réseau ou délai dépassé)`);
            }
            bar.style.width = `${Math.round(((i + 1) * 100) / ids.length)}%`;
        }
        status.innerHTML = `✔ Terminé : ${done} fiche${done > 1 ? 's' : ''} mise${done > 1 ? 's' : ''} à jour.`
            + (failed.length ? `<br>Non modifiés : ${failed.map((f) => f.replace(/</g, '&lt;')).join(', ')}` : '')
            + ' <a href="">Recharger la page</a>';
        button.disabled = false;
    });
})();
