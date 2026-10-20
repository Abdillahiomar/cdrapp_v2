@props(['active'])

@php
    $tabs = [
        'network.dashboard' => 'Tableau de bord',
        'network.directory' => 'Annuaire',
        'network.networks'  => 'Réseaux',
        'network.controls'  => 'Contrôles',
    ];
    $ref = \App\Support\Network::referenceDate();
    $t   = \App\Support\Network::thresholds();
@endphp

<div style="margin-bottom:16px;">
    <h2 style="font-size:16px; font-weight:700; color:#111827; margin:0 0 4px;">Réseau de distribution</h2>
    <p style="font-size:12px; color:#6b7280; margin:0;">
        Organisations D-Money (agents, distributeurs, marchands, caisses). Activité au {{ $ref->format('d/m/Y') }} :
        active ≤ {{ $t['active_days'] }} j, dormante ≤ {{ $t['dormant_days'] }} j, inactive au-delà.
    </p>
</div>

<div style="display:flex; gap:4px; border-bottom:1px solid #e5e7eb; margin-bottom:20px; flex-wrap:wrap;">
    @foreach($tabs as $route => $label)
        <a href="{{ route($route) }}" wire:navigate
           style="padding:9px 16px; font-size:13px; text-decoration:none; margin-bottom:-1px; border-bottom:2px solid {{ $active === $route ? '#1B2F6E' : 'transparent' }}; color:{{ $active === $route ? '#1B2F6E' : '#6b7280' }}; font-weight:{{ $active === $route ? '600' : '500' }};">
            {{ $label }}
        </a>
    @endforeach
</div>
