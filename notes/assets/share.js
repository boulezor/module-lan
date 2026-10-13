/* Vue publique d'une page partagée (lecture seule). */
(() => {
    const boot = JSON.parse(document.getElementById('boot').textContent);
    const esc = window.NotesEditor.esc;
    const page = boot.page;
    window.NotesPages = {};
    const cover = page.cover
        ? `<div class="cover ${page.cover.startsWith('gradient-') ? esc(page.cover) : ''}" style="${page.cover.startsWith('gradient-') ? '' : `background-image:url('${page.cover.replace(/'/g, '%27')}')`}"></div>` : '';
    document.getElementById('view').innerHTML = `${cover}<div class="page-inner ${page.cover ? 'with-cover' : ''}">
        ${page.icon ? `<div class="page-icon">${esc(page.icon)}</div>` : ''}<h1 class="page-title">${esc(page.title || 'Sans titre')}</h1><div id="editor" class="editor"></div></div>`;
    NotesEditor.create({ holder: 'editor', data: page.content, readOnly: true, csrf: '', uploadUrl: () => '' });
})();
