{{--
    Style « Glass UI » des pages de tirage (liste des cérémonies, cérémonie, salle de tirage).
    Classes préfixées tg- : aucun effet sur le reste de l'application.
    À inclure à l'intérieur de l'élément racine du composant Livewire.
--}}
<style>
.tg {
    --tg-yellow: #F5C400;
    --tg-yellow-soft: rgba(245, 196, 0, .16);
    --tg-navy: #10264B;
    --tg-navy-2: #1D3C69;
    --tg-night: #071530;
    --tg-ink-2: rgba(255, 255, 255, .74);
    --tg-ink-3: rgba(255, 255, 255, .52);
    --tg-stroke: rgba(255, 255, 255, .16);
    --tg-stroke-soft: rgba(255, 255, 255, .09);
    --tg-shadow: 0 18px 44px rgba(2, 8, 24, .40);
    position: relative; min-height: 100vh;
    color: #fff; font-family: system-ui, -apple-system, 'Segoe UI', Roboto, sans-serif; -webkit-font-smoothing: antialiased;
}
.tg *, .tg *::before, .tg *::after { box-sizing: border-box; }
.tg p, .tg h1, .tg h2, .tg h3 { margin: 0; }

/* Fond de page (layout layouts.tombola) */
.tg-body { margin: 0; min-height: 100vh; overflow-x: hidden; color: #fff; background:
    radial-gradient(1200px 700px at 85% -10%, rgba(29, 60, 105, .95), transparent 60%),
    radial-gradient(900px 600px at -10% 110%, rgba(16, 38, 75, .95), transparent 60%),
    linear-gradient(160deg, #0B1D3F 0%, #071530 55%, #050F24 100%) fixed; }
.tg-backdrop-fx { position: fixed; inset: 0; z-index: 0; overflow: hidden; pointer-events: none; }
.tg-backdrop-fx i { position: absolute; border-radius: 50%; filter: blur(70px); opacity: .55; animation: tg-drift 18s ease-in-out infinite alternate; }
.tg-backdrop-fx .o1 { width: 380px; height: 380px; background: rgba(245,196,0,.20); top: -120px; left: 38%; }
.tg-backdrop-fx .o2 { width: 520px; height: 520px; background: rgba(60,110,190,.33); bottom: -200px; right: -120px; animation-delay: -6s; }
.tg-backdrop-fx .o3 { width: 300px; height: 300px; background: rgba(245,196,0,.09); bottom: 10%; left: -80px; animation-delay: -11s; }
@keyframes tg-drift { to { transform: translate(40px, 30px) scale(1.08); } }

/* Cadre : barre latérale + contenu */
.tg-app { z-index: 1; display: grid; grid-template-columns: 264px minmax(0, 1fr); gap: 20px; padding: 20px; }
.tg-sidebar { position: sticky; top: 20px; height: calc(100vh - 40px); overflow-y: auto; padding: 22px 18px; display: flex; flex-direction: column; gap: 22px; scrollbar-width: none; }
.tg-main { min-width: 0; display: flex; flex-direction: column; gap: 20px; }
.tg-brand { display: flex; align-items: center; gap: 12px; }
.tg-brand-mark { width: 46px; height: 46px; border-radius: 14px; flex-shrink: 0; display: grid; place-items: center; overflow: hidden; padding: 5px;
    background: linear-gradient(145deg, var(--tg-yellow), #FFDD55); color: var(--tg-navy); font-weight: 900; font-size: 20px;
    box-shadow: 0 8px 24px rgba(245,196,0,.35), inset 0 1px 0 rgba(255,255,255,.6); }
.tg-brand-mark img { max-width: 100%; max-height: 100%; object-fit: contain; border-radius: 9px; background: #fff; }
.tg-brand-name { font-weight: 800; font-size: 17px; }
.tg-brand-name span { color: var(--tg-yellow); }
.tg-brand-sub { font-size: 11px; color: var(--tg-ink-3); letter-spacing: 1.5px; text-transform: uppercase; margin-top: 2px; }
.tg-nav-title { font-size: 10px; color: var(--tg-ink-3); letter-spacing: 2px; text-transform: uppercase; margin: 0 0 10px; font-weight: 700; }
.tg-nav-item { display: flex; align-items: center; gap: 10px; width: 100%; padding: 10px 12px; border-radius: 12px; margin-bottom: 4px;
    font-size: 13px; font-weight: 700; color: var(--tg-ink-2); text-decoration: none; border: 1px solid transparent; transition: background .2s, color .2s, border-color .2s; }
.tg-nav-item svg { width: 17px; height: 17px; flex-shrink: 0; }
.tg-nav-item small { margin-left: auto; color: var(--tg-ink-3); }
.tg-nav-item:hover { background: rgba(255,255,255,.06); color: #fff; }
.tg-nav-item.is-active { background: rgba(245,196,0,.12); border-color: rgba(245,196,0,.32); color: #fff; }
.tg-nav-item.is-active svg { color: var(--tg-yellow); }
.tg-nav-pack { display: block; padding: 11px 12px; border-radius: 12px; margin-bottom: 6px; color: #fff; text-decoration: none; transition: background .2s; }
.tg-nav-pack:hover { background: rgba(255,255,255,.06); }
.tg-nav-row { display: flex; align-items: center; justify-content: space-between; gap: 8px; font-size: 13px; font-weight: 700; }
.tg-nav-row small { color: var(--tg-ink-3); font-size: 11px; font-weight: 600; }
.tg-dot { display: inline-block; width: 9px; height: 9px; border-radius: 50%; margin-right: 8px; background: var(--c, #fff); box-shadow: 0 0 10px var(--c, #fff); }
.tg-bar { display: block; height: 5px; border-radius: 5px; background: rgba(255,255,255,.09); margin-top: 9px; overflow: hidden; }
.tg-bar > i { display: block; height: 100%; border-radius: inherit; background: var(--c); transition: width .8s cubic-bezier(.2,.8,.2,1); }
.tg-side-card { padding: 14px; border-radius: 14px; background: rgba(255,255,255,.05); border: 1px solid var(--tg-stroke-soft); }
.tg-rules { font-size: 11px; line-height: 1.55; color: var(--tg-ink-2); padding: 14px; border-radius: 14px; background: rgba(245,196,0,.06); border: 1px solid rgba(245,196,0,.18); }
.tg-rules b { color: var(--tg-yellow); }
.tg-topbar { display: flex; align-items: center; justify-content: space-between; gap: 16px; padding: 16px 22px; }
.tg-top-right { display: flex; align-items: center; gap: 12px; flex-shrink: 0; }
.tg-ellipsis { white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.tg-only-mobile { display: none !important; }
.tg-tabs { display: none; gap: 8px; overflow-x: auto; padding-bottom: 2px; scrollbar-width: none; }
.tg-tab { flex: 0 0 auto; display: inline-flex; align-items: center; padding: 9px 15px; border-radius: 999px; font-size: 12.5px; font-weight: 700; color: #fff; text-decoration: none;
    background: rgba(255,255,255,.07); border: 1px solid var(--tg-stroke-soft); backdrop-filter: blur(10px); -webkit-backdrop-filter: blur(10px); }
.tg-tab.is-active { background: var(--tg-yellow); color: var(--tg-navy); border-color: var(--tg-yellow); }
.tg-page { display: flex; flex-direction: column; gap: 20px; }
.tg-toolbar { display: flex; align-items: center; justify-content: space-between; gap: 14px; flex-wrap: wrap; }

/* Verre */
.tg-glass {
    position: relative;
    background: linear-gradient(145deg, rgba(255,255,255,.10), rgba(255,255,255,.035));
    border: 1px solid var(--tg-stroke); border-radius: 18px;
    box-shadow: var(--tg-shadow), inset 0 1px 0 rgba(255,255,255,.12);
    backdrop-filter: blur(16px) saturate(140%); -webkit-backdrop-filter: blur(16px) saturate(140%);
}
.tg-glass::before { content: ''; position: absolute; inset: 0; border-radius: inherit; pointer-events: none;
    background: linear-gradient(120deg, rgba(255,255,255,.09) 0%, transparent 30%); }
.tg-glass > * { position: relative; }
.tg-glass.tg-sidebar { position: sticky; }
.tg-pad { padding: 18px 20px; }

/* Textes */
.tg-eyebrow { font-size: 11px; font-weight: 800; color: var(--tg-yellow); letter-spacing: 2.5px; text-transform: uppercase; margin: 0; }
.tg-h1 { font-size: clamp(20px, 2.2vw, 26px); font-weight: 900; letter-spacing: -.3px; margin: 4px 0 4px; line-height: 1.2; }
.tg-h2 { font-size: 15px; font-weight: 800; margin: 0; }
.tg-muted { font-size: 12px; color: var(--tg-ink-3); margin: 0; line-height: 1.55; }
.tg-text { font-size: 12.5px; color: var(--tg-ink-2); line-height: 1.6; }
.tg-link { color: var(--tg-ink-2); text-decoration: none; font-size: 12px; font-weight: 600; }
.tg-link:hover { color: #fff; }
.tg-strong { color: #fff; font-weight: 800; }
.tg-mono { font-family: ui-monospace, Consolas, monospace; }

/* Boutons */
.tg-btn {
    display: inline-flex; align-items: center; justify-content: center; gap: 7px; position: relative; overflow: hidden;
    font: inherit; font-size: 12.5px; font-weight: 800; letter-spacing: .3px; padding: 9px 16px; border-radius: 11px;
    border: none; cursor: pointer; text-decoration: none; white-space: nowrap;
    color: var(--tg-navy); background: linear-gradient(180deg, #FFD83A, var(--tg-yellow));
    box-shadow: 0 8px 20px rgba(245,196,0,.28), inset 0 1px 0 rgba(255,255,255,.55);
    transition: transform .2s, box-shadow .2s, background .2s;
}
.tg-btn:hover { transform: translateY(-1px); box-shadow: 0 12px 26px rgba(245,196,0,.40), inset 0 1px 0 rgba(255,255,255,.55); }
.tg-btn:active { transform: translateY(1px); }
.tg-btn:disabled { opacity: .5; cursor: wait; transform: none; }
.tg-btn-ghost { color: #fff; background: rgba(255,255,255,.08); border: 1px solid var(--tg-stroke); box-shadow: none; }
.tg-btn-ghost:hover { background: rgba(255,255,255,.14); box-shadow: none; }
.tg-btn-danger { color: #FFB4AB; background: rgba(239, 68, 68, .12); border: 1px solid rgba(239, 68, 68, .35); box-shadow: none; }
.tg-btn-danger:hover { background: rgba(239, 68, 68, .2); box-shadow: none; }
.tg-btn-sm { font-size: 11.5px; padding: 6px 12px; border-radius: 9px; }
.tg-btn-shine::after { content: ''; position: absolute; top: 0; left: -60%; width: 40%; height: 100%; transform: skewX(-20deg);
    background: linear-gradient(100deg, transparent, rgba(255,255,255,.55), transparent); animation: tg-shine 3.2s infinite; }
@keyframes tg-shine { 0%, 60% { left: -60%; } 100% { left: 130%; } }

/* Formulaires */
.tg-label { font-size: 11px; color: var(--tg-ink-3); display: block; margin-bottom: 5px; font-weight: 700; letter-spacing: .3px; }
.tg-input {
    width: 100%; font: inherit; font-size: 13px; color: #fff; outline: none; padding: 9px 12px; border-radius: 10px;
    background: rgba(7, 21, 48, .55); border: 1px solid var(--tg-stroke); transition: border-color .2s, box-shadow .2s;
}
.tg-input::placeholder { color: rgba(255,255,255,.35); }
.tg-input:focus { border-color: rgba(245,196,0,.7); box-shadow: 0 0 0 3px rgba(245,196,0,.15); }
.tg-input option { color: #111827; background: #fff; }
.tg-input[type="date"] { color-scheme: dark; }
.tg-error { font-size: 11px; color: #FFB4AB; margin: 5px 0 0; }
.tg-req { color: var(--tg-yellow); }

/* Badges de statut (fond sombre) */
.tg-badge { display: inline-flex; align-items: center; gap: 6px; padding: 4px 11px; border-radius: 999px; font-size: 11px; font-weight: 700; white-space: nowrap;
    color: var(--tg-ink-2); background: rgba(255,255,255,.08); border: 1px solid var(--tg-stroke-soft); }
.tg-badge::before { content: ''; width: 7px; height: 7px; border-radius: 50%; background: currentColor; box-shadow: 0 0 8px currentColor; }
.tg-s-preparation, .tg-s-draft, .tg-s-computing { color: #FFD666; background: rgba(245,196,0,.10); border-color: rgba(245,196,0,.30); }
.tg-s-ready  { color: #9CC3FF; background: rgba(96,165,250,.12); border-color: rgba(96,165,250,.32); }
.tg-s-in_progress, .tg-s-drawing { color: #FFE27A; background: rgba(245,196,0,.16); border-color: rgba(245,196,0,.45); }
.tg-s-in_progress::before, .tg-s-drawing::before { animation: tg-pulse 1.6s infinite; }
.tg-s-done, .tg-s-drawn { color: #8EEBB0; background: rgba(74,222,128,.12); border-color: rgba(74,222,128,.32); }
.tg-s-closed { color: rgba(255,255,255,.6); }
@keyframes tg-pulse { 50% { opacity: .35; } }

/* Catégorie (Platinum, Gold, Bronze…) : couleur passée par --c */
.tg-cat { display: inline-flex; align-items: center; gap: 7px; padding: 4px 11px; border-radius: 999px; font-size: 11px; font-weight: 800;
    letter-spacing: 1.2px; text-transform: uppercase; color: #fff;
    background: color-mix(in srgb, var(--c) 28%, transparent); border: 1px solid color-mix(in srgb, var(--c) 60%, transparent); }
.tg-cat::before { content: ''; width: 8px; height: 8px; border-radius: 50%; background: var(--c); box-shadow: 0 0 10px var(--c); }

/* Statistiques */
.tg-stats { display: grid; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); gap: 14px; }
.tg-stat { padding: 16px 18px; text-align: left; overflow: hidden; cursor: default; font: inherit; color: inherit; }
button.tg-stat { cursor: pointer; transition: transform .2s, border-color .2s; }
button.tg-stat:hover { transform: translateY(-2px); }
.tg-stat.is-active { border-color: rgba(245,196,0,.6); box-shadow: 0 0 0 1px rgba(245,196,0,.3), var(--tg-shadow); }
.tg-stat-value { font-size: clamp(22px, 2.4vw, 30px); font-weight: 900; letter-spacing: -.5px; font-variant-numeric: tabular-nums; margin: 0; }
.tg-stat-label { font-size: 12px; color: var(--tg-ink-2); margin: 2px 0 0; font-weight: 600; }
.tg-stat-accent { background: linear-gradient(145deg, rgba(245,196,0,.20), rgba(245,196,0,.05)); border-color: rgba(245,196,0,.35); }
.tg-stat-accent .tg-stat-value { color: var(--tg-yellow); }
.tg-stat::after { content: ''; position: absolute; right: -40px; bottom: -40px; width: 110px; height: 110px; border-radius: 50%;
    background: radial-gradient(circle, rgba(245,196,0,.16), transparent 70%); pointer-events: none; }

/* Tableau */
.tg-table { width: 100%; border-collapse: collapse; font-size: 12.5px; }
.tg-table th { padding: 12px 16px; text-align: left; color: var(--tg-ink-3); font-weight: 700; font-size: 11px; letter-spacing: .8px; text-transform: uppercase;
    border-bottom: 1px solid var(--tg-stroke-soft); white-space: nowrap; background: rgba(255,255,255,.03); }
.tg-table td { padding: 12px 16px; border-bottom: 1px solid rgba(255,255,255,.06); color: var(--tg-ink-2); vertical-align: middle; }
.tg-table tbody tr { transition: background .2s; }
.tg-table tbody tr:hover { background: rgba(255,255,255,.04); }
.tg-table .num { text-align: right; font-variant-numeric: tabular-nums; }

/* Alertes */
.tg-alert { border-radius: 12px; padding: 12px 16px; margin-bottom: 16px; font-size: 13px; font-weight: 600; backdrop-filter: blur(10px); -webkit-backdrop-filter: blur(10px); }
.tg-alert-ok { color: #A7F3C4; background: rgba(74,222,128,.10); border: 1px solid rgba(74,222,128,.3); }
.tg-alert-ko { color: #FFC2BB; background: rgba(239,68,68,.12); border: 1px solid rgba(239,68,68,.35); }
.tg-alert-warn { color: #FFE27A; background: rgba(245,196,0,.10); border: 1px solid rgba(245,196,0,.32); }

/* Fenêtres modales */
.tg-backdrop { position: fixed; inset: 0; z-index: 998; background: rgba(3, 10, 26, .62); backdrop-filter: blur(6px); -webkit-backdrop-filter: blur(6px); }
.tg-modal { position: fixed; top: 50%; left: 50%; transform: translate(-50%, -50%); z-index: 999; width: min(500px, 94vw); max-height: 92vh; overflow-y: auto;
    border-radius: 20px; color: #fff;
    background: linear-gradient(160deg, rgba(29,60,105,.92), rgba(16,38,75,.94));
    border: 1px solid var(--tg-stroke); box-shadow: 0 30px 80px rgba(0,0,0,.5), inset 0 1px 0 rgba(255,255,255,.14);
    backdrop-filter: blur(20px); -webkit-backdrop-filter: blur(20px); animation: tg-pop .25s cubic-bezier(.2,.8,.2,1); }
@keyframes tg-pop { from { opacity: 0; transform: translate(-50%, -46%) scale(.97); } }
.tg-modal-head { padding: 18px 22px; border-bottom: 1px solid var(--tg-stroke-soft); }
.tg-modal-body { padding: 20px 22px; display: flex; flex-direction: column; gap: 14px; }
.tg-modal-foot { padding: 14px 22px; border-top: 1px solid var(--tg-stroke-soft); display: flex; justify-content: flex-end; gap: 10px; }

/* Cartes de catégorie (page cérémonie) */
.tg-pools { display: grid; grid-template-columns: repeat(auto-fill, minmax(min(100%, 320px), 1fr)); gap: 16px; }
.tg-pool { display: flex; flex-direction: column; overflow: hidden; }
.tg-pool-top { height: 4px; background: linear-gradient(90deg, var(--c), color-mix(in srgb, var(--c) 20%, transparent)); }
.tg-pool-row { display: flex; align-items: center; gap: 10px; padding: 7px 0; font-size: 12.5px; border-bottom: 1px solid rgba(255,255,255,.05); }
.tg-pool-row:last-child { border-bottom: none; }
.tg-thumb { width: 42px; height: 32px; object-fit: cover; border-radius: 7px; background: rgba(255,255,255,.9); flex-shrink: 0; }
.tg-sep { border-top: 1px solid var(--tg-stroke-soft); }
.tg-empty { border: 1px dashed var(--tg-stroke); border-radius: 16px; padding: 30px; text-align: center; color: var(--tg-ink-3); font-size: 13px; }

/* Salle de tirage */
.tg-room { min-height: 100vh; padding: 22px clamp(14px, 2.6vw, 34px) 60px; }
.tg-room-head { display: flex; align-items: center; justify-content: space-between; gap: 16px; padding: 14px 18px; margin-bottom: 18px; }
.tg-logo { flex: 0 0 auto; width: clamp(52px, 6vw, 72px); height: clamp(52px, 6vw, 72px); border-radius: 16px; padding: 6px;
    background: #fff; display: flex; align-items: center; justify-content: center; box-shadow: 0 10px 26px rgba(0,0,0,.3); }
.tg-logo img { max-width: 100%; max-height: 100%; object-fit: contain; }
.tg-profile { display: flex; align-items: center; gap: 10px; padding: 5px 12px 5px 5px; border-radius: 999px; background: rgba(255,255,255,.06); border: 1px solid var(--tg-stroke-soft); }
.tg-avatar { width: 34px; height: 34px; border-radius: 50%; display: grid; place-items: center; font-weight: 800; font-size: 13px;
    background: linear-gradient(145deg, var(--tg-navy-2), #2E5A98); border: 1px solid rgba(255,255,255,.25); }
.tg-section { padding: 22px; scroll-margin-top: 20px; }
.tg-chip { font-size: 11.5px; font-weight: 600; padding: 6px 11px; border-radius: 999px; background: rgba(255,255,255,.06); border: 1px solid var(--tg-stroke-soft); color: var(--tg-ink-2); white-space: nowrap; }
.tg-chip b { color: #fff; }
.tg-stat-icon { width: 38px; height: 38px; border-radius: 12px; display: grid; place-items: center; margin-bottom: 14px; background: rgba(255,255,255,.08); border: 1px solid var(--tg-stroke-soft); }
.tg-stat-icon svg { width: 19px; height: 19px; }
.tg-chip-float.is-open { color: #9AF0B8; background: rgba(22,101,52,.55); border-color: rgba(74,222,128,.35); }
.tg-chip-float.is-done { color: rgba(255,255,255,.8); }
.tg-noimg svg { width: 72px; height: 72px; filter: drop-shadow(0 12px 18px rgba(0,0,0,.35)); }
.tg-lot-visual img { background: #fff; }
[x-cloak] { display: none !important; }

/* ── Page de révélation d'un lot ── */
.tg-reveal { display: grid; grid-template-columns: minmax(0, 5fr) minmax(0, 7fr); gap: 20px; align-items: start; }
.tg-hero { overflow: hidden; }
.tg-hero-visual { aspect-ratio: 4 / 3; display: grid; place-items: center; position: relative; overflow: hidden; border-radius: 18px 18px 0 0;
    background: radial-gradient(circle at 50% 40%, rgba(245,196,0,.25), transparent 55%), linear-gradient(160deg, var(--tg-navy-2), var(--tg-navy)); }
.tg-hero-visual img { position: relative; max-width: 82%; max-height: 82%; object-fit: contain; border-radius: 16px; background: #fff; padding: 10px;
    box-shadow: 0 22px 44px rgba(0,0,0,.45); animation: tg-float 5s ease-in-out infinite; }
.tg-hero-icon svg { position: relative; width: 150px; height: 150px; filter: drop-shadow(0 22px 30px rgba(0,0,0,.45)); animation: tg-float 5s ease-in-out infinite; }
@keyframes tg-float { 50% { transform: translateY(-10px) rotate(1deg); } }
.tg-rays { position: absolute; inset: -30%; animation: tg-spin 40s linear infinite;
    background: conic-gradient(from 0deg, transparent 0 8%, rgba(245,196,0,.10) 10%, transparent 12% 25%, rgba(255,255,255,.06) 27%, transparent 29% 45%, rgba(245,196,0,.08) 47%, transparent 49% 70%, rgba(255,255,255,.05) 72%, transparent 74%); }
@keyframes tg-spin { to { transform: rotate(360deg); } }
.tg-hero-details { padding: 20px 22px 22px; }
.tg-hero-title { font-size: clamp(22px, 2.4vw, 30px) !important; font-weight: 900; line-height: 1.15; margin: 12px 0 14px !important; }
.tg-kv { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 10px; }
.tg-kv div { padding: 10px 12px; border-radius: 12px; background: rgba(255,255,255,.05); border: 1px solid var(--tg-stroke-soft); min-width: 0; }
.tg-kv small, .tg-winner-grid small, .tg-txn small { display: block; font-size: 10px; color: var(--tg-ink-3); letter-spacing: 1.5px; text-transform: uppercase; margin-bottom: 2px; }
.tg-kv span { font-size: 14px; font-weight: 800; }
.tg-stage { padding: 26px; text-align: center; overflow: hidden; }
.tg-slot { margin: 22px auto 18px; max-width: 520px; border-radius: 18px; padding: 22px 18px; position: relative; overflow: hidden;
    background: rgba(7,21,48,.55); border: 1px solid rgba(255,255,255,.14); box-shadow: inset 0 0 40px rgba(0,0,0,.35); }
.tg-slot-number { font-family: ui-monospace, Consolas, monospace; font-size: clamp(30px, 4.6vw, 50px); font-weight: 800; letter-spacing: 4px; color: #fff; transition: color .4s; }
.tg-slot-sub { font-family: ui-monospace, Consolas, monospace; font-size: 13px; color: var(--tg-ink-3); margin-top: 6px; letter-spacing: 1px; }
.tg-slot.is-rolling .tg-slot-number { animation: tg-blur .12s linear infinite; }
@keyframes tg-blur { 50% { filter: blur(1.2px); transform: translateY(2px); } }
.tg-progress { height: 4px; max-width: 520px; margin: 0 auto 20px; border-radius: 4px; background: rgba(255,255,255,.08); overflow: hidden; }
.tg-progress i { display: block; height: 100%; width: 0; border-radius: inherit; background: linear-gradient(90deg, rgba(245,196,0,.4), var(--tg-yellow)); }
.tg-launch { padding: 16px 38px; font-size: 16px; letter-spacing: 3px; border-radius: 14px; }
.tg-launch:disabled { opacity: .55; }
.tg-winner { margin: 0 auto; max-width: 640px; text-align: left; padding: 24px; border-radius: 22px; position: relative; overflow: hidden;
    background: linear-gradient(150deg, rgba(245,196,0,.20), rgba(245,196,0,.05) 45%, rgba(255,255,255,.05)); border: 1px solid rgba(245,196,0,.45);
    box-shadow: 0 0 0 1px rgba(245,196,0,.15), 0 30px 70px rgba(245,196,0,.16), inset 0 1px 0 rgba(255,255,255,.2); }
.tg-winner::before { content: ''; position: absolute; top: -60%; left: -40%; width: 60%; height: 220%; transform: rotate(18deg); pointer-events: none;
    background: linear-gradient(90deg, transparent, rgba(255,255,255,.14), transparent); animation: tg-sweep 2.4s .4s ease-out both; }
@keyframes tg-sweep { from { left: -40%; } to { left: 130%; } }
.tg-winner-top { display: flex; justify-content: space-between; align-items: center; gap: 12px; flex-wrap: wrap; margin-bottom: 16px; }
.tg-trophy { width: 46px; height: 46px; border-radius: 14px; display: grid; place-items: center; background: var(--tg-yellow); box-shadow: 0 10px 24px rgba(245,196,0,.4); flex-shrink: 0; }
.tg-trophy svg { width: 24px; height: 24px; }
.tg-provisional { display: inline-block; font-size: 11.5px; font-weight: 800; letter-spacing: 1px; text-transform: uppercase; padding: 7px 12px; border-radius: 999px;
    color: #FFE27A; background: rgba(245,196,0,.12); border: 1px dashed rgba(245,196,0,.6); }
.tg-provisional.is-ko { color: #FFB4AB; background: rgba(239,68,68,.12); border-color: rgba(239,68,68,.5); }
.tg-winner-name { font-size: clamp(26px, 3.4vw, 40px); font-weight: 900; letter-spacing: -.5px; line-height: 1.1; }
.tg-txn { margin-top: 18px; display: flex; align-items: center; justify-content: space-between; gap: 12px; padding: 14px 16px; border-radius: 14px;
    background: rgba(7,21,48,.55); border: 1px solid rgba(255,255,255,.16); }
.tg-txn code { font-family: ui-monospace, Consolas, monospace; font-size: clamp(18px, 2.4vw, 26px); font-weight: 800; color: var(--tg-yellow); letter-spacing: 2px; word-break: break-all; }
.tg-txn-id { margin-top: 10px; background: linear-gradient(150deg, rgba(245,196,0,.14), rgba(7,21,48,.6)); border-color: rgba(245,196,0,.45); }
.tg-txn-id code { color: #fff; font-size: clamp(16px, 2vw, 22px); }
.tg-copy { flex-shrink: 0; width: 42px; height: 42px; border-radius: 12px; display: grid; place-items: center; cursor: pointer;
    background: rgba(255,255,255,.08); border: 1px solid var(--tg-stroke); transition: background .2s; }
.tg-copy:hover { background: rgba(245,196,0,.2); }
.tg-copy svg { width: 18px; height: 18px; }
.tg-winner-grid { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 10px; margin-top: 10px; }
.tg-winner-grid div { padding: 10px 12px; border-radius: 12px; background: rgba(255,255,255,.05); border: 1px solid var(--tg-stroke-soft); min-width: 0; }
.tg-winner-grid span { font-size: 14px; font-weight: 700; }
.tg-step { opacity: 0; transform: translateY(14px); filter: blur(6px); transition: opacity .6s ease, transform .6s cubic-bezier(.2,.8,.2,1), filter .6s ease; }
.tg-step.in { opacity: 1; transform: none; filter: none; }
.tg-subs { padding: 20px; }
.tg-subs-head { display: flex; justify-content: space-between; align-items: baseline; gap: 10px; margin-bottom: 14px; flex-wrap: wrap; }
.tg-subs-head h3 { font-size: 15px; font-weight: 800; }
.tg-subs-head p { font-size: 12px; color: var(--tg-ink-3); }
.tg-subs-list { display: grid; grid-template-columns: repeat(auto-fill, minmax(min(100%, 180px), 1fr)); gap: 12px; }
.tg-sub { padding: 14px; border-radius: 14px; background: rgba(255,255,255,.05); border: 1px solid var(--tg-stroke-soft); }
.tg-sub-rank { display: inline-block; font-size: 10.5px; font-weight: 800; letter-spacing: 1.5px; text-transform: uppercase; color: var(--tg-navy);
    background: rgba(255,255,255,.88); padding: 4px 9px; border-radius: 999px; margin-bottom: 10px; }
.tg-sub-name { font-size: 14px; font-weight: 800; }
.tg-sub-phone { font-family: ui-monospace, Consolas, monospace; font-size: 13px; color: var(--tg-ink-2); margin-top: 3px; }
.tg-sub-txn { font-size: 11px; color: var(--tg-ink-3); margin-top: 6px; }
.tg-toast { position: fixed; left: 50%; bottom: 26px; transform: translate(-50%, 20px); opacity: 0; transition: all .3s; z-index: 70; pointer-events: none;
    padding: 11px 18px; border-radius: 12px; font-size: 13px; font-weight: 700; color: #fff; background: rgba(16,38,75,.92); border: 1px solid rgba(245,196,0,.5); }
.tg-toast.show { opacity: 1; transform: translate(-50%, 0); }
@media (max-width: 1180px) {
    .tg-reveal { grid-template-columns: minmax(0, 1fr); }
    .tg-hero { display: grid; grid-template-columns: minmax(0, 1fr) minmax(0, 1fr); }
    .tg-hero-visual { aspect-ratio: auto; min-height: 260px; border-radius: 18px 0 0 18px; }
}
@media (max-width: 640px) {
    .tg-hero { display: block; }
    .tg-hero-visual { border-radius: 18px 18px 0 0; }
    .tg-stage { padding: 20px 14px; }
    .tg-winner { padding: 18px; }
    .tg-winner-grid { grid-template-columns: minmax(0, 1fr); }
    .tg-launch { width: 100%; padding: 15px 18px; }
}

/* ── Tableau d'honneur (projection) ── */
.tg-honor { position: relative; z-index: 1; max-width: 1680px; margin: 0 auto; padding: 22px clamp(14px, 2.4vw, 34px) 60px; display: flex; flex-direction: column; gap: 20px; }
.tg-h-head { display: flex; align-items: center; justify-content: space-between; gap: 18px; padding: 16px 22px; }
.tg-h-brand { display: flex; align-items: center; gap: 16px; min-width: 0; }
.tg-h-logo { width: clamp(52px, 5vw, 70px); height: clamp(52px, 5vw, 70px); flex-shrink: 0; border-radius: 18px; display: grid; place-items: center; padding: 6px; overflow: hidden;
    background: linear-gradient(145deg, var(--tg-yellow), #FFDD55); color: var(--tg-navy); font-weight: 900; font-size: clamp(22px, 2.4vw, 30px);
    box-shadow: 0 10px 28px rgba(245,196,0,.38), inset 0 1px 0 rgba(255,255,255,.6); }
.tg-h-logo img { max-width: 100%; max-height: 100%; object-fit: contain; border-radius: 12px; background: #fff; }
.tg-h-title { font-size: clamp(24px, 3.2vw, 44px); font-weight: 900; letter-spacing: -.5px; line-height: 1.05; margin-top: 4px !important; }
.tg-h-sub { font-size: clamp(12px, 1.1vw, 15px); color: var(--tg-ink-2); margin-top: 4px !important; }
.tg-h-right { display: flex; align-items: center; gap: 12px; flex-shrink: 0; }
.tg-h-live { display: inline-flex; align-items: center; gap: 9px; padding: 9px 15px; border-radius: 999px; font-size: 13px; font-weight: 800;
    color: #9AF0B8; background: rgba(74,222,128,.10); border: 1px solid rgba(74,222,128,.3); }
.tg-h-live i { width: 9px; height: 9px; border-radius: 50%; background: #4ADE80; animation: tg-h-pulse 1.6s infinite; }
@keyframes tg-h-pulse { 0% { box-shadow: 0 0 0 0 rgba(74,222,128,.7); } 70% { box-shadow: 0 0 0 10px rgba(74,222,128,0); } 100% { box-shadow: 0 0 0 0 rgba(74,222,128,0); } }
.tg-h-clock { font-family: ui-monospace, Consolas, monospace; font-size: clamp(18px, 1.8vw, 26px); font-weight: 800; letter-spacing: 1px; padding: 6px 14px; border-radius: 12px;
    background: rgba(7,21,48,.5); border: 1px solid var(--tg-stroke-soft); }
.tg-h-icon { width: 42px; height: 42px; border-radius: 12px; display: grid; place-items: center; cursor: pointer; background: rgba(255,255,255,.08); border: 1px solid var(--tg-stroke); }
.tg-h-icon:hover { background: rgba(255,255,255,.15); }
.tg-h-icon svg { width: 19px; height: 19px; }
.tg-h-top { display: grid; grid-template-columns: minmax(0, 1.6fr) minmax(0, 1fr); gap: 20px; }
.tg-h-latest { padding: 22px 26px; overflow: hidden; display: flex; align-items: center; gap: 22px;
    background: linear-gradient(140deg, rgba(245,196,0,.22), rgba(245,196,0,.05) 50%, rgba(255,255,255,.04));
    border-color: rgba(245,196,0,.45); box-shadow: 0 0 0 1px rgba(245,196,0,.15), 0 26px 70px rgba(245,196,0,.14), inset 0 1px 0 rgba(255,255,255,.2); }
.tg-h-latest::after { content: ''; position: absolute; top: -60%; left: -40%; width: 50%; height: 220%; transform: rotate(18deg); pointer-events: none;
    background: linear-gradient(90deg, transparent, rgba(255,255,255,.13), transparent); animation: tg-h-sweep 6s ease-in-out infinite; }
@keyframes tg-h-sweep { 0% { left: -40%; } 40%, 100% { left: 130%; } }
.tg-h-latest.is-flash { animation: tg-h-flash 1.4s ease-out; }
@keyframes tg-h-flash { 0% { box-shadow: 0 0 0 0 rgba(245,196,0,.9); } 100% { box-shadow: 0 0 0 30px rgba(245,196,0,0); } }
.tg-h-latest-visual { width: clamp(90px, 9vw, 130px); aspect-ratio: 1; flex-shrink: 0; border-radius: 22px; display: grid; place-items: center; overflow: hidden;
    background: radial-gradient(circle at 50% 40%, rgba(245,196,0,.3), transparent 65%), linear-gradient(160deg, var(--tg-navy-2), var(--tg-navy)); border: 1px solid rgba(255,255,255,.18); }
.tg-h-latest-visual svg { width: 58%; filter: drop-shadow(0 10px 16px rgba(0,0,0,.4)); }
.tg-h-latest-visual img { width: 100%; height: 100%; object-fit: contain; background: #fff; padding: 8px; }
.tg-h-label { font-size: 12px; font-weight: 800; letter-spacing: 3px; text-transform: uppercase; color: var(--tg-yellow); display: flex; align-items: center; gap: 8px; }
.tg-h-latest-name { font-size: clamp(26px, 3.2vw, 46px); font-weight: 900; letter-spacing: -.5px; line-height: 1.05; margin-top: 6px !important; }
.tg-h-latest-meta { display: flex; flex-wrap: wrap; align-items: center; gap: 10px 16px; margin-top: 10px; font-size: clamp(13px, 1.2vw, 16px); color: var(--tg-ink-2); }
.tg-h-phone { font-family: ui-monospace, Consolas, monospace; font-size: clamp(18px, 1.8vw, 24px); font-weight: 800; color: var(--tg-yellow); letter-spacing: 2px; }
.tg-h-progress { padding: 22px 24px; display: flex; flex-direction: column; justify-content: center; gap: 14px; }
.tg-h-big { display: flex; align-items: baseline; gap: 10px; }
.tg-h-big b { font-size: clamp(40px, 4.6vw, 64px); font-weight: 900; letter-spacing: -1.5px; color: var(--tg-yellow); font-variant-numeric: tabular-nums; line-height: 1; }
.tg-h-big span { font-size: clamp(16px, 1.6vw, 22px); color: var(--tg-ink-2); font-weight: 700; }
.tg-h-bar { height: 10px; border-radius: 10px; background: rgba(255,255,255,.09); overflow: hidden; }
.tg-h-bar i { display: block; height: 100%; border-radius: inherit; background: linear-gradient(90deg, rgba(245,196,0,.5), var(--tg-yellow)); box-shadow: 0 0 16px rgba(245,196,0,.5); transition: width 1s cubic-bezier(.2,.8,.2,1); }
.tg-h-packs { display: grid; grid-template-columns: repeat(auto-fit, minmax(90px, 1fr)); gap: 10px; }
.tg-h-pp { padding: 10px 12px; border-radius: 12px; background: rgba(255,255,255,.05); border: 1px solid var(--tg-stroke-soft); }
.tg-h-pp small { display: flex; align-items: center; gap: 6px; font-size: 11px; font-weight: 800; letter-spacing: 1px; text-transform: uppercase; color: var(--tg-ink-2); }
.tg-h-pp small::before { content: ''; width: 7px; height: 7px; border-radius: 50%; background: var(--c); flex-shrink: 0; }
.tg-h-pp b { display: block; font-size: 20px; font-weight: 900; margin-top: 3px; }
.tg-h-mini { height: 4px; border-radius: 4px; background: rgba(255,255,255,.09); margin-top: 6px; overflow: hidden; }
.tg-h-mini i { display: block; height: 100%; background: var(--c); border-radius: inherit; transition: width 1s; }
.tg-h-honors { display: grid; grid-template-columns: repeat(auto-fill, minmax(min(100%, 270px), 1fr)); gap: 14px; }
.tg-h-honor { border-radius: 18px; overflow: hidden; display: flex; flex-direction: column; position: relative;
    background: linear-gradient(160deg, rgba(255,255,255,.09), rgba(255,255,255,.03)); border: 1px solid var(--tg-stroke-soft); }
.tg-h-honor.is-won { border-color: color-mix(in srgb, var(--c) 45%, transparent); }
.tg-h-honor.is-new { border-color: rgba(245,196,0,.8); box-shadow: 0 0 0 1px rgba(245,196,0,.4), 0 20px 50px rgba(245,196,0,.2); }
.tg-h-new { position: absolute; top: 12px; right: 12px; z-index: 1; font-size: 10.5px; font-weight: 900; letter-spacing: 1.5px; padding: 4px 9px; border-radius: 999px;
    color: var(--tg-navy); background: var(--tg-yellow); box-shadow: 0 6px 16px rgba(245,196,0,.45); }
.tg-h-honor-top { display: flex; align-items: center; gap: 12px; padding: 14px; background: rgba(255,255,255,.04); border-bottom: 1px solid var(--tg-stroke-soft); }
.tg-h-honor-visual { width: 58px; height: 58px; border-radius: 14px; flex-shrink: 0; display: grid; place-items: center; overflow: hidden;
    background: radial-gradient(circle at 50% 35%, rgba(255,255,255,.16), transparent 62%), linear-gradient(160deg, var(--tg-navy-2), var(--tg-navy)); border: 1px solid var(--tg-stroke-soft); }
.tg-h-honor-visual svg { width: 60%; }
.tg-h-honor-visual img { width: 100%; height: 100%; object-fit: contain; background: #fff; padding: 4px; }
.tg-h-rank { font-size: 10.5px; font-weight: 800; letter-spacing: 1.5px; text-transform: uppercase; color: var(--tg-ink-3); }
.tg-h-lot { font-size: 16px; font-weight: 800; line-height: 1.2; margin-top: 2px !important; }
.tg-h-honor.is-new .tg-h-lot { padding-right: 64px; }
.tg-h-honor-body { padding: 14px 16px 16px; flex: 1; display: flex; flex-direction: column; justify-content: center; min-height: 92px; }
.tg-h-winner { font-size: clamp(17px, 1.5vw, 21px); font-weight: 900; letter-spacing: -.2px; display: flex; align-items: center; gap: 8px; }
.tg-h-winner-phone { font-family: ui-monospace, Consolas, monospace; font-size: 17px; font-weight: 800; color: var(--tg-yellow); letter-spacing: 2px; margin-top: 4px !important; }
.tg-h-waiting { display: flex; align-items: center; gap: 10px; color: var(--tg-ink-3); font-size: 14px; font-weight: 600; }
.tg-h-dots i { display: inline-block; width: 6px; height: 6px; border-radius: 50%; background: currentColor; margin-right: 3px; animation: tg-h-blink 1.4s infinite; }
.tg-h-dots i:nth-child(2) { animation-delay: .2s; } .tg-h-dots i:nth-child(3) { animation-delay: .4s; }
@keyframes tg-h-blink { 0%, 80%, 100% { opacity: .2; } 40% { opacity: 1; } }
.tg-h-shimmer { width: 70%; height: 12px; border-radius: 6px; margin-top: 10px; background: linear-gradient(90deg, rgba(255,255,255,.05), rgba(255,255,255,.14), rgba(255,255,255,.05));
    background-size: 200% 100%; animation: tg-h-shimmer 2s linear infinite; }
@keyframes tg-h-shimmer { to { background-position: -200% 0; } }
@media (max-width: 1100px) { .tg-h-top { grid-template-columns: minmax(0, 1fr); } }
@media (max-width: 700px) {
    .tg-h-head { flex-wrap: wrap; }
    .tg-h-right { width: 100%; justify-content: space-between; }
    .tg-h-latest { flex-direction: column; align-items: flex-start; padding: 18px; }
}

.tg-lot-ref { font-family: ui-monospace, Consolas, monospace; font-size: 11px; color: var(--tg-ink-2); }
.tg-lot-winner { font-size: 12px; color: var(--tg-ink-2); padding: 8px 10px; border-radius: 10px; background: rgba(255,255,255,.05); }
.tg-lot-winner b { color: #fff; }
.tg-section-head { display: flex; align-items: center; justify-content: space-between; gap: 12px; flex-wrap: wrap; margin-bottom: 16px; }
.tg-pack-name { font-size: clamp(17px, 1.8vw, 21px); font-weight: 900; letter-spacing: 2.5px; text-transform: uppercase; margin: 0; color: #fff; }
.tg-pack-badge { width: 46px; height: 46px; border-radius: 14px; display: grid; place-items: center; flex-shrink: 0;
    background: linear-gradient(145deg, var(--c), color-mix(in srgb, var(--c) 35%, transparent)); border: 1px solid rgba(255,255,255,.28);
    box-shadow: 0 8px 22px color-mix(in srgb, var(--c) 35%, transparent), inset 0 1px 0 rgba(255,255,255,.4); }
.tg-lots { display: grid; grid-template-columns: repeat(auto-fill, minmax(min(100%, 240px), 1fr)); gap: 14px; }
.tg-lot { display: flex; flex-direction: column; overflow: hidden; border-radius: 16px;
    background: linear-gradient(160deg, rgba(255,255,255,.09), rgba(255,255,255,.03)); border: 1px solid var(--tg-stroke-soft);
    transition: transform .3s cubic-bezier(.2,.8,.2,1), border-color .3s, box-shadow .3s; }
.tg-lot:hover { transform: translateY(-4px); border-color: var(--tg-stroke); box-shadow: 0 18px 40px rgba(0,0,0,.35); }
.tg-lot.is-done { opacity: .66; }
.tg-lot-visual { position: relative; aspect-ratio: 16 / 10; display: grid; place-items: center; overflow: hidden;
    background: radial-gradient(circle at 50% 35%, rgba(255,255,255,.16), transparent 60%), linear-gradient(160deg, var(--tg-navy-2), var(--tg-navy)); }
.tg-lot-visual img { width: 100%; height: 100%; object-fit: cover; transition: transform .5s; }
.tg-lot:hover .tg-lot-visual img { transform: scale(1.05); }
.tg-lot-visual .tg-noimg { font-size: 42px; opacity: .8; }
.tg-chip-float { position: absolute; top: 10px; font-size: 11px; font-weight: 800; padding: 5px 10px; border-radius: 999px;
    background: rgba(7,21,48,.62); border: 1px solid rgba(255,255,255,.22); color: #fff; backdrop-filter: blur(8px); -webkit-backdrop-filter: blur(8px); }
.tg-lot-body { padding: 13px 14px 15px; display: flex; flex-direction: column; gap: 9px; flex: 1; }
.tg-lot-name { font-size: 15px; font-weight: 800; line-height: 1.25; margin: 0; }
.tg-draw { width: 100%; margin-top: auto; padding: 12px 14px; font-size: 14px; letter-spacing: 2px; border-radius: 12px; }
.tg-state { width: 100%; margin-top: auto; text-align: center; padding: 11px 14px; border-radius: 12px; font-size: 12.5px; font-weight: 800; letter-spacing: 1px;
    color: var(--tg-ink-3); background: rgba(255,255,255,.05); border: 1px solid var(--tg-stroke-soft); }
.tg-state.is-ok { color: #8EEBB0; border-color: rgba(74,222,128,.3); background: rgba(74,222,128,.08); }
.tg-fab { position: fixed; bottom: 14px; right: 16px; z-index: 5; }

@media (max-width: 1023px) {
    .tg-app { grid-template-columns: minmax(0, 1fr); padding: 14px 16px; gap: 14px; }
    .tg-main { gap: 14px; }
    .tg-sidebar { display: none; }
    .tg-tabs { display: flex; }
    .tg-only-mobile { display: grid !important; }
    .tg-topbar { padding: 12px 14px; }
    .tg-profile-text { display: none; }
    .tg-profile { padding: 4px; }
}
@media (max-width: 640px) {
    .tg-topbar .tg-eyebrow { display: none; }
    .tg-top-right .tg-badge { font-size: 0; padding: 7px; gap: 0; }
    .tg-stats { grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 10px; }
    .tg-stat { padding: 14px; }
    .tg-section { padding: 16px; }
    .tg-hide-sm { display: none !important; }
}
@media (prefers-reduced-motion: reduce) {
    .tg *, .tg *::before, .tg *::after { animation: none !important; transition: none !important; }
}
</style>
