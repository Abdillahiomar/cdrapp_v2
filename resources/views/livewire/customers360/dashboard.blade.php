<?php

use App\Support\Customers;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Livewire\Volt\Component;

new class extends Component {

    /** Données du tableau de bord : préparées chaque nuit par customers:aggregate (cache 24 h) */
    public array $data = [];

    public bool $showSettings = false;
    public array $form = [];

    public function mount(): void
    {
        $this->data = Customers::cachedDashboard();
    }

    public function refreshData(): void
    {
        $this->data = Customers::cachedDashboard(refresh: true);
        $this->dispatch('customers-charts');
    }

    public function openSettings(): void
    {
        abort_unless(auth()->user()->can('customer360.manage'), 403);
        $this->form = Customers::settings();
        $this->showSettings = true;
        $this->resetErrorBag();
    }

    public function saveSettings(): void
    {
        abort_unless(auth()->user()->can('customer360.manage'), 403);

        $this->validate([
            'form.active_days'  => 'required|integer|min:1|max:365',
            'form.dormant_days' => 'required|integer|gt:form.active_days|max:730',
            'form.pending_days' => 'required|integer|min:0|max:365',
            'form.new_days'     => 'required|integer|min:1|max:365',
            'form.minor_age'    => 'required|integer|min:10|max:25',
        ], ['form.dormant_days.gt' => 'Le seuil « dormant » doit être supérieur au seuil « actif ».']);

        foreach (Customers::DEFAULTS as $key => $_) {
            Customers::putSetting($key, (int) $this->form[$key]);
        }

        $this->showSettings = false;
        $this->refreshData();
        session()->flash('customers-message', 'Seuils enregistrés : ils s\'appliquent à toutes les pages Customers.');
    }
};
?>
<div style="padding:24px;">

@php
    $card = 'background:#fff; border:1px solid #e5e7eb; border-radius:10px; padding:16px;';
    $int  = fn ($n) => number_format((int) $n, 0, ',', ' ');
    $pct  = fn ($n, $t) => $t > 0 ? number_format($n / $t * 100, 1, ',', ' ') . ' %' : '—';
    $t    = $data['totals'];
    $settingLabels = [
        'active_days'  => 'Actif si dernière transaction il y a au plus (jours)',
        'dormant_days' => 'Dormant si au plus (jours) — au-delà : inactif',
        'pending_days' => '« Pending Active » à traiter après (jours)',
        'new_days'     => 'Client récent : inscrit depuis moins de (jours)',
        'minor_age'    => 'Âge minimum légal (ans)',
    ];
@endphp

    <x-customers-tabs active="customer360.dashboard" />

    @if(session('customers-message'))
        <div style="background:#E5F5ED; color:#005C2B; border:1px solid #A7E3C1; border-radius:8px; padding:10px 14px; font-size:12px; margin-bottom:14px;">{{ session('customers-message') }}</div>
    @endif

    <div style="display:flex; justify-content:space-between; align-items:center; gap:10px; flex-wrap:wrap; margin-bottom:12px;">
        <p style="font-size:11px; color:#9ca3af; margin:0;">Calculé le {{ $data['computed_at'] }} (préparé chaque nuit)
            <button wire:click="refreshData" style="background:none; border:none; color:#1B2F6E; font-size:11px; font-weight:600; cursor:pointer;">↻ Actualiser</button></p>
        @can('customer360.manage')
            <button wire:click="openSettings" style="background:#fff; color:#1B2F6E; font-size:12px; font-weight:600; padding:7px 14px; border-radius:7px; border:1px solid #1B2F6E; cursor:pointer;">⚙ Seuils</button>
        @endcan
    </div>

    {{-- Activité --}}
    <div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(160px, 1fr)); gap:12px; margin-bottom:16px;">
        <div style="{{ $card }} border-top:3px solid #1B2F6E;">
            <p style="font-size:11px; color:#6b7280; margin:0 0 6px;">Clients</p>
            <p style="font-size:22px; font-weight:700; color:#111827; margin:0;">{{ $int($t['total']) }}</p>
        </div>
        @foreach(\App\Support\Customers::ACTIVITY as $key => [$label, $bg, $color])
            <a href="{{ route('customer360.directory', ['activity' => $key]) }}" wire:navigate style="{{ $card }} border-top:3px solid {{ $color }}; text-decoration:none;">
                <p style="font-size:11px; color:#6b7280; margin:0 0 6px;">{{ $label }}s</p>
                <p style="font-size:22px; font-weight:700; color:#111827; margin:0;">{{ $int($t[$key]) }}</p>
                <p style="font-size:11px; color:{{ $color }}; margin:4px 0 0;">{{ $pct($t[$key], $t['total']) }}</p>
            </a>
        @endforeach
    </div>

    {{-- Parcours d'activation --}}
    <div style="{{ $card }} margin-bottom:16px;">
        <p style="font-size:13px; font-weight:700; color:#111827; margin:0 0 12px;">Parcours d'activation</p>
        <div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(170px, 1fr)); gap:10px;">
            @foreach([
                ['Inscrits', $t['total'], null],
                ['Activés', $t['activated'], $t['total']],
                ['Au moins une transaction', $t['transacted'], $t['activated']],
                ['Actifs aujourd\'hui', $t['active'], $t['transacted']],
            ] as $i => [$label, $n, $prev])
                <div style="background:#F7F8FC; border-radius:8px; padding:12px; position:relative;">
                    <p style="font-size:11px; color:#6b7280; margin:0 0 4px;">{{ $i + 1 }}. {{ $label }}</p>
                    <p style="font-size:18px; font-weight:700; color:#111827; margin:0;">{{ $int($n) }}</p>
                    @if($prev) <p style="font-size:11px; color:#6b7280; margin:3px 0 0;">{{ $pct($n, $prev) }} de l'étape précédente</p> @endif
                </div>
            @endforeach
        </div>
    </div>

    {{-- Graphiques --}}
    <div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(420px, 1fr)); gap:16px; margin-bottom:16px;">
        <div style="{{ $card }}">
            <p style="font-size:13px; font-weight:700; color:#111827; margin:0 0 4px;">Clients actifs par mois</p>
            <p style="font-size:11px; color:#9ca3af; margin:0 0 10px;">Clients ayant fait au moins une transaction dans le mois (le mois en cours est partiel)</p>
            <div wire:ignore style="position:relative; height:260px;"><canvas id="cust-chart-active" role="img" aria-label="Clients actifs par mois"></canvas></div>
        </div>
        <div style="{{ $card }}">
            <p style="font-size:13px; font-weight:700; color:#111827; margin:0 0 4px;">Inscriptions par mois</p>
            <p style="font-size:11px; color:#9ca3af; margin:0 0 10px;">24 derniers mois</p>
            <div wire:ignore style="position:relative; height:260px;"><canvas id="cust-chart-reg" role="img" aria-label="Inscriptions par mois"></canvas></div>
        </div>
    </div>

    {{-- Rétention --}}
    <div style="background:#fff; border:1px solid #e5e7eb; border-radius:10px; overflow:hidden; margin-bottom:16px;">
        <div style="padding:12px 16px; border-bottom:1px solid #e5e7eb;">
            <p style="font-size:13px; font-weight:700; color:#111827; margin:0;">Rétention par cohorte d'inscription</p>
            <p style="font-size:11px; color:#9ca3af; margin:2px 0 0;">Part des clients inscrits dans le mois qui font au moins une transaction le mois même, puis 1, 3 et 6 mois plus tard</p>
        </div>
        <div style="overflow-x:auto;">
            <table style="width:100%; border-collapse:collapse; font-size:12px;">
                <thead><tr style="background:#F7F8FC;">
                    @foreach(['Cohorte', 'Inscrits', 'Mois d\'inscription', 'M+1', 'M+3', 'M+6'] as $i => $th)
                        <th style="padding:9px 14px; text-align:{{ $i ? 'right' : 'left' }}; color:#6b7280; font-weight:500; border-bottom:1px solid #e5e7eb;">{{ $th }}</th>
                    @endforeach
                </tr></thead>
                <tbody>
                    @foreach($data['cohorts'] as $c)
                        @php $future = fn ($months) => \Carbon\Carbon::parse($c['cohort'] . '-01')->addMonths($months)->gt(\Carbon\Carbon::parse($data['reference'])->startOfMonth()); @endphp
                        <tr style="border-bottom:1px solid #f3f4f6;">
                            <td style="padding:8px 14px; color:#111827; font-weight:600;">{{ $c['label'] }}</td>
                            <td style="padding:8px 14px; text-align:right; color:#111827;">{{ $int($c['size']) }}</td>
                            @foreach(['m0' => 0, 'm1' => 1, 'm3' => 3, 'm6' => 6] as $k => $months)
                                @php $rate = $c['size'] > 0 ? $c[$k] / $c['size'] : 0; @endphp
                                <td style="padding:8px 14px; text-align:right;">
                                    @if($future($months)) <span style="color:#d1d5db;">—</span>
                                    @else <span style="display:inline-block; min-width:56px; padding:2px 6px; border-radius:4px; background:rgba(42,120,214,{{ round(0.08 + $rate * 0.6, 2) }}); color:{{ $rate > 0.45 ? '#fff' : '#111827' }};">{{ $pct($c[$k], $c['size']) }}</span> @endif
                                </td>
                            @endforeach
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>

    <div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(300px, 1fr)); gap:16px;">
        {{-- Par segment --}}
        <div style="background:#fff; border:1px solid #e5e7eb; border-radius:10px; overflow:hidden; grid-column:1 / -1;">
            <p style="font-size:13px; font-weight:700; color:#111827; margin:0; padding:12px 16px; border-bottom:1px solid #e5e7eb;">Activité par segment</p>
            <table style="width:100%; border-collapse:collapse; font-size:12px;">
                <thead><tr style="background:#F7F8FC;">
                    @foreach(['Segment', 'Clients', 'Actifs', 'Dormants', 'Inactifs', 'Jamais actifs', '% actifs'] as $i => $th)
                        <th style="padding:9px 14px; text-align:{{ $i ? 'right' : 'left' }}; color:#6b7280; font-weight:500; border-bottom:1px solid #e5e7eb;">{{ $th }}</th>
                    @endforeach
                </tr></thead>
                <tbody>
                    @foreach($data['segments'] as $s)
                        <tr style="border-bottom:1px solid #f3f4f6;">
                            <td style="padding:8px 14px;"><a href="{{ route('customer360.directory', ['segment' => $s['segment']]) }}" wire:navigate style="color:#1B2F6E; font-weight:600; text-decoration:none;">{{ $s['segment'] }}</a></td>
                            <td style="padding:8px 14px; text-align:right; font-weight:600;">{{ $int($s['total']) }}</td>
                            <td style="padding:8px 14px; text-align:right; color:#005C2B;">{{ $int($s['active']) }}</td>
                            <td style="padding:8px 14px; text-align:right; color:#92400E;">{{ $int($s['dormant']) }}</td>
                            <td style="padding:8px 14px; text-align:right; color:#7F1D1D;">{{ $int($s['inactive']) }}</td>
                            <td style="padding:8px 14px; text-align:right; color:#6b7280;">{{ $int($s['never']) }}</td>
                            <td style="padding:8px 14px; text-align:right; font-weight:600;">{{ $pct($s['active'], $s['total']) }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        @foreach(['Statut' => $data['statuses'], 'Tranche d\'âge' => $data['ages'], 'Pièce d\'identité' => $data['id_types']] as $title => $values)
            <div style="{{ $card }}">
                <p style="font-size:13px; font-weight:700; color:#111827; margin:0 0 10px;">{{ $title }}</p>
                @php $max = max($values ?: [1]); @endphp
                @foreach($values as $label => $n)
                    <div style="margin-bottom:7px;">
                        <div style="display:flex; justify-content:space-between; font-size:12px; color:#374151;"><span>{{ $label }}</span><span style="font-weight:600;">{{ $int($n) }} <span style="color:#9ca3af; font-weight:400;">· {{ $pct($n, $t['total']) }}</span></span></div>
                        <div style="height:5px; background:#f3f4f6; border-radius:3px; margin-top:3px;"><div style="height:100%; width:{{ round($n / $max * 100, 1) }}%; background:#2a78d6; border-radius:3px;"></div></div>
                    </div>
                @endforeach
            </div>
        @endforeach
    </div>

    {{-- Seuils --}}
    @if($showSettings)
        <div wire:click="$set('showSettings', false)" style="position:fixed; inset:0; background:rgba(0,0,0,0.45); z-index:998;"></div>
        <div style="position:fixed; top:50%; left:50%; transform:translate(-50%,-50%); width:min(480px,95vw); max-height:calc(100vh - 40px); overflow-y:auto; background:#fff; border-radius:14px; z-index:999; box-shadow:0 20px 60px rgba(0,0,0,0.2);">
            <div style="padding:16px 20px; border-bottom:1px solid #e5e7eb;">
                <p style="font-size:14px; font-weight:700; color:#111827; margin:0;">Seuils des clients</p>
                <p style="font-size:11px; color:#9ca3af; margin:3px 0 0;">Comptés par rapport à la dernière transaction connue ; propres aux clients (indépendants du réseau).</p>
            </div>
            <div style="padding:20px; display:flex; flex-direction:column; gap:12px;">
                @foreach($settingLabels as $key => $label)
                    <div>
                        <label style="font-size:11px; color:#6b7280; display:block; margin-bottom:4px; font-weight:600;">{{ $label }}</label>
                        <input type="number" wire:model="form.{{ $key }}" style="border:1px solid #d1d5db; border-radius:7px; padding:9px 12px; font-size:13px; width:100%; box-sizing:border-box;">
                        @error('form.' . $key) <p style="font-size:10px; color:#E24B4A; margin:4px 0 0;">{{ $message }}</p> @enderror
                    </div>
                @endforeach
            </div>
            <div style="padding:12px 20px; border-top:1px solid #e5e7eb; display:flex; justify-content:flex-end; gap:10px;">
                <button wire:click="$set('showSettings', false)" style="background:#f3f4f6; color:#374151; font-size:13px; padding:8px 20px; border-radius:8px; border:1px solid #e5e7eb; cursor:pointer;">Annuler</button>
                <button wire:click="saveSettings" style="background:#1B2F6E; color:#fff; font-size:13px; font-weight:600; padding:8px 20px; border-radius:8px; border:none; cursor:pointer;">Enregistrer</button>
            </div>
        </div>
    @endif

@script
<script>
    const CUST_COLOR = '#2a78d6';
    const custFmt = new Intl.NumberFormat('fr-FR');
    const custCompact = new Intl.NumberFormat('fr-FR', { notation: 'compact', maximumFractionDigits: 1 });

    function custDraw(attempt = 0) {
        if (typeof Chart === 'undefined') {
            if (attempt < 20) setTimeout(() => custDraw(attempt + 1), 150);
            return;
        }
        const charts = JSON.parse(JSON.stringify($wire.data.charts));
        const options = {
            responsive: true, maintainAspectRatio: false, interaction: { mode: 'index', intersect: false },
            plugins: { legend: { display: false }, tooltip: { callbacks: { label: (c) => ' ' + custFmt.format(c.parsed.y) } } },
            scales: {
                x: { grid: { display: false }, ticks: { color: '#6b7280', font: { size: 10 }, maxRotation: 0, autoSkip: true, autoSkipPadding: 10 } },
                y: { beginAtZero: true, grid: { color: '#eef0f4' }, border: { display: false }, ticks: { color: '#6b7280', font: { size: 10 }, callback: (v) => custCompact.format(v) } },
            },
        };
        [['cust-chart-active', charts.active], ['cust-chart-reg', charts.registrations]].forEach(([id, d]) => {
            const el = document.getElementById(id);
            if (!el) return;
            if (el.__chart) { el.__chart.data.labels = d.labels; el.__chart.data.datasets[0].data = d.values; el.__chart.update(); return; }
            el.__chart = new Chart(el, { type: 'bar', data: { labels: d.labels, datasets: [{ data: d.values, backgroundColor: CUST_COLOR, borderRadius: 4, borderSkipped: 'start', maxBarThickness: 26 }] }, options });
        });
    }

    custDraw();
    $wire.on('customers-charts', () => setTimeout(() => custDraw(), 50));
</script>
@endscript

</div>
