<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>403 — Accès refusé</title>
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
            background: #FDECEA;
            border-radius: 18px;
            display: flex; align-items: center; justify-content: center;
            margin: 0 auto 24px;
        }
        .code {
            font-size: 13px;
            font-weight: 700;
            color: #E24B4A;
            background: #FDECEA;
            padding: 4px 12px;
            border-radius: 20px;
            display: inline-block;
            margin-bottom: 16px;
            letter-spacing: 1px;
        }
        h1 {
            font-size: 22px;
            font-weight: 700;
            color: #111827;
            margin-bottom: 10px;
        }
        p {
            font-size: 14px;
            color: #6b7280;
            line-height: 1.6;
            margin-bottom: 28px;
        }
        .btn-home {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            background: #1B2F6E;
            color: #fff;
            font-size: 13px;
            font-weight: 600;
            padding: 10px 24px;
            border-radius: 8px;
            text-decoration: none;
            transition: background 0.15s;
        }
        .btn-home:hover { background: #111D45; }
        .btn-back {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            background: #f3f4f6;
            color: #374151;
            font-size: 13px;
            font-weight: 500;
            padding: 10px 20px;
            border-radius: 8px;
            text-decoration: none;
            margin-left: 10px;
            transition: background 0.15s;
        }
        .btn-back:hover { background: #e5e7eb; }
        .logo {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            margin-bottom: 32px;
        }
        .logo-icon {
            width: 36px; height: 36px;
            background: #1B2F6E;
            border-radius: 9px;
            display: flex; align-items: center; justify-content: center;
        }
        .logo-name { font-size: 16px; font-weight: 700; color: #1B2F6E; }
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
                <path d="M12 2L3 7v5c0 5.25 3.75 10.15 9 11.35C17.25 22.15 21 17.25 21 12V7l-9-5z"
                      stroke="#E24B4A" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/>
                <path d="M9 12l2 2 4-4" stroke="#E24B4A" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/>
            </svg>
        </div>

        <div class="code">ERREUR 403</div>

        <h1>Accès refusé</h1>

        <p>
            Vous n'avez pas les permissions nécessaires pour accéder à cette page.
            Contactez votre administrateur si vous pensez qu'il s'agit d'une erreur.
        </p>

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
            <a href="javascript:history.back()" class="btn-back">
                Retour
            </a>
        </div>

        <p style="margin-top:24px; font-size:11px; color:#d1d5db;">
            D-Money — Digital Mobile Money &nbsp;·&nbsp; Djibouti Telecom
        </p>
    </div>
</body>
</html>