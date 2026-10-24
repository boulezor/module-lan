// Panneau de l'organisateur : tirage, grille cliquable, phases, gagnants, vérification, message, réglages.
(function () {
    'use strict';
    const { bingoAction, ballHtml, buildBoard, updateBoard, cardHtml, cardSummary, toast, escapeHtml, letter } = window.Bingo;
    const id = +document.body.dataset.id;
    const $ = (sel) => document.getElementById(sel);
    const boardEl = $('p-board');
    const numberInput = $('p-number');

    let state = null;
    let busy = false;

    /* ---------- État ---------- */

    async function poll() {
        try {
            const res = await fetch(`api/game.php?action=state&id=${id}&rev=${state ? state.rev : 0}`, { cache: 'no-store' });
            if (res.status === 401) {
                location.href = 'login.php?next=' + encodeURIComponent('session.php?id=' + id);
                return;
            }
            const data = await res.json();
            if (!data.same && data.rev) apply(data);
        } catch (e) { /* nouvelle tentative au prochain tour */ }
        setTimeout(poll, document.hidden ? 4000 : 1500);
    }
    poll.now = () => { if (state) state.rev = -1; };

    async function act(action, params = {}) {
        if (busy && ['add', 'draw', 'undo'].includes(action)) return {};
        busy = true;
        const data = await bingoAction(id, action, params);
        busy = false;
        if (data.error) toast(data.error, 'error');
        if (data.rev) apply(data);
        return data;
    }

    function apply(next) {
        const prev = state;
        state = next;
        if (!prev || prev.type !== next.type) {
            buildBoard(boardEl, next.type);
            $('p-max').textContent = next.max;
            numberInput.placeholder = `1 à ${next.max}`;
        }
        const draws = next.draws;
        const last = draws[draws.length - 1];
        $('p-title').textContent = next.name;
        $('p-ball').innerHTML = last ? ballHtml(last, next.type, 'now-ball' + (prev && prev.draws.length < draws.length ? ' enter' : ''))
            : '<span class="ball now-ball empty"><span class="ball-face"><b>?</b></span></span>';
        $('p-label').textContent = last ? (next.type === 75 ? `${letter(last)} ${last}` : `Le ${last}`) : 'Aucune boule';
        $('p-count').textContent = draws.length;
        $('p-bar').style.width = (100 * draws.length / next.max) + '%';
        updateBoard(boardEl, draws);

        const undo = $('p-undo');
        undo.disabled = !last;
        undo.textContent = last ? `↶ Annuler la dernière boule (${last})` : '↶ Annuler la dernière boule';

        $('p-history').innerHTML = draws.length
            ? draws.map((n, i) => `<span class="h-item" title="${i + 1}ᵉ boule">${ballHtml(n, next.type)}<i>${i + 1}</i></span>`).reverse().join('')
            : '<p class="muted small">Aucune boule sortie.</p>';

        // Mode et tirage automatique
        document.body.classList.toggle('mode-auto', next.mode === 'auto');
        document.querySelectorAll('[data-mode]').forEach((tab) => tab.setAttribute('aria-selected', String(tab.dataset.mode === next.mode)));
        const autoBtn = $('p-auto');
        autoBtn.textContent = next.auto.running ? '⏸ Mettre en pause' : '▶ Lancer le tirage automatique';
        autoBtn.classList.toggle('running', next.auto.running);
        const interval = $('p-interval');
        if (document.activeElement !== interval) {
            interval.value = next.auto.interval;
            $('p-interval-val').textContent = next.auto.interval;
        }
        const progress = $('p-auto-progress');
        progress.hidden = !next.auto.running;
        if (next.auto.running && next.auto.next_in !== null) {
            const fill = progress.querySelector('span');
            fill.style.transition = 'none';
            fill.style.width = Math.max(0, 100 * (1 - next.auto.next_in / (next.auto.interval * 1000))) + '%';
            fill.getBoundingClientRect();
            fill.style.transition = `width ${next.auto.next_in}ms linear`;
            fill.style.width = '100%';
        }
        document.body.classList.toggle('auto-running', next.auto.running);

        // Phase et gagnants
        const phase = next.phase;
        $('p-phase-name').textContent = phase.name;
        $('p-phase-prize').textContent = phase.prize ? '🎁 ' + phase.prize : '';
        $('p-phase-pos').textContent = `Phase ${phase.index + 1} / ${phase.count}`;
        $('p-phase-prev').disabled = phase.index === 0;
        $('p-phase-next').disabled = phase.index >= phase.count - 1;
        $('p-winners').innerHTML = next.winners.length ? '<h3>🏆 Gagnants</h3><ul class="winners">' + next.winners.map((w) => `<li>
                <span><b>${escapeHtml(w.phase)}</b> · ${escapeHtml(w.name || 'Sans nom')}${w.card ? ` <span class="muted">(grille ${w.card})</span>` : ''}
                <small class="muted">${w.draws} boules · ${w.time}${w.prize ? ' · 🎁 ' + escapeHtml(w.prize) : ''}</small></span>
                <button type="button" class="icon-btn" data-winner="${w.id}" title="Retirer">✕</button></li>`).join('') + '</ul>' : '';

        // Planches imprimées
        if (next.runs) {
            $('p-print-hint').textContent = `Prochaine série : grilles numérotées à partir du n° ${next.next_card}.`;
            $('p-runs').innerHTML = next.runs.length ? '<ul class="runs">' + next.runs.map((r) => `<li>
                <span><b>${r.sheets} planche${r.sheets > 1 ? 's' : ''} de ${r.per} grille${r.per > 1 ? 's' : ''}</b>
                <small class="muted">n° ${r.from} à ${r.to} · ${r.date}</small></span>
                <a class="btn btn-small" target="_blank" rel="noopener" href="cartons.php?id=${id}&from=${r.from}&to=${r.to}&per=${r.per}">Réimprimer</a>
                <button type="button" class="icon-btn" data-run="${r.id}" title="Supprimer la série">✕</button></li>`).join('') + '</ul>' : '';
        }

        // Ce qui est affiché par-dessus l'écran
        const overlay = next.overlay;
        $('p-overlay').hidden = !overlay;
        if (overlay) {
            $('p-overlay-text').textContent = '📺 À l\'écran : ' + ({
                bingo: `BINGO ! ${overlay.phase}${overlay.name ? ' – ' + overlay.name : ''}`,
                card: `vérification de la grille n° ${overlay.card}`,
                message: `« ${overlay.text} »`,
            }[overlay.kind] || '');
        }
    }

    /* ---------- Tirage ---------- */

    function addNumber(value) {
        const n = parseInt(value, 10);
        if (!n) return;
        if (n < 1 || n > state.max) {
            toast(`Numéro hors limites (1 à ${state.max}).`, 'error');
            return;
        }
        if (state.draws.includes(n)) {
            toast(`Le ${n} est déjà sorti.`, 'error');
            return;
        }
        act('add', { number: n });
    }

    $('p-manual').addEventListener('submit', (event) => {
        event.preventDefault();
        addNumber(numberInput.value);
        numberInput.value = '';
    });
    $('p-keypad').addEventListener('click', (event) => {
        const key = event.target.closest('[data-k]')?.dataset.k;
        if (!key) return;
        if (key === 'back') numberInput.value = numberInput.value.slice(0, -1);
        else if (key === 'ok') { addNumber(numberInput.value); numberInput.value = ''; }
        else if (numberInput.value.length < 2) numberInput.value += key;
    });
    numberInput.addEventListener('input', () => { numberInput.value = numberInput.value.replace(/\D/g, '').slice(0, 2); });

    boardEl.addEventListener('click', (event) => {
        const cell = event.target.closest('.board-cell');
        if (!cell || !state) return;
        const n = +cell.dataset.n;
        if (state.draws.includes(n)) {
            if (confirm(`Retirer le ${n} des boules sorties ?`)) act('remove', { number: n });
        } else {
            act('add', { number: n });
        }
    });

    $('p-draw').addEventListener('click', () => act('draw'));
    $('p-undo').addEventListener('click', async () => {
        const data = await act('undo');
        if (data.undone) toast(`Boule ${data.undone} annulée.`);
    });
    $('p-auto').addEventListener('click', () => act('auto', { running: !state.auto.running }));
    $('p-interval').addEventListener('input', (event) => { $('p-interval-val').textContent = event.target.value; });
    $('p-interval').addEventListener('change', (event) => act('interval', { interval: +event.target.value }));
    document.querySelectorAll('[data-mode]').forEach((tab) => tab.addEventListener('click', () => {
        if (state && state.mode !== tab.dataset.mode) act('mode', { mode: tab.dataset.mode });
    }));

    // Clavier : chiffres + Entrée = numéro sorti ; Espace = boule au hasard (mode auto) ; Ctrl+Z = annuler
    document.addEventListener('keydown', (event) => {
        const tag = event.target.tagName;
        if (['INPUT', 'TEXTAREA', 'SELECT'].includes(tag) || document.querySelector('dialog[open]')) return;
        if ((event.ctrlKey || event.metaKey) && event.key === 'z') {
            event.preventDefault();
            $('p-undo').click();
        } else if (/^[0-9]$/.test(event.key) && !event.ctrlKey && !event.metaKey) {
            if (state.mode !== 'manual') act('mode', { mode: 'manual' });
            numberInput.focus();
            numberInput.value = event.key;
            event.preventDefault();
        } else if (event.key === ' ' && state.mode === 'auto' && tag !== 'BUTTON') {
            event.preventDefault();
            act('draw');
        }
    });

    /* ---------- Phases et gagnants ---------- */

    $('p-phase-prev').addEventListener('click', () => act('phase', { index: state.phase.index - 1 }));
    $('p-phase-next').addEventListener('click', () => act('phase', { index: state.phase.index + 1 }));

    const dialog = $('p-winner-dialog');
    function openWinner(card) {
        const form = $('p-winner-form');
        form.reset();
        if (card) form.card.value = card;
        $('p-winner-phase').textContent = state.phase.name + (state.phase.prize ? ' · 🎁 ' + state.phase.prize : '');
        dialog.showModal();
        form.name.focus();
    }
    $('p-bingo').addEventListener('click', () => openWinner(null));
    $('p-winner-cancel').addEventListener('click', () => dialog.close());
    $('p-winner-form').addEventListener('submit', (event) => {
        if (event.submitter && event.submitter.value === 'cancel') return;
        const form = event.target;
        act('winner', { name: form.name.value, card: +form.card.value || null });
    });
    $('p-winners').addEventListener('click', (event) => {
        const button = event.target.closest('[data-winner]');
        if (button && confirm('Retirer ce gagnant de la liste ?')) act('winner_delete', { winner: +button.dataset.winner });
    });

    /* ---------- Vérification d'un carton ---------- */

    async function check(show) {
        const card = +$('p-check-no').value;
        if (!card) { $('p-check-no').focus(); return; }
        const data = await act('check', { card, show });
        const box = $('p-check-result');
        if (!data.check) { box.innerHTML = ''; return; }
        const c = data.check;
        const missing = c.full ? '' : `<p class="muted small">Pas encore sortis : ${c.missing.join(', ')}</p>`;
        box.innerHTML = `<div class="check-card">${cardHtml(c, state.draws, state.type)}</div>
            <p class="check-summary ${c.lines || c.full ? 'ok' : 'ko'}">${cardSummary(c, state.type)}</p>${missing}
            <div class="row">${show ? '' : '<button type="button" class="btn btn-small" data-check="show">📺 Afficher à l\'écran</button>'}
            <button type="button" class="btn btn-small btn-bingo" data-check="win">🎉 Valider le gagnant</button></div>`;
    }
    $('p-check').addEventListener('submit', (event) => { event.preventDefault(); check(false); });
    $('p-check-show').addEventListener('click', () => check(true));
    $('p-check-result').addEventListener('click', (event) => {
        const what = event.target.closest('[data-check]')?.dataset.check;
        if (what === 'show') check(true);
        if (what === 'win') openWinner(+$('p-check-no').value);
    });

    /* ---------- Planches ---------- */

    $('p-print').addEventListener('submit', () => setTimeout(() => poll.now(), 1500));
    $('p-runs').addEventListener('click', (event) => {
        const button = event.target.closest('[data-run]');
        if (button && confirm('Supprimer cette série de la liste ? Ses numéros pourront être réattribués à une prochaine série : à faire seulement si ces planches n\'ont pas été distribuées.')) {
            act('run_delete', { run: +button.dataset.run });
        }
    });

    /* ---------- Message et incrustations ---------- */

    $('p-message').addEventListener('submit', (event) => {
        event.preventDefault();
        const text = $('p-message-text').value.trim();
        if (text) act('message', { text });
    });
    document.querySelectorAll('[data-message]').forEach((chip) => chip.addEventListener('click', () => {
        $('p-message-text').value = chip.dataset.message;
        act('message', { text: chip.dataset.message });
    }));
    $('p-overlay-clear').addEventListener('click', () => act('overlay_clear'));

    /* ---------- Écran, réglages, réinitialisation ---------- */

    document.querySelectorAll('[data-copy]').forEach((button) => button.addEventListener('click', async () => {
        const input = $(button.dataset.copy);
        input.select();
        try { await navigator.clipboard.writeText(input.value); } catch (e) { document.execCommand('copy'); }
        button.textContent = 'Copié ✔';
        setTimeout(() => { button.textContent = 'Copier'; }, 2000);
    }));
    $('p-new-link').addEventListener('click', async () => {
        if (!confirm("Créer un nouveau lien d'écran ? L'ancien lien ne fonctionnera plus (à rouvrir sur le projecteur).")) return;
        const data = await act('new_link');
        if (data.display_url) {
            $('p-display-url').value = data.display_url;
            $('p-display-open').href = data.display_url;
            document.querySelectorAll('a[href*="ecran.php"]').forEach((a) => { a.href = data.display_url; });
            toast('Nouveau lien créé.');
        }
    });

    $('p-settings').addEventListener('submit', async (event) => {
        event.preventDefault();
        const form = event.target;
        const data = await act('settings', {
            name: form.name.value,
            type: +form.type.value,
            theme: form.theme.value,
            voice: form.voice.checked,
            suspense: form.suspense.checked,
            phases: form.phases.value,
        });
        if (!data.error && data.rev) {
            form.phases.value = data.phases.map((p) => p.name + (p.prize ? ' | ' + p.prize : '')).join('\n');
            toast('Réglages enregistrés ✔');
        }
        if (data.error && state) form.querySelector(`input[name=type][value="${state.type}"]`).checked = true;
    });

    $('p-reset').addEventListener('click', async () => {
        if (!confirm('Réinitialiser la partie ? Les boules sorties et les gagnants seront effacés.')) return;
        const data = await act('reset');
        if (!data.error) {
            $('p-check-result').innerHTML = '';
            toast('Nouvelle partie prête 🎱');
        }
    });

    poll();
})();
