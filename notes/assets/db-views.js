/* Bases de données : affichage (barre d'outils, Tableau, Kanban, Calendrier) et page d'une ligne. */
(() => {
    const P = window.NotesDBParts;
    const { esc, el, uid, COLORS, COLOR_NAMES, TYPES, VIEW_TYPES, MONTHS, DAYS, dbApi, openPop, closePop, propEditor, valueHtml, tag, dateLabel } = P;
    let S = null;
    let ctx = null;

    // ---------- Chargement ----------
    async function mount(root, page, context) {
        ctx = context;
        P.setCtx(context);
        root.innerHTML = '<div class="muted">Chargement…</div>';
        const data = await ctx.apiGetDb(page.id);
        S = { root, page, config: data.config, rows: data.rows, users: data.users, icalUrl: data.icalUrl,
              viewId: sessionStorage.getItem('db-view-' + page.id) || data.config.views[0].id, search: '', month: null };
        if (!S.config.views.some((v) => v.id === S.viewId)) S.viewId = S.config.views[0].id;
        P.setState(S);
        render();
    }
    function unmount() { closePop(); S = null; }
    const view = () => S.config.views.find((v) => v.id === S.viewId) || S.config.views[0];

    // ---------- Enregistrements ----------
    let configTimer = null;
    function saveConfig(rerender = true) {
        if (rerender) render();
        clearTimeout(configTimer);
        configTimer = setTimeout(async () => {
            try { S.config = (await dbApi('config', { id: S.page.id, config: S.config })).config; } catch (err) { ctx.toast(err.message, true); }
        }, 400);
    }
    async function updateRow(row, changes) {
        if (changes.props) row.props = { ...row.props, ...changes.props };
        if ('title' in changes) row.title = changes.title;
        try {
            const res = await dbApi('row_update', { id: row.id, ...changes });
            Object.assign(row, res.row);
        } catch (err) { ctx.toast(err.message, true); }
    }
    async function createRow(props = {}, title = '') {
        try {
            const { row } = await dbApi('row_create', { dbId: S.page.id, title, props });
            S.rows.push(row);
            return row;
        } catch (err) { ctx.toast(err.message, true); return null; }
    }
    async function addOption(prop, name) {
        const option = { id: uid('o'), name, color: COLORS[(prop.options || []).length % COLORS.length] };
        prop.options = [...(prop.options || []), option];
        // Enregistrée tout de suite : la valeur de la ligne qui l'utilise part juste après
        clearTimeout(configTimer);
        try { S.config = (await dbApi('config', { id: S.page.id, config: S.config })).config; } catch (err) { ctx.toast(err.message, true); }
        return option;
    }

    // ---------- Filtres, tri, recherche ----------
    const rowValue = (row, propId) => (propId === 'title' ? row.title : row.props?.[propId]);
    function textOf(prop, v) {
        if (!prop) return String(v ?? '');
        if (prop.type === 'select') return P.optionOf(prop, v)?.name || '';
        if (prop.type === 'multi_select') return (v || []).map((id) => P.optionOf(prop, id)?.name || '').join(' ');
        if (prop.type === 'person') return P.userName(v);
        if (prop.type === 'date') return v?.start || '';
        return String(v ?? '');
    }
    function matchFilter(row, f) {
        const prop = f.prop === 'title' ? null : P.propById(f.prop);
        const v = rowValue(row, f.prop);
        const empty = v === null || v === undefined || v === '' || (Array.isArray(v) && !v.length);
        const t = textOf(prop, v).toLowerCase();
        const q = (f.value || '').toLowerCase();
        switch (f.op) {
            case 'empty': return empty;
            case 'not_empty': return !empty;
            case 'checked': return !!v;
            case 'unchecked': return !v;
            case 'is': return prop?.type === 'multi_select' ? (v || []).includes(f.value) : prop && ['select', 'person'].includes(prop.type) ? String(v) === f.value : t === q;
            case 'is_not': return prop?.type === 'multi_select' ? !(v || []).includes(f.value) : prop && ['select', 'person'].includes(prop.type) ? String(v) !== f.value : t !== q;
            case 'not_contains': return !t.includes(q);
            case 'before': return !!v?.start && v.start.slice(0, 10) < f.value;
            case 'after': return !!v?.start && v.start.slice(0, 10) > f.value;
            case 'gt': return v !== null && v !== undefined && Number(v) > Number(f.value);
            case 'lt': return v !== null && v !== undefined && Number(v) < Number(f.value);
            default: return t.includes(q); // contains
        }
    }
    function visibleRows() {
        const v = view();
        let rows = S.rows.filter((r) => (v.filters || []).every((f) => matchFilter(r, f)));
        const q = S.search.trim().toLowerCase();
        if (q) rows = rows.filter((r) => [r.title, ...S.config.properties.map((p) => textOf(p, r.props?.[p.id]))].join(' ').toLowerCase().includes(q));
        if (v.sort) {
            const prop = v.sort.prop === 'title' ? null : P.propById(v.sort.prop);
            const key = (r) => {
                const x = rowValue(r, v.sort.prop);
                if (prop?.type === 'number') return x ?? -Infinity;
                if (prop?.type === 'select') return (prop.options || []).findIndex((o) => o.id === x);
                if (prop?.type === 'checkbox') return x ? 1 : 0;
                return textOf(prop, x).toLowerCase() || '￿';
            };
            rows = [...rows].sort((a, b) => { const ka = key(a); const kb = key(b); return (ka < kb ? -1 : ka > kb ? 1 : 0) * (v.sort.dir === 'desc' ? -1 : 1); });
        }
        return rows;
    }

    // ---------- Barre d'outils ----------
    function render() {
        if (!S) return;
        const v = view();
        sessionStorage.setItem('db-view-' + S.page.id, v.id);
        const root = S.root;
        root.innerHTML = '';
        const bar = el('div', 'db-toolbar');
        const tabs = el('div', 'db-tabs');
        S.config.views.forEach((vw) => {
            const t = el('button', 'db-tab' + (vw.id === v.id ? ' active' : ''), { type: 'button', html: `${VIEW_TYPES[vw.type][1]} ${esc(vw.name)}` });
            t.addEventListener('click', () => { if (vw.id === v.id) viewMenu(t); else { S.viewId = vw.id; render(); } });
            tabs.append(t);
        });
        const addView = el('button', 'db-tab db-tab-add db-pop-anchor', { type: 'button', title: 'Ajouter une vue', text: '＋' });
        addView.addEventListener('click', () => openPop(addView, (p) => {
            Object.entries(VIEW_TYPES).forEach(([type, [label, icon]]) => {
                const item = el('button', 'db-pop-item', { type: 'button', text: `${icon} ${label}` });
                item.addEventListener('click', () => {
                    const nv = { id: uid('v'), name: label, type, filters: [], hidden: [] };
                    if (type === 'board') nv.groupBy = S.config.properties.find((x) => x.type === 'select')?.id;
                    if (type === 'calendar') nv.dateProp = S.config.properties.find((x) => x.type === 'date')?.id;
                    S.config.views.push(nv);
                    S.viewId = nv.id;
                    closePop();
                    saveConfig();
                });
                p.append(item);
            });
        }));
        tabs.append(addView);

        const tools = el('div', 'db-tools');
        const search = el('input', 'db-search', { type: 'search', placeholder: '🔍 Rechercher' });
        search.value = S.search;
        search.addEventListener('input', () => { S.search = search.value; renderBody(); });
        const filterBtn = el('button', 'db-tool db-pop-anchor' + ((v.filters || []).length ? ' on' : ''), { type: 'button', text: `Filtre${(v.filters || []).length ? ' (' + v.filters.length + ')' : ''}` });
        filterBtn.addEventListener('click', () => filterMenu(filterBtn));
        const sortBtn = el('button', 'db-tool db-pop-anchor' + (v.sort ? ' on' : ''), { type: 'button', text: v.sort ? `Tri : ${esc(propName(v.sort.prop))} ${v.sort.dir === 'asc' ? '↑' : '↓'}` : 'Tri' });
        sortBtn.addEventListener('click', () => sortMenu(sortBtn));
        const more = el('button', 'db-tool db-pop-anchor', { type: 'button', title: 'Plus', text: '⋯' });
        more.addEventListener('click', () => moreMenu(more));
        const add = el('button', 'btn-primary db-new', { type: 'button', text: '＋ Nouveau' });
        add.addEventListener('click', async () => {
            const props = {};
            (v.filters || []).forEach((f) => { if (f.op === 'is' && f.value) { const pr = P.propById(f.prop); if (pr && ['select', 'person'].includes(pr.type)) props[f.prop] = pr.type === 'person' ? Number(f.value) : f.value; } });
            const row = await createRow(props);
            if (row) { renderBody(); focusRowTitle(row.id); }
        });
        tools.append(search, filterBtn, sortBtn, more, add);
        bar.append(tabs, tools);
        root.append(bar);
        S.body = el('div', 'db-body');
        root.append(S.body);
        renderBody();
    }
    const propName = (id) => (id === 'title' ? 'Nom' : P.propById(id)?.name || '?');

    function renderBody() {
        if (!S) return;
        S.body.innerHTML = '';
        const v = view();
        if (v.type === 'board') renderBoard(S.body, v);
        else if (v.type === 'calendar') renderCalendar(S.body, v);
        else renderTable(S.body, v);
    }

    function viewMenu(anchor) {
        const v = view();
        openPop(anchor, (p) => {
            const name = el('input', 'db-input', { type: 'text', value: v.name });
            name.addEventListener('change', () => { v.name = name.value.trim() || 'Vue'; saveConfig(); });
            p.append(el('div', 'db-pop-label', { text: 'Nom de la vue' }), name);
            if (v.type === 'board' || v.type === 'calendar') {
                const want = v.type === 'board' ? 'select' : 'date';
                const key = v.type === 'board' ? 'groupBy' : 'dateProp';
                const sel = el('select', 'db-input');
                const candidates = S.config.properties.filter((x) => x.type === want);
                sel.innerHTML = candidates.map((x) => `<option value="${esc(x.id)}" ${x.id === v[key] ? 'selected' : ''}>${esc(x.name)}</option>`).join('') || '<option value="">(aucune)</option>';
                sel.addEventListener('change', () => { v[key] = sel.value; saveConfig(); });
                p.append(el('div', 'db-pop-label', { text: v.type === 'board' ? 'Colonnes selon' : 'Date utilisée' }), sel);
            }
            const dup = el('button', 'db-pop-item', { type: 'button', text: '⧉ Dupliquer la vue' });
            dup.addEventListener('click', () => { const copy = JSON.parse(JSON.stringify(v)); copy.id = uid('v'); copy.name += ' (copie)'; S.config.views.push(copy); S.viewId = copy.id; closePop(); saveConfig(); });
            p.append(dup);
            if (S.config.views.length > 1) {
                const del = el('button', 'db-pop-item danger', { type: 'button', text: '🗑️ Supprimer la vue' });
                del.addEventListener('click', () => { S.config.views = S.config.views.filter((x) => x.id !== v.id); S.viewId = S.config.views[0].id; closePop(); saveConfig(); });
                p.append(del);
            }
        });
    }

    const OPS = {
        text: [['contains', 'contient'], ['not_contains', 'ne contient pas'], ['is', 'est'], ['empty', 'est vide'], ['not_empty', 'n\'est pas vide']],
        url: [['contains', 'contient'], ['empty', 'est vide'], ['not_empty', 'n\'est pas vide']],
        number: [['is', '='], ['gt', '>'], ['lt', '<'], ['empty', 'est vide'], ['not_empty', 'n\'est pas vide']],
        select: [['is', 'est'], ['is_not', 'n\'est pas'], ['empty', 'est vide'], ['not_empty', 'n\'est pas vide']],
        multi_select: [['is', 'contient'], ['is_not', 'ne contient pas'], ['empty', 'est vide'], ['not_empty', 'n\'est pas vide']],
        person: [['is', 'est'], ['is_not', 'n\'est pas'], ['empty', 'est vide'], ['not_empty', 'n\'est pas vide']],
        date: [['before', 'avant le'], ['after', 'après le'], ['empty', 'est vide'], ['not_empty', 'n\'est pas vide']],
        checkbox: [['checked', 'est cochée'], ['unchecked', 'n\'est pas cochée']],
    };
    function filterMenu(anchor) {
        const v = view();
        v.filters = v.filters || [];
        const label = () => {
            anchor.textContent = `Filtre${v.filters.length ? ' (' + v.filters.length + ')' : ''}`;
            anchor.classList.toggle('on', v.filters.length > 0);
        };
        openPop(anchor, (p) => {
            const draw = () => {
                label();
                p.innerHTML = '';
                if (!v.filters.length) p.append(el('div', 'db-pop-empty', { text: 'Aucun filtre pour cette vue.' }));
                v.filters.forEach((f, i) => {
                    const row = el('div', 'db-filter-row');
                    const propSel = el('select', 'db-input');
                    propSel.innerHTML = `<option value="title">Nom</option>` + S.config.properties.map((x) => `<option value="${esc(x.id)}">${esc(x.name)}</option>`).join('');
                    propSel.value = f.prop;
                    const prop = f.prop === 'title' ? { type: 'text' } : P.propById(f.prop) || { type: 'text' };
                    const opSel = el('select', 'db-input');
                    opSel.innerHTML = OPS[prop.type].map(([o, l]) => `<option value="${o}">${l}</option>`).join('');
                    opSel.value = OPS[prop.type].some(([o]) => o === f.op) ? f.op : OPS[prop.type][0][0];
                    f.op = opSel.value;
                    let valueEl;
                    if (['select', 'multi_select'].includes(prop.type)) {
                        valueEl = el('select', 'db-input');
                        valueEl.innerHTML = (prop.options || []).map((o) => `<option value="${esc(o.id)}">${esc(o.name)}</option>`).join('');
                    } else if (prop.type === 'person') {
                        valueEl = el('select', 'db-input');
                        valueEl.innerHTML = S.users.map((u) => `<option value="${u.id}">${esc(u.name)}</option>`).join('');
                    } else {
                        valueEl = el('input', 'db-input', { type: prop.type === 'date' ? 'date' : prop.type === 'number' ? 'number' : 'text', placeholder: 'Valeur' });
                    }
                    valueEl.value = f.value || (valueEl.tagName === 'SELECT' ? valueEl.options[0]?.value || '' : '');
                    f.value = valueEl.value;
                    valueEl.hidden = ['empty', 'not_empty', 'checked', 'unchecked'].includes(f.op);
                    const del = el('button', 'icon-btn', { type: 'button', title: 'Retirer', text: '✕' });
                    propSel.addEventListener('change', () => { f.prop = propSel.value; f.op = ''; f.value = ''; draw(); saveConfig(false); renderBody(); });
                    opSel.addEventListener('change', () => { f.op = opSel.value; draw(); saveConfig(false); renderBody(); });
                    valueEl.addEventListener('change', () => { f.value = valueEl.value; saveConfig(false); renderBody(); });
                    del.addEventListener('click', () => { v.filters.splice(i, 1); draw(); saveConfig(false); renderBody(); });
                    row.append(propSel, opSel, valueEl, del);
                    p.append(row);
                });
                const add = el('button', 'db-pop-item', { type: 'button', text: '＋ Ajouter un filtre' });
                add.addEventListener('click', () => { v.filters.push({ prop: 'title', op: 'contains', value: '' }); draw(); });
                p.append(add);
            };
            draw();
        }, 'db-pop-wide');
    }
    function sortMenu(anchor) {
        const v = view();
        openPop(anchor, (p) => {
            const sel = el('select', 'db-input');
            sel.innerHTML = `<option value="">— Aucun tri —</option><option value="title">Nom</option>` + S.config.properties.map((x) => `<option value="${esc(x.id)}">${esc(x.name)}</option>`).join('');
            sel.value = v.sort?.prop || '';
            const dir = el('select', 'db-input');
            dir.innerHTML = '<option value="asc">Croissant ↑</option><option value="desc">Décroissant ↓</option>';
            dir.value = v.sort?.dir || 'asc';
            const apply = () => { v.sort = sel.value ? { prop: sel.value, dir: dir.value } : undefined; saveConfig(); };
            sel.addEventListener('change', apply);
            dir.addEventListener('change', apply);
            p.append(el('div', 'db-pop-label', { text: 'Trier par' }), sel, dir);
        });
    }
    function moreMenu(anchor) {
        const v = view();
        openPop(anchor, (p) => {
            const csv = el('a', 'db-pop-item', { href: `csv.php?id=${S.page.id}`, text: '⬇️ Exporter en CSV (Excel)' });
            const ical = el('button', 'db-pop-item', { type: 'button', text: '📅 S\'abonner au calendrier (iCal)' });
            ical.addEventListener('click', () => { closePop(); icalModal(); });
            const props = el('button', 'db-pop-item', { type: 'button', text: '⚙️ Propriétés' });
            props.addEventListener('click', () => { closePop(); propertiesModal(); });
            p.append(props, csv, ical);
            if (v.type !== 'calendar') {
                p.append(el('div', 'db-pop-label', { text: 'Propriétés affichées dans cette vue' }));
                S.config.properties.forEach((x) => {
                    const lab = el('label', 'db-check-label', { html: `<input type="checkbox" ${(v.hidden || []).includes(x.id) ? '' : 'checked'}> ${esc(x.name)}` });
                    lab.querySelector('input').addEventListener('change', (e) => {
                        v.hidden = (v.hidden || []).filter((h) => h !== x.id);
                        if (!e.target.checked) v.hidden.push(x.id);
                        saveConfig(false);
                        renderBody();
                    });
                    p.append(lab);
                });
            }
        });
    }

    // ---------- Propriétés (ajout, modification, suppression) ----------
    function propertyMenu(anchor, prop) {
        openPop(anchor, (p) => {
            const name = el('input', 'db-input', { type: 'text', value: prop.name });
            name.addEventListener('change', () => { prop.name = name.value.trim() || 'Propriété'; saveConfig(); });
            const type = el('select', 'db-input');
            type.innerHTML = Object.entries(TYPES).map(([t, [l, i]]) => `<option value="${t}" ${t === prop.type ? 'selected' : ''}>${i} ${l}</option>`).join('');
            type.addEventListener('change', () => {
                if (!confirm('Changer le type peut effacer les valeurs incompatibles. Continuer ?')) { type.value = prop.type; return; }
                prop.type = type.value;
                if (['select', 'multi_select'].includes(prop.type)) prop.options = prop.options || [];
                closePop();
                saveConfig();
            });
            p.append(el('div', 'db-pop-label', { text: 'Nom' }), name, el('div', 'db-pop-label', { text: 'Type' }), type);
            if (prop.type === 'date') {
                const lab = el('label', 'db-check-label', { html: `<input type="checkbox" ${prop.withTime ? 'checked' : ''}> Inclure l'heure` });
                lab.querySelector('input').addEventListener('change', (e) => { prop.withTime = e.target.checked; saveConfig(); });
                p.append(lab);
            }
            if (['select', 'multi_select'].includes(prop.type)) {
                p.append(el('div', 'db-pop-label', { text: 'Options' }));
                const list = el('div', 'db-options');
                const drawOptions = () => {
                    list.innerHTML = '';
                    (prop.options || []).forEach((o, i) => {
                        const row = el('div', 'db-option-row');
                        const n = el('input', 'db-input', { type: 'text', value: o.name });
                        n.addEventListener('change', () => { o.name = n.value.trim() || 'Option'; saveConfig(false); renderBody(); });
                        const c = el('select', `db-input db-color c-${o.color}`);
                        c.innerHTML = COLORS.map((col) => `<option value="${col}" ${col === o.color ? 'selected' : ''}>${COLOR_NAMES[col]}</option>`).join('');
                        c.addEventListener('change', () => { o.color = c.value; c.className = `db-input db-color c-${o.color}`; saveConfig(false); renderBody(); });
                        const del = el('button', 'icon-btn', { type: 'button', title: 'Supprimer l\'option', text: '✕' });
                        del.addEventListener('click', () => { prop.options.splice(i, 1); drawOptions(); saveConfig(false); renderBody(); });
                        row.append(n, c, del);
                        list.append(row);
                    });
                    const add = el('button', 'db-pop-item', { type: 'button', text: '＋ Ajouter une option' });
                    add.addEventListener('click', () => { prop.options = [...(prop.options || []), { id: uid('o'), name: 'Nouvelle option', color: COLORS[(prop.options || []).length % COLORS.length] }]; drawOptions(); saveConfig(false); });
                    list.append(add);
                };
                drawOptions();
                p.append(list);
            }
            const sortAsc = el('button', 'db-pop-item', { type: 'button', text: '↑ Trier croissant' });
            sortAsc.addEventListener('click', () => { view().sort = { prop: prop.id, dir: 'asc' }; closePop(); saveConfig(); });
            const sortDesc = el('button', 'db-pop-item', { type: 'button', text: '↓ Trier décroissant' });
            sortDesc.addEventListener('click', () => { view().sort = { prop: prop.id, dir: 'desc' }; closePop(); saveConfig(); });
            const hide = el('button', 'db-pop-item', { type: 'button', text: '🙈 Masquer dans cette vue' });
            hide.addEventListener('click', () => { const v = view(); v.hidden = [...(v.hidden || []), prop.id]; closePop(); saveConfig(); });
            const del = el('button', 'db-pop-item danger', { type: 'button', text: '🗑️ Supprimer la propriété' });
            del.addEventListener('click', () => {
                if (!confirm(`Supprimer la propriété « ${prop.name} » et ses valeurs ?`)) return;
                S.config.properties = S.config.properties.filter((x) => x.id !== prop.id);
                closePop();
                saveConfig();
            });
            p.append(sortAsc, sortDesc, hide, del);
        }, 'db-pop-wide');
    }
    function addPropertyMenu(anchor) {
        openPop(anchor, (p) => {
            const name = el('input', 'db-input', { type: 'text', placeholder: 'Nom de la propriété' });
            p.append(el('div', 'db-pop-label', { text: 'Nouvelle propriété' }), name);
            Object.entries(TYPES).forEach(([t, [l, i]]) => {
                const item = el('button', 'db-pop-item', { type: 'button', text: `${i} ${l}` });
                item.addEventListener('click', () => {
                    const prop = { id: uid('p'), name: name.value.trim() || l, type: t };
                    if (['select', 'multi_select'].includes(t)) prop.options = [];
                    if (t === 'date') prop.withTime = false;
                    S.config.properties.push(prop);
                    closePop();
                    saveConfig();
                });
                p.append(item);
            });
            setTimeout(() => name.focus(), 20);
        });
    }
    function propertiesModal() {
        ctx.openModal('<h2>⚙️ Propriétés</h2><p class="muted small">Clique sur une propriété pour la renommer, changer son type ou ses options.</p><div id="db-props-list"></div>', (box) => {
            const list = box.querySelector('#db-props-list');
            const draw = () => {
                list.innerHTML = '';
                S.config.properties.forEach((prop) => {
                    const b = el('button', 'db-pop-item db-pop-anchor', { type: 'button', text: `${TYPES[prop.type][1]} ${prop.name} — ${TYPES[prop.type][0]}` });
                    b.addEventListener('click', () => propertyMenu(b, prop));
                    list.append(b);
                });
                const add = el('button', 'db-pop-item db-pop-anchor', { type: 'button', text: '＋ Ajouter une propriété' });
                add.addEventListener('click', () => addPropertyMenu(add));
                list.append(add);
            };
            draw();
        });
    }
    function icalModal() {
        const draw = (box) => {
            box.innerHTML = `<h2>📅 Abonnement calendrier</h2>
                <p class="muted">Les lignes qui ont une date apparaissent dans ton agenda (Google Agenda, Outlook, iPhone…) et se mettent à jour automatiquement (en général toutes les quelques heures).</p>
                <label class="switch"><input type="checkbox" id="ical-toggle" ${S.icalUrl ? 'checked' : ''}> Activer le lien d'abonnement</label>
                ${S.icalUrl ? `<div class="copy-row"><input type="text" readonly value="${esc(S.icalUrl)}" id="ical-url"><button type="button" class="btn-primary" id="ical-copy">Copier</button></div>
                <ul class="muted small"><li><strong>Google Agenda</strong> : Autres agendas › ＋ › À partir de l'URL › coller le lien.</li>
                <li><strong>Outlook</strong> : Ajouter un calendrier › S'abonner à partir du web.</li>
                <li><strong>iPhone</strong> : <a href="${esc(S.icalUrl.replace(/^https?:/, 'webcal:'))}">ouvrir ce lien</a> sur le téléphone.</li></ul>
                <p class="muted small">⚠️ Toute personne qui a ce lien voit les dates et titres de cette base. Désactive-le pour le révoquer.</p>` : ''}
                <div class="modal-actions"><button type="button" class="btn-secondary" data-close>Fermer</button></div>`;
            box.querySelector('[data-close]').addEventListener('click', ctx.closeModal);
            box.querySelector('#ical-toggle').addEventListener('change', async (e) => {
                S.icalUrl = (await dbApi('ical', { id: S.page.id, on: e.target.checked })).icalUrl;
                draw(box);
            });
            box.querySelector('#ical-copy')?.addEventListener('click', async (e) => {
                await navigator.clipboard.writeText(S.icalUrl).catch(() => { box.querySelector('#ical-url').select(); document.execCommand('copy'); });
                e.target.textContent = 'Copié ✔';
            });
        };
        ctx.openModal('', draw);
    }

    // ---------- Vue Tableau ----------
    function focusRowTitle(id) {
        setTimeout(() => S?.body.querySelector(`[data-row="${id}"] .db-title-input`)?.focus(), 30);
    }
    function rowMenu(anchor, row) {
        openPop(anchor, (p) => {
            const open = el('button', 'db-pop-item', { type: 'button', text: '↗ Ouvrir comme page' });
            open.addEventListener('click', () => { closePop(); ctx.openPage(row.id); });
            const del = el('button', 'db-pop-item danger', { type: 'button', text: '🗑️ Supprimer' });
            del.addEventListener('click', async () => {
                closePop();
                await dbApi('row_trash', { id: row.id });
                S.rows = S.rows.filter((r) => r.id !== row.id);
                renderBody();
                ctx.toast('Ligne mise à la corbeille.');
            });
            p.append(open, del);
        });
    }
    function renderTable(body, v) {
        const props = S.config.properties.filter((x) => !(v.hidden || []).includes(x.id));
        const rows = visibleRows();
        const scroll = el('div', 'db-scroll');
        const table = el('table', 'db-table');
        const head = el('tr');
        const thTitle = el('th', 'db-th db-th-title db-pop-anchor', { html: '<span>Aa Nom</span>' });
        thTitle.addEventListener('click', () => openPop(thTitle, (p) => {
            [['asc', '↑ Trier A → Z'], ['desc', '↓ Trier Z → A']].forEach(([dir, label]) => {
                const b = el('button', 'db-pop-item', { type: 'button', text: label });
                b.addEventListener('click', () => { v.sort = { prop: 'title', dir }; closePop(); saveConfig(); });
                p.append(b);
            });
        }));
        head.append(thTitle);
        props.forEach((prop) => {
            const th = el('th', 'db-th db-pop-anchor', { html: `<span>${TYPES[prop.type][1]} ${esc(prop.name)}</span>` });
            th.addEventListener('click', () => propertyMenu(th, prop));
            head.append(th);
        });
        const thAdd = el('th', 'db-th db-th-add db-pop-anchor', { title: 'Ajouter une propriété', text: '＋' });
        thAdd.addEventListener('click', () => addPropertyMenu(thAdd));
        head.append(thAdd);
        const thead = el('thead');
        thead.append(head);
        const tbody = el('tbody');
        rows.forEach((row) => {
            const tr = el('tr', '', { 'data-row': row.id });
            const tdTitle = el('td', 'db-td-title');
            const handle = el('button', 'db-row-handle db-pop-anchor', { type: 'button', title: 'Actions (glisser pour déplacer)', text: '⋮⋮', draggable: v.sort ? 'false' : 'true' });
            handle.addEventListener('click', () => rowMenu(handle, row));
            const input = el('input', 'db-input db-title-input', { type: 'text', placeholder: 'Sans titre' });
            input.value = row.title;
            input.addEventListener('change', () => updateRow(row, { title: input.value.trim() }));
            input.addEventListener('keydown', async (e) => {
                if (e.key === 'Enter') {
                    e.preventDefault();
                    input.blur();
                    const next = await createRow();
                    if (next) { renderBody(); focusRowTitle(next.id); }
                }
            });
            const open = el('button', 'db-open', { type: 'button', text: '↗ Ouvrir' });
            open.addEventListener('click', () => ctx.openPage(row.id));
            tdTitle.append(handle, el('span', 'db-row-icon', { text: row.icon || '' }), input, open);
            tr.append(tdTitle);
            props.forEach((prop) => {
                const td = el('td');
                td.append(propEditor(prop, row.props?.[prop.id] ?? null, (val) => updateRow(row, { props: { [prop.id]: val } }), { addOption }));
                tr.append(td);
            });
            tr.append(el('td'));
            // Réorganiser les lignes (sans tri actif)
            handle.addEventListener('dragstart', (e) => { e.dataTransfer.setData('text/plain', String(row.id)); tr.classList.add('dragging'); });
            handle.addEventListener('dragend', () => tr.classList.remove('dragging'));
            tr.addEventListener('dragover', (e) => { if (!v.sort) { e.preventDefault(); tr.classList.add('drop-before'); } });
            tr.addEventListener('dragleave', () => tr.classList.remove('drop-before'));
            tr.addEventListener('drop', async (e) => {
                e.preventDefault();
                tr.classList.remove('drop-before');
                const id = Number(e.dataTransfer.getData('text/plain'));
                if (!id || id === row.id) return;
                const res = await dbApi('row_move', { id, beforeId: row.id });
                const moved = S.rows.find((r) => r.id === id);
                if (moved) moved.position = res.position;
                S.rows.sort((a, b) => a.position - b.position);
                renderBody();
            });
            tbody.append(tr);
        });
        table.append(thead, tbody);
        scroll.append(table);
        const add = el('button', 'db-add-row', { type: 'button', text: '＋ Nouvelle ligne' });
        add.addEventListener('click', async () => { const row = await createRow(); if (row) { renderBody(); focusRowTitle(row.id); } });
        body.append(scroll, add, el('div', 'db-count muted small', { text: `${rows.length} ligne${rows.length > 1 ? 's' : ''}${rows.length !== S.rows.length ? ` (sur ${S.rows.length})` : ''}` }));
    }

    // ---------- Vue Kanban ----------
    function renderBoard(body, v) {
        const prop = P.propById(v.groupBy) || S.config.properties.find((x) => x.type === 'select');
        if (!prop) {
            body.append(el('div', 'db-hint', { html: 'Le Kanban range les lignes en colonnes selon une propriété <strong>Sélection</strong> (ex. Statut).' }));
            const b = el('button', 'btn-primary', { type: 'button', text: '＋ Ajouter une propriété Statut' });
            b.addEventListener('click', () => {
                const id = uid('p');
                S.config.properties.push({ id, name: 'Statut', type: 'select', options: [{ id: uid('o'), name: 'À faire', color: 'gray' }, { id: uid('o'), name: 'En cours', color: 'blue' }, { id: uid('o'), name: 'Fait', color: 'green' }] });
                v.groupBy = id;
                saveConfig();
            });
            body.append(b);
            return;
        }
        v.groupBy = prop.id;
        const rows = visibleRows();
        const others = S.config.properties.filter((x) => x.id !== prop.id && !(v.hidden || []).includes(x.id));
        const board = el('div', 'db-board');
        const columns = [{ id: null, name: 'Sans ' + prop.name.toLowerCase(), color: 'gray' }, ...(prop.options || [])];
        columns.forEach((col) => {
            const colRows = rows.filter((r) => (r.props?.[prop.id] ?? null) === col.id);
            if (col.id === null && !colRows.length) return; // colonne « sans valeur » seulement si utile
            const column = el('div', 'db-column');
            column.innerHTML = `<div class="db-column-head">${col.id ? tag(col) : `<span class="db-tag c-gray">${esc(col.name)}</span>`}<span class="muted small">${colRows.length}</span></div>`;
            const list = el('div', 'db-column-list');
            colRows.forEach((row) => {
                const card = el('div', 'db-card', { draggable: 'true', 'data-row': row.id });
                card.innerHTML = `<div class="db-card-title">${esc(row.icon || '')} ${esc(row.title || 'Sans titre')}</div>
                    <div class="db-card-props">${others.map((x) => valueHtml(x, row.props?.[x.id])).filter(Boolean).join(' ')}</div>`;
                card.addEventListener('click', () => ctx.openPage(row.id));
                card.addEventListener('dragstart', (e) => { e.dataTransfer.setData('text/plain', String(row.id)); card.classList.add('dragging'); });
                card.addEventListener('dragend', () => card.classList.remove('dragging'));
                list.append(card);
            });
            column.addEventListener('dragover', (e) => { e.preventDefault(); column.classList.add('drop'); });
            column.addEventListener('dragleave', (e) => { if (!column.contains(e.relatedTarget)) column.classList.remove('drop'); });
            column.addEventListener('drop', async (e) => {
                e.preventDefault();
                column.classList.remove('drop');
                const row = S.rows.find((r) => r.id === Number(e.dataTransfer.getData('text/plain')));
                if (!row || (row.props?.[prop.id] ?? null) === col.id) return;
                renderBodyAfter(updateRow(row, { props: { [prop.id]: col.id } }));
            });
            const add = el('button', 'db-add-card', { type: 'button', text: '＋ Nouveau' });
            add.addEventListener('click', () => inlineCreate(add, async (title) => { await createRow({ [prop.id]: col.id }, title); renderBody(); }));
            column.append(list, add);
            board.append(column);
        });
        body.append(board);
    }
    function renderBodyAfter(promise) { renderBody(); promise.then(renderBody); }

    /** Petit champ « titre » pour créer une ligne sur place (Kanban, Calendrier). */
    function inlineCreate(anchor, create) {
        const input = el('input', 'db-input db-inline-create', { type: 'text', placeholder: 'Titre puis Entrée' });
        anchor.replaceWith(input);
        input.focus();
        let done = false;
        const finish = async (save) => {
            if (done) return;
            done = true;
            if (save && input.value.trim()) await create(input.value.trim());
            else renderBody();
        };
        input.addEventListener('keydown', (e) => { if (e.key === 'Enter') finish(true); if (e.key === 'Escape') finish(false); });
        input.addEventListener('blur', () => finish(true));
    }

    // ---------- Vue Calendrier ----------
    const iso = (d) => `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
    function shiftDate(value, days) {
        const move = (s) => {
            const d = new Date(s.slice(0, 10) + 'T12:00:00');
            d.setDate(d.getDate() + days);
            return iso(d) + s.slice(10);
        };
        return { start: move(value.start), ...(value.end ? { end: move(value.end) } : {}) };
    }
    function renderCalendar(body, v) {
        const prop = P.propById(v.dateProp) || S.config.properties.find((x) => x.type === 'date');
        if (!prop) {
            body.append(el('div', 'db-hint', { html: 'Le calendrier place les lignes selon une propriété <strong>Date</strong>.' }));
            const b = el('button', 'btn-primary', { type: 'button', text: '＋ Ajouter une propriété Date' });
            b.addEventListener('click', () => { const id = uid('p'); S.config.properties.push({ id, name: 'Date', type: 'date', withTime: false }); v.dateProp = id; saveConfig(); });
            body.append(b);
            return;
        }
        v.dateProp = prop.id;
        const now = new Date();
        S.month = S.month || new Date(now.getFullYear(), now.getMonth(), 1);
        const month = S.month;
        const head = el('div', 'db-cal-head');
        const title = el('strong', 'db-cal-title', { text: `${MONTHS[month.getMonth()]} ${month.getFullYear()}` });
        const nav = (delta) => { S.month = new Date(month.getFullYear(), month.getMonth() + delta, 1); renderBody(); };
        const prev = el('button', 'db-tool', { type: 'button', text: '◀' });
        prev.addEventListener('click', () => nav(-1));
        const next = el('button', 'db-tool', { type: 'button', text: '▶' });
        next.addEventListener('click', () => nav(1));
        const today = el('button', 'db-tool', { type: 'button', text: 'Aujourd\'hui' });
        today.addEventListener('click', () => { S.month = null; renderBody(); });
        const ical = el('button', 'db-tool', { type: 'button', text: S.icalUrl ? '📅 Abonné (iCal)' : '📅 S\'abonner' });
        ical.addEventListener('click', icalModal);
        head.append(title, prev, today, next, ical);
        body.append(head);

        const grid = el('div', 'db-cal');
        DAYS.forEach((d) => grid.append(el('div', 'db-cal-dow', { text: d })));
        const first = new Date(month.getFullYear(), month.getMonth(), 1);
        const offset = (first.getDay() + 6) % 7; // lundi en premier
        const start = new Date(first);
        start.setDate(first.getDate() - offset);
        const weeks = Math.ceil((offset + new Date(month.getFullYear(), month.getMonth() + 1, 0).getDate()) / 7);
        const rows = visibleRows().filter((r) => r.props?.[prop.id]?.start);
        const todayIso = iso(now);
        for (let i = 0; i < weeks * 7; i++) {
            const day = new Date(start);
            day.setDate(start.getDate() + i);
            const dayIso = iso(day);
            const cell = el('div', 'db-cal-day' + (day.getMonth() !== month.getMonth() ? ' other' : '') + (dayIso === todayIso ? ' today' : ''));
            cell.append(el('div', 'db-cal-num', { text: String(day.getDate()) }));
            rows.filter((r) => {
                const d = r.props[prop.id];
                return d.start.slice(0, 10) <= dayIso && (d.end || d.start).slice(0, 10) >= dayIso;
            }).forEach((row) => {
                const d = row.props[prop.id];
                const time = d.start.length > 10 && d.start.slice(0, 10) === dayIso ? d.start.slice(11, 16).replace(':', 'h') + ' ' : '';
                const ev = el('div', 'db-cal-event', { draggable: 'true', title: row.title || 'Sans titre', text: `${time}${row.icon || ''} ${row.title || 'Sans titre'}` });
                ev.addEventListener('click', () => ctx.openPage(row.id));
                ev.addEventListener('dragstart', (e) => { e.dataTransfer.setData('text/plain', `${row.id}|${dayIso}`); });
                cell.append(ev);
            });
            const add = el('button', 'db-cal-add', { type: 'button', title: 'Ajouter', text: '＋' });
            add.addEventListener('click', () => inlineCreate(add, async (t) => { await createRow({ [prop.id]: { start: dayIso } }, t); renderBody(); }));
            cell.append(add);
            cell.addEventListener('dragover', (e) => { e.preventDefault(); cell.classList.add('drop'); });
            cell.addEventListener('dragleave', () => cell.classList.remove('drop'));
            cell.addEventListener('drop', (e) => {
                e.preventDefault();
                cell.classList.remove('drop');
                const [id, fromIso] = e.dataTransfer.getData('text/plain').split('|');
                const row = S.rows.find((r) => r.id === Number(id));
                if (!row || fromIso === dayIso) return;
                const days = Math.round((new Date(dayIso + 'T12:00') - new Date(fromIso + 'T12:00')) / 86400000);
                renderBodyAfter(updateRow(row, { props: { [prop.id]: shiftDate(row.props[prop.id], days) } }));
            });
            grid.append(cell);
        }
        body.append(grid);
        const undated = visibleRows().length - rows.length;
        if (undated) body.append(el('div', 'db-count muted small', { text: `${undated} ligne${undated > 1 ? 's' : ''} sans date (visible${undated > 1 ? 's' : ''} dans la vue Tableau).` }));
    }

    // ---------- Page d'une ligne : panneau des propriétés ----------
    function mountRowProps(container, page, context) {
        ctx = context;
        P.setCtx(context);
        const db = page.db;
        if (!db) return;
        ctx.users = page.users;
        P.setState({ config: db.config, users: page.users });
        const save = async (changes) => {
            try {
                const res = await dbApi('row_update', { id: page.id, ...changes });
                page.props = res.row.props;
            } catch (err) { ctx.toast(err.message, true); }
        };
        container.innerHTML = '';
        const list = el('div', 'db-row-props');
        db.config.properties.forEach((prop) => {
            const line = el('div', 'db-row-prop');
            line.append(el('div', 'db-row-prop-name', { text: `${TYPES[prop.type][1]} ${prop.name}` }));
            line.append(propEditor(prop, page.props?.[prop.id] ?? null, (val) => save({ props: { [prop.id]: val } }), {
                readOnly: page.trashed,
                addOption: async (pr, name) => {
                    const option = { id: uid('o'), name, color: COLORS[(pr.options || []).length % COLORS.length] };
                    pr.options = [...(pr.options || []), option];
                    db.config = (await dbApi('config', { id: db.id, config: db.config })).config;
                    return option;
                },
            }));
            list.append(line);
        });
        container.append(list);
    }

    window.NotesDB = { mount, unmount, mountRowProps };
})();
