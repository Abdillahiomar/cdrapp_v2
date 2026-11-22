{{-- Scripts des pages du tirage (layout layouts.tombola) : scène de révélation, confettis, notification.
     Chargés dans le <head> : disponibles dès le premier rendu, même en ouvrant directement ?lot=… --}}
<script>
(() => {
    if (window.tgDraw) return;   // déjà chargé (navigation wire:navigate)

    const sleep = ms => new Promise(r => setTimeout(r, ms));
    const fakeMasked = () => '77 ** ** ' + String(Math.floor(Math.random() * 100)).padStart(2, '0');

    // Scène de tirage : le gagnant est tiré et enregistré par le serveur ($wire.launch),
    // le défilement n'est qu'un habillage (aucun tirage côté navigateur).
    window.tgDraw = (hasWinner) => ({
        phase: hasWinner ? 'done' : 'idle',
        shown: hasWinner ? 99 : 0,
        fresh: false, full: false, settled: false,
        roll: '77 ** ** **', sub: 'Numéro du gagnant', progress: 0,

        init() {
            // État partagé avec la carte des suppléants (hors de la scène)
            if (!Alpine.store('tgReveal')) Alpine.store('tgReveal', { shown: 99 });
            Alpine.store('tgReveal').shown = this.shown;
            this.$watch('shown', v => Alpine.store('tgReveal').shown = v);
        },

        async launch(prizeId, question) {
            if (this.phase !== 'idle' || !confirm(question)) return;
            this.phase = 'rolling'; this.settled = false; this.shown = 0; this.full = false;

            const DURATION = 4200;
            const animation = this.spin(DURATION);
            let res = null;
            try { res = await this.$wire.launch(prizeId); } catch (e) { res = null; }
            await animation;

            if (!res || !res.ok) { this.reset(); return; }
            this.roll = res.masked; this.sub = 'Gagnant tiré'; this.settled = true;
            await sleep(800);
            this.fresh = true; this.phase = 'done';
            for (let i = 1; i <= 5; i++) { await sleep(i === 1 ? 150 : 420); this.shown = i; }
            tgConfetti();
        },

        spin(duration) {
            return new Promise(resolve => {
                const t0 = performance.now(); let last = 0;
                const tick = now => {
                    const p = Math.min(1, (now - t0) / duration);
                    this.progress = p * 100;
                    if (now - last > 45 + Math.pow(p, 3) * 380) { last = now; this.roll = fakeMasked(); this.sub = 'Sélection en cours…'; }
                    p < 1 ? requestAnimationFrame(tick) : resolve();
                };
                requestAnimationFrame(tick);
            });
        },

        again() { this.reset(); },
        reset() { this.phase = 'idle'; this.progress = 0; this.roll = '77 ** ** **'; this.sub = 'Numéro du gagnant'; this.settled = false; this.shown = 0; },

        copy(value, label = 'Numéro copié') {
            const done = () => tgToast(label + ' : ' + value);
            if (navigator.clipboard?.writeText) navigator.clipboard.writeText(value).then(done, done);
            else { const t = document.createElement('textarea'); t.value = value; document.body.appendChild(t); t.select(); try { document.execCommand('copy'); } catch (e) {} t.remove(); done(); }
        },
    });

    window.tgToast = (msg) => {
        const t = document.getElementById('tg-toast'); if (!t) return;
        t.textContent = msg; t.classList.add('show'); clearTimeout(t._h); t._h = setTimeout(() => t.classList.remove('show'), 2200);
    };

    // Célébration discrète : confettis jaunes et blancs (~3 s)
    window.tgConfetti = () => {
        if (matchMedia('(prefers-reduced-motion: reduce)').matches) return;
        const cv = document.getElementById('tg-confetti'); if (!cv) return;
        const c = cv.getContext('2d'), dpr = devicePixelRatio || 1;
        cv.width = innerWidth * dpr; cv.height = innerHeight * dpr; c.setTransform(dpr, 0, 0, dpr, 0, 0);
        const colors = ['#F5C400', '#FFE27A', '#FFFFFF', '#9DB8E6'];
        const parts = Array.from({ length: 150 }, (_, i) => ({
            x: innerWidth / 2 + (Math.random() - .5) * 240, y: innerHeight * .35,
            vx: (Math.random() - .5) * 14, vy: -Math.random() * 12 - 4,
            w: 5 + Math.random() * 6, h: 8 + Math.random() * 8, r: Math.random() * 6, vr: (Math.random() - .5) * .3, col: colors[i % colors.length],
        }));
        const t0 = performance.now();
        cancelAnimationFrame(window.__tgConfettiRaf);
        const frame = now => {
            const age = (now - t0) / 1000;
            c.clearRect(0, 0, innerWidth, innerHeight);
            parts.forEach(p => {
                p.vy += .32; p.vx *= .985; p.x += p.vx; p.y += p.vy; p.r += p.vr;
                c.save(); c.globalAlpha = Math.max(0, 1 - age / 3.2); c.translate(p.x, p.y); c.rotate(p.r);
                c.fillStyle = p.col; c.fillRect(-p.w / 2, -p.h / 2, p.w, p.h * Math.abs(Math.cos(p.r * 2))); c.restore();
            });
            if (age < 3.3) window.__tgConfettiRaf = requestAnimationFrame(frame); else c.clearRect(0, 0, innerWidth, innerHeight);
        };
        window.__tgConfettiRaf = requestAnimationFrame(frame);
    };
    // Tableau d'honneur : nouveau « dernier gagnant » depuis le dernier rafraîchissement → éclat + confettis
    // (pas au premier affichage de la page)
    window.tgHonorNew = (id, el) => {
        if (window.__tgHonorLast !== undefined && window.__tgHonorLast !== id) {
            el.classList.add('is-flash');
            window.tgConfetti();
        }
        window.__tgHonorLast = id;
    };
})();
</script>
