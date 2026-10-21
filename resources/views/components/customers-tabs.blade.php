@props(['active'])

@php
    $tabs = [
        'customer360.dashboard' => 'Tableau de bord',
        'customer360.directory' => 'Annuaire',
        'customer360.controls'  => 'Contrôles',
        'customer360.journal'   => 'Journal des statuts',
    ];
    $ref = \App\Support\Customers::referenceDate();
    $s   = \App\Support\Customers::settings();
@endphp

<div style="margin-bottom:16px;">
    <h2 style="font-size:16px; font-weight:700; color:#111827; margin:0 0 4px;">Customers</h2>
    <p style="font-size:12px; color:#6b7280; margin:0;">
        Base clients D-Money (référentiel kyc.customer_master). Activité au {{ $ref->format('d/m/Y') }} :
        actif ≤ {{ $s['active_days'] }} j, dormant ≤ {{ $s['dormant_days'] }} j, inactif au-delà.
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
