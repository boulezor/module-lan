// Écran de projection : se met à jour tout seul (~1 fois par seconde), animations, voix, confettis.
(function () {
    'use strict';
    const { bingoAction, ballHtml, buildBoard, updateBoard, cardHtml, cardSummary, speak, toast, escapeHtml, letter } = window.Bingo;
    const body = document.body;
    const token = body.dataset.token;
    const gameId = body.dataset.id ? +body.dataset.id : null;
    const $ = (id) => document.getElementById(id);
    const boardEl = $('s-board');

    let state = null;
    let shownDraws = [];      // boules affichées (la dernière peut être en cours d'animation)
    let revealTimer = null;
    let overlayKey = '';
    let audioOn = false;
    let soundMuted = false;
    let audioCtx = null;

    /* ---------- Mise à jour ---------- */

    async function poll() {
        try {
            const res = await fetch(`api/game.php?action=state&t=${token}&rev=${state ? state.rev : 0}`, { cache: 'no-store' });
            const data = await res.json();
            $('s-offline').hidden = true;
            if (res.status === 404) {
                showOverlay({ kind: 'message', text: data.error || 'Écran introuvable.' });
                return;
            }
            if (!data.same) apply(data);
        } catch (e) {
            $('s-offline').hidden = false;
        } finally {
            setTimeout(poll, document.hidden ? 3000 : 1000);
        }
    }

    function apply(next) {
        const prev = state;
        state = next;
        if (!prev || prev.type !== next.type) {
            buildBoard(boardEl, next.type);
            $('s-max').textContent = '/ ' + next.max;
        }
        if (!prev || prev.theme !== next.theme) {
            body.className = body.className.replace(/theme-\S+/, 'theme-' + next.theme);
        }
        $('s-name').textContent = next.name;
        // Thème « École Saint-Martin » : logo et titre de l'école
        const school = next.theme === 'saintmartin' ? next.school : null;
        $('s-brand').hidden = !school;
        $('s-brand').textContent = school ? school.title : '';
        for (const img of [$('s-logo'), $('s-stage-logo')]) {
            img.hidden = !(school && school.logo);
            if (school && school.logo && img.getAttribute('src') !== school.logo) img.src = school.logo;
        }
        if (school) {
            body.style.setProperty('--sm-1', school.color1);
            body.style.setProperty('--sm-2', school.color2);
        }
        document.title = next.name + ' · Bingo';
        const phase = next.phase;
        $('s-phase').innerHTML = `<span class="phase-label">On joue</span> <b>${escapeHtml(phase.name)}</b>`
            + (phase.prize ? ` <span class="prize">🎁 ${escapeHtml(phase.prize)}</span>` : '');

        // Nouvelle boule : exactement une de plus que ce qui est affiché → animation
        const draws = next.draws;
        const isNew = prev && draws.length === shownDraws.length + 1 && shownDraws.every((n, i) => n === draws[i]);
        clearTimeout(revealTimer);
        if (isNew) {
            const n = draws[draws.length - 1];
            const suspense = next.suspense && next.last_src !== 'manual';
            if (suspense) {
                renderDraws(shownDraws, false);
                spin(n);
                revealTimer = setTimeout(() => reveal(draws), 2000);
            } else {
                reveal(draws);
            }
        } else {
            renderDraws(draws, false);
        }

        // Barre du tirage automatique
        const bar = $('s-auto');
        bar.hidden = !next.auto.running;
        if (next.auto.running && next.auto.next_in !== null) {
            const fill = bar.querySelector('span');
            const total = next.auto.interval * 1000;
            fill.style.transition = 'none';
            fill.style.width = Math.max(0, 100 * (1 - next.auto.next_in / total)) + '%';
            fill.getBoundingClientRect();
            fill.style.transition = `width ${next.auto.next_in}ms linear`;
            fill.style.width = '100%';
        }
        const autoBtn = $('s-auto-btn');
        if (autoBtn) autoBtn.textContent = next.auto.running ? '⏸ Pause' : '▶ Auto';

        // Incrustation (BINGO, carton vérifié, message)
        const key = JSON.stringify(next.overlay) + (next.overlay && next.overlay.kind === 'card' ? draws.length : '');
        if (key !== overlayKey) {
            const first = overlayKey === '' && !prev;
            overlayKey = key;
            showOverlay(next.overlay, first);
        }
    }

    function renderDraws(draws, animate) {
        shownDraws = draws.slice();
        const type = state.type;
        const last = draws[draws.length - 1];
        updateBoard(boardEl, draws);
        $('s-count').textContent = draws.length;
        const ball = $('s-ball');
        if (last) {
            ball.innerHTML = ballHtml(last, type, 'big' + (animate ? ' enter' : ''));
            $('s-label').textContent = type === 75 ? `${letter(last)} ${last}` : `Le ${last}`;
        } else {
            ball.innerHTML = '<span class="ball big empty"><span class="ball-face"><b>?</b></span></span>';
            $('s-label').textContent = 'En attente du premier tirage…';
        }
        $('s-recent').innerHTML = draws.slice(-7, -1).reverse().map((n, i) => ballHtml(n, type, i === 0 ? 'r-first' : '')).join('');
    }

    /** Suspense : la boule tourne et affiche des numéros au hasard */
    function spin(n) {
        const type = state.type;
        const ball = $('s-ball');
        ball.innerHTML = ballHtml(n, type, 'big spinning');
        const face = ball.querySelector('.ball-face b');
        const tick = setInterval(() => {
            if (!ball.querySelector('.spinning')) return clearInterval(tick);
            face.textContent = 1 + Math.floor(Math.random() * state.max);
        }, 90);
        $('s-label').textContent = 'Tirage…';
        sound('tick');
    }

    function reveal(draws) {
        renderDraws(draws, true);
        const n = draws[draws.length - 1];
        sound('pop');
        {
            const digits = n > 9 ? '. ' + String(n).split('').join(', ') : '';
            say(state.type === 75 ? `${letter(n)}, ${n}${digits}` : `Le ${n}${digits}`);
        }
        const cell = boardEl.querySelector(`[data-n="${n}"]`);
        if (cell) {
            cell.classList.remove('flash');
            cell.getBoundingClientRect();
            cell.classList.add('flash');
        }
    }

    /* ---------- Incrustations ---------- */

    function showOverlay(overlay, silent) {
        const box = $('s-overlay');
        stopConfetti();
        if (!overlay) {
            box.hidden = true;
            box.innerHTML = '';
            return;
        }
        box.hidden = false;
        box.className = 'overlay overlay-' + overlay.kind;
        if (overlay.kind === 'bingo') {
            const who = overlay.name ? `<div class="bingo-name">${escapeHtml(overlay.name)}</div>` : '';
            const card = overlay.card ? `<div class="bingo-card-no">Grille n° ${overlay.card}</div>` : '';
            const prize = overlay.prize ? `<div class="bingo-prize">🎁 ${escapeHtml(overlay.prize)}</div>` : '';
            const logo = state.school && state.school.logo && state.theme === 'saintmartin' ? `<img class="bingo-logo" src="${escapeHtml(state.school.logo)}" alt="">` : '';
            box.innerHTML = `<div class="bingo-burst">${logo}<div class="bingo-word">${[...'BINGO'].map((l, i) => `<span style="--i:${i}">${l}</span>`).join('')}<span class="bang" style="--i:5">!</span></div>`
                + `<div class="bingo-phase">${escapeHtml(overlay.phase)}</div>${who}${card}${prize}</div>`;
            if (!silent) {
                confetti();
                sound('fanfare');
                say(`Bingo ! ${overlay.phase}${overlay.name ? ' pour ' + overlay.name : ''} !`);
            }
        } else if (overlay.kind === 'card' && overlay.check) {
            const check = overlay.check;
            box.innerHTML = `<div class="card-show"><h2>Vérification de la grille n° ${check.card}</h2>`
                + cardHtml(check, state.draws, state.type)
                + `<p class="card-result ${check.lines || check.full ? 'ok' : 'ko'}">${cardSummary(check, state.type)}</p></div>`;
        } else if (overlay.kind === 'message') {
            box.innerHTML = `<div class="message-show">${escapeHtml(overlay.text).replace(/\n/g, '<br>')}</div>`;
        } else {
            box.hidden = true;
        }
    }

    /* Confettis (canvas) */
    let confettiFrame = null;
    function confetti() {
        const canvas = $('confetti');
        const ctx = canvas.getContext('2d');
        canvas.width = innerWidth;
        canvas.height = innerHeight;
        const colors = ['#ffd54f', '#e53935', '#43a047', '#1e88e5', '#ab47bc', '#ff7043', '#ffffff'];
        const parts = Array.from({ length: 260 }, () => ({
            x: Math.random() * canvas.width,
            y: -20 - Math.random() * canvas.height,
            w: 6 + Math.random() * 8,
            h: 10 + Math.random() * 10,
            vy: 2 + Math.random() * 4,
            vx: -1.5 + Math.random() * 3,
            r: Math.random() * Math.PI,
            vr: -0.2 + Math.random() * 0.4,
            c: colors[Math.floor(Math.random() * colors.length)],
        }));
        const start = performance.now();
        cancelAnimationFrame(confettiFrame);
        (function frame(now) {
            ctx.clearRect(0, 0, canvas.width, canvas.height);
            const loop = now - start < 6000;
            let alive = 0;
            for (const p of parts) {
                p.y += p.vy;
                p.x += p.vx + Math.sin((now / 400) + p.r);
                p.r += p.vr;
                if (p.y > canvas.height + 20 && loop) { p.y = -20; p.x = Math.random() * canvas.width; }
                if (p.y < canvas.height + 20) alive++;
                ctx.save();
                ctx.translate(p.x, p.y);
                ctx.rotate(p.r);
                ctx.fillStyle = p.c;
                ctx.fillRect(-p.w / 2, -p.h / 2, p.w, p.h);
                ctx.restore();
            }
            if (alive) confettiFrame = requestAnimationFrame(frame);
            else ctx.clearRect(0, 0, canvas.width, canvas.height);
        })(start);
    }
    function stopConfetti() {
        cancelAnimationFrame(confettiFrame);
        const canvas = $('confetti');
        canvas.getContext('2d').clearRect(0, 0, canvas.width, canvas.height);
    }

    /* Sons synthétisés (pas de fichier à charger) */
    function sound(kind) {
        if (!audioOn || soundMuted || !audioCtx) return;
        const notes = { pop: [[880, 0, 0.08], [1320, 0.07, 0.12]], tick: [[440, 0, 0.04]], fanfare: [[523, 0, 0.18], [659, 0.18, 0.18], [784, 0.36, 0.18], [1047, 0.54, 0.5]] }[kind] || [];
        const t0 = audioCtx.currentTime;
        for (const [freq, at, dur] of notes) {
            const osc = audioCtx.createOscillator();
            const gain = audioCtx.createGain();
            osc.type = kind === 'fanfare' ? 'triangle' : 'sine';
            osc.frequency.value = freq;
            gain.gain.setValueAtTime(0.0001, t0 + at);
            gain.gain.exponentialRampToValueAtTime(0.25, t0 + at + 0.02);
            gain.gain.exponentialRampToValueAtTime(0.0001, t0 + at + dur);
            osc.connect(gain).connect(audioCtx.destination);
            osc.start(t0 + at);
            osc.stop(t0 + at + dur + 0.05);
        }
    }

    /* ---------- Démarrage : son, plein écran, écran toujours allumé ---------- */

    let wakeLock = null;
    async function keepAwake() {
        try { wakeLock = await navigator.wakeLock?.request('screen'); } catch (e) { /* non disponible */ }
    }
    document.addEventListener('visibilitychange', () => { if (!document.hidden && audioOn) keepAwake(); });

    function fullscreen() {
        if (document.fullscreenElement) document.exitFullscreen();
        else document.documentElement.requestFullscreen?.().catch(() => {});
    }

    $('s-start').addEventListener('click', () => {
        $('s-start').remove();
        audioOn = true;
        try { audioCtx = new (window.AudioContext || window.webkitAudioContext)(); } catch (e) { /* sans son */ }
        if ('speechSynthesis' in window) speechSynthesis.speak(new SpeechSynthesisUtterance(''));
        document.documentElement.requestFullscreen?.().catch(() => {});
        keepAwake();
    });

    // Curseur masqué quand la souris ne bouge plus ; barre de l'organisateur visible quand elle bouge
    let idleTimer = null;
    function wake() {
        body.classList.add('awake');
        clearTimeout(idleTimer);
        idleTimer = setTimeout(() => {
            if (!document.querySelector('.presenter:focus-within')) body.classList.remove('awake');
        }, 3000);
    }
    document.addEventListener('mousemove', wake);
    document.addEventListener('touchstart', wake, { passive: true });

    /* ---------- Pilotage par l'organisateur (s'il est connecté sur cet ordinateur) ---------- */

    async function act(action, params) {
        const data = await bingoAction(gameId, action, params);
        if (data.error) toast(data.error, 'error');
        if (data.rev) apply(data);
        return data;
    }

    if (gameId) {
        const manual = $('s-manual');
        const input = manual.querySelector('input');
        manual.addEventListener('submit', (event) => {
            event.preventDefault();
            const n = parseInt(input.value, 10);
            input.value = '';
            input.blur();
            if (n) act('add', { number: n });
        });
        $('s-presenter').addEventListener('click', (event) => {
            const button = event.target.closest('[data-do]');
            if (!button) return;
            const what = button.dataset.do;
            if (what === 'draw') act('draw');
            if (what === 'undo') undo();
            if (what === 'auto') act('auto', { running: !state.auto.running });
            if (what === 'clear') act('overlay_clear');
            if (what === 'sound') toggleSound();
            if (what === 'full') fullscreen();
            button.blur();
        });
        document.addEventListener('keydown', (event) => {
            if (event.target === input) {
                if (event.key === 'Escape') { input.value = ''; input.blur(); }
                return;
            }
            if (event.ctrlKey || event.metaKey || event.altKey || document.getElementById('s-start')) return;
            if (/^[0-9]$/.test(event.key)) {
                wake();
                input.focus();
                input.value = event.key;
                event.preventDefault();
            } else if (event.key === ' ' || event.key === 'Enter') {
                event.preventDefault();
                act('draw');
            } else if (event.key === 'Backspace') {
                event.preventDefault();
                undo();
            } else if (event.key === 'a' || event.key === 'A') {
                act('auto', { running: !state.auto.running });
            } else if (event.key === 'Escape') {
                if (!$('s-help').hidden) $('s-help').hidden = true;
                else if (state.overlay) act('overlay_clear');
            } else if (event.key === '?') {
                $('s-help').hidden = !$('s-help').hidden;
            } else if (event.key === 's' || event.key === 'S') {
                toggleSound();
            }
        });
        $('s-help').addEventListener('click', () => { $('s-help').hidden = true; });
    }

    async function undo() {
        const data = await act('undo');
        if (data.undone) toast(`Boule ${data.undone} annulée`, 'info');
    }

    function toggleSound() {
        soundMuted = !soundMuted;
        const button = $('s-sound');
        if (button) button.textContent = soundMuted ? '🔇' : '🔊';
        if (soundMuted && 'speechSynthesis' in window) speechSynthesis.cancel();
        toast(soundMuted ? 'Son coupé' : 'Son activé');
    }

    // Voix : réglage de la session, après le clic « Lancer l'écran », sauf si le son est coupé ici
    function say(text) {
        if (state.voice && audioOn && !soundMuted) speak(text);
    }

    document.addEventListener('keydown', (event) => {
        if ((event.key === 'f' || event.key === 'F') && !event.ctrlKey && !event.metaKey && event.target.tagName !== 'INPUT') fullscreen();
    });
    window.addEventListener('resize', () => {
        const canvas = $('confetti');
        canvas.width = innerWidth;
        canvas.height = innerHeight;
    });

    poll();
})();
