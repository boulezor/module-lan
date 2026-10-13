/*
 * Éditeur de pages : configuration d'Editor.js + blocs maison (encadré, repliable, lien de page,
 * table des matières, écriture IA) + outil IA sur la sélection + raccourcis Markdown.
 * Utilisé par l'application (index.php) et par la vue publique (share.php).
 */
(() => {
    const esc = (s) => String(s ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
    const INLINE_SANITIZE = { b: true, strong: true, i: true, em: true, u: true, s: true, a: { href: true }, mark: { class: true }, code: { class: true }, br: true };

    const el = (tag, cls, attrs = {}) => {
        const node = document.createElement(tag);
        if (cls) node.className = cls;
        Object.entries(attrs).forEach(([k, v]) => { if (k === 'text') node.textContent = v; else if (k === 'html') node.innerHTML = v; else node.setAttribute(k, v); });
        return node;
    };

    // ---------- Encadré (callout) ----------
    const CALLOUT_EMOJIS = ['💡', 'ℹ️', '⚠️', '✅', '❗', '📌', '🔥', '❓'];
    const CALLOUT_COLORS = ['gray', 'blue', 'green', 'yellow', 'red', 'purple'];
    class Callout {
        static get toolbox() { return { title: 'Encadré', icon: '<span class="tb-emoji">💡</span>' }; }
        static get isReadOnlySupported() { return true; }
        static get conversionConfig() { return { export: 'text', import: 'text' }; }
        static get sanitize() { return { text: INLINE_SANITIZE }; }
        constructor({ data, readOnly }) {
            this.data = { emoji: data.emoji || '💡', color: CALLOUT_COLORS.includes(data.color) ? data.color : 'gray', text: data.text || '' };
            this.readOnly = readOnly;
        }
        render() {
            this.wrap = el('div', `nb-callout nb-color-${this.data.color}`);
            this.emoji = el('button', 'nb-callout-emoji', { type: 'button', title: 'Changer l\'icône', text: this.data.emoji });
            this.text = el('div', 'nb-callout-text', { contenteditable: String(!this.readOnly), 'data-placeholder': 'Écris quelque chose…' });
            this.text.innerHTML = this.data.text;
            this.wrap.append(this.emoji, this.text);
            if (!this.readOnly) {
                this.emoji.addEventListener('click', () => {
                    const next = CALLOUT_EMOJIS[(CALLOUT_EMOJIS.indexOf(this.emoji.textContent) + 1) % CALLOUT_EMOJIS.length];
                    this.emoji.textContent = next;
                    this.text.dispatchEvent(new Event('input', { bubbles: true }));
                });
            }
            return this.wrap;
        }
        renderSettings() {
            return CALLOUT_COLORS.map((color) => ({
                icon: `<span class="nb-dot nb-color-${color}"></span>`,
                label: { gray: 'Gris', blue: 'Bleu', green: 'Vert', yellow: 'Jaune', red: 'Rouge', purple: 'Violet' }[color],
                toggle: 'callout-color',
                isActive: this.data.color === color,
                onActivate: () => {
                    this.data.color = color;
                    this.wrap.className = `nb-callout nb-color-${color}`;
                },
            }));
        }
        save() { return { emoji: this.emoji.textContent, color: this.data.color, text: this.text.innerHTML }; }
    }

    // ---------- Bloc repliable (toggle) ----------
    class Toggle {
        static get toolbox() { return { title: 'Bloc repliable', icon: '<span class="tb-emoji">▸</span>' }; }
        static get isReadOnlySupported() { return true; }
        static get enableLineBreaks() { return true; }
        static get sanitize() { return { title: INLINE_SANITIZE, text: INLINE_SANITIZE }; }
        constructor({ data, readOnly }) {
            this.data = { title: data.title || '', text: data.text || '', open: data.open !== false };
            this.readOnly = readOnly;
        }
        render() {
            this.wrap = el('div', 'nb-toggle' + (this.data.open ? ' open' : ''));
            const head = el('div', 'nb-toggle-head');
            const arrow = el('button', 'nb-toggle-arrow', { type: 'button', title: 'Déplier / replier', text: '▸' });
            this.title = el('div', 'nb-toggle-title', { contenteditable: String(!this.readOnly), 'data-placeholder': 'Titre du bloc repliable' });
            this.title.innerHTML = this.data.title;
            this.body = el('div', 'nb-toggle-body', { contenteditable: String(!this.readOnly), 'data-placeholder': 'Contenu (Entrée = nouvelle ligne)' });
            this.body.innerHTML = this.data.text;
            head.append(arrow, this.title);
            this.wrap.append(head, this.body);
            arrow.addEventListener('click', () => this.wrap.classList.toggle('open'));
            if (!this.readOnly) {
                this.title.addEventListener('keydown', (e) => {
                    if (e.key === 'Enter') { e.preventDefault(); e.stopPropagation(); this.wrap.classList.add('open'); this.body.focus(); }
                });
                this.body.addEventListener('keydown', (e) => {
                    if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); e.stopPropagation(); document.execCommand('insertLineBreak'); }
                });
            }
            return this.wrap;
        }
        save() { return { title: this.title.innerHTML, text: this.body.innerHTML, open: this.wrap.classList.contains('open') }; }
    }

    // ---------- Lien vers une autre page ----------
    class PageLink {
        static get toolbox() { return { title: 'Lien vers une page', icon: '<span class="tb-emoji">📄</span>' }; }
        static get isReadOnlySupported() { return true; }
        constructor({ data, readOnly, config }) {
            this.pageId = Number(data.pageId) || 0;
            this.readOnly = readOnly;
            this.config = config || {};
        }
        render() {
            this.wrap = el('div', 'nb-pagelink');
            this.pageId ? this.renderLink() : this.renderPicker();
            return this.wrap;
        }
        renderLink() {
            const page = (window.NotesPages || {})[this.pageId];
            this.wrap.innerHTML = '';
            if (!page) {
                this.wrap.append(el('span', 'nb-pagelink-missing', { text: this.readOnly ? '📄 Page non partagée' : '📄 Page introuvable ou supprimée' }));
                return;
            }
            const link = el('a', 'nb-pagelink-a', { href: `index.php?p=${this.pageId}` });
            link.append(el('span', 'nb-pagelink-icon', { text: page.icon || '📄' }), el('span', 'nb-pagelink-title', { text: page.title || 'Sans titre' }));
            link.addEventListener('click', (e) => {
                if (this.config.onOpen) { e.preventDefault(); this.config.onOpen(this.pageId); }
            });
            this.wrap.append(link);
        }
        renderPicker() {
            if (this.readOnly) return;
            this.wrap.innerHTML = '';
            const input = el('input', 'nb-picker-input', { type: 'text', placeholder: 'Chercher une page à lier…' });
            const list = el('div', 'nb-picker-list');
            const draw = () => {
                const q = input.value.trim().toLowerCase();
                const pages = Object.values(window.NotesPages || {}).filter((p) => p.id !== this.config.currentId?.() && (p.title || 'Sans titre').toLowerCase().includes(q)).slice(0, 8);
                list.innerHTML = '';
                pages.forEach((p) => {
                    const item = el('button', 'nb-picker-item', { type: 'button' });
                    item.append(el('span', '', { text: p.icon || '📄' }), el('span', '', { text: p.title || 'Sans titre' }));
                    item.addEventListener('click', () => { this.pageId = p.id; this.renderLink(); this.wrap.dispatchEvent(new Event('input', { bubbles: true })); });
                    list.append(item);
                });
                if (this.config.createSubpage) {
                    const create = el('button', 'nb-picker-item nb-picker-new', { type: 'button', text: `＋ Nouvelle sous-page${q ? ` « ${input.value.trim()} »` : ''}` });
                    create.addEventListener('click', async () => {
                        const page = await this.config.createSubpage(input.value.trim());
                        if (page) { this.pageId = page.id; this.renderLink(); this.wrap.dispatchEvent(new Event('input', { bubbles: true })); }
                    });
                    list.append(create);
                }
            };
            input.addEventListener('input', draw);
            input.addEventListener('keydown', (e) => e.stopPropagation());
            this.wrap.append(input, list);
            draw();
            setTimeout(() => input.focus(), 50);
        }
        save() { return { pageId: this.pageId }; }
        validate(data) { return data.pageId > 0; }
    }

    // ---------- Aperçu de lien (carte avec titre, description, image) ----------
    class Bookmark {
        static get toolbox() { return { title: 'Aperçu de lien', icon: '<span class="tb-emoji">🔗</span>' }; }
        static get isReadOnlySupported() { return true; }
        // Une URL seule collée dans une ligne vide devient un aperçu (YouTube/Vimeo restent des vidéos intégrées)
        static get pasteConfig() { return { patterns: { link: /^https?:\/\/\S+$/i } }; }
        constructor({ data, readOnly }) { this.data = data || {}; this.readOnly = readOnly; }
        render() {
            this.wrap = el('div', 'nb-bookmark');
            this.data.url ? this.renderCard() : this.renderInput();
            return this.wrap;
        }
        onPaste(event) {
            this.data = { url: event.detail.data };
            this.load(this.data.url);
        }
        renderInput(error = '') {
            if (this.readOnly) return;
            this.wrap.innerHTML = '';
            const input = el('input', 'nb-picker-input', { type: 'url', placeholder: 'Colle un lien (https://…) puis Entrée' });
            input.addEventListener('keydown', (e) => {
                e.stopPropagation();
                if (e.key === 'Enter') { e.preventDefault(); if (input.value.trim()) this.load(input.value.trim()); }
            });
            this.wrap.append(input);
            if (error) this.wrap.append(el('div', 'nb-ai-status', { text: '⚠️ ' + error }));
            setTimeout(() => input.focus(), 50);
        }
        async load(url) {
            this.wrap.innerHTML = '<div class="nb-bookmark-loading muted"><span class="spinner"></span> Chargement de l\'aperçu…</div>';
            try {
                const res = await fetch('api/unfurl.php?url=' + encodeURIComponent(url));
                const data = await res.json();
                if (!res.ok) { this.data = { url }; this.renderCard(); return; } // aperçu indisponible : simple lien
                this.data = data;
            } catch { this.data = { url }; }
            this.renderCard();
            this.wrap.dispatchEvent(new Event('input', { bubbles: true }));
        }
        renderCard() {
            const d = this.data;
            let host = '';
            try { host = new URL(d.url).hostname.replace(/^www\./, ''); } catch { /* ignore */ }
            this.wrap.innerHTML = '';
            const a = el('a', 'nb-bookmark-card', { href: d.url, target: '_blank', rel: 'noopener noreferrer' });
            const text = el('div', 'nb-bookmark-text');
            text.append(el('div', 'nb-bookmark-title', { text: d.title || d.url }));
            if (d.description) text.append(el('div', 'nb-bookmark-desc', { text: d.description }));
            const site = el('div', 'nb-bookmark-site');
            if (d.favicon) { const fav = el('img', '', { src: d.favicon, alt: '', loading: 'lazy' }); fav.addEventListener('error', () => fav.remove()); site.append(fav); }
            site.append(el('span', '', { text: d.site || host }));
            text.append(site);
            a.append(text);
            if (d.image) { const img = el('img', 'nb-bookmark-img', { src: d.image, alt: '', loading: 'lazy' }); img.addEventListener('error', () => img.remove()); a.append(img); }
            this.wrap.append(a);
        }
        save() { return this.data; }
        validate(data) { return !!data.url; }
    }

    // ---------- Table des matières ----------
    class Toc {
        static get toolbox() { return { title: 'Table des matières', icon: '<span class="tb-emoji">📑</span>' }; }
        static get isReadOnlySupported() { return true; }
        render() {
            this.wrap = el('nav', 'nb-toc');
            this.refresh = () => {
                const holder = this.wrap.closest('.codex-editor');
                const headers = holder ? [...holder.querySelectorAll('.ce-header')] : [];
                // Ne redessine que si les titres ont changé (sinon l'éditeur y verrait une modification sans fin)
                const signature = headers.map((h) => h.tagName + h.textContent).join('|');
                if (signature === this.signature) return;
                this.signature = signature;
                this.wrap.innerHTML = '';
                if (!headers.length) {
                    this.wrap.append(el('span', 'muted', { text: '📑 Table des matières : ajoute des titres à la page.' }));
                    return;
                }
                headers.forEach((h) => {
                    const level = Number(h.tagName.slice(1)) || 2;
                    const item = el('a', `nb-toc-item lvl-${level}`, { href: '#', text: h.textContent || 'Titre' });
                    item.addEventListener('click', (e) => { e.preventDefault(); h.scrollIntoView({ behavior: 'smooth', block: 'center' }); });
                    this.wrap.append(item);
                });
            };
            document.addEventListener('notes:changed', this.refresh);
            setTimeout(this.refresh, 100);
            return this.wrap;
        }
        destroy() { document.removeEventListener('notes:changed', this.refresh); }
        save() { return {}; }
    }

    // ---------- Écrire avec l'IA (bloc temporaire, remplacé par le texte généré) ----------
    class AIWriter {
        static get toolbox() { return { title: 'Écrire avec l\'IA', icon: '<span class="tb-emoji">✨</span>' }; }
        constructor({ api, block, config }) { this.api = api; this.block = block; this.config = config || {}; }
        render() {
            const wrap = el('div', 'nb-ai-writer');
            const input = el('textarea', 'nb-ai-input', { rows: '2', placeholder: 'Demande à l\'IA d\'écrire… (ex. « un plan pour la réunion de lundi »)' });
            const chips = el('div', 'nb-ai-chips');
            const status = el('div', 'nb-ai-status muted');
            const run = async (action, prompt) => {
                status.textContent = '✨ L\'IA écrit…';
                wrap.classList.add('busy');
                const index = this.api.blocks.getBlockIndex(this.block.id);
                const res = await window.NotesAI.request(action, prompt, action === 'custom' && !this.config.withContext ? '' : await this.config.pageText?.());
                wrap.classList.remove('busy');
                if (res.error) { status.textContent = '⚠️ ' + res.error; return; }
                window.NotesAI.insertBlocks(this.api, res.blocks, index, true);
            };
            [['continue', '✍️ Continuer la page'], ['summarize', '📝 Résumer la page'], ['actions', '☑️ Tâches de la page'],
             ['custom:Propose un plan détaillé sur ce sujet', '🗂️ Plan'], ['custom:Propose 10 idées sur ce sujet', '💡 Idées']].forEach(([action, label]) => {
                const chip = el('button', 'nb-chip', { type: 'button', text: label });
                chip.addEventListener('click', () => {
                    if (action.startsWith('custom:')) {
                        const topic = input.value.trim();
                        if (!topic) { status.textContent = 'Écris d\'abord le sujet ci-dessus.'; input.focus(); return; }
                        run('custom', `${action.slice(7)} : ${topic}`);
                    } else run(action, '');
                });
                chips.append(chip);
            });
            const go = el('button', 'nb-ai-go', { type: 'button', text: 'Générer' });
            go.addEventListener('click', () => input.value.trim() ? run('custom', input.value.trim()) : input.focus());
            input.addEventListener('keydown', (e) => {
                e.stopPropagation();
                if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); go.click(); }
                if (e.key === 'Escape') this.api.blocks.delete(this.api.blocks.getBlockIndex(this.block.id));
            });
            const row = el('div', 'nb-ai-row');
            row.append(input, go);
            wrap.append(row, chips, status);
            setTimeout(() => input.focus(), 50);
            return wrap;
        }
        save() { return {}; }
    }

    // ---------- Outil IA sur la sélection (barre d'outils de texte) ----------
    class AIInline {
        static get isInline() { return true; }
        static get title() { return 'Assistant IA'; }
        constructor({ api }) { this.api = api; }
        render() {
            this.button = el('button', this.api.styles.inlineToolButton + ' nb-ai-inline', { type: 'button', text: '✨ IA' });
            return this.button;
        }
        surround(range) {
            if (!range || range.collapsed) return;
            window.NotesAI.openSelectionMenu(range.cloneRange(), this.api);
        }
        checkState() { return false; }
    }

    // ---------- Assistant IA (appels + insertion des résultats) ----------
    const NotesAI = {
        csrf: '',
        actions: {},
        async request(action, prompt, text) {
            try {
                const res = await fetch('api/ai.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': NotesAI.csrf },
                    body: JSON.stringify({ action, prompt, text }),
                });
                const data = await res.json().catch(() => ({ error: `Erreur serveur (${res.status}).` }));
                return res.ok ? data : { error: data.error || `Erreur (${res.status}).` };
            } catch {
                return { error: 'Connexion impossible ou délai dépassé.' };
            }
        },
        /** Insère des blocs à l'index donné (en remplaçant le bloc à cet index si replace). */
        insertBlocks(api, blocks, index, replace) {
            if (!blocks || !blocks.length) blocks = [{ type: 'paragraph', data: { text: '' } }];
            if (replace) api.blocks.delete(index);
            blocks.forEach((b, i) => api.blocks.insert(b.type, b.data, undefined, index + i, i === blocks.length - 1));
        },
        /** Menu IA flottant pour le texte sélectionné. */
        openSelectionMenu(range, api) {
            NotesAI.close();
            const text = range.toString().trim();
            if (!text) return;
            const rect = range.getBoundingClientRect();
            const box = el('div', 'nb-ai-pop');
            box.style.top = `${window.scrollY + rect.bottom + 8}px`;
            box.style.left = `${Math.max(8, Math.min(window.innerWidth - 340, window.scrollX + rect.left))}px`;
            const custom = el('input', 'nb-ai-pop-input', { type: 'text', placeholder: 'Demander à l\'IA de modifier le texte…' });
            const list = el('div', 'nb-ai-pop-list');
            Object.entries(NotesAI.actions).forEach(([key, label]) => {
                const item = el('button', 'nb-ai-pop-item', { type: 'button', text: label });
                item.addEventListener('click', () => go(key, ''));
                list.append(item);
            });
            const status = el('div', 'nb-ai-pop-status');
            box.append(custom, list, status);
            document.body.append(box);
            NotesAI.pop = box;
            setTimeout(() => custom.focus(), 30);
            custom.addEventListener('keydown', (e) => {
                if (e.key === 'Enter' && custom.value.trim()) go('custom', custom.value.trim());
                if (e.key === 'Escape') NotesAI.close();
            });
            const blockIndex = api.blocks.getCurrentBlockIndex();

            async function go(action, prompt) {
                list.hidden = true;
                custom.hidden = true;
                status.innerHTML = '<span class="spinner"></span> L\'IA réfléchit…';
                const res = await NotesAI.request(action, prompt, text);
                if (res.error) { status.textContent = '⚠️ ' + res.error; return; }
                status.innerHTML = '';
                const preview = el('div', 'nb-ai-preview', { text: res.markdown });
                const actions = el('div', 'nb-ai-pop-actions');
                const replace = el('button', 'btn-primary', { type: 'button', text: 'Remplacer la sélection' });
                const below = el('button', 'btn-secondary', { type: 'button', text: 'Insérer en dessous' });
                const copy = el('button', 'btn-secondary', { type: 'button', text: 'Copier' });
                const cancel = el('button', 'btn-link', { type: 'button', text: 'Annuler' });
                actions.append(replace, below, copy, cancel);
                status.append(preview, actions);
                replace.addEventListener('click', () => {
                    const single = res.blocks.length === 1 && res.blocks[0].type === 'paragraph';
                    range.deleteContents();
                    if (single) {
                        const tpl = document.createElement('template');
                        tpl.innerHTML = res.blocks[0].data.text; // HTML produit et échappé par le serveur
                        range.insertNode(tpl.content);
                    } else {
                        NotesAI.insertBlocks(api, res.blocks, blockIndex + 1, false);
                    }
                    document.dispatchEvent(new CustomEvent('notes:dirty'));
                    NotesAI.close();
                });
                below.addEventListener('click', () => { NotesAI.insertBlocks(api, res.blocks, blockIndex + 1, false); NotesAI.close(); });
                copy.addEventListener('click', async () => { await navigator.clipboard.writeText(res.markdown).catch(() => {}); copy.textContent = 'Copié ✔'; });
                cancel.addEventListener('click', NotesAI.close);
            }
        },
        close() { NotesAI.pop?.remove(); NotesAI.pop = null; },
    };
    document.addEventListener('mousedown', (e) => { if (NotesAI.pop && !NotesAI.pop.contains(e.target)) NotesAI.close(); });
    window.NotesAI = NotesAI;

    // ---------- Traductions de l'interface d'Editor.js ----------
    const i18n = {
        messages: {
            ui: {
                blockTunes: { toggler: { 'Click to tune': 'Options du bloc', 'or drag to move': 'ou glisser pour déplacer' } },
                inlineToolbar: { converter: { 'Convert to': 'Convertir en' } },
                toolbar: { toolbox: { Add: 'Ajouter un bloc' } },
                popover: { Filter: 'Filtrer…', 'Nothing found': 'Aucun résultat', 'Convert to': 'Convertir en' },
            },
            toolNames: {
                Text: 'Texte', Heading: 'Titre', 'Unordered List': 'Liste à puces', 'Ordered List': 'Liste numérotée', Checklist: 'Cases à cocher',
                List: 'Liste', Quote: 'Citation', Code: 'Code', Delimiter: 'Séparateur', Table: 'Tableau', Image: 'Image', Attachment: 'Fichier joint',
                Link: 'Lien', Marker: 'Surligner', Bold: 'Gras', Italic: 'Italique', InlineCode: 'Code', Underline: 'Souligné', Embed: 'Vidéo / intégration',
            },
            tools: {
                header: { 'Heading 1': 'Titre 1', 'Heading 2': 'Titre 2', 'Heading 3': 'Titre 3', 'Heading 4': 'Titre 4' },
                list: { Unordered: 'À puces', Ordered: 'Numérotée', Checklist: 'Cases à cocher', 'Start with': 'Commencer à', 'Counter type': 'Type de numéro' },
                link: { 'Add a link': 'Coller un lien' },
                stub: { 'The block can not be displayed correctly.': 'Ce bloc ne peut pas être affiché.' },
                table: { 'With headings': 'Avec en-têtes', 'Without headings': 'Sans en-têtes', 'Add row above': 'Ajouter une ligne au-dessus', 'Add row below': 'Ajouter une ligne en dessous',
                         'Add column to left': 'Ajouter une colonne à gauche', 'Add column to right': 'Ajouter une colonne à droite', 'Delete row': 'Supprimer la ligne', 'Delete column': 'Supprimer la colonne' },
                image: { Caption: 'Légende', 'Select an Image': 'Choisir une image', 'With border': 'Avec bordure', 'Stretch image': 'Pleine largeur', 'With background': 'Avec fond' },
                quote: { 'Align Left': 'Aligner à gauche', 'Align Center': 'Centrer' },
                code: { 'Enter a code': 'Saisir du code' },
                attaches: { 'Select file to upload': 'Choisir un fichier' },
            },
            blockTunes: {
                delete: { Delete: 'Supprimer', 'Click to delete': 'Confirmer la suppression' },
                moveUp: { 'Move up': 'Monter' },
                moveDown: { 'Move down': 'Descendre' },
            },
        },
    };

    // ---------- Raccourcis Markdown en début de ligne ----------
    // Déclenchés à l'appui sur Espace (« # », « - », « [] »…) ou Entrée (« ``` », « --- »), de façon synchrone
    // pour ne perdre aucune lettre tapée ensuite.
    const ON_SPACE = {
        '#': () => ['header', { text: '', level: 1 }],
        '##': () => ['header', { text: '', level: 2 }],
        '###': () => ['header', { text: '', level: 3 }],
        '-': () => ['list', { style: 'unordered', meta: {}, items: [{ content: '', meta: {}, items: [] }] }],
        '*': () => ['list', { style: 'unordered', meta: {}, items: [{ content: '', meta: {}, items: [] }] }],
        '1.': () => ['list', { style: 'ordered', meta: {}, items: [{ content: '', meta: {}, items: [] }] }],
        '[]': () => ['list', { style: 'checklist', meta: {}, items: [{ content: '', meta: { checked: false }, items: [] }] }],
        '>': () => ['quote', { text: '', caption: '', alignment: 'left' }],
    };
    const ON_ENTER = {
        '```': () => ['code', { code: '' }],
        '---': () => ['delimiter', {}],
    };
    function attachShortcuts(editor, holder) {
        holder.addEventListener('keydown', (e) => {
            if ((e.key !== ' ' && e.key !== 'Enter') || e.shiftKey || e.ctrlKey || e.metaKey || e.altKey) return;
            const index = editor.blocks.getCurrentBlockIndex();
            const block = index >= 0 ? editor.blocks.getBlockByIndex(index) : null;
            // Citation : Entrée = nouveau paragraphe (comme Notion), Maj+Entrée = retour à la ligne
            if (block && block.name === 'quote' && e.key === 'Enter' && e.target.closest('.cdx-quote__text')) {
                e.preventDefault();
                e.stopPropagation();
                editor.blocks.insert('paragraph', { text: '' }, undefined, index + 1, true);
                editor.caret.setToBlock(index + 1, 'start');
                return;
            }
            if (!block || block.name !== 'paragraph') return;
            const text = (block.holder.querySelector('.ce-paragraph')?.textContent ?? '').replace(/\u00a0/g, ' ').trim();
            const make = (e.key === ' ' ? ON_SPACE : ON_ENTER)[text];
            if (!make) return;
            e.preventDefault();
            e.stopPropagation();
            const [type, data] = make();
            editor.blocks.insert(type, data, undefined, index, true, true);
            if (type === 'delimiter') {
                editor.blocks.insert('paragraph', { text: '' }, undefined, index + 1, true);
                editor.caret.setToBlock(index + 1, 'start');
            } else {
                editor.caret.setToBlock(index, 'start');
            }
        }, true);
    }

    // Menu « / » : Entrée choisit directement le premier résultat du filtre (comme Notion)
    document.addEventListener('keydown', (e) => {
        if (e.key !== 'Enter' || !e.target.matches?.('.ce-popover .cdx-search-field__input')) return;
        const popover = e.target.closest('.ce-popover');
        if (popover.querySelector('.ce-popover-item--focused')) return;
        const first = [...popover.querySelectorAll('.ce-popover-item')].find((item) => item.offsetParent && !item.classList.contains('ce-popover-item--hidden'));
        if (first) {
            e.preventDefault();
            e.stopPropagation();
            first.click();
        }
    }, true);

    // ---------- Création de l'éditeur ----------
    /**
     * options : holder, data, readOnly, uploadUrl(kind), csrf, onChange, onReady, onOpenPage, createSubpage, currentId, pageText
     */
    window.NotesEditor = {
        create(options) {
            const upload = (kind) => async (file) => {
                const body = new FormData();
                body.append('file', file);
                const res = await fetch(options.uploadUrl(kind), { method: 'POST', headers: { 'X-CSRF-Token': options.csrf }, body });
                const data = await res.json().catch(() => ({ success: 0, error: `Erreur serveur (${res.status})` }));
                if (!data.success) options.onError?.(data.error || 'Envoi impossible.');
                return data;
            };
            const tools = {
                paragraph: { inlineToolbar: true, config: { placeholder: 'Tape « / » pour choisir un bloc…' } },
                header: { class: Header, inlineToolbar: true, shortcut: 'CMD+SHIFT+H', config: { levels: [1, 2, 3], defaultLevel: 2, placeholder: 'Titre' } },
                list: { class: EditorjsList, inlineToolbar: true, config: { defaultStyle: 'unordered' } },
                quote: { class: Quote, inlineToolbar: true, config: { quotePlaceholder: 'Citation', captionPlaceholder: 'Auteur' } },
                code: { class: CodeTool, config: { placeholder: 'Code…' } },
                delimiter: Delimiter,
                table: { class: Table, inlineToolbar: true, config: { rows: 2, cols: 3, withHeadings: true } },
                image: { class: ImageTool, config: { captionPlaceholder: 'Légende', buttonContent: 'Choisir une image', uploader: { uploadByFile: upload('image'), uploadByUrl: (url) => Promise.resolve({ success: 1, file: { url } }) } } },
                attaches: { class: AttachesTool, config: { endpoint: 'api/upload.php', buttonText: 'Choisir un fichier', errorMessage: 'Envoi impossible', uploader: { uploadByFile: upload('file') } } },
                embed: { class: Embed, inlineToolbar: true, config: { services: { youtube: true, vimeo: true, twitch: true, codepen: true, instagram: true } } },
                bookmark: Bookmark,
                callout: { class: Callout, inlineToolbar: true },
                toggle: { class: Toggle, inlineToolbar: true },
                pageLink: { class: PageLink, config: { onOpen: options.onOpenPage, createSubpage: options.createSubpage, currentId: options.currentId } },
                toc: Toc,
                marker: { class: Marker, shortcut: 'CMD+SHIFT+M' },
                underline: Underline,
                inlineCode: { class: InlineCode, shortcut: 'CMD+SHIFT+C' },
            };
            if (!options.readOnly) {
                tools.aiWriter = { class: AIWriter, config: { pageText: options.pageText } };
                tools.aiInline = AIInline;
            }
            const editor = new EditorJS({
                holder: options.holder,
                data: options.data && Array.isArray(options.data.blocks) ? options.data : { blocks: [] },
                readOnly: !!options.readOnly,
                autofocus: false,
                minHeight: 120,
                placeholder: 'Commence à écrire, ou tape « / » pour les commandes…',
                tools,
                inlineToolbar: options.readOnly ? false : ['aiInline', 'bold', 'italic', 'underline', 'link', 'marker', 'inlineCode'],
                i18n,
                onChange: () => { document.dispatchEvent(new CustomEvent('notes:changed')); options.onChange?.(); },
                onReady: () => {
                    if (!options.readOnly) {
                        try { new DragDrop(editor); } catch { /* glisser-déposer facultatif */ }
                        attachShortcuts(editor, typeof options.holder === 'string' ? document.getElementById(options.holder) : options.holder);
                    }
                    document.dispatchEvent(new CustomEvent('notes:changed'));
                    options.onReady?.(editor);
                },
            });
            return editor;
        },
        esc,
    };
})();
