/* Application Notes : barre latérale, navigation, éditeur, enregistrement automatique, menus. */
(() => {
    const boot = JSON.parse(document.getElementById('boot').textContent);
    const $ = (sel, root = document) => root.querySelector(sel);
    const esc = window.NotesEditor.esc;
    const state = { tree: [], favorites: [], page: null, editor: null, dirty: false, saving: null, conflict: false, expanded: new Set() };
    NotesAI.csrf = boot.csrf;
    NotesAI.actions = boot.aiActions;

    try { JSON.parse(localStorage.getItem('notes-expanded') || '[]').forEach((id) => state.expanded.add(id)); } catch { /* stockage indisponible */ }
    const saveExpanded = () => { try { localStorage.setItem('notes-expanded', JSON.stringify([...state.expanded])); } catch { /* ignore */ } };

    // ---------- Appels serveur ----------
    async function api(action, payload = {}) {
        const res = await fetch('api/pages.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': boot.csrf },
            body: JSON.stringify({ action, ...payload }),
        });
        const data = await res.json().catch(() => ({ error: `Erreur serveur (${res.status}).` }));
        if (res.status === 401) { location.href = 'login.php?next=' + encodeURIComponent(location.pathname.split('/').pop() + location.search); }
        if (!res.ok && res.status !== 409) throw new Error(data.error || 'Erreur');
        return Object.assign(data, { status: res.status });
    }
    async function apiGet(action, params = {}) {
        const res = await fetch('api/pages.php?' + new URLSearchParams({ action, ...params }));
        const data = await res.json().catch(() => ({ error: 'Erreur serveur.' }));
        if (!res.ok) throw new Error(data.error || 'Erreur');
        return data;
    }
    function applySidebar(data) {
        if (data.tree) { state.tree = data.tree; state.favorites = data.favorites; renderSidebar(); }
    }

    // ---------- Petits composants ----------
    function toast(message, isError = false) {
        const t = $('#toast');
        t.textContent = message;
        t.className = 'toast' + (isError ? ' error' : '');
        t.hidden = false;
        clearTimeout(toast.timer);
        toast.timer = setTimeout(() => { t.hidden = true; }, 3500);
    }
    function setStatus(text) { $('#save-status').textContent = text; }

    /** Menu contextuel : items = [{label, action, danger}] ou '-' (séparateur) */
    function openMenu(anchor, items) {
        const menu = $('#menu');
        menu.innerHTML = '';
        items.forEach((item) => {
            if (item === '-') { menu.append(Object.assign(document.createElement('hr'))); return; }
            if (item.info) { const p = document.createElement('div'); p.className = 'menu-info'; p.textContent = item.info; menu.append(p); return; }
            const b = document.createElement('button');
            b.type = 'button';
            b.className = 'menu-item' + (item.danger ? ' danger' : '');
            b.textContent = item.label;
            b.addEventListener('click', () => { closeMenu(); item.action(); });
            menu.append(b);
        });
        menu.hidden = false;
        const r = anchor.getBoundingClientRect();
        menu.style.top = `${Math.min(window.innerHeight - menu.offsetHeight - 8, r.bottom + 4)}px`;
        menu.style.left = `${Math.max(8, Math.min(window.innerWidth - menu.offsetWidth - 8, r.left))}px`;
    }
    function closeMenu() { $('#menu').hidden = true; }
    document.addEventListener('mousedown', (e) => { if (!$('#menu').hidden && !$('#menu').contains(e.target)) closeMenu(); });

    function openModal(html, onOpen) {
        $('#modal-box').innerHTML = html;
        $('#modal').hidden = false;
        onOpen?.($('#modal-box'));
    }
    function closeModal() { $('#modal').hidden = true; $('#modal-box').innerHTML = ''; $('#modal-box').className = 'modal'; }
    $('#modal').addEventListener('mousedown', (e) => { if (e.target.id === 'modal') closeModal(); });
    document.addEventListener('keydown', (e) => {
        if (e.key === 'Escape') { closeModal(); closeMenu(); NotesAI.close(); }
        if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 'k') { e.preventDefault(); openSearch(); }
    });

    // ---------- Barre latérale ----------
    const byId = () => Object.fromEntries(state.tree.map((p) => [p.id, p]));
    const children = (parentId, space) => state.tree.filter((p) => p.parentId === parentId && (parentId !== null || (p.space === space && !p.isTemplate)));
    const templates = () => state.tree.filter((p) => p.isTemplate);

    function renderSidebar() {
        window.NotesPages = byId();
        ['team', 'private'].forEach((space) => {
            const list = $(`[data-list="${space}"]`);
            list.innerHTML = '';
            const roots = children(null, space);
            roots.forEach((p) => list.append(treeItem(p, 0)));
            if (!roots.length) list.innerHTML = `<div class="sb-empty">Aucune page</div>`;
        });
        const tplList = $('[data-list="templates"]');
        tplList.innerHTML = '';
        templates().forEach((p) => tplList.append(treeItem(p, 0, true)));
        if (!tplList.children.length) tplList.innerHTML = '<div class="sb-empty">Aucun modèle</div>';
        const favList = $('[data-list="favorites"]');
        favList.innerHTML = '';
        const pages = byId();
        state.favorites.filter((id) => pages[id]).forEach((id) => favList.append(treeItem(pages[id], 0, true)));
        $('#sb-favorites').hidden = !favList.children.length;
        highlightCurrent();
    }

    function treeItem(page, depth, flat = false) {
        const wrap = document.createElement('div');
        wrap.className = 'tree-node';
        const kids = flat ? [] : children(page.id);
        const open = state.expanded.has(page.id);
        const row = document.createElement('div');
        row.className = 'tree-row';
        row.dataset.id = page.id;
        row.style.paddingLeft = `${8 + depth * 14}px`;
        row.draggable = !flat;
        row.innerHTML = `<button type="button" class="tree-toggle${kids.length ? '' : ' empty'}" aria-label="Déplier">${open ? '▾' : '▸'}</button>
            <span class="tree-icon">${esc(page.icon || (page.kind === 'database' ? '🗃️' : '📄'))}</span>
            <a class="tree-title" href="index.php?p=${page.id}">${esc(page.title || 'Sans titre')}</a>
            ${page.shared ? '<span class="tree-shared" title="Partagée sur le web">🌐</span>' : ''}
            <span class="tree-actions"><button type="button" class="icon-btn" data-act="menu" title="Actions">⋯</button>${flat ? '' : '<button type="button" class="icon-btn" data-act="add" title="Ajouter une sous-page">＋</button>'}</span>`;
        row.querySelector('.tree-toggle').addEventListener('click', () => {
            open ? state.expanded.delete(page.id) : state.expanded.add(page.id);
            saveExpanded();
            renderSidebar();
        });
        row.querySelector('.tree-title').addEventListener('click', (e) => { e.preventDefault(); openPage(page.id); });
        row.querySelector('[data-act="menu"]').addEventListener('click', (e) => openMenu(e.currentTarget, pageMenuItems(page.id)));
        row.querySelector('[data-act="add"]')?.addEventListener('click', (e) => openNewPageMenu(e.currentTarget, page.space, page.id));
        if (!flat) attachDrag(row, page);
        wrap.append(row);
        if (open && kids.length) kids.forEach((k) => wrap.append(treeItem(k, depth + 1)));
        return wrap;
    }

    function highlightCurrent() {
        document.querySelectorAll('.tree-row.active').forEach((r) => r.classList.remove('active'));
        if (state.page) document.querySelectorAll(`.tree-row[data-id="${state.page.id}"]`).forEach((r) => r.classList.add('active'));
        document.querySelector('[data-nav="home"]').classList.toggle('active', !state.page);
    }

    // Glisser-déposer : haut de la ligne = avant, milieu = dans, bas = après
    let dragId = null;
    function attachDrag(row, page) {
        row.addEventListener('dragstart', (e) => { dragId = page.id; e.dataTransfer.effectAllowed = 'move'; row.classList.add('dragging'); });
        row.addEventListener('dragend', () => { dragId = null; row.classList.remove('dragging'); clearDrop(); });
        row.addEventListener('dragover', (e) => {
            if (!dragId || dragId === page.id) return;
            e.preventDefault();
            const r = row.getBoundingClientRect();
            const y = (e.clientY - r.top) / r.height;
            clearDrop();
            row.classList.add(y < 0.25 ? 'drop-before' : y > 0.75 ? 'drop-after' : 'drop-inside');
        });
        row.addEventListener('dragleave', () => row.classList.remove('drop-before', 'drop-after', 'drop-inside'));
        row.addEventListener('drop', async (e) => {
            e.preventDefault();
            const zone = row.classList.contains('drop-before') ? 'before' : row.classList.contains('drop-after') ? 'after' : 'inside';
            clearDrop();
            if (!dragId) return;
            const siblings = children(page.parentId, page.space).filter((p) => p.id !== dragId);
            let payload;
            if (zone === 'inside') {
                payload = { parentId: page.id };
                state.expanded.add(page.id);
                saveExpanded();
            } else {
                const idx = siblings.findIndex((p) => p.id === page.id);
                const before = zone === 'before' ? page.id : siblings[idx + 1]?.id ?? null;
                payload = { parentId: page.parentId, space: page.space, beforeId: before };
            }
            await movePage(dragId, payload);
        });
    }
    function clearDrop() { document.querySelectorAll('.drop-before,.drop-after,.drop-inside').forEach((r) => r.classList.remove('drop-before', 'drop-after', 'drop-inside')); }
    document.querySelectorAll('[data-drop-space]').forEach((title) => {
        title.addEventListener('dragover', (e) => { if (dragId) { e.preventDefault(); title.classList.add('drop-inside'); } });
        title.addEventListener('dragleave', () => title.classList.remove('drop-inside'));
        title.addEventListener('drop', async (e) => { e.preventDefault(); title.classList.remove('drop-inside'); if (dragId) await movePage(dragId, { parentId: null, space: title.dataset.dropSpace }); });
    });

    async function movePage(id, payload) {
        try {
            const res = await api('move', { id, ...payload });
            applySidebar(res);
            if (state.page?.id === id) await reloadMeta();
            toast('Page déplacée.');
        } catch (err) { toast(err.message, true); }
    }

    function pageMenuItems(id) {
        const fav = state.favorites.includes(id);
        return [
            { label: '↗ Ouvrir', action: () => openPage(id) },
            { label: '＋ Ajouter une sous-page', action: () => newPage(null, id) },
            { label: fav ? '☆ Retirer des favoris' : '⭐ Ajouter aux favoris', action: () => toggleFavorite(id, !fav) },
            { label: '⧉ Dupliquer', action: () => duplicatePage(id) },
            { label: '↪ Déplacer vers…', action: () => openMove(id) },
            '-',
            { label: '🗑️ Mettre à la corbeille', danger: true, action: () => trashPage(id) },
        ];
    }

    /** Menu « Page vide / modèles » pour créer une page */
    function openNewPageMenu(anchor, space, parentId = null) {
        openMenu(anchor, [
            { label: '📄 Page vide', action: () => newPage(space, parentId) },
            { label: '🗃️ Base de données', action: () => newPage(space, parentId, '', null, 'database') },
            ...(templates().length ? ['-', { info: 'Depuis un modèle' }] : []),
            ...templates().map((t) => ({ label: `${t.icon || '📄'} ${t.title || 'Sans titre'}${t.kind === 'database' ? ' (base)' : ''}`, action: () => newPage(space, parentId, '', t.id) })),
        ]);
    }

    // ---------- Actions sur les pages ----------
    async function newPage(space, parentId = null, title = '', templateId = null, kind = 'page') {
        try {
            const res = await api('create', { space: space || 'team', parentId, title, templateId, kind });
            applySidebar(res);
            if (parentId) { state.expanded.add(parentId); saveExpanded(); renderSidebar(); }
            await openPage(res.page.id, true, res.page);
            $('.page-title')?.focus();
            return res.page;
        } catch (err) { toast(err.message, true); return null; }
    }
    async function toggleFavorite(id, on) {
        applySidebar(await api('favorite', { id, on }));
        if (state.page?.id === id) { state.page.favorite = on; renderTopbar(); }
    }
    async function duplicatePage(id) {
        const res = await api('duplicate', { id });
        applySidebar(res);
        await openPage(res.page.id, true, res.page);
        toast('Page dupliquée.');
    }
    async function trashPage(id) {
        if (!confirm('Mettre cette page (et ses sous-pages) à la corbeille ?')) return;
        applySidebar(await api('trash', { id }));
        if (state.page && (state.page.id === id || state.page.breadcrumb.some((c) => c.id === id))) await showHome();
        toast('Page mise à la corbeille.');
    }

    // ---------- Navigation ----------
    async function leaveCurrent() {
        await flushSave();
        window.NotesDB?.unmount();
        if (state.editor) { try { state.editor.destroy(); } catch { /* déjà détruit */ } }
        state.editor = null;
        NotesAI.close();
    }

    async function openPage(id, push = true, preloaded = null) {
        await leaveCurrent();
        closeSidebarMobile();
        let page = preloaded;
        if (!page) {
            try { page = (await apiGet('get', { id })).page; } catch (err) { renderMissing(err.message); return; }
        }
        state.page = page;
        if (push) history.pushState({ id }, '', `index.php?p=${id}`);
        // déplie les parents dans la barre latérale
        page.breadcrumb.forEach((c) => state.expanded.add(c.id));
        saveExpanded();
        renderSidebar();
        renderPage();
    }

    async function showHome(push = true) {
        await leaveCurrent();
        state.page = null;
        if (push) history.pushState({}, '', 'index.php');
        renderTopbar();
        highlightCurrent();
        document.title = `Accueil · ${boot.siteTitle}`;
        const view = $('#view');
        view.innerHTML = `<div class="home"><h1>Bonjour ${esc(boot.user.name)} 👋</h1>
            <div class="home-actions">
                <button type="button" class="btn-primary" data-new="team">＋ Nouvelle page ${esc(boot.teamName)}</button>
                <button type="button" class="btn-secondary" data-new="private">＋ Nouvelle page privée</button>
            </div>
            <h2>Récemment modifiées</h2><div class="recent-grid" id="recent">Chargement…</div></div>`;
        view.querySelectorAll('[data-new]').forEach((b) => b.addEventListener('click', (e) => openNewPageMenu(e.currentTarget, b.dataset.new)));
        const { pages } = await apiGet('recent');
        const grid = $('#recent');
        if (!grid) return;
        grid.innerHTML = pages.length ? '' : '<p class="muted">Aucune page pour l\'instant : crée la première !</p>';
        pages.forEach((p) => {
            const a = document.createElement('a');
            a.className = 'recent-card';
            a.href = `index.php?p=${p.id}`;
            a.innerHTML = `<span class="recent-icon">${esc(p.icon || (p.kind === 'database' ? '🗃️' : '📄'))}</span><strong>${esc(p.title || 'Sans titre')}</strong>
                <span class="muted small">${p.space === 'private' ? '🔒 Privé · ' : ''}${esc(p.updated)}${p.updated_by ? ' · ' + esc(p.updated_by) : ''}</span>`;
            a.addEventListener('click', (e) => { e.preventDefault(); openPage(p.id); });
            grid.append(a);
        });
    }

    function renderMissing(message) {
        state.page = null;
        renderTopbar();
        $('#view').innerHTML = `<div class="home"><h1>Page introuvable</h1><p class="muted">${esc(message || 'Cette page n\'existe pas ou ne t\'est pas accessible.')}</p><p><a href="index.php">Retour à l'accueil</a></p></div>`;
    }

    window.addEventListener('popstate', () => {
        const id = Number(new URLSearchParams(location.search).get('p'));
        id ? openPage(id, false) : showHome(false);
    });

    // ---------- Affichage d'une page ----------
    const GRADIENTS = 8;
    function coverStyle(cover) {
        if (!cover) return '';
        if (cover.startsWith('gradient-')) return '';
        return `background-image:url('${cover.replace(/'/g, '%27')}')`;
    }

    function renderTopbar() {
        const page = state.page;
        $('#top-actions').hidden = !page || page.trashed;
        const crumbs = $('#breadcrumb');
        crumbs.innerHTML = '';
        if (!page) return;
        const space = document.createElement('span');
        space.className = 'crumb muted';
        space.textContent = page.space === 'private' ? '🔒 Privé' : boot.teamName;
        crumbs.append(space);
        [...page.breadcrumb, { id: page.id, title: page.title, icon: page.icon }].forEach((c) => {
            const sep = document.createElement('span');
            sep.className = 'crumb-sep';
            sep.textContent = '/';
            const a = document.createElement('a');
            a.className = 'crumb';
            a.href = `index.php?p=${c.id}`;
            a.textContent = `${c.icon || ''} ${c.title || 'Sans titre'}`.trim();
            a.addEventListener('click', (e) => { e.preventDefault(); if (c.id !== page.id) openPage(c.id); });
            crumbs.append(sep, a);
        });
        $('#fav-btn').textContent = page.favorite ? '★' : '☆';
        $('#fav-btn').classList.toggle('on', page.favorite);
        $('#share-btn').classList.toggle('on', !!page.shareUrl);
        $('#ai-page-btn').hidden = page.kind === 'database';
    }

    function renderPage() {
        const page = state.page;
        document.title = `${page.title || 'Sans titre'} · ${boot.siteTitle}`;
        renderTopbar();
        setStatus(page.updatedAt ? `Modifié ${page.updatedBy ? 'par ' + page.updatedBy + ' ' : ''}le ${page.updatedAt}` : '');
        $('#conflict-bar').hidden = true;
        state.conflict = false;
        const kids = state.tree.filter((p) => p.parentId === page.id);
        const view = $('#view');
        view.innerHTML = `
            ${page.isTemplate && !page.trashed ? `<div class="template-banner">🧩 Ceci est un <strong>modèle</strong> : modifie-le librement, puis crée des pages à partir de lui.
                <button type="button" class="btn-primary" id="use-template">＋ Créer une page depuis ce modèle</button></div>` : ''}
            ${page.trashed ? `<div class="trash-banner">Cette page est dans la corbeille. <button type="button" class="btn-secondary" id="restore-btn">Restaurer</button></div>` : ''}
            <div class="cover ${page.cover.startsWith('gradient-') ? page.cover : ''}" id="cover" style="${coverStyle(page.cover)}" ${page.cover ? '' : 'hidden'}>
                <div class="cover-actions"><button type="button" class="btn-ghost" id="cover-change">Changer la couverture</button><button type="button" class="btn-ghost" id="cover-remove">Retirer</button></div>
            </div>
            <div class="page-inner ${page.cover ? 'with-cover' : ''}">
                <button type="button" class="page-icon" id="page-icon" ${page.icon ? '' : 'hidden'} title="Changer l'icône">${esc(page.icon)}</button>
                <div class="page-controls">
                    <button type="button" class="btn-ghost" id="add-icon" ${page.icon ? 'hidden' : ''}>☺ Ajouter une icône</button>
                    <button type="button" class="btn-ghost" id="add-cover" ${page.cover ? 'hidden' : ''}>🖼 Ajouter une couverture</button>
                </div>
                <h1 class="page-title" contenteditable="${!page.trashed}" data-placeholder="Sans titre" spellcheck="true"></h1>
                ${page.kind === 'row' && page.db ? `<a class="row-db-link" href="index.php?p=${page.db.id}" data-id="${page.db.id}">${esc(page.db.icon || '🗃️')} ${esc(page.db.title || 'Sans titre')}</a><div id="row-props"></div>` : ''}
                ${page.kind === 'database' ? '<div id="db-root" class="db-root"></div>' : '<div id="editor" class="editor"></div>'}
                <div class="subpages" ${page.kind === 'page' ? '' : 'hidden'}>
                    ${kids.length ? '<h3>Sous-pages</h3>' : ''}
                    ${kids.map((k) => `<a class="subpage" href="index.php?p=${k.id}" data-id="${k.id}">${esc(k.icon || '📄')} <span>${esc(k.title || 'Sans titre')}</span></a>`).join('')}
                    ${page.trashed ? '' : '<button type="button" class="btn-ghost" id="add-subpage">＋ Ajouter une sous-page</button>'}
                </div>
            </div>`;
        const title = $('.page-title');
        title.textContent = page.title;
        title.addEventListener('input', () => {
            page.title = title.textContent.trim();
            const crumb = $('#breadcrumb .crumb:last-child');
            if (crumb) crumb.textContent = `${page.icon || ''} ${page.title || 'Sans titre'}`.trim();
            document.title = `${page.title || 'Sans titre'} · ${boot.siteTitle}`;
            markDirty();
        });
        title.addEventListener('keydown', (e) => {
            if (e.key === 'Enter') { e.preventDefault(); state.editor?.caret.setToFirstBlock('start'); state.editor?.focus(); }
        });
        title.addEventListener('paste', (e) => { e.preventDefault(); document.execCommand('insertText', false, e.clipboardData.getData('text/plain').replace(/\s+/g, ' ')); });
        view.querySelectorAll('.subpage').forEach((a) => a.addEventListener('click', (e) => { e.preventDefault(); openPage(Number(a.dataset.id)); }));
        $('#add-subpage')?.addEventListener('click', (e) => openNewPageMenu(e.currentTarget, page.space, page.id));
        $('#use-template')?.addEventListener('click', () => newPage('team', null, '', page.id));
        $('#restore-btn')?.addEventListener('click', async () => { applySidebar(await api('restore', { id: page.id })); openPage(page.id, false); });
        $('#add-icon')?.addEventListener('click', (e) => openEmojiPicker(e.currentTarget));
        $('#page-icon').addEventListener('click', (e) => !page.trashed && openEmojiPicker(e.currentTarget));
        $('#add-cover')?.addEventListener('click', (e) => openCoverPicker(e.currentTarget));
        $('#cover-change').addEventListener('click', (e) => openCoverPicker(e.currentTarget));
        $('#cover-remove').addEventListener('click', () => setCover(''));

        state.revision = page.revision;
        const dbContext = {
            boot, toast, openModal, closeModal, openPage: (id) => openPage(id),
            apiGetDb: async (id) => {
                const res = await fetch('api/db.php?' + new URLSearchParams({ action: 'get', id }));
                const data = await res.json();
                if (!res.ok) throw new Error(data.error || 'Erreur');
                return data;
            },
        };
        $('.row-db-link')?.addEventListener('click', (e) => { e.preventDefault(); openPage(page.db.id); });
        if (page.kind === 'database') {
            state.editor = null;
            state.lastSaved = snapshotMeta(page);
            NotesDB.mount($('#db-root'), page, dbContext).catch((err) => { $('#db-root').textContent = err.message; });
            return;
        }
        if (page.kind === 'row') NotesDB.mountRowProps($('#row-props'), page, dbContext);
        state.editor = NotesEditor.create({
            holder: 'editor',
            data: page.content,
            readOnly: page.trashed,
            csrf: boot.csrf,
            uploadUrl: (kind) => `api/upload.php?page=${page.id}&kind=${kind}`,
            onError: (msg) => toast(msg, true),
            onChange: markDirty,
            onOpenPage: (id) => openPage(id),
            currentId: () => state.page?.id,
            createSubpage: async (t) => {
                const res = await api('create', { parentId: page.id, title: t });
                applySidebar(res);
                return res.page;
            },
            pageText: () => pageText(),
            onReady: async () => {
                // Version de référence : on n'enregistre que s'il y a une vraie différence
                state.lastSaved = await snapshot(page);
                if (!state.dirty) setStatus(page.updatedAt ? `Modifié ${page.updatedBy ? 'par ' + page.updatedBy + ' ' : ''}le ${page.updatedAt}` : '');
            },
        });
    }

    async function snapshot(page, content = null) {
        if (!state.editor) return snapshotMeta(page);
        content ??= await state.editor.save();
        return JSON.stringify({ t: page.title, i: page.icon, c: page.cover, b: content.blocks.map((b) => [b.type, b.data]) });
    }
    const snapshotMeta = (page) => JSON.stringify({ t: page.title, i: page.icon, c: page.cover });

    async function reloadMeta() {
        const { page } = await apiGet('get', { id: state.page.id });
        Object.assign(state.page, { breadcrumb: page.breadcrumb, space: page.space, parentId: page.parentId });
        renderTopbar();
    }

    // ---------- Icône et couverture ----------
    const EMOJIS = '📄 📝 📌 📎 📚 📖 🗂️ 📁 📊 📈 🗓️ ✅ ☑️ 💡 🔥 ⭐ 🎯 🚀 🎮 🎲 🎉 🎨 🎵 🎬 📷 🏠 🏢 🛠️ ⚙️ 🔧 🧪 🧠 💬 📣 📢 ❓ ❗ ⚠️ 🔒 🔑 💰 🛒 🍕 ☕ 🍔 🚗 ✈️ 🌍 🌱 🌞 🌙 ❤️ 💙 💚 💛 🧡 💜 👋 👍 🙌 🤝 👀 🐱 🐶 🦊 🐼 🏆 ⚽ 🎁 📦 🧾 🗒️ 🖊️ 💼 🎓 🏖️ 🎄 🎃'.split(' ');
    function openEmojiPicker(anchor) {
        const menu = $('#menu');
        menu.innerHTML = '<div class="emoji-grid"></div><div class="menu-row"><button type="button" class="btn-link" data-e="random">🎲 Au hasard</button><button type="button" class="btn-link" data-e="none">Retirer</button></div>';
        const grid = menu.querySelector('.emoji-grid');
        EMOJIS.forEach((em) => {
            const b = document.createElement('button');
            b.type = 'button';
            b.textContent = em;
            b.addEventListener('click', () => { setIcon(em); closeMenu(); });
            grid.append(b);
        });
        menu.querySelector('[data-e="random"]').addEventListener('click', () => { setIcon(EMOJIS[Math.floor(Math.random() * EMOJIS.length)]); closeMenu(); });
        menu.querySelector('[data-e="none"]').addEventListener('click', () => { setIcon(''); closeMenu(); });
        menu.hidden = false;
        const r = anchor.getBoundingClientRect();
        menu.style.top = `${r.bottom + 4}px`;
        menu.style.left = `${Math.max(8, Math.min(window.innerWidth - menu.offsetWidth - 8, r.left))}px`;
    }
    function setIcon(icon) {
        state.page.icon = icon;
        const btn = $('#page-icon');
        btn.textContent = icon;
        btn.hidden = !icon;
        $('#add-icon').hidden = !!icon;
        markDirty('meta');
    }
    function openCoverPicker(anchor) {
        const menu = $('#menu');
        menu.innerHTML = `<div class="cover-grid">${Array.from({ length: GRADIENTS }, (_, i) => `<button type="button" class="gradient-${i + 1}" data-g="gradient-${i + 1}"></button>`).join('')}</div>
            <div class="menu-row"><label class="btn-link">📤 Envoyer une image<input type="file" accept="image/*" hidden></label></div>`;
        menu.querySelectorAll('[data-g]').forEach((b) => b.addEventListener('click', () => { setCover(b.dataset.g); closeMenu(); }));
        menu.querySelector('input[type=file]').addEventListener('change', async (e) => {
            const file = e.target.files[0];
            if (!file) return;
            closeMenu();
            const body = new FormData();
            body.append('file', file);
            const res = await fetch(`api/upload.php?page=${state.page.id}&kind=image`, { method: 'POST', headers: { 'X-CSRF-Token': boot.csrf }, body });
            const data = await res.json().catch(() => ({}));
            data.success ? setCover(data.file.url) : toast(data.error || 'Envoi impossible.', true);
        });
        menu.hidden = false;
        const r = anchor.getBoundingClientRect();
        menu.style.top = `${r.bottom + 4}px`;
        menu.style.left = `${Math.max(8, Math.min(window.innerWidth - menu.offsetWidth - 8, r.left))}px`;
    }
    function setCover(cover) {
        state.page.cover = cover;
        const el = $('#cover');
        el.hidden = !cover;
        el.className = 'cover ' + (cover.startsWith('gradient-') ? cover : '');
        el.setAttribute('style', coverStyle(cover));
        $('#add-cover').hidden = !!cover;
        $('.page-inner').classList.toggle('with-cover', !!cover);
        markDirty('meta');
    }

    // ---------- Enregistrement automatique ----------
    function markDirty() {
        if (!state.page || state.page.trashed) return;
        state.dirty = true;
        setStatus('Modifications non enregistrées…');
        clearTimeout(markDirty.timer);
        markDirty.timer = setTimeout(save, 800);
    }
    document.addEventListener('notes:dirty', markDirty);

    async function save(force = false) {
        if (!state.page || (!state.editor && state.page.kind !== 'database') || state.conflict && !force) return;
        if (state.saving) { await state.saving; if (!state.dirty) return; }
        state.dirty = false;
        const page = state.page;
        state.saving = (async () => {
            try {
                const content = state.editor ? await state.editor.save() : null;
                const snap = await snapshot(page, content);
                if (!force && snap === state.lastSaved) {
                    if (state.page === page) setStatus('Enregistré ✔');
                    return;
                }
                setStatus('Enregistrement…');
                const res = await api('save', { id: page.id, revision: state.revision, title: page.title, icon: page.icon, cover: page.cover, ...(content ? { content } : {}), force });
                if (res.status === 409) {
                    state.conflict = true;
                    showConflict(res);
                    return;
                }
                state.revision = res.revision;
                state.lastSaved = snap;
                applySidebar(res);
                if (state.page === page) setStatus('Enregistré ✔');
            } catch (err) {
                state.dirty = true;
                setStatus('⚠️ Non enregistré');
                toast(err.message || 'Enregistrement impossible.', true);
            }
        })();
        await state.saving;
        state.saving = null;
    }
    async function flushSave() {
        clearTimeout(markDirty.timer);
        if (state.dirty) await save();
        else if (state.saving) await state.saving;
    }
    function showConflict(info) {
        const bar = $('#conflict-bar');
        bar.innerHTML = `⚠️ Cette page a été modifiée par <strong>${esc(info.updatedBy)}</strong> le ${esc(info.updatedAt)} pendant que tu l'éditais.
            <button type="button" class="btn-secondary" id="conflict-reload">Charger sa version</button>
            <button type="button" class="btn-link" id="conflict-force">Garder la mienne (écraser)</button>`;
        bar.hidden = false;
        setStatus('⚠️ Conflit');
        $('#conflict-reload').addEventListener('click', () => { state.dirty = false; state.conflict = false; openPage(state.page.id, false); });
        $('#conflict-force').addEventListener('click', () => { bar.hidden = true; state.conflict = false; state.dirty = true; save(true); });
    }
    window.addEventListener('beforeunload', (e) => {
        if (state.dirty || state.saving) { e.preventDefault(); e.returnValue = ''; }
    });

    // ---------- Barre du haut ----------
    $('#fav-btn').addEventListener('click', () => toggleFavorite(state.page.id, !state.page.favorite));
    $('#page-menu-btn').addEventListener('click', (e) => {
        const p = state.page;
        openMenu(e.currentTarget, [
            { label: p.favorite ? '☆ Retirer des favoris' : '⭐ Ajouter aux favoris', action: () => toggleFavorite(p.id, !p.favorite) },
            { label: '⧉ Dupliquer', action: () => duplicatePage(p.id) },
            { label: '↪ Déplacer vers…', action: () => openMove(p.id) },
            { label: '🕘 Historique des versions', action: () => openHistory(p.id) },
            { label: p.isTemplate ? '📄 Retirer des modèles' : '🧩 Utiliser comme modèle', action: async () => {
                applySidebar(await api('template', { id: p.id, on: !p.isTemplate }));
                p.isTemplate = !p.isTemplate;
                renderPage();
                toast(p.isTemplate ? 'Page ajoutée aux modèles.' : 'Page retirée des modèles.');
            } },
            { label: '⬇️ Exporter en Markdown', action: () => { location.href = `export.php?p=${p.id}`; } },
            { label: '🖨️ Imprimer / PDF', action: () => window.print() },
            '-',
            { label: '🗑️ Mettre à la corbeille', danger: true, action: () => trashPage(p.id) },
            '-',
            { info: `Modifiée le ${p.updatedAt}${p.updatedBy ? ' par ' + p.updatedBy : ''}` },
        ]);
    });
    $('#share-btn').addEventListener('click', () => {
        const p = state.page;
        const draw = (box) => {
            box.innerHTML = `<h2>Partager « ${esc(p.title || 'Sans titre')} »</h2>
                <p class="muted">${p.space === 'private' ? '🔒 Page privée : visible par toi seul dans l\'espace.' : `👥 Visible par tous les membres de l'espace ${esc(boot.teamName)}.`}</p>
                <label class="switch"><input type="checkbox" id="share-toggle" ${p.shareUrl ? 'checked' : ''}> Publier sur le web (lecture seule, avec un lien secret)</label>
                ${p.shareUrl ? `<div class="copy-row"><input type="text" readonly value="${esc(p.shareUrl)}" id="share-url"><button type="button" class="btn-primary" id="share-copy">Copier</button></div>
                <p class="muted small">Toute personne qui a ce lien peut lire la page (sans pouvoir la modifier). Les sous-pages ne sont pas partagées.</p>` : ''}
                <div class="modal-actions"><button type="button" class="btn-secondary" data-close>Fermer</button></div>`;
            box.querySelector('[data-close]').addEventListener('click', closeModal);
            box.querySelector('#share-toggle').addEventListener('change', async (e) => {
                const res = await api('share', { id: p.id, on: e.target.checked });
                p.shareUrl = res.shareUrl;
                applySidebar(res);
                renderTopbar();
                draw(box);
            });
            box.querySelector('#share-copy')?.addEventListener('click', async (e) => {
                await navigator.clipboard.writeText(p.shareUrl).catch(() => { box.querySelector('#share-url').select(); document.execCommand('copy'); });
                e.target.textContent = 'Copié ✔';
            });
        };
        openModal('', draw);
    });

    // Assistant IA sur toute la page
    async function pageText() {
        if (!state.editor) return '';
        const data = await state.editor.save();
        const tmp = document.createElement('div');
        const lines = [state.page.title];
        data.blocks.forEach((b) => {
            const d = b.data || {};
            const parts = [d.text, d.title, d.code, d.caption, ...(d.items || []).map((i) => (typeof i === 'string' ? i : i.content)), ...(d.content || []).flat()];
            parts.filter(Boolean).forEach((html) => { tmp.innerHTML = String(html); lines.push(tmp.textContent); });
        });
        return lines.join('\n').slice(0, 30000);
    }
    $('#ai-page-btn').addEventListener('click', (e) => {
        const run = async (action, prompt, where) => {
            const text = await pageText();
            if (action !== 'custom' && text.trim().length < 3) { toast('La page est vide.', true); return; }
            setStatus('✨ L\'IA écrit…');
            const res = await NotesAI.request(action, prompt, text);
            if (res.error) { setStatus(''); toast(res.error, true); return; }
            const count = state.editor.blocks.getBlocksCount();
            const blocks = where === 'top'
                ? [{ type: 'callout', data: { emoji: '📝', color: 'blue', text: '<b>Résumé</b>' } }, ...res.blocks, { type: 'delimiter', data: {} }]
                : res.blocks;
            NotesAI.insertBlocks(state.editor, blocks, where === 'top' ? 0 : count, false);
            markDirty();
            toast('Texte ajouté par l\'IA ✨');
        };
        openMenu(e.currentTarget, [
            { label: '📝 Résumer la page (en haut)', action: () => run('summarize', '', 'top') },
            { label: '✍️ Continuer d\'écrire (à la fin)', action: () => run('continue', '', 'end') },
            { label: '☑️ Extraire les tâches (à la fin)', action: () => run('actions', '', 'end') },
            { label: '💬 Demander à l\'IA…', action: () => {
                openModal(`<h2>✨ Demander à l'IA</h2><p class="muted small">L'IA connaît le contenu de la page. Le résultat est ajouté à la fin.</p>
                    <textarea id="ai-prompt" rows="4" placeholder="ex. Rédige un e-mail d'invitation à partir de ces notes"></textarea>
                    <div class="modal-actions"><button type="button" class="btn-secondary" data-close>Annuler</button><button type="button" class="btn-primary" id="ai-go">Générer</button></div>`, (box) => {
                    box.querySelector('[data-close]').addEventListener('click', closeModal);
                    box.querySelector('#ai-go').addEventListener('click', () => {
                        const prompt = box.querySelector('#ai-prompt').value.trim();
                        if (!prompt) return;
                        closeModal();
                        run('custom', prompt, 'end');
                    });
                    box.querySelector('#ai-prompt').focus();
                });
            } },
            ...(boot.aiEnabled ? [] : ['-', { info: 'Assistant non configuré : ajoute une clé OpenAI dans l\'administration.' }]),
        ]);
    });

    // ---------- Historique des versions ----------
    async function openHistory(id) {
        await flushSave();
        const { versions } = await apiGet('versions', { id });
        let preview = null;
        openModal(`<h2>🕘 Historique des versions</h2>
            ${versions.length ? '' : '<p class="muted">Aucune version précédente pour l\'instant. Une version est gardée avant chaque série de modifications (au plus toutes les 10 minutes par personne).</p>'}
            <div class="history" ${versions.length ? '' : 'hidden'}>
                <div class="history-list" id="history-list"></div>
                <div class="history-preview"><div class="muted small" id="history-info">Choisis une version à gauche.</div><div id="history-editor" class="editor"></div></div>
            </div>
            <div class="modal-actions"><button type="button" class="btn-secondary" data-close>Fermer</button>
                <button type="button" class="btn-primary" id="history-restore" disabled>Restaurer cette version</button></div>`, (box) => {
            box.classList.add('wide');
            box.querySelector('[data-close]').addEventListener('click', () => { box.classList.remove('wide'); closeModal(); });
            const list = box.querySelector('#history-list');
            let selected = null;
            versions.forEach((v) => {
                const b = document.createElement('button');
                b.type = 'button';
                b.className = 'history-item';
                b.innerHTML = `<strong>${esc(v.date)}</strong><span class="muted small">${esc(v.author || '—')} · ${esc(v.icon || '')} ${esc(v.title || 'Sans titre')}</span>`;
                b.addEventListener('click', async () => {
                    list.querySelectorAll('.active').forEach((x) => x.classList.remove('active'));
                    b.classList.add('active');
                    selected = v.id;
                    const { version } = await apiGet('version', { id, versionId: v.id });
                    box.querySelector('#history-info').textContent = `${version.icon || ''} ${version.title || 'Sans titre'} — version du ${v.date}`;
                    if (preview) { try { preview.destroy(); } catch { /* ignore */ } }
                    box.querySelector('#history-editor').innerHTML = '';
                    preview = NotesEditor.create({ holder: 'history-editor', data: version.content, readOnly: true, csrf: '', uploadUrl: () => '' });
                    box.querySelector('#history-restore').disabled = false;
                });
                list.append(b);
            });
            box.querySelector('#history-restore').addEventListener('click', async () => {
                if (!selected || !confirm('Remplacer le contenu actuel par cette version ? (la version actuelle reste dans l\'historique)')) return;
                const res = await api('restore_version', { id, versionId: selected });
                applySidebar(res);
                box.classList.remove('wide');
                closeModal();
                state.dirty = false;
                await openPage(id, false, res.page);
                toast('Version restaurée.');
            });
        });
    }

    // ---------- Recherche (Ctrl+K) ----------
    function openSearch() {
        closeSidebarMobile();
        openModal(`<input type="search" class="search-input" id="search-input" placeholder="Rechercher une page…" autocomplete="off"><div class="search-results" id="search-results"></div>`, (box) => {
            const input = box.querySelector('#search-input');
            const results = box.querySelector('#search-results');
            let items = [];
            let active = 0;
            const draw = () => {
                results.innerHTML = '';
                items.forEach((it, i) => {
                    const a = document.createElement('a');
                    a.href = it.id ? `index.php?p=${it.id}` : '#';
                    a.className = 'search-item' + (i === active ? ' active' : '');
                    a.innerHTML = it.id
                        ? `<span>${esc(it.icon || '📄')}</span><span><strong>${esc(it.title || 'Sans titre')}</strong>${it.snippet ? `<small class="muted">${esc(it.snippet)}</small>` : ''}</span>`
                        : `<span>＋</span><span>${esc(it.label)}</span>`;
                    a.addEventListener('click', (e) => { e.preventDefault(); choose(it); });
                    results.append(a);
                });
            };
            const choose = (it) => { closeModal(); it.id ? openPage(it.id) : newPage('team', null, it.title); };
            let timer;
            input.addEventListener('input', () => {
                clearTimeout(timer);
                timer = setTimeout(async () => {
                    const q = input.value.trim();
                    if (!q) { items = recentFromTree(); active = 0; draw(); return; }
                    const { results: found } = await apiGet('search', { q });
                    items = [...found, { label: `Créer la page « ${q} »`, title: q }];
                    active = 0;
                    draw();
                }, 200);
            });
            input.addEventListener('keydown', (e) => {
                if (e.key === 'ArrowDown') { e.preventDefault(); active = Math.min(items.length - 1, active + 1); draw(); }
                if (e.key === 'ArrowUp') { e.preventDefault(); active = Math.max(0, active - 1); draw(); }
                if (e.key === 'Enter' && items[active]) { e.preventDefault(); choose(items[active]); }
            });
            items = recentFromTree();
            draw();
            input.focus();
        });
    }
    const recentFromTree = () => state.tree.slice(0, 8).map((p) => ({ id: p.id, title: p.title, icon: p.icon }));
    $('#open-search').addEventListener('click', openSearch);

    // ---------- Corbeille ----------
    async function openTrash() {
        closeSidebarMobile();
        const { pages } = await apiGet('trash_list');
        openModal(`<h2>🗑️ Corbeille</h2><div id="trash-list">${pages.length ? '' : '<p class="muted">La corbeille est vide.</p>'}</div>
            <div class="modal-actions"><button type="button" class="btn-secondary" data-close>Fermer</button></div>`, (box) => {
            box.querySelector('[data-close]').addEventListener('click', closeModal);
            const list = box.querySelector('#trash-list');
            pages.forEach((p) => {
                const row = document.createElement('div');
                row.className = 'trash-row';
                row.innerHTML = `<a href="index.php?p=${p.id}">${esc(p.icon || '📄')} ${esc(p.title || 'Sans titre')}</a><span class="muted small">${esc(p.trashed)}</span>
                    <button type="button" class="btn-link" data-a="restore">Restaurer</button><button type="button" class="btn-link danger" data-a="delete">Supprimer</button>`;
                row.querySelector('a').addEventListener('click', (e) => { e.preventDefault(); closeModal(); openPage(p.id); });
                row.querySelector('[data-a="restore"]').addEventListener('click', async () => { applySidebar(await api('restore', { id: p.id })); row.remove(); toast('Page restaurée.'); });
                row.querySelector('[data-a="delete"]').addEventListener('click', async () => {
                    if (!confirm('Supprimer définitivement cette page et ses sous-pages ?')) return;
                    await api('delete', { id: p.id });
                    row.remove();
                    if (state.page?.id === p.id) showHome();
                });
                list.append(row);
            });
        });
    }
    $('#open-trash').addEventListener('click', openTrash);

    // ---------- Déplacer vers… ----------
    function openMove(id) {
        const subtree = new Set([id]);
        let grew = true;
        while (grew) { grew = false; state.tree.forEach((p) => { if (p.parentId && subtree.has(p.parentId) && !subtree.has(p.id)) { subtree.add(p.id); grew = true; } }); }
        const targets = state.tree.filter((p) => !subtree.has(p.id));
        openModal(`<h2>Déplacer la page</h2><input type="search" id="move-filter" placeholder="Filtrer…"><div class="move-list" id="move-list"></div>
            <div class="modal-actions"><button type="button" class="btn-secondary" data-close>Annuler</button></div>`, (box) => {
            box.querySelector('[data-close]').addEventListener('click', closeModal);
            const list = box.querySelector('#move-list');
            const draw = (q = '') => {
                list.innerHTML = '';
                const options = [{ label: `📂 Racine de ${boot.teamName}`, payload: { parentId: null, space: 'team' } },
                                 { label: '🔒 Racine de Privé', payload: { parentId: null, space: 'private' } },
                                 ...targets.filter((p) => (p.title || 'Sans titre').toLowerCase().includes(q)).map((p) => ({
                                     label: `${p.icon || '📄'} ${p.title || 'Sans titre'}${p.space === 'private' ? ' 🔒' : ''}`, payload: { parentId: p.id } }))];
                options.forEach((o) => {
                    const b = document.createElement('button');
                    b.type = 'button';
                    b.className = 'move-item';
                    b.textContent = o.label;
                    b.addEventListener('click', () => { closeModal(); movePage(id, o.payload); });
                    list.append(b);
                });
            };
            box.querySelector('#move-filter').addEventListener('input', (e) => draw(e.target.value.trim().toLowerCase()));
            draw();
        });
    }

    // ---------- Menu utilisateur, mobile ----------
    $('#user-menu-btn').addEventListener('click', (e) => openMenu(e.currentTarget, [
        { label: '👤 Mon compte', action: () => { location.href = 'compte.php'; } },
        ...(boot.user.isAdmin ? [{ label: '⚙️ Administration', action: () => { location.href = 'admin.php'; } }] : []),
        '-',
        { label: '↩ Se déconnecter', action: async () => { await flushSave(); $('#logout-form').submit(); } },
    ]));
    document.querySelectorAll('[data-new-page]').forEach((b) => b.addEventListener('click', (e) => openNewPageMenu(e.currentTarget, b.dataset.newPage)));
    $('#templates-fold').addEventListener('click', () => {
        const list = $('[data-list="templates"]');
        list.hidden = !list.hidden;
        $('#templates-fold').textContent = (list.hidden ? '▸' : '▾') + ' Modèles';
    });
    $('[data-nav="home"]').addEventListener('click', (e) => { e.preventDefault(); showHome(); closeSidebarMobile(); });
    $('#sidebar-open').addEventListener('click', () => document.body.classList.add('sidebar-open'));
    $('#sidebar-close').addEventListener('click', closeSidebarMobile);
    $('#sidebar-overlay').addEventListener('click', closeSidebarMobile);
    function closeSidebarMobile() { document.body.classList.remove('sidebar-open'); }

    // ---------- Application installable (PWA) ----------
    if ('serviceWorker' in navigator) {
        navigator.serviceWorker.register('sw.js').catch(() => { /* facultatif */ });
    }

    // ---------- Démarrage ----------
    (async () => {
        applySidebar(await apiGet('sidebar'));
        if (boot.page) {
            state.page = boot.page;
            boot.page.breadcrumb.forEach((c) => state.expanded.add(c.id));
            renderSidebar();
            renderPage();
        } else if (boot.missing) {
            renderMissing();
        } else {
            showHome(false);
        }
    })();
})();
