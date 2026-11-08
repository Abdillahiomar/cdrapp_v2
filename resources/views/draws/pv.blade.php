<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <title>PV — {{ $draw->name }}</title>
    <style>
        @page { margin: 28px 32px; }
        body { font-family: DejaVu Sans, sans-serif; font-size: 10px; color: #111827; }
        h1 { font-size: 16px; color: #1B2F6E; margin: 0 0 2px; }
        h2 { font-size: 12px; color: #1B2F6E; margin: 16px 0 6px; border-bottom: 1px solid #d1d5db; padding-bottom: 3px; }
        table { width: 100%; border-collapse: collapse; }
        th, td { padding: 4px 6px; text-align: left; vertical-align: top; }
        .grid td { border-bottom: 1px solid #e5e7eb; }
        .grid th { background: #F3F4F6; font-weight: bold; border-bottom: 1px solid #d1d5db; }
        .info td:first-child { width: 34%; color: #6b7280; }
        .mono { font-family: DejaVu Sans Mono, monospace; font-size: 9px; }
        td.mono { white-space: nowrap; }
        .muted { color: #6b7280; }
        .ok { color: #005C2B; font-weight: bold; }
        .ko { color: #B42318; font-weight: bold; }
        .strike { color: #9ca3af; }
        .right { text-align: right; }
    </style>
</head>
<body>
@php
    $fmt   = fn ($n) => number_format((float) $n, 0, ',', ' ');
    $phone = fn ($m) => \App\Support\DrawEngine::display($m);
@endphp

    <h1>Procès-verbal de tirage au sort</h1>
    <p class="muted">D-Money · Tirage n° {{ $draw->id }} · document généré le {{ now()->format('d/m/Y à H:i') }}</p>

    <h2>Campagne</h2>
    <table class="info">
        <tr><td>Nom</td><td><strong>{{ $draw->name }}</strong></td></tr>
        @if($draw->description)<tr><td>Description</td><td>{{ $draw->description }}</td></tr>@endif
        <tr><td>Période des transactions</td><td>du {{ $draw->period_start->format('d/m/Y') }} au {{ $draw->period_end->format('d/m/Y') }} inclus</td></tr>
        <tr><td>Types de transactions</td><td>{{ $typeNames->implode(', ') }}</td></tr>
        <tr><td>Conditions</td><td>transactions terminées payées par un client ; au moins {{ $draw->min_txn_count }} transaction(s){{ $draw->min_txn_amount > 0 ? ', dont au moins une de ' . $fmt($draw->min_txn_amount) . ' FDJ ou plus' : '' }}</td></tr>
        <tr><td>Chances de gagner</td><td>{{ \App\Models\Draw::CHANCE_MODES[$draw->chance_mode] }}</td></tr>
        <tr><td>Lots par client</td><td>{{ $draw->one_prize_per_client ? 'Un seul lot par client' : 'Plusieurs lots possibles par client' }}</td></tr>
        <tr><td>Exclusions</td><td>
            {{ collect([
                $draw->exclude_employees ? 'employés D-Money' : null,
                $draw->exclude_rds_lite ? 'clients RDS_LITE' : null,
                $draw->exclude_winners_days ? "gagnants des {$draw->exclude_winners_days} derniers jours" : null,
                $draw->blacklistMsisdns() ? count($draw->blacklistMsisdns()) . ' numéro(s) en liste noire' : null,
            ])->filter()->implode(' ; ') ?: 'aucune' }}
        </td></tr>
        <tr><td>Créé par</td><td>{{ $draw->creator?->name ?? '—' }}, le {{ $draw->created_at->format('d/m/Y H:i') }}</td></tr>
    </table>

    <h2>Liste des participants</h2>
    <table class="grid">
        @foreach($draw->funnel ?? [] as $row)
            <tr><td>{{ $row['label'] }}</td><td class="right"><strong>{{ $row['value'] < 0 ? '−' . $fmt(-$row['value']) : $fmt($row['value']) }}</strong></td></tr>
        @endforeach
    </table>
    <p class="muted" style="margin-top:6px;">
        Un client ayant effectué plusieurs transactions n'apparaît qu'une fois. Liste figée le {{ $draw->computed_at?->format('d/m/Y H:i:s') }}
        par {{ $draw->computer?->name ?? '—' }} ; {{ $fmt($draw->ticket_count) }} chance(s) au total.
    </p>
    <p class="mono">Empreinte SHA-256 de la liste : {{ $draw->eligible_hash }}</p>

    <h2>Tirage</h2>
    <table class="info">
        <tr><td>Mode</td><td>lot par lot, un gagnant par tirage ; suppléants tirés après le dernier gagnant de chaque lot</td></tr>
        @if($draw->drawn_at)
            <tr><td>Tous les lots tirés</td><td>le {{ $draw->drawn_at->format('d/m/Y à H:i:s') }} ({{ $draw->drawer?->name ?? '—' }})</td></tr>
        @endif
        <tr><td>Contrôle d'intégrité</td><td>
            liste <span class="{{ $verify['list_ok'] ? 'ok' : 'ko' }}">{{ $verify['list_ok'] ? 'intacte' : 'modifiée' }}</span>,
            tirage rejoué <span class="{{ $verify['result_ok'] ? 'ok' : 'ko' }}">{{ $verify['result_ok'] ? 'identique' : 'différent' }}</span>
        </td></tr>
        @if($draw->closed_at)
            <tr><td>Clôturé par</td><td>{{ $draw->closer?->name ?? '—' }}, le {{ $draw->closed_at->format('d/m/Y H:i') }}</td></tr>
        @endif
    </table>
    <p class="muted" style="margin-top:6px;">
        Chaque tirage est rejouable : à partir de la liste figée (triée par numéro), des clients déjà sortis à cet instant et de la
        graine propre à ce tirage (indiquée ci-dessous), le générateur Xoshiro256** redonne exactement le même client.
    </p>

    <h2>Résultats</h2>
    @foreach($prizes as $i => $prize)
        <table style="margin:12px 0 4px;">
            <tr>
                @if($images[$prize->id] ?? null)
                    <td style="width:70px; padding:0;"><img src="{{ $images[$prize->id] }}" style="width:62px; height:46px;"></td>
                @endif
                <td style="padding:0 0 0 6px; vertical-align:middle;">
                    <strong>{{ $i + 1 }}. {{ $prize->name }}</strong>
                    @if($prize->category_name)
                        <span style="color:#fff; background:{{ $prize->category_color ?? '#6b7280' }}; padding:1px 6px; border-radius:4px; font-size:8px; font-weight:bold;">{{ $prize->category_name }}</span>
                    @endif
                    <br><span class="muted">{{ $prize->quantity }} gagnant(s), {{ $prize->substitutes }} suppléant(s)</span>
                </td>
            </tr>
        </table>
        <table class="grid">
            <tr><th>Type</th><th>Numéro</th><th>Nom</th><th class="right">Txn</th><th>Retenu</th><th>Tiré le</th><th>Observation</th></tr>
            @foreach($prize->winners as $w)
                <tr class="{{ $w->status === 'invalidated' ? 'strike' : '' }}">
                    <td>{{ $w->promoted_at ? 'Suppléant promu' : \App\Models\DrawWinner::KINDS[$w->kind] }}{{ $w->kind !== 'extra' ? ' #' . $w->rank : '' }}</td>
                    <td class="mono">{{ $phone($w->msisdn) }}</td>
                    <td>{{ $w->name ?? '—' }}</td>
                    <td class="right">{{ $w->txn_count }}</td>
                    <td>{!! $w->isEffective() ? '<span class="ok">Oui</span>' : 'Non' !!}</td>
                    <td style="white-space:nowrap;">{{ $w->created_at?->format('d/m H:i:s') }}<br><span class="mono" style="font-size:6px; color:#9ca3af;">{!! implode('<br>', str_split((string) $w->seed, 32)) !!}</span></td>
                    <td>
                        @if($w->status === 'invalidated')
                            Écarté : {{ $w->invalidated_reason }} ({{ $w->invalidator?->name ?? '—' }}, {{ $w->invalidated_at?->format('d/m/Y H:i') }})
                        @elseif($w->replaces)
                            Remplace {{ $phone($w->replaces->msisdn) }}
                        @endif
                    </td>
                </tr>
            @endforeach
        </table>
    @endforeach

    <h2>Signatures</h2>
    <table style="margin-top:10px;">
        <tr>
            <td style="width:33%; height:70px; border:1px solid #d1d5db;"><span class="muted">Marketing</span></td>
            <td style="width:33%; border:1px solid #d1d5db;"><span class="muted">Contrôle / Conformité</span></td>
            <td style="width:33%; border:1px solid #d1d5db;"><span class="muted">Témoin</span></td>
        </tr>
    </table>
</body>
</html>
