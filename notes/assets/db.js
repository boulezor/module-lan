/*
 * Bases de données : vues Tableau / Kanban / Calendrier, propriétés, filtres, tris,
 * et panneau des propriétés sur la page d'une ligne.
 */
(() => {
    const esc = (s) => String(s ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
    const el = (tag, cls, attrs = {}) => {
        const node = document.createElement(tag);
        if (cls) node.className = cls;
        Object.entries(attrs).forEach(([k, v]) => { if (k === 'text') node.textContent = v; else if (k === 'html') node.innerHTML = v; else node.setAttribute(k, v); });
        return node;
    };
    const uid = (prefix) => prefix + Math.random().toString(36).slice(2, 8);
    const COLORS = ['gray', 'brown', 'orange', 'yellow', 'green', 'blue', 'purple', 'pink', 'red'];
    const COLOR_NAMES = { gray: 'Gris', brown: 'Marron', orange: 'Orange', yellow: 'Jaune', green: 'Vert', blue: 'Bleu', purple: 'Violet', pink: 'Rose', red: 'Rouge' };
    const TYPES = {
        text: ['Texte', '≡'], number: ['Nombre', '#'], select: ['Sélection', '▾'], multi_select: ['Sélection multiple', '☰'],
        date: ['Date', '📅'], checkbox: ['Case à cocher', '☑'], person: ['Personne', '👤'], url: ['Lien', '🔗'],
    };
    const VIEW_TYPES = { table: ['Tableau', '▦'], board: ['Kanban', '▥'], calendar: ['Calendrier', '📅'] };
    const MONTHS = ['janvier', 'février', 'mars', 'avril', 'mai', 'juin', 'juillet', 'août', 'septembre', 'octobre', 'novembre', 'décembre'];
    const MONTHS_SHORT = ['janv.', 'févr.', 'mars', 'avr.', 'mai', 'juin', 'juil.', 'août', 'sept.', 'oct.', 'nov.', 'déc.'];
    const DAYS = ['lun.', 'mar.', 'mer.', 'jeu.', 'ven.', 'sam.', 'dim.'];

    let ctx = null;   // fonctions fournies par l'application (api, ouverture de page, notifications…)
    let S = null;     // état de la base affichée

    async function dbApi(action, payload = {}) {
        const res = await notesFetch('api/db.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ action, ...payload }),
        });
        const data = await res.json().catch(() => ({ error: `Erreur serveur (${res.status}).` }));
        if (!res.ok) throw new Error(data.error || 'Erreur');
        return data;
    }

    // ---------- Petites fenêtres flottantes ----------
    let pop = null;
    function closePop() { pop?.remove(); pop = null; }
    function openPop(anchor, build, cls = '') {
        closePop();
        pop = el('div', 'db-pop ' + cls);
        document.body.append(pop);
        build(pop);
        const r = anchor.getBoundingClientRect();
        const top = r.bottom + 4 + pop.offsetHeight > window.innerHeight ? Math.max(8, r.top - pop.offsetHeight - 4) : r.bottom + 4;
        pop.style.top = `${top}px`;
        pop.style.left = `${Math.max(8, Math.min(window.innerWidth - pop.offsetWidth - 8, r.left))}px`;
        return pop;
    }
    document.addEventListener('mousedown', (e) => { if (pop && !pop.contains(e.target) && !e.target.closest('.db-pop-anchor')) closePop(); });
    document.addEventListener('keydown', (e) => { if (e.key === 'Escape') closePop(); });

    // ---------- Valeurs ----------
    const propsOf = (config) => config.properties;
    const propById = (id) => S.config.properties.find((p) => p.id === id);
    const optionOf = (prop, id) => (prop.options || []).find((o) => o.id === id);
    const userName = (id) => (S?.users || ctx?.users || []).find((u) => u.id === Number(id))?.name || '';

    function dateLabel(d) {
        if (!d || !d.start) return '';
        const one = (v) => {
            const [date, time] = v.split('T');
            const [y, m, day] = date.split('-').map(Number);
            const sameYear = y === new Date().getFullYear();
            return `${day} ${MONTHS_SHORT[m - 1]}${sameYear ? '' : ' ' + y}${time ? ' ' + time.replace(':', 'h') : ''}`;
        };
        return one(d.start) + (d.end ? ' → ' + one(d.end) : '');
    }
    function tag(option) {
        return option ? `<span class="db-tag c-${esc(option.color)}">${esc(option.name)}</span>` : '';
    }
    /** Affichage compact d'une valeur (cartes du Kanban, calendrier) */
    function valueHtml(prop, value) {
        if (value === null || value === undefined || value === '' || (Array.isArray(value) && !value.length)) return '';
        switch (prop.type) {
            case 'select': return tag(optionOf(prop, value));
            case 'multi_select': return value.map((id) => tag(optionOf(prop, id))).join(' ');
            case 'date': return `<span class="db-chip">📅 ${esc(dateLabel(value))}</span>`;
            case 'checkbox': return value ? `<span class="db-chip">☑ ${esc(prop.name)}</span>` : '';
            case 'person': return `<span class="db-chip">👤 ${esc(userName(value))}</span>`;
            case 'url': return `<span class="db-chip">🔗 ${esc(value.replace(/^https?:\/\/(www\.)?/, '').slice(0, 30))}</span>`;
            case 'number': return `<span class="db-chip">${esc(prop.name)} : ${esc(value)}</span>`;
            default: return `<span class="db-chip">${esc(String(value).slice(0, 40))}</span>`;
        }
    }

    // ---------- Éditeurs de valeur (tableau et page d'une ligne) ----------
    /**
     * Éditeur d'une propriété. onChange(valeur) est appelé à chaque modification.
     * getConfig() / setConfig() permettent de créer une option à la volée.
     */
    function propEditor(prop, value, onChange, opts = {}) {
        const wrap = el('div', `db-cell db-cell-${prop.type}`);
        const readOnly = !!opts.readOnly;
        switch (prop.type) {
            case 'text':
            case 'number':
            case 'url': {
                const input = el('input', 'db-input', { type: prop.type === 'number' ? 'number' : 'text', placeholder: readOnly ? '' : 'Vide', step: 'any' });
                input.value = value ?? '';
                input.readOnly = readOnly;
                input.addEventListener('change', () => onChange(input.value.trim() === '' ? null : prop.type === 'number' ? Number(input.value) : input.value.trim()));
                input.addEventListener('keydown', (e) => { if (e.key === 'Enter') input.blur(); });
                wrap.append(input);
                if (prop.type === 'url' && value) {
                    const a = el('a', 'db-open-link', { href: value, target: '_blank', rel: 'noopener noreferrer', title: 'Ouvrir le lien', text: '↗' });
                    wrap.append(a);
                }
                break;
            }
            case 'checkbox': {
                const box = el('input', 'db-checkbox', { type: 'checkbox' });
                box.checked = !!value;
                box.disabled = readOnly;
                box.addEventListener('change', () => onChange(box.checked));
                wrap.append(box);
                break;
            }
            case 'select':
            case 'multi_select': {
                const btn = el('button', 'db-value-btn db-pop-anchor', { type: 'button' });
                const draw = (v) => {
                    const list = prop.type === 'select' ? (v ? [v] : []) : (v || []);
                    btn.innerHTML = list.map((id) => tag(optionOf(prop, id))).join(' ') || '<span class="db-empty">Vide</span>';
                };
                draw(value);
                if (!readOnly) btn.addEventListener('click', () => optionPicker(btn, prop, value, (v) => { value = v; draw(v); onChange(v); }, opts));
                wrap.append(btn);
                break;
            }
            case 'date': {
                const btn = el('button', 'db-value-btn db-pop-anchor', { type: 'button' });
                const draw = (v) => { btn.innerHTML = v?.start ? esc(dateLabel(v)) : '<span class="db-empty">Vide</span>'; };
                draw(value);
                if (!readOnly) btn.addEventListener('click', () => datePicker(btn, prop, value, (v) => { value = v; draw(v); onChange(v); }));
                wrap.append(btn);
                break;
            }
            case 'person': {
                const btn = el('button', 'db-value-btn db-pop-anchor', { type: 'button' });
                const draw = (v) => { btn.innerHTML = v ? `👤 ${esc(userName(v))}` : '<span class="db-empty">Vide</span>'; };
                draw(value);
                if (!readOnly) {
                    btn.addEventListener('click', () => openPop(btn, (p) => {
                        const users = S?.users || ctx.users || [];
                        [{ id: null, name: '— Personne' }, ...users].forEach((u) => {
                            const item = el('button', 'db-pop-item' + (u.id === value ? ' on' : ''), { type: 'button', text: u.id ? `👤 ${u.name}` : u.name });
                            item.addEventListener('click', () => { value = u.id; draw(u.id); onChange(u.id); closePop(); });
                            p.append(item);
                        });
                    }));
                }
                wrap.append(btn);
                break;
            }
        }
        return wrap;
    }

    /** Choix d'option(s), avec création d'une nouvelle option en tapant son nom. */
    function optionPicker(anchor, prop, value, onChange, opts) {
        const multi = prop.type === 'multi_select';
        let current = multi ? [...(value || [])] : value;
        openPop(anchor, (p) => {
            const input = el('input', 'db-input db-pop-search', { type: 'text', placeholder: multi ? 'Chercher ou créer une option…' : 'Chercher ou créer…' });
            const list = el('div', 'db-pop-list');
            p.append(input, list);
            const draw = () => {
                const q = input.value.trim().toLowerCase();
                list.innerHTML = '';
                (prop.options || []).filter((o) => o.name.toLowerCase().includes(q)).forEach((o) => {
                    const on = multi ? current.includes(o.id) : current === o.id;
                    const item = el('button', 'db-pop-item' + (on ? ' on' : ''), { type: 'button', html: `${tag(o)} ${on ? '✓' : ''}` });
                    item.addEventListener('click', () => {
                        if (multi) { current = on ? current.filter((x) => x !== o.id) : [...current, o.id]; onChange(current); draw(); }
                        else { current = on ? null : o.id; onChange(current); closePop(); }
                    });
                    list.append(item);
                });
                const exact = (prop.options || []).some((o) => o.name.toLowerCase() === q);
                if (q && !exact && opts.addOption) {
                    const create = el('button', 'db-pop-item db-pop-create', { type: 'button', html: `Créer ${tag({ name: input.value.trim(), color: 'gray' })}` });
                    create.addEventListener('click', async () => {
                        const option = await opts.addOption(prop, input.value.trim());
                        input.value = '';
                        if (multi) { current = [...current, option.id]; onChange(current); draw(); }
                        else { current = option.id; onChange(current); closePop(); }
                    });
                    list.append(create);
                }
                if (!list.children.length) list.append(el('div', 'db-pop-empty', { text: 'Aucune option : tape un nom pour en créer une.' }));
            };
            input.addEventListener('input', draw);
            input.addEventListener('keydown', (e) => { if (e.key === 'Enter') list.querySelector('.db-pop-item')?.click(); });
            draw();
            setTimeout(() => input.focus(), 20);
        });
    }

    /** Date (avec heure si la propriété le prévoit) et date de fin facultative. */
    function datePicker(anchor, prop, value, onChange) {
        let v = value?.start ? { ...value } : null;
        openPop(anchor, (p) => {
            const type = prop.withTime ? 'datetime-local' : 'date';
            const norm = (s) => (s ? (prop.withTime ? (s.length === 10 ? s + 'T09:00' : s.slice(0, 16)) : s.slice(0, 10)) : '');
            const start = el('input', 'db-input', { type });
            start.value = norm(v?.start);
            const endToggle = el('label', 'db-check-label', { html: '<input type="checkbox"> Date de fin' });
            const endBox = endToggle.querySelector('input');
            const end = el('input', 'db-input', { type });
            endBox.checked = !!v?.end;
            end.value = norm(v?.end);
            end.hidden = !v?.end;
            const apply = () => {
                v = start.value ? { start: start.value } : null;
                if (v && endBox.checked && end.value && end.value >= start.value) v.end = end.value;
                onChange(v);
            };
            start.addEventListener('change', apply);
            end.addEventListener('change', apply);
            endBox.addEventListener('change', () => { end.hidden = !endBox.checked; if (!endBox.checked) apply(); else end.focus(); });
            const today = el('button', 'db-pop-item', { type: 'button', text: 'Aujourd\'hui' });
            today.addEventListener('click', () => { start.value = norm(new Date().toISOString().slice(0, 10)); apply(); });
            const clear = el('button', 'db-pop-item danger', { type: 'button', text: 'Effacer la date' });
            clear.addEventListener('click', () => { onChange(null); closePop(); });
            p.append(el('div', 'db-pop-label', { text: 'Début' }), start, endToggle, end, today, clear);
            setTimeout(() => start.focus(), 20);
        }, 'db-pop-date');
    }

    window.NotesDBParts = { esc, el, uid, COLORS, COLOR_NAMES, TYPES, VIEW_TYPES, MONTHS, DAYS, dbApi, openPop, closePop, propEditor, valueHtml, tag, dateLabel, optionOf, userName,
        setCtx: (c) => { ctx = c; }, getCtx: () => ctx, setState: (s) => { S = s; }, getState: () => S, propById };
})();
