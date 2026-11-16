    const REVEAL_KEY = 'draws.reveal.settings';
    const REVEAL_DEFAULTS = { title: 'La grande tombola Djibouti Telecom & D-Money', bg: '#0F1D47', duration: 5, sound: true };

    // Objets navigateur (AudioContext, timers…) gardés hors de l'état réactif d'Alpine :
    // enveloppés dans un proxy, ils lèveraient « Illegal invocation ».
    Alpine.data('drawReveal', () => { const k = { timers: [], raf: null, onResize: null, ctx: null, master: null, noise: null, end: 0 }; return {
        rolling: true, full: false, roll: '', settingsOpen: false,
        s: { ...REVEAL_DEFAULTS },

        init() {
            try { Object.assign(this.s, JSON.parse(localStorage.getItem(REVEAL_KEY) || '{}')); } catch (e) {}
            const t = setInterval(() => this.roll = '2537****' + String(Math.floor(Math.random() * 1000)).padStart(3, '0'), 70);
            k.timers.push(t, setTimeout(() => { clearInterval(t); this.rolling = false; this.celebrate(); }, 2600));
        },

        destroy() {
            k.timers.forEach(id => { clearInterval(id); clearTimeout(id); });
            cancelAnimationFrame(k.raf);
            if (k.onResize) window.removeEventListener('resize', k.onResize);
            this.stopSound();
        },

        save() {
            this.s.duration = Math.min(30, Math.max(1, parseInt(this.s.duration) || REVEAL_DEFAULTS.duration));
            try { localStorage.setItem(REVEAL_KEY, JSON.stringify(this.s)); } catch (e) {}
        },

        resetSettings() { this.s = { ...REVEAL_DEFAULTS }; this.save(); },

        // Couleur du titre selon la clarté du fond choisi
        get ink() {
            const h = (this.s.bg || '#000000').replace('#', '');
            const [r, g, b] = [0, 2, 4].map(i => parseInt(h.substr(i, 2), 16) || 0);
            return (0.299 * r + 0.587 * g + 0.114 * b) > 160 ? '#111827' : '#FFFFFF';
        },

        celebrate() {
            this.fireworks();
            if (this.s.sound) this.playSound();
        },

        toggleSound() {
            this.s.sound = !this.s.sound;
            this.save();
            if (!this.s.sound) this.stopSound();
            else if (!this.rolling) this.playSound();
        },

        // ---------- Feux d'artifice (canvas), tant que la fenêtre est ouverte ----------
        fireworks() {
            const canvas = this.$refs.sky;
            if (!canvas) return;
            const c = canvas.getContext('2d');
            const fit = () => {
                const dpr = window.devicePixelRatio || 1;
                canvas.width = window.innerWidth * dpr; canvas.height = window.innerHeight * dpr;
                c.setTransform(dpr, 0, 0, dpr, 0, 0);
            };
            fit();
            k.onResize = fit;
            window.addEventListener('resize', fit);

            const rockets = [], sparks = [];
            const W = () => window.innerWidth, H = () => window.innerHeight;
            let next = 0;

            const launch = () => {
                rockets.push({ x: W() * (0.1 + Math.random() * 0.8), y: H(), vx: (Math.random() - 0.5) * 1.5,
                               vy: -(H() / 70 + Math.random() * 4), top: H() * (0.12 + Math.random() * 0.35),
                               hue: Math.floor(Math.random() * 360) });
            };
            const explode = (r) => {
                const n = 60 + Math.floor(Math.random() * 50);
                for (let i = 0; i < n; i++) {
                    const a = (Math.PI * 2 * i) / n, v = 2 + Math.random() * 4.5;
                    sparks.push({ x: r.x, y: r.y, vx: Math.cos(a) * v, vy: Math.sin(a) * v, life: 1,
                                  decay: 0.010 + Math.random() * 0.012, hue: r.hue + (Math.random() * 40 - 20) });
                }
                this.boom();
            };

            const frame = (now) => {
                // efface progressivement l'image précédente : traînées lumineuses
                c.globalCompositeOperation = 'destination-out';
                c.fillStyle = 'rgba(0,0,0,0.22)';
                c.fillRect(0, 0, W(), H());
                c.globalCompositeOperation = 'lighter';

                if (now > next) { launch(); if (Math.random() < 0.35) launch(); next = now + 350 + Math.random() * 600; }

                for (let i = rockets.length - 1; i >= 0; i--) {
                    const r = rockets[i];
                    r.x += r.vx; r.y += r.vy; r.vy += 0.12;
                    c.fillStyle = `hsl(${r.hue},100%,75%)`;
                    c.fillRect(r.x - 1.5, r.y - 1.5, 3, 3);
                    if (r.y <= r.top || r.vy >= 0) { explode(r); rockets.splice(i, 1); }
                }
                for (let i = sparks.length - 1; i >= 0; i--) {
                    const p = sparks[i];
                    p.x += p.vx; p.y += p.vy; p.vx *= 0.985; p.vy = p.vy * 0.985 + 0.05; p.life -= p.decay;
                    if (p.life <= 0) { sparks.splice(i, 1); continue; }
                    c.fillStyle = `hsla(${p.hue},100%,${55 + p.life * 25}%,${p.life})`;
                    c.beginPath(); c.arc(p.x, p.y, 2.2, 0, Math.PI * 2); c.fill();
                }
                k.raf = requestAnimationFrame(frame);
            };
            k.raf = requestAnimationFrame(frame);
        },

        // ---------- Son (Web Audio) : durée réglable, coupable à tout moment ----------
        playSound() {
            this.stopSound();
            const AC = window.AudioContext || window.webkitAudioContext;
            if (!AC) return;
            const ctx = k.ctx = new AC();
            ctx.resume?.();
            const t0 = ctx.currentTime, dur = Math.min(30, Math.max(1, +this.s.duration || 5));
            k.end = t0 + dur;

            const master = k.master = ctx.createGain();
            master.gain.setValueAtTime(0.8, t0);
            master.gain.setValueAtTime(0.8, t0 + Math.max(0, dur - 1));
            master.gain.linearRampToValueAtTime(0.0001, t0 + dur);
            master.connect(ctx.destination);

            // bruit blanc réutilisable (applaudissements, détonations)
            const noise = k.noise = ctx.createBuffer(1, ctx.sampleRate, ctx.sampleRate);
            const d = noise.getChannelData(0);
            for (let i = 0; i < d.length; i++) d[i] = Math.random() * 2 - 1;

            // fanfare : do-mi-sol puis accord final
            const note = (f, at, len, vol = 0.12) => {
                ['sawtooth', 'square'].forEach(type => {
                    const o = ctx.createOscillator(), g = ctx.createGain(), lp = ctx.createBiquadFilter();
                    o.type = type; o.frequency.value = f; lp.type = 'lowpass'; lp.frequency.value = 2400;
                    g.gain.setValueAtTime(0.0001, t0 + at);
                    g.gain.exponentialRampToValueAtTime(vol, t0 + at + 0.03);
                    g.gain.exponentialRampToValueAtTime(0.0001, t0 + at + len);
                    o.connect(lp).connect(g).connect(master);
                    o.start(t0 + at); o.stop(t0 + at + len + 0.05);
                });
            };
            [[523.25, 0], [659.25, 0.16], [783.99, 0.32]].forEach(([f, at]) => note(f, at, 0.2));
            [523.25, 659.25, 783.99, 1046.5].forEach(f => note(f, 0.5, Math.max(0.3, Math.min(1.4, dur - 0.5)), 0.08));

            // applaudissements : nombreux petits claquements filtrés jusqu'à la fin de la durée
            for (let at = 0.4; at < dur; at += 0.012 + Math.random() * 0.03) {
                const src = ctx.createBufferSource(), bp = ctx.createBiquadFilter(), g = ctx.createGain();
                src.buffer = noise; bp.type = 'bandpass'; bp.frequency.value = 900 + Math.random() * 1800; bp.Q.value = 1.2;
                g.gain.setValueAtTime(0.05 + Math.random() * 0.12, t0 + at);
                g.gain.exponentialRampToValueAtTime(0.0001, t0 + at + 0.04);
                src.connect(bp).connect(g).connect(master);
                src.start(t0 + at, Math.random() * 0.9, 0.06);
            }
        },

        // détonation à chaque explosion, seulement pendant la durée du son
        boom() {
            const ctx = k.ctx;
            if (!ctx || !this.s.sound || ctx.state === 'closed' || ctx.currentTime > k.end - 0.3) return;
            const t = ctx.currentTime, src = ctx.createBufferSource(), lp = ctx.createBiquadFilter(), g = ctx.createGain();
            src.buffer = k.noise; lp.type = 'lowpass'; lp.frequency.value = 500;
            g.gain.setValueAtTime(0.5, t); g.gain.exponentialRampToValueAtTime(0.0001, t + 0.7);
            src.connect(lp).connect(g).connect(k.master);
            src.start(t, 0, 0.75);
        },

        stopSound() {
            if (k.ctx && k.ctx.state !== 'closed') k.ctx.close();
            k.ctx = null;
        },
    }; });
