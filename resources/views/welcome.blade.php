<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>D-Money — Plateforme de Supervision</title>
    <link rel="preconnect" href="https://fonts.bunny.net">
    <link href="https://fonts.bunny.net/css?family=sora:300,400,500,600,700&family=dm-mono:400,500&display=swap" rel="stylesheet"/>
    <style>
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

        :root {
            --green:       #1B2F6E;
            --green-dark:  #111D45;
            --green-light: #E8ECF8;
            --border:      rgba(27,47,110,0.12);
            --off-white:   #F7F8FC;
            --text-dark:   #0A0F1A;
            --text-mid:    #3D4A6A;
            --text-muted:  #7A85A5;
        }

        html { scroll-behavior: smooth; }

        body {
            font-family: 'Sora', sans-serif;
            background: var(--off-white);
            color: var(--text-dark);
            overflow-x: hidden;
        }

        /* ── NAV ── */
        nav {
            position: fixed;
            top: 0; left: 0; right: 0;
            z-index: 100;
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 0 48px;
            height: 64px;
            background: rgba(247,250,248,0.88);
            backdrop-filter: blur(12px);
            border-bottom: 1px solid var(--border);
        }
        .nav-brand {
            display: flex;
            align-items: center;
            gap: 10px;
            text-decoration: none;
        }
        .nav-brand-icon {
            width: 36px; height: 36px;
            background: var(--green);
            border-radius: 9px;
            display: flex; align-items: center; justify-content: center;
        }
        .nav-brand-icon svg { width: 20px; height: 20px; }
        .nav-brand-name {
            font-size: 16px;
            font-weight: 700;
            color: var(--green);
            letter-spacing: -0.3px;
        }
        .nav-brand-sub {
            font-size: 10px;
            color: var(--text-muted);
            font-family: 'DM Mono', monospace;
            letter-spacing: 0.5px;
        }
        .nav-right {
            display: flex;
            align-items: center;
            gap: 12px;
        }
        .btn-outline {
            padding: 8px 20px;
            border: 1.5px solid var(--green);
            border-radius: 8px;
            color: var(--green);
            font-size: 13px;
            font-weight: 600;
            font-family: 'Sora', sans-serif;
            background: transparent;
            cursor: pointer;
            text-decoration: none;
            transition: background 0.15s, color 0.15s;
        }
        .btn-outline:hover { background: var(--green-light); }
        .btn-primary {
            padding: 8px 20px;
            border: none;
            border-radius: 8px;
            background: var(--green);
            color: #fff;
            font-size: 13px;
            font-weight: 600;
            font-family: 'Sora', sans-serif;
            cursor: pointer;
            text-decoration: none;
            transition: background 0.15s;
        }
        .btn-primary:hover { background: var(--green-dark); }

        /* ── HERO ── */
        .hero {
            min-height: 100vh;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            text-align: center;
            padding: 120px 24px 80px;
            position: relative;
            overflow: hidden;
        }

        /* grille de fond */
        .hero::before {
            content: '';
            position: absolute;
            inset: 0;
            background-image:
                linear-gradient(var(--border) 1px, transparent 1px),
                linear-gradient(90deg, var(--border) 1px, transparent 1px);
            background-size: 48px 48px;
            mask-image: radial-gradient(ellipse 80% 60% at 50% 0%, black 40%, transparent 100%);
            pointer-events: none;
        }

        /* cercle lumineux */
        .hero::after {
            content: '';
            position: absolute;
            top: -120px; left: 50%;
            transform: translateX(-50%);
            width: 700px; height: 700px;
            border-radius: 50%;
            background: radial-gradient(circle, rgba(0,132,61,0.08) 0%, transparent 70%);
            pointer-events: none;
        }

        .hero-tag {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            background: var(--green-light);
            border: 1px solid rgba(0,132,61,0.2);
            border-radius: 20px;
            padding: 5px 14px;
            font-size: 11px;
            font-weight: 600;
            color: var(--green-dark);
            font-family: 'DM Mono', monospace;
            letter-spacing: 0.5px;
            text-transform: uppercase;
            margin-bottom: 28px;
            opacity: 0;
            animation: fadeUp 0.6s ease forwards;
        }
        .hero-tag-dot {
            width: 6px; height: 6px;
            border-radius: 50%;
            background: var(--green);
            animation: pulse 2s ease-in-out infinite;
        }

        .hero-title {
            font-size: clamp(36px, 6vw, 72px);
            font-weight: 700;
            line-height: 1.08;
            letter-spacing: -2px;
            color: var(--text-dark);
            max-width: 800px;
            margin-bottom: 24px;
            opacity: 0;
            animation: fadeUp 0.6s 0.15s ease forwards;
        }
        .hero-title span {
            color: var(--green);
            position: relative;
        }
        .hero-title span::after {
            content: '';
            position: absolute;
            bottom: 4px; left: 0; right: 0;
            height: 3px;
            background: var(--gold);
            border-radius: 2px;
        }

        .hero-sub {
            font-size: 17px;
            font-weight: 400;
            color: var(--text-mid);
            max-width: 520px;
            line-height: 1.7;
            margin-bottom: 40px;
            opacity: 0;
            animation: fadeUp 0.6s 0.25s ease forwards;
        }

        .hero-cta {
            display: flex;
            align-items: center;
            gap: 12px;
            flex-wrap: wrap;
            justify-content: center;
            opacity: 0;
            animation: fadeUp 0.6s 0.35s ease forwards;
        }
        .btn-hero {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 14px 32px;
            background: var(--green);
            color: #fff;
            border: none;
            border-radius: 10px;
            font-size: 14px;
            font-weight: 700;
            font-family: 'Sora', sans-serif;
            cursor: pointer;
            text-decoration: none;
            transition: background 0.15s, transform 0.1s;
        }
        .btn-hero:hover { background: var(--green-dark); transform: translateY(-1px); }
        .btn-hero svg { width: 16px; height: 16px; }

        .btn-hero-ghost {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 14px 28px;
            background: transparent;
            color: var(--text-mid);
            border: 1.5px solid var(--border);
            border-radius: 10px;
            font-size: 14px;
            font-weight: 600;
            font-family: 'Sora', sans-serif;
            cursor: pointer;
            text-decoration: none;
            transition: border-color 0.15s, color 0.15s;
        }
        .btn-hero-ghost:hover { border-color: var(--green); color: var(--green); }

        /* ── STATS BAND ── */
        .stats-band {
            background: var(--green);
            padding: 28px 48px;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 0;
            flex-wrap: wrap;
        }
        .stat-item {
            flex: 1;
            min-width: 160px;
            text-align: center;
            padding: 0 32px;
            border-right: 1px solid rgba(255,255,255,0.15);
        }
        .stat-item:last-child { border-right: none; }
        .stat-num {
            font-size: 32px;
            font-weight: 700;
            color: #fff;
            letter-spacing: -1px;
            line-height: 1;
        }
        .stat-num span { color: var(--gold); }
        .stat-label {
            font-size: 11px;
            color: rgba(255,255,255,0.6);
            margin-top: 4px;
            font-family: 'DM Mono', monospace;
            text-transform: uppercase;
            letter-spacing: 0.8px;
        }

        /* ── FEATURES ── */
        .section {
            padding: 96px 48px;
            max-width: 1200px;
            margin: 0 auto;
        }
        .section-tag {
            display: inline-block;
            font-size: 10px;
            font-weight: 600;
            font-family: 'DM Mono', monospace;
            text-transform: uppercase;
            letter-spacing: 1.5px;
            color: var(--green);
            background: var(--green-light);
            padding: 4px 12px;
            border-radius: 20px;
            margin-bottom: 16px;
        }
        .section-title {
            font-size: clamp(28px, 4vw, 44px);
            font-weight: 700;
            letter-spacing: -1.5px;
            color: var(--text-dark);
            line-height: 1.15;
            margin-bottom: 12px;
        }
        .section-sub {
            font-size: 16px;
            color: var(--text-mid);
            max-width: 480px;
            line-height: 1.7;
            margin-bottom: 56px;
        }

        .features-grid {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 16px;
        }
        .feature-card {
            background: #fff;
            border: 1px solid var(--border);
            border-radius: 14px;
            padding: 28px;
            transition: border-color 0.2s, transform 0.2s;
        }
        .feature-card:hover {
            border-color: var(--green);
            transform: translateY(-3px);
        }
        .feature-icon {
            width: 44px; height: 44px;
            border-radius: 11px;
            display: flex; align-items: center; justify-content: center;
            margin-bottom: 18px;
        }
        .feature-icon svg { width: 22px; height: 22px; }
        .feature-title {
            font-size: 15px;
            font-weight: 700;
            color: var(--text-dark);
            margin-bottom: 8px;
            letter-spacing: -0.3px;
        }
        .feature-desc {
            font-size: 13px;
            color: var(--text-mid);
            line-height: 1.6;
        }

        /* ── DASHBOARD PREVIEW ── */
        .preview-section {
            background: var(--green);
            padding: 80px 48px;
            overflow: hidden;
            position: relative;
        }
        .preview-section::before {
            content: '';
            position: absolute;
            top: -200px; right: -200px;
            width: 600px; height: 600px;
            border-radius: 50%;
            background: rgba(255,255,255,0.04);
            pointer-events: none;
        }
        .preview-inner {
            max-width: 1200px;
            margin: 0 auto;
            display: grid;
            grid-template-columns: 1fr 1fr;
            align-items: center;
            gap: 64px;
        }
        .preview-text .section-tag { background: rgba(255,255,255,0.12); color: #fff; }
        .preview-text .section-title { color: #fff; }
        .preview-text .section-sub { color: rgba(255,255,255,0.7); margin-bottom: 32px; }
        .preview-btn {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 12px 24px;
            background: var(--gold);
            color: var(--green-dark);
            border-radius: 9px;
            font-size: 13px;
            font-weight: 700;
            font-family: 'Sora', sans-serif;
            text-decoration: none;
            transition: background 0.15s;
        }
        .preview-btn:hover { background: var(--gold-dark); }

        /* mini dashboard card */
        .mini-dashboard {
            background: #fff;
            border-radius: 16px;
            overflow: hidden;
            box-shadow: 0 32px 80px rgba(0,0,0,0.2);
        }
        .mini-dash-header {
            background: var(--green-dark);
            padding: 12px 16px;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .mini-dash-dot {
            width: 8px; height: 8px;
            border-radius: 50%;
        }
        .mini-dash-title {
            font-size: 11px;
            color: rgba(255,255,255,0.7);
            font-family: 'DM Mono', monospace;
            margin-left: 4px;
        }
        .mini-dash-body { padding: 16px; }
        .mini-kpi-row {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 8px;
            margin-bottom: 14px;
        }
        .mini-kpi {
            background: var(--off-white);
            border-radius: 8px;
            padding: 10px 12px;
        }
        .mini-kpi-label {
            font-size: 9px;
            color: var(--text-muted);
            font-family: 'DM Mono', monospace;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        .mini-kpi-val {
            font-size: 16px;
            font-weight: 700;
            color: var(--text-dark);
            margin-top: 2px;
        }
        .mini-kpi-delta { font-size: 9px; color: var(--green); margin-top: 1px; }

        /* mini chart bars */
        .mini-chart {
            background: var(--off-white);
            border-radius: 8px;
            padding: 12px;
        }
        .mini-chart-label {
            font-size: 9px;
            color: var(--text-muted);
            font-family: 'DM Mono', monospace;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-bottom: 10px;
        }
        .bars {
            display: flex;
            align-items: flex-end;
            gap: 4px;
            height: 50px;
        }
        .bar {
            flex: 1;
            border-radius: 3px 3px 0 0;
            background: var(--green);
            opacity: 0.7;
            transition: opacity 0.2s;
        }
        .bar:nth-child(7) { opacity: 1; background: var(--gold); }

        /* ── MODULES ── */
        .modules-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 14px;
        }
        .module-card {
            background: #fff;
            border: 1px solid var(--border);
            border-radius: 12px;
            padding: 22px 24px;
            display: flex;
            align-items: flex-start;
            gap: 16px;
            transition: border-color 0.2s;
        }
        .module-card:hover { border-color: var(--green); }
        .module-num {
            font-size: 11px;
            font-family: 'DM Mono', monospace;
            color: var(--green);
            background: var(--green-light);
            border-radius: 6px;
            padding: 3px 7px;
            flex-shrink: 0;
            font-weight: 600;
            margin-top: 2px;
        }
        .module-title {
            font-size: 14px;
            font-weight: 700;
            color: var(--text-dark);
            margin-bottom: 5px;
        }
        .module-desc {
            font-size: 12px;
            color: var(--text-mid);
            line-height: 1.6;
        }

        /* ── CTA FINAL ── */
        .cta-section {
            text-align: center;
            padding: 96px 24px;
            position: relative;
        }
        .cta-section::before {
            content: '';
            position: absolute;
            inset: 0;
            background-image:
                linear-gradient(var(--border) 1px, transparent 1px),
                linear-gradient(90deg, var(--border) 1px, transparent 1px);
            background-size: 48px 48px;
            mask-image: radial-gradient(ellipse 60% 80% at 50% 50%, black 40%, transparent 100%);
            pointer-events: none;
        }
        .cta-box {
            position: relative;
            display: inline-block;
            background: var(--green);
            border-radius: 20px;
            padding: 56px 72px;
            max-width: 640px;
            width: 100%;
        }
        .cta-box-tag {
            display: inline-block;
            background: rgba(255,255,255,0.15);
            color: #fff;
            font-size: 10px;
            font-family: 'DM Mono', monospace;
            text-transform: uppercase;
            letter-spacing: 1px;
            padding: 4px 12px;
            border-radius: 20px;
            margin-bottom: 20px;
        }
        .cta-box-title {
            font-size: 32px;
            font-weight: 700;
            color: #fff;
            letter-spacing: -1px;
            margin-bottom: 14px;
            line-height: 1.2;
        }
        .cta-box-sub {
            font-size: 14px;
            color: rgba(255,255,255,0.7);
            margin-bottom: 32px;
            line-height: 1.7;
        }
        .cta-box-btn {
            display: inline-flex;
            align-items: center;
            gap: 9px;
            padding: 14px 36px;
            background: var(--gold);
            color: var(--green-dark);
            border: none;
            border-radius: 10px;
            font-size: 14px;
            font-weight: 700;
            font-family: 'Sora', sans-serif;
            cursor: pointer;
            text-decoration: none;
            transition: background 0.15s, transform 0.1s;
        }
        .cta-box-btn:hover { background: var(--gold-dark); transform: translateY(-2px); }
        .cta-box-btn svg { width: 16px; height: 16px; }

        /* ── FOOTER ── */
        footer {
            border-top: 1px solid var(--border);
            padding: 24px 48px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            flex-wrap: wrap;
            gap: 12px;
        }
        .footer-copy {
            font-size: 11px;
            color: var(--text-muted);
            font-family: 'DM Mono', monospace;
        }
        .footer-right {
            display: flex;
            align-items: center;
            gap: 6px;
            font-size: 11px;
            color: var(--green);
            font-family: 'DM Mono', monospace;
        }
        .footer-dot {
            width: 6px; height: 6px;
            border-radius: 50%;
            background: var(--green);
        }

        /* ── ANIMATIONS ── */
        @keyframes fadeUp {
            from { opacity: 0; transform: translateY(20px); }
            to   { opacity: 1; transform: translateY(0); }
        }
        @keyframes pulse {
            0%, 100% { opacity: 1; }
            50%       { opacity: 0.4; }
        }
    </style>
</head>
<body>

    {{-- NAV --}}
    <nav>
        <a href="/" class="nav-brand">
            <div class="nav-brand-icon">
                <svg viewBox="0 0 24 24" fill="none">
                    <rect x="3" y="7" width="18" height="12" rx="2" fill="#fff"/>
                    <path d="M7 12h10M7 15h6" stroke="#00843D" stroke-width="1.5" stroke-linecap="round"/>
                    <circle cx="17" cy="15" r="1.5" fill="#FFC72C"/>
                </svg>
            </div>
            <div>
                <div class="nav-brand-name">D-Money</div>
                <div class="nav-brand-sub">Supervision & Analyse</div>
            </div>
        </a>
        <div class="nav-right">
            @if (Route::has('login'))
                @auth
                    <a href="{{ url('/tableau_de_bord') }}" class="btn-primary">Tableau de bord</a>
                @else
                    <a href="{{ route('login') }}" class="btn-outline">Connexion</a>
                @endauth
            @endif
        </div>
    </nav>

    {{-- HERO --}}
    <section class="hero">
        <div class="hero-tag">
            <div class="hero-tag-dot"></div>
            Plateforme de supervision — Djibouti Telecom
        </div>
        <h1 class="hero-title">
            Prenez les meilleures<br>décisions avec <span>D-Money</span>
        </h1>
        <p class="hero-sub">
            Un tableau de bord centralisé pour analyser les transactions,
            surveiller les clients et détecter les anomalies en temps réel.
        </p>
        <div class="hero-cta">
            @auth
                <a href="{{ url('/dashboard') }}" class="btn-hero">
                    <svg viewBox="0 0 16 16" fill="white"><rect x="1" y="1" width="6" height="6" rx="1.5"/><rect x="9" y="1" width="6" height="6" rx="1.5"/><rect x="1" y="9" width="6" height="6" rx="1.5"/><rect x="9" y="9" width="6" height="6" rx="1.5"/></svg>
                    Accéder au dashboard
                </a>
            @else
                <a href="{{ route('login') }}" class="btn-hero">
                    <svg viewBox="0 0 16 16" fill="white"><path d="M10 3H13a1 1 0 011 1v9a1 1 0 01-1 1h-3M7 11l3-3-3-3M10 8H2" stroke="white" stroke-width="1.5" stroke-linecap="round" fill="none"/></svg>
                    Se connecter
                </a>
            @endauth
            <a href="#features" class="btn-hero-ghost">
                Découvrir les fonctionnalités
            </a>
        </div>
    </section>

    {{-- STATS BAND --}}
    <div class="stats-band">
        <div class="stat-item">
            <div class="stat-num">12<span>K+</span></div>
            <div class="stat-label">Transactions / jour</div>
        </div>
        <div class="stat-item">
            <div class="stat-num">4,2<span>M</span></div>
            <div class="stat-label">Volume DJF / mois</div>
        </div>
        <div class="stat-item">
            <div class="stat-num">3<span>K+</span></div>
            <div class="stat-label">Clients actifs</div>
        </div>
        <div class="stat-item">
            <div class="stat-num">99<span>%</span></div>
            <div class="stat-label">Disponibilité système</div>
        </div>
    </div>

    {{-- FEATURES --}}
    <section class="section" id="features">
        <div class="section-tag">Fonctionnalités</div>
        <h2 class="section-title">Tout ce dont vous<br>avez besoin</h2>
        <p class="section-sub">
            Une plateforme pensée pour la direction — données claires,
            analyses rapides, décisions éclairées.
        </p>

        <div class="features-grid">
            <div class="feature-card">
                <div class="feature-icon" style="background:#E5F5ED;">
                    <svg viewBox="0 0 22 22" fill="none">
                        <rect x="2" y="2" width="7" height="7" rx="2" fill="#00843D"/>
                        <rect x="13" y="2" width="7" height="7" rx="2" fill="#00843D" opacity="0.4"/>
                        <rect x="2" y="13" width="7" height="7" rx="2" fill="#00843D" opacity="0.4"/>
                        <rect x="13" y="13" width="7" height="7" rx="2" fill="#00843D" opacity="0.4"/>
                    </svg>
                </div>
                <div class="feature-title">Tableau de bord en temps réel</div>
                <div class="feature-desc">KPIs clés, volume des transactions, revenus et taux d'échec agrégés et présentés instantanément.</div>
            </div>
            <div class="feature-card">
                <div class="feature-icon" style="background:#FFF3D0;">
                    <svg viewBox="0 0 22 22" fill="none">
                        <path d="M3 15l5-5 4 4 7-8" stroke="#7A4F00" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/>
                    </svg>
                </div>
                <div class="feature-title">Évolution jour par jour</div>
                <div class="feature-desc">Courbes d'évolution du volume et du nombre de transactions sur 7 jours, 30 jours ou une période personnalisée.</div>
            </div>
            <div class="feature-card">
                <div class="feature-icon" style="background:#FDECEA;">
                    <svg viewBox="0 0 22 22" fill="none">
                        <path d="M11 3L3 19h16L11 3zm0 7v5" stroke="#A32D2D" stroke-width="1.8" stroke-linecap="round"/>
                        <circle cx="11" cy="16" r="0.9" fill="#A32D2D"/>
                    </svg>
                </div>
                <div class="feature-title">Détection des anomalies</div>
                <div class="feature-desc">Identification automatique des transactions suspectes, transactions circulaires et comportements frauduleux.</div>
            </div>
            <div class="feature-card">
                <div class="feature-icon" style="background:#E6F1FB;">
                    <svg viewBox="0 0 22 22" fill="none">
                        <circle cx="8" cy="7" r="4" stroke="#185FA5" stroke-width="1.8"/>
                        <path d="M2 19c0-4 2.7-6 6-6s6 2 6 6" stroke="#185FA5" stroke-width="1.8" stroke-linecap="round"/>
                        <path d="M16 11l2 2 3-3" stroke="#185FA5" stroke-width="1.8" stroke-linecap="round"/>
                    </svg>
                </div>
                <div class="feature-title">Gestion des clients</div>
                <div class="feature-desc">Recherche avancée par MSISDN, nom, statut KYC ou période d'inscription avec pagination optimisée.</div>
            </div>
            <div class="feature-card">
                <div class="feature-icon" style="background:#E5F5ED;">
                    <svg viewBox="0 0 22 22" fill="none">
                        <rect x="3" y="5" width="16" height="13" rx="2" stroke="#00843D" stroke-width="1.8"/>
                        <path d="M7 10h8M7 14h5" stroke="#00843D" stroke-width="1.8" stroke-linecap="round"/>
                    </svg>
                </div>
                <div class="feature-title">Historique des transactions</div>
                <div class="feature-desc">Filtrage multicritère par type, statut, MSISDN ou montant avec export et pagination sur 100 lignes.</div>
            </div>
            <div class="feature-card">
                <div class="feature-icon" style="background:#FFF3D0;">
                    <svg viewBox="0 0 22 22" fill="none">
                        <rect x="3" y="9" width="4" height="10" rx="1" fill="#7A4F00" opacity="0.4"/>
                        <rect x="9" y="5" width="4" height="14" rx="1" fill="#7A4F00" opacity="0.7"/>
                        <rect x="15" y="2" width="4" height="17" rx="1" fill="#7A4F00"/>
                    </svg>
                </div>
                <div class="feature-title">Top types de transactions</div>
                <div class="feature-desc">Classement des types les plus actifs avec volumes associés pour prioriser les actions commerciales.</div>
            </div>
        </div>
    </section>

    {{-- DASHBOARD PREVIEW --}}
    <div class="preview-section">
        <div class="preview-inner">
            <div class="preview-text">
                <div class="section-tag">Aperçu du dashboard</div>
                <h2 class="section-title">Des données<br>lisibles au premier coup d'œil</h2>
                <p class="section-sub">
                    Chaque indicateur est conçu pour répondre aux questions de la direction :
                    combien, comment, et pourquoi.
                </p>
                @auth
                    <a href="{{ url('/dashboard') }}" class="preview-btn">
                        Ouvrir le dashboard
                        <svg width="14" height="14" viewBox="0 0 16 16" fill="currentColor"><path d="M4.5 12h15m0 0l-6.75-6.75M19.5 12l-6.75 6.75" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" fill="none"/></svg>
                    </a>
                @else
                    <a href="{{ route('login') }}" class="preview-btn">
                        Accéder maintenant
                        <svg width="14" height="14" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"><path d="M10 3H13a1 1 0 011 1v9a1 1 0 01-1 1h-3M7 11l3-3-3-3M10 8H2"/></svg>
                    </a>
                @endauth
            </div>

            <div class="mini-dashboard">
                <div class="mini-dash-header">
                    <div class="mini-dash-dot" style="background:#E24B4A;"></div>
                    <div class="mini-dash-dot" style="background:#FFC72C;"></div>
                    <div class="mini-dash-dot" style="background:#00843D;"></div>
                    <div class="mini-dash-title">d-money — tableau de bord</div>
                </div>
                <div class="mini-dash-body">
                    <div class="mini-kpi-row">
                        <div class="mini-kpi">
                            <div class="mini-kpi-label">Volume</div>
                            <div class="mini-kpi-val">4,2M</div>
                            <div class="mini-kpi-delta">↑ +12%</div>
                        </div>
                        <div class="mini-kpi">
                            <div class="mini-kpi-label">Revenus</div>
                            <div class="mini-kpi-val">218K</div>
                            <div class="mini-kpi-delta">↑ +8%</div>
                        </div>
                        <div class="mini-kpi">
                            <div class="mini-kpi-label">Échec</div>
                            <div class="mini-kpi-val" style="color:#E24B4A;">3,2%</div>
                            <div class="mini-kpi-delta" style="color:#9ca3af;">— stable</div>
                        </div>
                    </div>
                    <div class="mini-chart">
                        <div class="mini-chart-label">Volume 7 derniers jours</div>
                        <div class="bars">
                            <div class="bar" style="height:40%;"></div>
                            <div class="bar" style="height:55%;"></div>
                            <div class="bar" style="height:45%;"></div>
                            <div class="bar" style="height:70%;"></div>
                            <div class="bar" style="height:60%;"></div>
                            <div class="bar" style="height:85%;"></div>
                            <div class="bar" style="height:100%;"></div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    {{-- MODULES --}}
    <section class="section">
        <div class="section-tag">Modules disponibles</div>
        <h2 class="section-title">Une plateforme complète</h2>
        <p class="section-sub">4 modules intégrés pour une supervision totale de l'activité D-Money.</p>

        <div class="modules-grid">
            <div class="module-card">
                <div class="module-num">01</div>
                <div>
                    <div class="module-title">Dashboard analytique</div>
                    <div class="module-desc">Vue synthétique avec KPIs, graphiques d'évolution et répartition par statut. Filtres de période flexibles.</div>
                </div>
            </div>
            <div class="module-card">
                <div class="module-num">02</div>
                <div>
                    <div class="module-title">Gestion des clients</div>
                    <div class="module-desc">Recherche par MSISDN, nom, niveau KYC ou date d'inscription. Statut en temps réel.</div>
                </div>
            </div>
            <div class="module-card">
                <div class="module-num">03</div>
                <div>
                    <div class="module-title">Historique des transactions</div>
                    <div class="module-desc">Filtrage avancé par type, statut, montant ou numéro. Pagination optimisée pour grands volumes.</div>
                </div>
            </div>
            <div class="module-card">
                <div class="module-num">04</div>
                <div>
                    <div class="module-title">Détection de fraude</div>
                    <div class="module-desc">Algorithmes de détection de transactions circulaires, doublons et comportements suspects.</div>
                </div>
            </div>
        </div>
    </section>

    {{-- CTA FINAL --}}
    <section class="cta-section">
        <div class="cta-box">
            <div class="cta-box-tag">Accès sécurisé</div>
            <h2 class="cta-box-title">Prête à prendre<br>les commandes ?</h2>
            <p class="cta-box-sub">
                Connectez-vous pour accéder à votre espace de supervision
                et piloter l'activité D-Money en temps réel.
            </p>
            @auth
                <a href="{{ url('/dashboard') }}" class="cta-box-btn">
                    <svg viewBox="0 0 16 16" fill="currentColor"><rect x="1" y="1" width="6" height="6" rx="1.5"/><rect x="9" y="1" width="6" height="6" rx="1.5"/><rect x="1" y="9" width="6" height="6" rx="1.5"/><rect x="9" y="9" width="6" height="6" rx="1.5"/></svg>
                    Ouvrir le tableau de bord
                </a>
            @else
                <a href="{{ route('login') }}" class="cta-box-btn">
                    <svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"><path d="M10 3H13a1 1 0 011 1v9a1 1 0 01-1 1h-3M7 11l3-3-3-3M10 8H2"/></svg>
                    Se connecter maintenant
                </a>
            @endauth
        </div>
    </section>

    {{-- FOOTER --}}
    <footer>
        <div class="footer-copy">© {{ date('Y') }} D-Money — Djibouti Telecom. Tous droits réservés.</div>
        <div class="footer-right">
            <div class="footer-dot"></div>
            Système opérationnel
        </div>
    </footer>

</body>
</html>