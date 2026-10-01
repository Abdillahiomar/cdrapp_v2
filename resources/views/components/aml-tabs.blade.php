@props(['active'])

@php
    $tabs = [
        'aml.alerts'    => 'Alertes',
        'aml.rules'     => 'Règles',
        'aml.watchlist' => 'Listes de surveillance',
    ];
@endphp

<div style="display:flex; gap:4px; border-bottom:1px solid #e5e7eb; margin-bottom:20px; flex-wrap:wrap;">
    @foreach($tabs as $route => $label)
        <a href="{{ route($route) }}" wire:navigate
           style="padding:9px 16px; font-size:13px; text-decoration:none; margin-bottom:-1px; border-bottom:2px solid {{ $active === $route ? '#1B2F6E' : 'transparent' }}; color:{{ $active === $route ? '#1B2F6E' : '#6b7280' }}; font-weight:{{ $active === $route ? '600' : '500' }};">
            {{ $label }}
            @if($route === 'aml.alerts' && ($n = \App\Models\AmlAlert::newCount()) > 0)
                <span style="background:#E24B4A; color:#fff; font-size:10px; font-weight:700; padding:1px 7px; border-radius:10px; margin-left:4px;">{{ $n }}</span>
            @endif
        </a>
    @endforeach
    @can('fraudes.view')
        <a href="{{ route('aml.index') }}" wire:navigate
           style="padding:9px 16px; font-size:13px; text-decoration:none; margin-bottom:-1px; border-bottom:2px solid transparent; color:#6b7280; font-weight:500;">
            Analyse des scénarios
        </a>
    @endcan
</div>
