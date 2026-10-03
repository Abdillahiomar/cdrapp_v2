<!doctype html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>QR Code {{ $qrCode->merchant_name ?? $qrCode->short_code ?? '' }}</title>
    <style>
        * { box-sizing: border-box; }
        body { margin: 0; font-family: system-ui, -apple-system, "Segoe UI", sans-serif; background: #f3f4f6; color: #111827; }
        .toolbar { display: flex; justify-content: center; gap: 8px; padding: 16px; }
        .toolbar button { background: #1B2F6E; color: #fff; border: none; border-radius: 8px; padding: 10px 18px; font-size: 14px; font-weight: 600; cursor: pointer; }
        .toolbar button.secondary { background: #fff; color: #374151; border: 1px solid #d1d5db; }
        .sheet { width: 100%; max-width: 420px; margin: 0 auto 24px; background: #fff; border-radius: 12px; padding: 32px 28px; text-align: center; box-shadow: 0 4px 16px rgba(0,0,0,.08); }
        .sheet svg { width: 100%; height: auto; display: block; }
        .name { font-size: 22px; font-weight: 700; margin: 16px 0 4px; }
        .code { font-size: 16px; color: #6b7280; margin: 0; }
        @media print {
            body { background: #fff; }
            .toolbar { display: none; }
            .sheet { box-shadow: none; margin: 0 auto; }
        }
    </style>
</head>
<body>
    <div class="toolbar">
        <button onclick="window.print()">Imprimer</button>
        <button class="secondary" onclick="window.close()">Fermer</button>
    </div>

    <div class="sheet">
        {!! $svg !!}
        @if($qrCode->merchant_name)
            <p class="name">{{ $qrCode->merchant_name }}</p>
        @endif
        @if($qrCode->short_code)
            <p class="code">Code marchand : {{ $qrCode->short_code }}</p>
        @endif
    </div>
</body>
</html>
