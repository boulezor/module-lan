// Bingo : fonctions partagées par le panneau de l'organisateur et l'écran de projection
(function () {
    'use strict';

    /* Jeton de sécurité : rafraîchi automatiquement (page ouverte toute une soirée) */
    const BingoCsrf = {
        token: '',
        async refresh() {
            const res = await fetch('api/game.php?action=csrf', { cache: 'no-store' }).catch(() => null);
            if (!res) return false;
            if (res.status === 401) {
                location.href = 'login.php?next=' + encodeURIComponent(location.pathname.split('/').pop() + location.search);
                return false;
            }
            const data = await res.json().catch(() => ({}));
            if (data.csrf) {
                BingoCsrf.token = data.csrf;
                document.querySelectorAll('input[name=csrf]').forEach((input) => { input.value = data.csrf; });
            }
            return !!data.csrf;
        },
    };

    /** Action de l'organisateur : renvoie l'état (ou { error }) */
    async function bingoAction(id, action, params = {}) {
        const send = () => fetch('api/game.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': BingoCsrf.token },
            body: JSON.stringify({ ...params, action, id }),
        });
        try {
            let res = await send();
            if (res.status === 403) {
                const body = await res.clone().json().catch(() => ({}));
                if (/jeton/i.test(body.error || '') && await BingoCsrf.refresh()) res = await send();
            }
            const data = await res.json().catch(() => ({ error: 'Réponse invalide du serveur.' }));
            if (res.status === 401) data.error = 'Session expirée : reconnecte-toi.';
            return data;
        } catch (e) {
            return { error: 'Connexion perdue : réessaie.' };
        }
    }

    /** Couleur d'une boule : par dizaine (90) ou par lettre B-I-N-G-O (75) */
    function colorClass(n, type) {
        return type === 75 ? 'l' + Math.floor((n - 1) / 15) : 'c' + Math.min(8, Math.floor(n / 10));
    }

    function letter(n) {
        return 'BINGO'[Math.floor((n - 1) / 15)];
    }

    function ballHtml(n, type, extra = '') {
        const top = type === 75 ? `<small>${letter(n)}</small>` : '';
        return `<span class="ball ${colorClass(n, type)} ${extra}"><span class="ball-face">${top}<b>${n}</b></span></span>`;
    }

    /** Grille de toutes les boules : 9 × 10 (90) ou 5 lignes B-I-N-G-O × 15 (75) */
    function buildBoard(container, type) {
        container.innerHTML = '';
        container.className = 'board board-' + type;
        if (type === 75) {
            for (let r = 0; r < 5; r++) {
                const head = document.createElement('div');
                head.className = 'board-letter l' + r;
                head.textContent = 'BINGO'[r];
                container.appendChild(head);
                for (let c = 1; c <= 15; c++) container.appendChild(cell(r * 15 + c));
            }
        } else {
            for (let n = 1; n <= 90; n++) container.appendChild(cell(n));
        }
        function cell(n) {
            const el = document.createElement('div');
            el.className = 'board-cell ' + colorClass(n, type);
            el.dataset.n = n;
            el.textContent = n;
            return el;
        }
    }

    function updateBoard(container, draws) {
        const drawn = new Set(draws);
        const last = draws[draws.length - 1];
        container.querySelectorAll('.board-cell').forEach((el) => {
            const n = +el.dataset.n;
            el.classList.toggle('on', drawn.has(n));
            el.classList.toggle('last', n === last);
        });
    }

    /** Carton vérifié : cases cochées si le numéro est sorti */
    function cardHtml(check, draws, type) {
        const drawn = new Set(draws);
        const rows = check.grid.map((row) => '<tr>' + row.map((n) => {
            if (n === null) return '<td class="blank"></td>';
            if (n === 0) return '<td class="free on">★</td>';
            return `<td class="${drawn.has(n) ? 'on ' + colorClass(n, type) : ''}">${n}</td>`;
        }).join('') + '</tr>').join('');
        const head = type === 75 ? '<thead><tr>' + [...'BINGO'].map((l, i) => `<th class="l${i}">${l}</th>`).join('') + '</tr></thead>' : '';
        return `<table class="bingo-card card-${type}">${head}<tbody>${rows}</tbody></table>`;
    }

    function cardSummary(check, type) {
        if (check.full) return '✅ Carton plein !';
        const lines = check.lines
            ? `✅ ${check.lines} ligne${check.lines > 1 ? 's' : ''} complète${check.lines > 1 ? 's' : ''} (${check.line_labels.join(', ')})`
            : '❌ Aucune ligne complète';
        return `${lines} · ${check.marked}/${check.total} numéros sortis`;
    }

    /* Annonce vocale (voix française du navigateur) */
    let voice = null;
    function pickVoice() {
        if (!('speechSynthesis' in window)) return;
        const voices = speechSynthesis.getVoices().filter((v) => /^fr/i.test(v.lang));
        voice = voices.find((v) => /fr-(FR|BE)/i.test(v.lang) && /google|natural|online|amélie|thomas/i.test(v.name))
            || voices.find((v) => /fr-(FR|BE)/i.test(v.lang)) || voices[0] || null;
    }
    if ('speechSynthesis' in window) {
        pickVoice();
        speechSynthesis.addEventListener?.('voiceschanged', pickVoice);
    }
    function speak(text) {
        if (!('speechSynthesis' in window)) return;
        speechSynthesis.cancel();
        const utterance = new SpeechSynthesisUtterance(text);
        utterance.lang = voice ? voice.lang : 'fr-FR';
        if (voice) utterance.voice = voice;
        utterance.rate = 0.9;
        speechSynthesis.speak(utterance);
    }

    /** Petit message temporaire en bas de l'écran */
    function toast(message, type = 'info') {
        let box = document.getElementById('toast');
        if (!box) {
            box = document.createElement('div');
            box.id = 'toast';
            document.body.appendChild(box);
        }
        const item = document.createElement('div');
        item.className = 'toast toast-' + type;
        item.textContent = message;
        box.appendChild(item);
        setTimeout(() => item.classList.add('out'), 2800);
        setTimeout(() => item.remove(), 3300);
    }

    function escapeHtml(text) {
        return String(text ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
    }

    window.Bingo = { BingoCsrf, bingoAction, colorClass, letter, ballHtml, buildBoard, updateBoard, cardHtml, cardSummary, speak, toast, escapeHtml };

    // Jeton rafraîchi en arrière-plan (toutes les 15 min, et au retour sur l'onglet)
    if (document.body.dataset.csrf !== undefined) {
        BingoCsrf.token = document.body.dataset.csrf;
        setInterval(() => { if (document.visibilityState === 'visible') BingoCsrf.refresh(); }, 15 * 60 * 1000);
        document.addEventListener('visibilitychange', () => { if (document.visibilityState === 'visible') BingoCsrf.refresh(); });
    }
})();
