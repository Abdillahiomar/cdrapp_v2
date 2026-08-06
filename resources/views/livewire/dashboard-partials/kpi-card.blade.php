{{--
    Carte KPI réutilisable.
    Variables attendues :
    - $label      : titre de la carte
    - $valeur     : valeur déjà formatée (string)
    - $variation  : float|null  (% vs période précédente ; null = pas de base)
    - $accent     : couleur de la barre supérieure
    - $spark      : array de nombres (sparkline) — peut être vide
    - $sparkId    : id HTML unique pour le graphe
    - $sparkColor : couleur de la sparkline
    - $hint       : petite précision sous la valeur
--}}
@php
    $variation = $variation ?? null;
    if ($variation === null) {
        $vCouleur = '#9ca3af'; $vFleche = '—'; $vTexte = 'n/d';
    } elseif ($variation > 0) {
        $vCouleur = '#005C2B'; $vFleche = '▲'; $vTexte = '+' . number_format($variation, 1, ',', ' ') . ' %';
    } elseif ($variation < 0) {
        $vCouleur = '#E24B4A'; $vFleche = '▼'; $vTexte = number_format($variation, 1, ',', ' ') . ' %';
    } else {
        $vCouleur = '#6b7280'; $vFleche = '='; $vTexte = '0 %';
    }
@endphp

<div style="background:#fff; border:1px solid #e5e7eb; border-radius:12px; padding:16px 18px; border-top:3px solid {{ $accent }}; box-shadow:0 1px 2px rgba(0,0,0,0.03);">

    <div style="display:flex; align-items:center; justify-content:space-between;">
        <p style="font-size:11px; color:#6b7280; font-weight:600; margin:0; text-transform:uppercase; letter-spacing:0.03em;">
            {{ $label }}
        </p>
        <span style="font-size:11px; font-weight:700; color:{{ $vCouleur }};">
            {{ $vFleche }} {{ $vTexte }}
        </span>
    </div>

    <p style="font-size:24px; font-weight:700; color:#111827; margin:10px 0 2px; line-height:1.1;">
        {{ $valeur }}
    </p>
    <p style="font-size:10px; color:#9ca3af; margin:0;">{{ $hint }}</p>

    @if(!empty($spark) && count($spark) > 1)
        <div wire:ignore style="margin-top:10px; height:38px;">
            <div id="{{ $sparkId }}"></div>
        </div>
        <script>
            (function () {
                const el = document.querySelector('#{{ $sparkId }}');
                if (!el) return;
                if (el.__chart) { el.__chart.destroy(); }
                const opts = {
                    chart: { type: 'area', height: 38, sparkline: { enabled: true }, animations: { enabled: false } },
                    stroke: { width: 2, curve: 'smooth' },
                    fill: { type: 'gradient', gradient: { opacityFrom: 0.35, opacityTo: 0 } },
                    series: [{ data: @json($spark) }],
                    colors: ['{{ $sparkColor }}'],
                    tooltip: { enabled: false },
                };
                el.__chart = new ApexCharts(el, opts);
                el.__chart.render();
            })();
        </script>
    @endif
</div>