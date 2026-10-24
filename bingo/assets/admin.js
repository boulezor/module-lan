// Administration : aperçu du thème « École Saint-Martin » et couleurs reprises du logo
(function () {
    'use strict';
    const form = document.querySelector('.school-form');
    if (!form) return;
    const preview = document.getElementById('school-preview');
    const pick = document.getElementById('school-pick');
    const c1 = form.school_color1;
    const c2 = form.school_color2;

    function refresh() {
        preview.style.setProperty('--sm-1', c1.value);
        preview.style.setProperty('--sm-2', c2.value);
        document.getElementById('school-title-preview').textContent = form.school_title.value || 'Bingo École Saint-Martin';
    }
    [c1, c2, form.school_title].forEach((input) => input.addEventListener('input', refresh));

    function logoImage() {
        return preview.querySelector('img');
    }

    // Nouveau logo choisi : aperçu immédiat et couleurs proposées
    document.getElementById('school-logo-input').addEventListener('change', (event) => {
        const file = event.target.files[0];
        if (!file) return;
        let img = logoImage();
        if (!img) {
            img = document.createElement('img');
            img.alt = 'Logo';
            preview.querySelector('.no-logo')?.remove();
            preview.prepend(img);
        }
        img.onload = () => { pickColors(img); pick.hidden = false; };
        img.src = URL.createObjectURL(file);
    });
    pick.addEventListener('click', () => { if (logoImage()) pickColors(logoImage()); });

    /** Deux couleurs dominantes et franches du logo (on ignore le blanc, le noir et les gris) */
    function pickColors(img) {
        const size = 64;
        const canvas = document.createElement('canvas');
        canvas.width = canvas.height = size;
        const ctx = canvas.getContext('2d');
        ctx.drawImage(img, 0, 0, size, size);
        const data = ctx.getImageData(0, 0, size, size).data;
        const buckets = new Map();
        for (let i = 0; i < data.length; i += 4) {
            const [r, g, b, a] = [data[i], data[i + 1], data[i + 2], data[i + 3]];
            const max = Math.max(r, g, b);
            const min = Math.min(r, g, b);
            if (a < 200 || max - min < 40 || max < 40) continue;
            const key = (r >> 5) + ',' + (g >> 5) + ',' + (b >> 5);
            const bucket = buckets.get(key) || { n: 0, r: 0, g: 0, b: 0 };
            bucket.n++; bucket.r += r; bucket.g += g; bucket.b += b;
            buckets.set(key, bucket);
        }
        const colors = [...buckets.values()].sort((x, y) => y.n - x.n)
            .map((x) => [x.r / x.n, x.g / x.n, x.b / x.n].map(Math.round));
        if (!colors.length) return;
        const distinct = colors.find((c) => Math.abs(c[0] - colors[0][0]) + Math.abs(c[1] - colors[0][1]) + Math.abs(c[2] - colors[0][2]) > 120);
        // La couleur la plus sombre sert de fond, l'autre d'accent
        const pair = [colors[0], distinct || colors[0]].sort((x, y) => (x[0] + x[1] + x[2]) - (y[0] + y[1] + y[2]));
        const hex = (c) => '#' + c.map((v) => v.toString(16).padStart(2, '0')).join('');
        c1.value = hex(pair[0]);
        c2.value = hex(pair[1]);
        refresh();
    }
})();
