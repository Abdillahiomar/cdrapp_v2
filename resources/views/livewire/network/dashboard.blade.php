<?php

use App\Support\Network;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Livewire\Volt\Component;

new class extends Component {

    /** Séries des graphiques, lues par le script Chart.js */
    public array $charts = [];

    // Réglage des seuils
    public bool $showSettings = false;
    public $activeDays  = 7;
    public $dormantDays = 30;
    public $newOrgDays  = 30;

    public function mount(): void
    {
        $ref = Network::referenceDate();

        // Organisations actives par jour (90 derniers jours chargés)
        $daily = DB::table('org_daily_activity')
            ->where('activity_date', '>', $ref->copy()->subDays(90)->toDateString())
            ->groupBy('activity_date')->orderBy('activity_date')
            ->selectRaw('activity_date, COUNT(*) AS n')
            ->pluck('n', 'activity_date');

        // Inscriptions par mois (24 derniers mois)
        $months = DB::table('kyc.org_master')
            ->where('registered_at', '>=', $ref->copy()->startOfMonth()->subMonths(23)->toDateString())
            ->groupByRaw("to_char(registered_at, 'YYYY-MM')")->orderByRaw("to_char(registered_at, 'YYYY-MM')")
            ->selectRaw("to_char(registered_at, 'YYYY-MM') AS m, COUNT(*) AS n")
            ->pluck('n', 'm');

        $this->charts = [
            'daily'  => ['labels' => $daily->keys()->map(fn ($d) => Carbon::parse($d)->format('d/m'))->values()->all(), 'values' => $daily->values()->map(fn ($v) => (int) $v)->all()],
            'months' => ['labels' => $months->keys()->map(fn ($m) => Carbon::parse($m . '-01')->locale('fr')->translatedFormat('M Y'))->values()->all(), 'values' => $months->values()->map(fn ($v) => (int) $v)->all()],
        ];
    }

    public function openSettings(): void
    {
        abort_unless(auth()->user()->can('network.manage'), 403);

        $t = Network::thresholds();
        $this->activeDays   = $t['active_days'];
        $this->dormantDays  = $t['dormant_days'];
        $this->newOrgDays   = $t['new_org_days'];
        $this->showSettings = true;
        $this->resetErrorBag();
    }

    public function saveSettings(): void
    {
        abort_unless(auth()->user()->can('network.manage'), 403);

        $this->validate([
            'activeDays'  => 'required|integer|min:1|max:365',
            'dormantDays' => 'required|integer|gt:activeDays|max:730',
            'newOrgDays'  => 'required|integer|min:1|max:365',
        ], [
            'dormantDays.gt' => 'Le seuil « dormante » doit être supérieur au seuil « active ».',
        ]);

        Network::putSetting('active_days', (int) $this->activeDays);
        Network::putSetting('dormant_days', (int) $this->dormantDays);
        Network::putSetting('new_org_days', (int) $this->newOrgDays);

        $this->showSettings = false;
        session()->flash('network-message', 'Seuils enregistrés : ils s\'appliquent immédiatement à toutes les pages du réseau.');
    }

    public function with(): array
    {
        $activity = Network::activitySql();

        $bySegment = DB::table('kyc.org_master as m')
            ->leftJoin('org_last_activity as la', 'la.org_id', '=', 'm.short_code')
            ->selectRaw("COALESCE(m.segment, '—') AS segment, COUNT(*) AS total,
                COUNT(*) FILTER (WHERE {$activity} = 'active') AS active,
                COUNT(*) FILTER (WHERE {$activity} = 'dormant') AS dormant,
                COUNT(*) FILTER (WHERE {$activity} = 'inactive') AS inactive,
                COUNT(*) FILTER (WHERE {$activity} = 'never') AS never")
            ->groupBy('m.segment')
            ->orderByDesc('total')
            ->get();

        $ref = Network::referenceDate();

        return [
            'bySegment'  => $bySegment,
            'totals'     => [
                'total'    => $bySegment->sum('total'),
                'active'   => $bySegment->sum('active'),
                'dormant'  => $bySegment->sum('dormant'),
                'inactive' => $bySegment->sum('inactive'),
                'never'    => $bySegment->sum('never'),
            ],
            'statuses'   => DB::table('kyc.org_master')->groupBy('identity_status')->selectRaw('identity_status, COUNT(*) AS n')->pluck('n', 'identity_status'),
            'newOrgs'    => DB::table('kyc.org_master')->where('registered_at', '>=', $ref->copy()->subDays(Network::thresholds()['new_org_days'])->toDateString())->count(),
            'networks'   => count(Network::options()['networks']),
            'thresholds' => Network::thresholds(),
            'loadedAt'   => DB::table('kyc.org_etl_files')->max('processed_at'),
        ];
    }
};
?>
<div style="padding:24px;">

@php
    $card = 'background:#fff; border:1px solid #e5e7eb; border-radius:10px; padding:16px;';
    $pct  = fn ($n, $t) => $t > 0 ? number_format($n / $t * 100, 1, ',', ' ') . ' %' : '—';
    $int  = fn ($n) => number_format($n, 0, ',', ' ');
@endphp

    <x-network-tabs active="network.dashboard" />

    @if(session('network-message'))
        <div style="background:#E5F5ED; color:#005C2B; border:1px solid #A7E3C1; border-radius:8px; padding:10px 14px; font-size:12px; margin-bottom:14px;">{{ session('network-message') }}</div>
    @endif

    <div style="display:flex; justify-content:space-between; align-items:center; gap:10px; flex-wrap:wrap; margin-bottom:12px;">
        <p style="font-size:11px; color:#9ca3af; margin:0;">
            Référentiel des organisations mis à jour le {{ $loadedAt ? \Carbon\Carbon::parse($loadedAt)->format('d/m/Y H:i') : '—' }} ·
            {{ $int($networks) }} réseaux (organisations de tête avec des caisses) · {{ $int($newOrgs) }} inscription(s) sur les {{ $thresholds['new_org_days'] }} derniers jours
        </p>
        @can('network.manage')
            <button wire:click="openSettings" style="background:#fff; color:#1B2F6E; font-size:12px; font-weight:600; padding:7px 14px; border-radius:7px; border:1px solid #1B2F6E; cursor:pointer;">⚙ Seuils d'activité</button>
        @endcan
    </div>

    {{-- KPI --}}
    <div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(160px, 1fr)); gap:12px; margin-bottom:16px;">
        <div style="{{ $card }} border-top:3px solid #1B2F6E;">
            <p style="font-size:11px; color:#6b7280; margin:0 0 6px;">Organisations</p>
            <p style="font-size:22px; font-weight:700; color:#111827; margin:0;">{{ $int($totals['total']) }}</p>
        </div>
        @foreach(\App\Support\Network::ACTIVITY as $key => [$label, $bg, $color])
            <a href="{{ route('network.directory', ['activity' => $key]) }}" wire:navigate style="{{ $card }} border-top:3px solid {{ $color }}; text-decoration:none;">
                <p style="font-size:11px; color:#6b7280; margin:0 0 6px;">{{ $label }}s</p>
                <p style="font-size:22px; font-weight:700; color:#111827; margin:0;">{{ $int($totals[$key]) }}</p>
                <p style="font-size:11px; color:{{ $color }}; margin:4px 0 0;">{{ $pct($totals[$key], $totals['total']) }}</p>
            </a>
        @endforeach
    </div>

    {{-- Statuts --}}
    <div style="{{ $card }} margin-bottom:16px; display:flex; gap:18px; flex-wrap:wrap; align-items:center;">
        <span style="font-size:12px; font-weight:600; color:#111827;">Statut des organisations :</span>
        @foreach($statuses as $status => $n)
            @php [$bg, $color] = \App\Support\Network::IDENTITY_STATUS[$status] ?? ['#F3F4F6', '#374151']; @endphp
            <a href="{{ route('network.directory', ['identity_status' => $status]) }}" wire:navigate style="text-decoration:none;">
                <span style="background:{{ $bg }}; color:{{ $color }}; font-size:11px; font-weight:600; padding:3px 10px; border-radius:12px;">{{ $status }} : {{ $int($n) }}</span>
            </a>
        @endforeach
    </div>

    {{-- Graphiques --}}
    <div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(420px, 1fr)); gap:16px; margin-bottom:16px;">
        <div style="{{ $card }}">
            <p style="font-size:13px; font-weight:700; color:#111827; margin:0 0 4px;">Organisations actives par jour</p>
            <p style="font-size:11px; color:#9ca3af; margin:0 0 10px;">Nombre d'organisations ayant fait au moins une transaction, 90 derniers jours</p>
            <div wire:ignore style="position:relative; height:260px;"><canvas id="net-chart-daily" role="img" aria-label="Organisations actives par jour"></canvas></div>
        </div>
        <div style="{{ $card }}">
            <p style="font-size:13px; font-weight:700; color:#111827; margin:0 0 4px;">Inscriptions par mois</p>
            <p style="font-size:11px; color:#9ca3af; margin:0 0 10px;">Nouvelles organisations, 24 derniers mois</p>
            <div wire:ignore style="position:relative; height:260px;"><canvas id="net-chart-months" role="img" aria-label="Inscriptions par mois"></canvas></div>
        </div>
    </div>

    {{-- Par segment --}}
    <div style="background:#fff; border:1px solid #e5e7eb; border-radius:10px; overflow:hidden;">
        <p style="font-size:13px; font-weight:700; color:#111827; margin:0; padding:12px 16px; border-bottom:1px solid #e5e7eb;">Activité par segment</p>
        <div style="overflow-x:auto;">
            <table style="width:100%; border-collapse:collapse; font-size:12px;">
                <thead>
                    <tr style="background:#F7F8FC;">
                        @foreach(['Segment', 'Organisations', 'Actives', 'Dormantes', 'Inactives', 'Jamais actives', '% actives'] as $i => $th)
                            <th style="padding:9px 14px; text-align:{{ $i ? 'right' : 'left' }}; color:#6b7280; font-weight:500; border-bottom:1px solid #e5e7eb;">{{ $th }}</th>
                        @endforeach
                    </tr>
                </thead>
                <tbody>
                    @foreach($bySegment as $s)
                        <tr style="border-bottom:1px solid #f3f4f6;">
                            <td style="padding:8px 14px;">
                                <a href="{{ route('network.directory', ['segment' => $s->segment]) }}" wire:navigate style="color:#1B2F6E; font-weight:600; text-decoration:none;">{{ $s->segment }}</a>
                            </td>
                            <td style="padding:8px 14px; text-align:right; color:#111827; font-weight:600;">{{ $int($s->total) }}</td>
                            <td style="padding:8px 14px; text-align:right; color:#005C2B;">{{ $int($s->active) }}</td>
                            <td style="padding:8px 14px; text-align:right; color:#92400E;">{{ $int($s->dormant) }}</td>
                            <td style="padding:8px 14px; text-align:right; color:#7F1D1D;">{{ $int($s->inactive) }}</td>
                            <td style="padding:8px 14px; text-align:right; color:#6b7280;">{{ $int($s->never) }}</td>
                            <td style="padding:8px 14px; text-align:right; color:#111827; font-weight:600;">{{ $pct($s->active, $s->total) }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>

    {{-- Réglage des seuils --}}
    @if($showSettings)
        <div wire:click="$set('showSettings', false)" style="position:fixed; inset:0; background:rgba(0,0,0,0.45); z-index:998;"></div>
        <div style="position:fixed; top:50%; left:50%; transform:translate(-50%,-50%); width:min(460px,95vw); max-height:calc(100vh - 40px); overflow-y:auto; background:#fff; border-radius:14px; z-index:999; box-shadow:0 20px 60px rgba(0,0,0,0.2);">
            <div style="padding:16px 20px; border-bottom:1px solid #e5e7eb;">
                <p style="font-size:14px; font-weight:700; color:#111827; margin:0;">Seuils d'activité</p>
                <p style="font-size:11px; color:#9ca3af; margin:3px 0 0;">Comptés en jours depuis la dernière transaction, par rapport au dernier jour chargé.</p>
            </div>
            <div style="padding:20px; display:flex; flex-direction:column; gap:14px;">
                @foreach([
                    ['activeDays', 'Active si dernière transaction il y a au plus (jours)'],
                    ['dormantDays', 'Dormante si au plus (jours) — au-delà : inactive'],
                    ['newOrgDays', 'Nouvelle organisation : inscrite depuis moins de (jours)'],
                ] as [$field, $label])
                    <div>
                        <label style="font-size:11px; color:#6b7280; display:block; margin-bottom:4px; font-weight:600;">{{ $label }}</label>
                        <input type="number" min="1" wire:model="{{ $field }}" style="border:1px solid #d1d5db; border-radius:7px; padding:9px 12px; font-size:13px; width:100%; box-sizing:border-box;">
                        @error($field) <p style="font-size:10px; color:#E24B4A; margin:4px 0 0;">{{ $message }}</p> @enderror
                    </div>
                @endforeach
            </div>
            <div style="padding:12px 20px; border-top:1px solid #e5e7eb; display:flex; justify-content:flex-end; gap:10px;">
                <button wire:click="$set('showSettings', false)" style="background:#f3f4f6; color:#374151; font-size:13px; font-weight:500; padding:8px 20px; border-radius:8px; border:1px solid #e5e7eb; cursor:pointer;">Annuler</button>
                <button wire:click="saveSettings" style="background:#1B2F6E; color:#fff; font-size:13px; font-weight:600; padding:8px 20px; border-radius:8px; border:none; cursor:pointer;">Enregistrer</button>
            </div>
        </div>
    @endif

{{-- Le script doit commencer par une déclaration (const) : voir manager_dashboard --}}
@script
<script>
    const NET_COLOR = '#2a78d6';
    const netFmt = new Intl.NumberFormat('fr-FR');

    function netDraw(attempt = 0) {
        if (typeof Chart === 'undefined') {
            if (attempt < 20) setTimeout(() => netDraw(attempt + 1), 150);
            return;
        }
        const data = JSON.parse(JSON.stringify($wire.charts));
        const base = {
            responsive: true,
            maintainAspectRatio: false,
            interaction: { mode: 'index', intersect: false },
            plugins: { legend: { display: false }, tooltip: { callbacks: { label: (c) => ' ' + netFmt.format(c.parsed.y) } } },
            scales: {
                x: { grid: { display: false }, ticks: { color: '#6b7280', font: { size: 10 }, maxRotation: 0, autoSkip: true, autoSkipPadding: 10 } },
                y: { beginAtZero: true, grid: { color: '#eef0f4' }, border: { display: false }, ticks: { color: '#6b7280', font: { size: 10 } } },
            },
        };
        const daily = document.getElementById('net-chart-daily');
        if (daily && !daily.__chart) {
            daily.__chart = new Chart(daily, { type: 'line', data: { labels: data.daily.labels, datasets: [{ label: 'Organisations actives', data: data.daily.values, borderColor: NET_COLOR, backgroundColor: NET_COLOR, borderWidth: 2, pointRadius: 0, pointHoverRadius: 5, tension: 0.25 }] }, options: base });
        }
        const months = document.getElementById('net-chart-months');
        if (months && !months.__chart) {
            months.__chart = new Chart(months, { type: 'bar', data: { labels: data.months.labels, datasets: [{ label: 'Inscriptions', data: data.months.values, backgroundColor: NET_COLOR, borderRadius: 4, borderSkipped: 'start', maxBarThickness: 26 }] }, options: base });
        }
    }

    netDraw();
</script>
@endscript

</div>
