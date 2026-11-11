// Planning en direct : jeu en cours, compte à rebours, rappels push, notes ⭐, palmarès, grand écran.
(() => {
    'use strict';
    const $ = (id) => document.getElementById(id);
    const esc = (s) => String(s ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
    let state = JSON.parse($('planning-state').textContent);
    let offset = state.now - Date.now();
    let lastKey = '';
    let lastCurrent;
    const now = () => Date.now() + offset;
    const hm = (t) => t.replace(':', 'h');
    const ios = /iPhone|iPad|iPod/.test(navigator.userAgent) || (navigator.platform === 'MacIntel' && navigator.maxTouchPoints > 1);
    const standalone = matchMedia('(display-mode: standalone)').matches || navigator.standalone === true;
    const palette = ['c-orange', 'c-yellow', 'c-green', 'c-blue', 'c-pink'];

    function duration(ms) {
        const total = Math.max(0, Math.round(ms / 1000));
        if (total < 60) return total + ' s';
        const minutes = Math.round(total / 60);
        if (minutes < 60) return minutes + ' min';
        const days = Math.floor(minutes / 1440);
        const hours = Math.floor((minutes % 1440) / 60);
        const rest = String(minutes % 60).padStart(2, '0');
        return (days ? days + ' j ' : '') + hours + ' h ' + rest;
    }

    function stars(value, slotId, small) {
        let html = `<span class="stars${small ? ' small' : ''}" data-slot="${slotId}" role="radiogroup" aria-label="Ta note">`;
        for (let i = 1; i <= 5; i++) {
            html += `<button type="button" class="star${value >= i ? ' on' : ''}" data-stars="${i}" aria-label="${i} étoile${i > 1 ? 's' : ''}">★</button>`;
        }
        return html + '</span>';
    }

    function avgHtml(slot) {
        return slot.ratings ? `<span class="avg">★ ${slot.avg.toLocaleString('fr-BE')} <small>(${slot.ratings})</small></span>` : '';
    }

    function situation() {
        const t = now();
        const slots = state.slots;
        const current = slots.find((s) => s.start <= t && t < s.end) || null;
        const next = slots.find((s) => s.start > t) || null;
        return { t, current, next, started: slots.length && slots[0].start <= t, over: !next && !current };
    }

    /* ---------- Carte « en ce moment » ---------- */

    function renderNow() {
        const { t, current, next, started, over } = situation();
        let html = '';
        if (current) {
            const icon = current.kind === 'break' ? '⏸️' : current.kind === 'favorite' ? '🏆' : '🎮';
            const label = current.kind === 'favorite' ? 'Revanche · jeu préféré' : current.kind === 'break' ? 'En ce moment' : 'On joue à';
            const progress = Math.min(100, 100 * (t - current.start) / (current.end - current.start));
            html += `<div class="now-main ${current.image ? 'has-img' : ''}" ${current.image ? `style="--img: url('${esc(current.image)}')"` : ''}>
                <span class="now-label">${icon} ${label}</span>
                <strong class="now-title">${esc(current.title)}</strong>
                <span class="now-time">${hm(current.from)} – ${hm(current.to)}${current.note ? ' · ' + esc(current.note) : ''}</span>
                <div class="now-bar"><span style="width:${progress}%"></span></div>
                <span class="now-left">encore ${duration(current.end - t)}</span>
            </div>`;
        } else if (!started && next) {
            html += `<div class="now-main"><span class="now-label">🚀 La LAN commence dans</span>
                <strong class="now-title countdown">${duration(next.start - t)}</strong>
                <span class="now-time">Premier jeu : ${esc(next.title)} à ${hm(next.from)}</span></div>`;
        } else if (next) {
            html += `<div class="now-main"><span class="now-label">😴 Pause</span>
                <strong class="now-title">Reprise dans ${duration(next.start - t)}</strong>
                <span class="now-time">à ${hm(next.from)} avec ${esc(next.title)}</span></div>`;
        } else if (over) {
            html += `<div class="now-main"><span class="now-label">🏁 LAN terminée</span>
                <strong class="now-title">Merci à tous !</strong>
                ${state.favorite ? `<span class="now-time">Jeu préféré : ${esc(state.favorite.title)} (★ ${state.favorite.avg.toLocaleString('fr-BE')})</span>` : ''}</div>`;
        }
        if (next && (current || started)) {
            const soon = next.start - t <= state.remind * 60000;
            html += `<div class="now-next${soon ? ' soon' : ''}">
                <span>${soon ? '⏰ Changement dans' : 'Ensuite dans'} <b>${duration(next.start - t)}</b></span>
                <strong>${esc(next.title)}</strong><span class="muted">${hm(next.from)} – ${hm(next.to)}</span></div>`;
        }
        $('now').innerHTML = html;
    }

    /** Organisateur : décaler le créneau en cours (ou le prochain) et toute la suite */
    function renderAdmin() {
        const { current, next } = situation();
        const target = current || next;
        $('now-admin').innerHTML = state.admin && target
            ? `<span class="muted small">Organisateur · retard ? Décaler « ${esc(target.title)} » et la suite :</span>
               ${[-5, 5, 15, 30].map((m) => `<button type="button" class="btn-secondary small-btn" data-shift="${m}" data-slot="${target.id}">${m > 0 ? '+' : '−'}${Math.abs(m)} min</button>`).join('')}`
            : '';
    }

    /* ---------- Notes, programme, palmarès ---------- */

    function renderRate() {
        const t = now();
        const box = $('rate-box');
        const toRate = state.slots.filter((s) => s.kind !== 'break' && s.start <= t && s.mine === null).slice(-3).reverse();
        if (!toRate.length) { box.innerHTML = ''; return; }
        if (!state.user) {
            box.innerHTML = `<div class="card rate-card"><strong>⭐ Note les jeux joués</strong> <a href="connexion.php?next=planning.php">Connecte-toi pour noter</a></div>`;
            return;
        }
        box.innerHTML = `<div class="card rate-card"><strong>⭐ Note les jeux joués</strong><ul>`
            + toRate.map((s) => `<li id="noter-${s.id}"><span>${esc(s.title)} <small class="muted">${hm(s.from)}</small></span>${stars(0, s.id)}</li>`).join('')
            + '</ul></div>';
    }

    function renderDays() {
        const { t, current, next } = situation();
        const colors = {};
        let i = 0;
        $('days').innerHTML = state.days.map((day) => `<section class="day">
            <header><strong>${esc(day.label)}</strong><span>${esc(day.hours)}</span></header>
            <ul>${state.slots.filter((s) => s.day === day.key).map((s) => {
                const status = current && s.id === current.id ? 'is-now' : next && s.id === next.id ? 'is-next' : s.end <= t ? 'is-past' : '';
                const color = s.kind === 'game' ? (colors[s.label] ??= palette[i++ % palette.length]) : 'c-' + s.kind;
                const canRate = s.kind !== 'break' && s.start <= t && state.user;
                return `<li class="slot ${color} ${status}" id="slot-${s.id}">
                    <span class="slot-time">${hm(s.from)} – ${hm(s.to)}${status === 'is-now' ? ' <b class="live">● EN COURS</b>' : ''}</span>
                    <strong class="slot-title">${s.kind === 'favorite' ? '🏆 ' : ''}${esc(s.title)}</strong>
                    ${s.kind === 'favorite' && s.title !== s.label ? `<span class="slot-note">${esc(s.label)}</span>` : ''}
                    ${s.note ? `<span class="slot-note">${esc(s.note)}</span>` : ''}
                    ${s.kind !== 'break' && (canRate || s.ratings) ? `<span class="slot-rate">${canRate ? stars(s.mine || 0, s.id, true) : ''}${avgHtml(s)}</span>` : ''}
                </li>`;
            }).join('')}</ul></section>`).join('');
    }

    function renderPalmares() {
        const played = state.slots.filter((s) => s.kind === 'game' && s.ratings)
            .sort((a, b) => b.avg - a.avg || b.ratings - a.ratings);
        $('palmares').innerHTML = played.length ? played.map((s, i) => `<li>
            <span class="rank">${i === 0 ? '🥇' : i === 1 ? '🥈' : i === 2 ? '🥉' : i + 1}</span>
            <strong>${esc(s.title)}</strong>
            <span class="bar"><span style="width:${s.avg * 20}%"></span></span>
            ${avgHtml(s)}</li>`).join('') : '<li class="muted">Pas encore de notes : notez les jeux après les avoir joués !</li>';
    }

    function renderAll() {
        renderNow();
        renderAdmin();
        renderRate();
        renderDays();
        renderPalmares();
        renderPush();
        const hash = location.hash.match(/^#noter-(\d+)$/);
        if (hash && !renderAll.scrolled) {
            renderAll.scrolled = true;
            ($('noter-' + hash[1]) || $('slot-' + hash[1]))?.scrollIntoView({ block: 'center' });
        }
    }

    /* ---------- Échanges avec le serveur ---------- */

    async function post(params, retry = true) {
        const body = new URLSearchParams({ ...params, csrf: state.csrf });
        const res = await fetch('api/planning.php', { method: 'POST', body }).catch(() => null);
        if (!res) return { error: 'Connexion perdue.' };
        const data = await res.json().catch(() => ({ error: 'Réponse invalide.' }));
        if (res.status === 403 && data.csrf && retry) {
            state.csrf = data.csrf;
            return post(params, false);
        }
        return data;
    }

    async function refresh() {
        const res = await fetch('api/planning.php?lan=' + state.lan.id, { cache: 'no-store' }).catch(() => null);
        if (!res || !res.ok) return;
        const data = await res.json().catch(() => null);
        if (!data) return;
        state = data;
        offset = state.now - Date.now();
        lastKey = '';
        tick();
    }

    function toast(message) {
        const el = document.createElement('div');
        el.className = 'toast';
        el.textContent = message;
        document.body.appendChild(el);
        setTimeout(() => el.remove(), 3500);
    }

    document.addEventListener('click', async (event) => {
        const star = event.target.closest('[data-stars]');
        if (star) {
            const slot = +star.closest('[data-slot]').dataset.slot;
            const value = +star.dataset.stars;
            const item = state.slots.find((s) => s.id === slot);
            const newValue = item && item.mine === value ? 0 : value;
            const data = await post({ action: 'rate', slot, stars: newValue });
            if (data.error) toast(data.error); else { toast(newValue ? `Merci ! ${'★'.repeat(newValue)}` : 'Note retirée'); refresh(); }
            return;
        }
        const shift = event.target.closest('[data-shift]');
        if (shift) {
            const data = await post({ action: 'shift', slot: shift.dataset.slot, minutes: shift.dataset.shift });
            toast(data.error || data.message);
            refresh();
        }
    });

    /* ---------- Notifications push ---------- */

    let registration = null;
    let subscription = null;
    const pushSupported = 'serviceWorker' in navigator && 'PushManager' in window && 'Notification' in window;

    function keyBytes(base64) {
        const raw = atob((base64 + '='.repeat((4 - base64.length % 4) % 4)).replace(/-/g, '+').replace(/_/g, '/'));
        return Uint8Array.from(raw, (c) => c.charCodeAt(0));
    }

    function renderPush() {
        const box = $('push');
        box.hidden = false;
        const reminder = state.remind ? `${state.remind} min avant chaque changement de jeu` : 'à chaque changement de jeu';
        if (!pushSupported) {
            box.innerHTML = ios && !standalone
                ? `<strong>🔔 Rappels sur iPhone</strong><p>Touche <b>Partager</b> <span aria-hidden="true">⬆️</span> puis <b>« Sur l'écran d'accueil »</b>, ouvre l'appli LAN depuis la nouvelle icône, et active les rappels ici.</p>`
                : `<strong>🔔 Rappels</strong><p class="muted">Ce navigateur ne gère pas les notifications. Essaie Chrome, Firefox, Edge ou Safari récent.</p>`;
            return;
        }
        if (!state.user) {
            box.innerHTML = `<strong>🔔 Rappels ${reminder}</strong><p><a href="connexion.php?next=planning.php">Connecte-toi</a> pour les activer sur ce téléphone.</p>`;
            return;
        }
        if (Notification.permission === 'denied') {
            box.innerHTML = `<strong>🔕 Notifications bloquées</strong><p class="muted">Autorise les notifications pour ce site dans les réglages du navigateur, puis recharge la page.</p>`;
            return;
        }
        box.innerHTML = subscription
            ? `<strong>✅ Rappels activés sur cet appareil</strong><p class="muted small">Tu seras prévenu ${reminder}.</p>
               <p><button type="button" class="btn-secondary" data-push="test">Envoyer un test</button> <button type="button" class="btn-link" data-push="off">Désactiver</button></p>`
            : `<strong>🔔 Ne rate aucun changement de jeu</strong><p class="muted small">Notification ${reminder}, puis au lancement du jeu suivant.</p>
               <p><button type="button" data-push="on">🔔 Activer les rappels</button></p>`;
    }

    async function initPush() {
        if (!pushSupported) return renderPush();
        try {
            registration = await navigator.serviceWorker.register('sw.js');
            subscription = await registration.pushManager.getSubscription();
            if (subscription && state.user) await post({ action: 'subscribe', subscription: JSON.stringify(subscription) });
        } catch (e) { /* pas de service worker (navigation privée…) */ }
        renderPush();
    }

    $('push').addEventListener('click', async (event) => {
        const what = event.target.closest('[data-push]')?.dataset.push;
        if (!what) return;
        if (what === 'on') {
            try {
                if (await Notification.requestPermission() !== 'granted') return renderPush();
                registration ??= await navigator.serviceWorker.register('sw.js');
                await navigator.serviceWorker.ready;
                subscription = await registration.pushManager.subscribe({ userVisibleOnly: true, applicationServerKey: keyBytes(state.vapid) });
                const data = await post({ action: 'subscribe', subscription: JSON.stringify(subscription) });
                if (data.error) throw new Error(data.error);
                await post({ action: 'test' });
                toast('Rappels activés 🔔');
            } catch (e) {
                toast('Impossible d\'activer les rappels : ' + e.message);
            }
        } else if (what === 'test') {
            const data = await post({ action: 'test' });
            toast(data.error || 'Notification envoyée : elle arrive dans quelques secondes.');
        } else if (what === 'off' && subscription) {
            await post({ action: 'unsubscribe', endpoint: subscription.endpoint });
            await subscription.unsubscribe().catch(() => {});
            subscription = null;
            toast('Rappels désactivés sur cet appareil.');
        }
        renderPush();
    });

    /* ---------- Grand écran (TV) : annonce sonore à chaque changement ---------- */

    let audio = null;
    $('tv-btn').addEventListener('click', () => {
        document.body.classList.toggle('tv');
        if (document.body.classList.contains('tv')) {
            try { audio = new (window.AudioContext || window.webkitAudioContext)(); } catch (e) { audio = null; }
            document.documentElement.requestFullscreen?.().catch(() => {});
            navigator.wakeLock?.request('screen').catch(() => {});
        } else if (document.fullscreenElement) {
            document.exitFullscreen();
        }
    });
    document.addEventListener('fullscreenchange', () => { if (!document.fullscreenElement) document.body.classList.remove('tv'); });

    function announce(slot) {
        if (!document.body.classList.contains('tv') || !slot) return;
        const box = document.createElement('div');
        box.className = 'announce';
        box.innerHTML = `<span>${slot.kind === 'break' ? '⏸️' : '🎮'} Changement !</span><strong>${esc(slot.title)}</strong>`;
        document.body.appendChild(box);
        setTimeout(() => box.remove(), 12000);
        if (audio) {
            [523, 659, 784, 1047].forEach((freq, i) => {
                const osc = audio.createOscillator();
                const gain = audio.createGain();
                osc.frequency.value = freq;
                gain.gain.setValueAtTime(0.0001, audio.currentTime + i * 0.15);
                gain.gain.exponentialRampToValueAtTime(0.3, audio.currentTime + i * 0.15 + 0.02);
                gain.gain.exponentialRampToValueAtTime(0.0001, audio.currentTime + i * 0.15 + 0.4);
                osc.connect(gain).connect(audio.destination);
                osc.start(audio.currentTime + i * 0.15);
                osc.stop(audio.currentTime + i * 0.15 + 0.45);
            });
        }
    }

    /* ---------- Boucle ---------- */

    function tick() {
        const { current, next } = situation();
        const key = (current ? current.id : '-') + '/' + (next ? next.id : '-');
        if (key !== lastKey) {
            if (lastCurrent !== undefined && current && current.id !== lastCurrent) announce(current);
            lastCurrent = current ? current.id : null;
            lastKey = key;
            renderAll();
        } else {
            renderNow();
        }
    }

    tick();
    initPush();
    setInterval(tick, 1000);
    setInterval(() => { if (!document.hidden) refresh(); }, 30000);
    document.addEventListener('visibilitychange', () => { if (!document.hidden) refresh(); });
})();
