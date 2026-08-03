<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>404 — Page introuvable</title>
    <style>
        * { box-sizing:border-box; margin:0; padding:0; }
        body {
            font-family: sans-serif;
            background: #F0F2F8;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        .card {
            background: #fff;
            border-radius: 16px;
            padding: 48px 56px;
            text-align: center;
            max-width: 480px;
            width: 90%;
            border: 1px solid #e5e7eb;
        }
        .icon-wrap {
            width: 72px; height: 72px;
            background: #E8ECF8;
            border-radius: 18px;
            display: flex; align-items: center; justify-content: center;
            margin: 0 auto 24px;
        }
        .code {
            font-size: 13px;
            font-weight: 700;
            color: #1B2F6E;
            background: #E8ECF8;
            padding: 4px 12px;
            border-radius: 20px;
            display: inline-block;
            margin-bottom: 16px;
            letter-spacing: 1px;
        }
        h1 { font-size: 22px; font-weight: 700; color: #111827; margin-bottom: 10px; }
        p { font-size: 14px; color: #6b7280; line-height: 1.6; margin-bottom: 28px; }
        .btn-home {
            display: inline-flex; align-items: center; gap: 8px;
            background: #1B2F6E; color: #fff;
            font-size: 13px; font-weight: 600;
            padding: 10px 24px; border-radius: 8px; text-decoration: none;
        }
        .btn-back {
            display: inline-flex; align-items: center; gap: 8px;
            background: #f3f4f6; color: #374151;
            font-size: 13px; font-weight: 500;
            padding: 10px 20px; border-radius: 8px;
            text-decoration: none; margin-left: 10px;
        }
        .logo { display:flex; align-items:center; justify-content:center; gap:8px; margin-bottom:32px; }
        .logo-icon { width:36px; height:36px; background:#1B2F6E; border-radius:9px; display:flex; align-items:center; justify-content:center; }
        .logo-name { font-size:16px; font-weight:700; color:#1B2F6E; }
    </style>
</head>
<body>
    <div class="card">
        <div class="logo">
            <div class="logo-icon">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none">
                    <rect x="3" y="7" width="18" height="12" rx="2" fill="#FFC72C"/>
                    <path d="M7 12h10M7 15h6" stroke="#1B2F6E" stroke-width="1.5" stroke-linecap="round"/>
                </svg>
            </div>
            <span class="logo-name">D-Money</span>
        </div>

        <div class="icon-wrap">
            <svg width="34" height="34" viewBox="0 0 24 24" fill="none">
                <circle cx="11" cy="11" r="8" stroke="#1B2F6E" stroke-width="1.5"/>
                <path d="M21 21l-4.35-4.35" stroke="#1B2F6E" stroke-width="1.5" stroke-linecap="round"/>
                <path d="M8 11h6M11 8v6" stroke="#E24B4A" stroke-width="1.5" stroke-linecap="round"/>
            </svg>
        </div>

        <div class="code">ERREUR 404</div>
        <h1>Page introuvable</h1>
        <p>La page que vous recherchez n'existe pas ou a été déplacée.</p>

        <div>
            <a href="{{ url('/dashboard') }}" class="btn-home">
                <svg width="14" height="14" viewBox="0 0 16 16" fill="white">
                    <rect x="1" y="1" width="6" height="6" rx="1.5"/>
                    <rect x="9" y="1" width="6" height="6" rx="1.5"/>
                    <rect x="1" y="9" width="6" height="6" rx="1.5"/>
                    <rect x="9" y="9" width="6" height="6" rx="1.5"/>
                </svg>
                Tableau de bord
            </a>
            <a href="javascript:history.back()" class="btn-back">Retour</a>
        </div>

        <p style="margin-top:24px; font-size:11px; color:#d1d5db;">
            D-Money — Digital Mobile Money &nbsp;·&nbsp; Djibouti Telecom
        </p>
    </div>
</body>
</html>