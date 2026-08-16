<?php

use App\Models\DashboardAggregation;
use Livewire\Volt\Component;
use Carbon\Carbon;

new class extends Component {

    public string $date_debut = '';
    public string $date_fin   = '';
    public bool   $searched   = false;

    public function mount()
    {
        $this->date_debut = Carbon::now()->startOfMonth()->format('Y-m-d');
        $this->date_fin   = Carbon::now()->format('Y-m-d');
    }

    public function rechercher()
    {
        $this->validate([
            'date_debut' => 'required|date',
            'date_fin'   => 'required|date|after_or_equal:date_debut',
        ], [
            'date_debut.required'       => 'La date de début est obligatoire.',
            'date_fin.required'         => 'La date de fin est obligatoire.',
            'date_fin.after_or_equal'   => 'La date de fin doit être après la date de début.',
        ]);

        $this->searched = true;
    }

    public function getSummary(): array
    {
        if (!$this->searched) return [];

        return DashboardAggregation::query()
            ->where('jour', '>=', $this->date_debut)
            ->where('jour', '<=', $this->date_fin)
            ->selectRaw('
                txn_type_name,
                alias,
                SUM(nb_transactions)    AS nb_transactions,
                SUM(volume_total)*100       AS volume_total,
                SUM(revenus)*100            AS revenus,
                SUM(frais)*100              AS frais,
                SUM(commission)*100         AS commission,
                SUM(taxe)*100               AS taxe
            ')
            ->groupBy('txn_type_name', 'alias')
            ->orderByDesc('nb_transactions')
            ->get()
            ->map(fn($r) => [
                'type'           => $r->alias ?? $r->txn_type_name ?? '—',
                'nb_transactions'=> (int)   $r->nb_transactions,
                'volume_total'   => (float) $r->volume_total,
                'revenus'        => (float) $r->revenus,
                'frais'          => (float) $r->frais,
                'commission'     => (float) $r->commission,
                'taxe'           => (float) $r->taxe,
            ])
            ->toArray();
    }

    public function getGrouped(): array
    {
        if (!$this->searched) return [];

        $raw = DashboardAggregation::query()
            ->where('jour', '>=', $this->date_debut)
            ->where('jour', '<=', $this->date_fin)
            ->selectRaw('
                alias,
                txn_type_name,
                SUM(nb_transactions)*100 AS nb,
                SUM(volume_total)*100    AS volume,
                SUM(revenus)*100         AS revenus
            ')
            ->groupBy('alias', 'txn_type_name')
            ->get()
            ->map(fn($r) => [
                'alias'   => strtolower(trim($r->alias ?? $r->txn_type_name ?? '')),
                'nb'      => (int)   $r->nb,
                'volume'  => (float) $r->volume,
                'revenus' => (float) $r->revenus,
            ]);

        // Définition des groupes
        $groupes = [
            'Airtime'          => ['self top up', 'third top up', 'bulk buy airtime'],
            'Forfait Mobile'   => ['purchase airtime package'],
            'Cash In'          => ['customer cash in', 'business cash in'],
            'Cash In'          => ['customer cash in', 'business cash in'],
            'Amana'            => ['ODLoanPayment'],
            'Amana Repayment'  => ['OD Loan Auto Repayment'],
            'Virement de Masse'=> ['Bulk B2B Payment','Bulk B2C Payment'],
            'SP Bulk'          => ['Bulk SP2B Payment','Bulk SP2C Payment'],
            'Pay Bills'        => ['pay edd bills', 'pay onead bills', 'pay dt bills', 'pay dt bundles'],
            'Merchant Payment' => ['merchant payment', 'online merchant payment'],
            'W2B'              => ['bank initiate w2b', 'eab w2b', 'cac w2b', 'boa w2b', 'bcimr w2b', 'saba w2b'],
            'B2W'              => ['bank initiate b2w', 'eab b2w', 'cac b2w', 'boa b2w', 'bcimr b2w', 'saba b2w'],
        ];

        $result = [];
        foreach ($groupes as $label => $keywords) {
            // ✅ Après — insensible à la casse + match exact possible
            $matching = $raw->filter(function ($r) use ($keywords) {
                $alias = strtolower(trim($r['alias']));
                foreach ($keywords as $kw) {
                    // Vérifie si l'alias contient le keyword OU si c'est une correspondance exacte
                    if (str_contains($alias, strtolower(trim($kw)))) return true;
                }
                return false;
            });

            $result[] = [
                'groupe'  => $label,
                'nb'      => $matching->sum('nb'),
                'volume'  => $matching->sum('volume'),
                'revenus' => $matching->sum('revenus'),
            ];
        }

        return $result;
    }

    public function with(): array
    {
        $summary = $this->getSummary();
        $grouped = $this->getGrouped();

        $totalNb     = array_sum(array_column($summary, 'nb_transactions'));
        $totalVolume = array_sum(array_column($summary, 'volume_total'));
        $totalRevenu = array_sum(array_column($summary, 'revenus'));

        return [
            'summary'      => $summary,
            'totalNb'      => $totalNb,
            'grouped'      => $grouped,
            'totalVolume'  => $totalVolume,
            'totalRevenu'  => $totalRevenu,
        ];
    }
};
?>

<div style="padding:24px;">

    {{-- FILTRES --}}
    <div style="background:#fff; border:1px solid #e5e7eb; border-radius:12px; padding:20px; margin-bottom:20px;">
        <p style="font-size:14px; font-weight:700; color:#111827; margin-bottom:16px;">Transaction Summary</p>

        <div style="display:flex; align-items:flex-end; gap:12px; flex-wrap:wrap;">
            <div>
                <label style="font-size:11px; color:#6b7280; display:block; margin-bottom:4px;">Date début</label>
                <input type="date" wire:model="date_debut"
                       style="border:1px solid #d1d5db; border-radius:7px; padding:8px 12px; font-size:13px; color:#111827; outline:none;">
                @error('date_debut')
                    <p style="font-size:10px; color:#E24B4A; margin:3px 0 0;">{{ $message }}</p>
                @enderror
            </div>

            <div>
                <label style="font-size:11px; color:#6b7280; display:block; margin-bottom:4px;">Date fin</label>
                <input type="date" wire:model="date_fin"
                       style="border:1px solid #d1d5db; border-radius:7px; padding:8px 12px; font-size:13px; color:#111827; outline:none;">
                @error('date_fin')
                    <p style="font-size:10px; color:#E24B4A; margin:3px 0 0;">{{ $message }}</p>
                @enderror
            </div>

            <button wire:click="rechercher"
                    wire:loading.attr="disabled"
                    wire:target="rechercher"
                    style="background:#1B2F6E; color:#fff; font-size:13px; font-weight:600; padding:9px 22px; border-radius:8px; border:none; cursor:pointer; display:flex; align-items:center; gap:7px;">
                <span wire:loading.remove wire:target="rechercher" style="display:flex; align-items:center; gap:7px;">
                    <svg width="14" height="14" viewBox="0 0 16 16" fill="white">
                        <circle cx="6.5" cy="6.5" r="4.5" stroke="white" stroke-width="1.5" fill="none"/>
                        <path d="M10.5 10.5L14 14" stroke="white" stroke-width="1.5" stroke-linecap="round"/>
                    </svg>
                    Afficher
                </span>
                <span wire:loading.delay wire:target="rechercher" style="display:flex; align-items:center; gap:7px;">
                    <svg width="14" height="14" viewBox="0 0 40 40" fill="none" style="animation:spin 0.8s linear infinite;">
                        <circle cx="20" cy="20" r="16" stroke="rgba(255,255,255,0.3)" stroke-width="4"/>
                        <path d="M20 4a16 16 0 0116 16" stroke="white" stroke-width="4" stroke-linecap="round"/>
                    </svg>
                    Chargement...
                </span>
            </button>
        </div>
    </div>

    

    @if(!$searched)
        <div style="text-align:center; padding:60px 20px; background:#fff; border:1px solid #e5e7eb; border-radius:10px;">
            <div style="width:52px; height:52px; background:#E8ECF8; border-radius:14px; display:flex; align-items:center; justify-content:center; margin:0 auto 14px;">
                <svg width="26" height="26" viewBox="0 0 24 24" fill="none">
                    <rect x="3" y="5" width="18" height="14" rx="2" stroke="#1B2F6E" stroke-width="1.5"/>
                    <path d="M7 9h10M7 13h6" stroke="#1B2F6E" stroke-width="1.5" stroke-linecap="round"/>
                </svg>
            </div>
            <p style="font-size:14px; font-weight:600; color:#111827; margin-bottom:4px;">Sélectionnez une période</p>
            <p style="font-size:12px; color:#9ca3af;">Choisissez une date de début et de fin puis cliquez sur <strong>Afficher</strong>.</p>
        </div>

    @elseif(empty($summary))
        <div style="text-align:center; padding:60px 20px; background:#fff; border:1px solid #e5e7eb; border-radius:10px;">
            <p style="font-size:14px; font-weight:600; color:#111827; margin-bottom:4px;">Aucune donnée</p>
            <p style="font-size:12px; color:#9ca3af;">Aucune transaction trouvée pour cette période.</p>
        </div>

    @else

        {{-- KPIs --}}
        <div style="display:grid; grid-template-columns:repeat(3, minmax(0,1fr)); gap:12px; margin-bottom:20px;">
            <div style="background:#fff; border:1px solid #e5e7eb; border-radius:10px; padding:16px; border-top:3px solid #1B2F6E;">
                <p style="font-size:11px; color:#6b7280; margin:0 0 6px;">Nombre total de transactions</p>
                <p style="font-size:24px; font-weight:700; color:#111827; margin:0;">{{ number_format($totalNb, 0, ',', ' ') }}</p>
                <p style="font-size:10px; color:#9ca3af; margin:4px 0 0;">{{ count($summary) }} types de transactions</p>
            </div>
            <div style="background:#fff; border:1px solid #e5e7eb; border-radius:10px; padding:16px; border-top:3px solid #FFC72C;">
                <p style="font-size:11px; color:#6b7280; margin:0 0 6px;">Valeur total</p>
                <p style="font-size:24px; font-weight:700; color:#111827; margin:0;">{{ number_format($totalVolume, 0, ',', ' ') }}</p>
                <p style="font-size:10px; color:#9ca3af; margin:4px 0 0;">DJF</p>
            </div>
            <div style="background:#fff; border:1px solid #e5e7eb; border-radius:10px; padding:16px; border-top:3px solid #16a34a;">
                <p style="font-size:11px; color:#6b7280; margin:0 0 6px;">Revenus totaux</p>
                <p style="font-size:24px; font-weight:700; color:#111827; margin:0;">{{ number_format($totalRevenu, 0, ',', ' ') }}</p>
                <p style="font-size:10px; color:#9ca3af; margin:4px 0 0;">DJF (frais + commissions)</p>
            </div>
        </div>

        {{-- GRAPHIQUES --}}
        <div style="display:grid; grid-template-columns:repeat(2, minmax(0,1fr)); gap:12px; margin-bottom:20px;">

            {{-- Graphique barres — Volume par type --}}
            <div style="background:#fff; border:1px solid #e5e7eb; border-radius:10px; overflow:hidden;">
                <div style="padding:14px 16px; border-bottom:1px solid #e5e7eb;">
                    <p style="font-size:13px; font-weight:600; color:#111827; margin:0;">Volume par type (DJF)</p>
                </div>
                <div style="padding:16px;"
                    x-data="{
                        chart: null,
                        grouped: {{ Js::from($grouped) }},
                        palette: ['#1B2F6E','#FFC72C','#16a34a','#E24B4A','#9333ea','#f97316','#0891b2','#db2777','#65a30d','#7c3aed','#ea580c','#0284c7'],
                    }"
                    x-init="
                        $nextTick(() => {
                            if (typeof Chart === 'undefined') return;
                            if (chart) chart.destroy();
                            chart = new Chart($refs.barCanvas, {
                                type: 'bar',
                                data: {
                                    labels: grouped.map(r => r.groupe),
                                    datasets: [{
                                        label: 'Volume (DJF)',
                                        data: grouped.map(r => r.volume),
                                        backgroundColor: palette.slice(0, grouped.length),
                                        borderRadius: 5,
                                        borderSkipped: false,
                                    }]
                                },
                                options: {
                                    responsive: true,
                                    plugins: {
                                        legend: { display: false },
                                        tooltip: {
                                            callbacks: {
                                                label: ctx => new Intl.NumberFormat('fr-FR').format(ctx.raw) + ' DJF'
                                            }
                                        }
                                    },
                                    scales: {
                                        x: {
                                            grid: { display: false },
                                            ticks: { font: { size: 10 }, color: '#9ca3af', maxRotation: 30 }
                                        },
                                        y: {
                                            grid: { color: '#f3f4f6' },
                                            ticks: {
                                                font: { size: 10 },
                                                color: '#9ca3af',
                                                callback: v => new Intl.NumberFormat('fr-FR', { notation: 'compact' }).format(v)
                                            }
                                        }
                                    }
                                }
                            });
                        })
                    ">
                    <canvas x-ref="barCanvas" height="280"></canvas>
                </div>
            </div>

            {{-- Graphique camembert — Répartition nb transactions --}}
            <div style="background:#fff; border:1px solid #e5e7eb; border-radius:10px; overflow:hidden;">
                <div style="padding:14px 16px; border-bottom:1px solid #e5e7eb;">
                    <p style="font-size:13px; font-weight:600; color:#111827; margin:0;">Répartition par nombre de transactions</p>
                </div>
                <div style="padding:16px; display:flex; justify-content:center;"
                    x-data="{
                        chart: null,
                        grouped: {{ Js::from($grouped) }},
                        palette: ['#1B2F6E','#FFC72C','#16a34a','#E24B4A','#9333ea','#f97316','#0891b2','#db2777','#65a30d','#7c3aed','#ea580c','#0284c7'],
                    }"
                    x-init="
                        $nextTick(() => {
                            if (typeof Chart === 'undefined') return;
                            if (chart) chart.destroy();
                            chart = new Chart($refs.pieCanvas, {
                                type: 'doughnut',
                                data: {
                                    labels: grouped.map(r => r.groupe),
                                    datasets: [{
                                        data: grouped.map(r => r.nb),
                                        backgroundColor: palette.slice(0, grouped.length),
                                        borderWidth: 2,
                                        borderColor: '#fff',
                                    }]
                                },
                                options: {
                                    responsive: true,
                                    cutout: '60%',
                                    plugins: {
                                        legend: {
                                            position: 'bottom',
                                            labels: {
                                                font: { size: 10 },
                                                color: '#6b7280',
                                                padding: 12,
                                                boxWidth: 12,
                                            }
                                        },
                                        tooltip: {
                                            callbacks: {
                                                label: ctx => {
                                                    const total = ctx.dataset.data.reduce((a, b) => a + b, 0);
                                                    const pct = ((ctx.raw / total) * 100).toFixed(1);
                                                    return ' ' + new Intl.NumberFormat('fr-FR').format(ctx.raw) + ' txn (' + pct + '%)';
                                                }
                                            }
                                        }
                                    }
                                }
                            });
                        })
                    ">
                    <canvas x-ref="pieCanvas" height="280" style="max-width:280px;"></canvas>
                </div>
            </div>
        </div>

        {{-- TABLEAU GROUPÉ --}}
        @if(!empty($grouped))
        <div style="background:#fff; border:1px solid #e5e7eb; border-radius:10px; overflow:hidden; margin-bottom:20px;">
            <div style="padding:12px 16px; border-bottom:1px solid #e5e7eb; display:flex; align-items:center; justify-content:space-between;">
                <p style="font-size:13px; font-weight:600; color:#111827; margin:0;">Synthèse par nature</p>
                <span style="background:#E8ECF8; color:#1B2F6E; font-size:11px; font-weight:600; padding:3px 10px; border-radius:20px;">
                    {{ \Carbon\Carbon::parse($date_debut)->format('d/m/Y') }} — {{ \Carbon\Carbon::parse($date_fin)->format('d/m/Y') }}
                </span>
            </div>

            <div style="overflow-x:auto;">
                <table style="width:100%; border-collapse:collapse; font-size:12px;">
                    <thead>
                        <tr style="background:#F7F8FC;">
                            <th style="padding:10px 14px; text-align:left; color:#6b7280; font-weight:500; border-bottom:1px solid #e5e7eb; white-space:nowrap;">#</th>
                            <th style="padding:10px 14px; text-align:left; color:#6b7280; font-weight:500; border-bottom:1px solid #e5e7eb; white-space:nowrap;">Nature</th>
                            <th style="padding:10px 14px; text-align:right; color:#6b7280; font-weight:500; border-bottom:1px solid #e5e7eb; white-space:nowrap;">Nb transactions</th>
                            <th style="padding:10px 14px; text-align:right; color:#6b7280; font-weight:500; border-bottom:1px solid #e5e7eb; white-space:nowrap;">% nb</th>
                            <th style="padding:10px 14px; text-align:right; color:#6b7280; font-weight:500; border-bottom:1px solid #e5e7eb; white-space:nowrap;">Valeur (DJF)</th>
                            <th style="padding:10px 14px; text-align:right; color:#6b7280; font-weight:500; border-bottom:1px solid #e5e7eb; white-space:nowrap;">% valeur</th>
                            <th style="padding:10px 14px; text-align:right; color:#6b7280; font-weight:500; border-bottom:1px solid #e5e7eb; white-space:nowrap;">Revenus (DJF)</th>
                        </tr>
                    </thead>
                    <tbody>
                        @php
                            $totGrpNb     = array_sum(array_column($grouped, 'nb'));
                            $totGrpVolume = array_sum(array_column($grouped, 'volume'));
                            $totGrpRevenu = array_sum(array_column($grouped, 'revenus'));

                            $colors = [
                                'Airtime'          => ['bg' => '#E8ECF8', 'color' => '#1B2F6E'],
                                'Cash In'          => ['bg' => '#E5F5ED', 'color' => '#005C2B'],
                                'Amana'          => ['bg' => '#E5F5ED', 'color' => '#5b9174'],
                                'Virement de Masse'          => ['bg' => '#8edab4', 'color' => '#446c56'],
                                'Cash Out'         => ['bg' => '#FDECEA', 'color' => '#7F1D1D'],
                                'Pay Bills'        => ['bg' => '#FFF3D0', 'color' => '#7A4F00'],
                                'Forfait Mobile'        => ['bg' => '#FFF3D0', 'color' => '#3b56b0'],
                                'SP Bulk'        => ['bg' => '#FFF3D0', 'color' => '#6e5c39'],
                                'Merchant Payment' => ['bg' => '#F3E8FF', 'color' => '#6B21A8'],
                                'W2B'              => ['bg' => '#E6F1FB', 'color' => '#0C447C'],
                                'B2W'              => ['bg' => '#FEF3C7', 'color' => '#92400E'],
                            ];
                        @endphp

                        @foreach($grouped as $i => $row)
                            @php
                                $pctNb  = $totGrpNb     > 0 ? round(($row['nb']     / $totGrpNb)     * 100, 1) : 0;
                                $pctVol = $totGrpVolume  > 0 ? round(($row['volume'] / $totGrpVolume) * 100, 1) : 0;
                                $c      = $colors[$row['groupe']] ?? ['bg' => '#f3f4f6', 'color' => '#374151'];
                            @endphp
                            <tr style="border-bottom:1px solid #f3f4f6;"
                                onmouseover="this.style.background='#F7F8FC'"
                                onmouseout="this.style.background='transparent'">

                                <td style="padding:10px 14px; color:#9ca3af;">{{ $i + 1 }}</td>

                                <td style="padding:10px 14px;">
                                    <span style="background:{{ $c['bg'] }}; color:{{ $c['color'] }}; font-size:11px; font-weight:700; padding:4px 12px; border-radius:12px;">
                                        {{ $row['groupe'] }}
                                    </span>
                                </td>

                                <td style="padding:10px 14px; text-align:right; font-weight:600; color:#111827;">
                                    {{ number_format($row['nb'], 0, ',', ' ') }}
                                </td>

                                <td style="padding:10px 14px; text-align:right;">
                                    <div style="display:flex; align-items:center; justify-content:flex-end; gap:6px;">
                                        <div style="width:60px; background:#f3f4f6; border-radius:3px; height:5px; overflow:hidden;">
                                            <div style="height:100%; background:{{ $c['color'] }}; border-radius:3px; width:{{ $pctNb }}%;"></div>
                                        </div>
                                        <span style="font-size:11px; color:#6b7280; min-width:32px; text-align:right;">{{ $pctNb }}%</span>
                                    </div>
                                </td>

                                <td style="padding:10px 14px; text-align:right; color:#374151;">
                                    {{ number_format($row['volume'], 0, ',', ' ') }}
                                </td>

                                <td style="padding:10px 14px; text-align:right;">
                                    <div style="display:flex; align-items:center; justify-content:flex-end; gap:6px;">
                                        <div style="width:60px; background:#f3f4f6; border-radius:3px; height:5px; overflow:hidden;">
                                            <div style="height:100%; background:{{ $c['color'] }}; border-radius:3px; width:{{ $pctVol }}%;"></div>
                                        </div>
                                        <span style="font-size:11px; color:#6b7280; min-width:32px; text-align:right;">{{ $pctVol }}%</span>
                                    </div>
                                </td>

                                <td style="padding:10px 14px; text-align:right; font-weight:600; color:#16a34a;">
                                    {{ number_format($row['revenus'], 0, ',', ' ') }}
                                </td>
                            </tr>
                        @endforeach

                        {{-- Ligne totaux --}}
                        <tr style="background:#F7F8FC; border-top:2px solid #e5e7eb;">
                            <td colspan="2" style="padding:10px 14px; font-weight:700; color:#111827;">Total</td>
                            <td style="padding:10px 14px; text-align:right; font-weight:700; color:#111827;">
                                {{ number_format($totGrpNb, 0, ',', ' ') }}
                            </td>
                            <td style="padding:10px 14px; text-align:right; font-size:11px; color:#6b7280;">100%</td>
                            <td style="padding:10px 14px; text-align:right; font-weight:700; color:#111827;">
                                {{ number_format($totGrpVolume, 0, ',', ' ') }}
                            </td>
                            <td style="padding:10px 14px; text-align:right; font-size:11px; color:#6b7280;">100%</td>
                            <td style="padding:10px 14px; text-align:right; font-weight:700; color:#16a34a;">
                                {{ number_format($totGrpRevenu, 0, ',', ' ') }}
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
        @endif

        {{-- TABLEAU --}}
        <div style="background:#fff; border:1px solid #e5e7eb; border-radius:10px; overflow:hidden;">
            <div style="padding:12px 16px; border-bottom:1px solid #e5e7eb; display:flex; align-items:center; justify-content:space-between;">
                <p style="font-size:13px; font-weight:600; color:#111827; margin:0;">Détail par type de transaction</p>
                <span style="background:#E8ECF8; color:#1B2F6E; font-size:11px; font-weight:600; padding:3px 10px; border-radius:20px;">
                    {{ \Carbon\Carbon::parse($date_debut)->format('d/m/Y') }} — {{ \Carbon\Carbon::parse($date_fin)->format('d/m/Y') }}
                </span>
            </div>

            <div style="overflow-x:auto; overflow-y:auto; max-height:480px;">
                <table style="width:100%; border-collapse:collapse; font-size:12px;">
                    <thead>
                        <tr style="background:#F7F8FC;">
                            <th style="padding:10px 14px; text-align:left; color:#6b7280; font-weight:500; border-bottom:1px solid #e5e7eb; white-space:nowrap; position:sticky; top:0; background:#F7F8FC; z-index:1;">#</th>
                            <th style="padding:10px 14px; text-align:left; color:#6b7280; font-weight:500; border-bottom:1px solid #e5e7eb; white-space:nowrap; position:sticky; top:0; background:#F7F8FC; z-index:1;">Type de transaction</th>
                            <th style="padding:10px 14px; text-align:right; color:#6b7280; font-weight:500; border-bottom:1px solid #e5e7eb; white-space:nowrap; position:sticky; top:0; background:#F7F8FC; z-index:1;">Nb transactions</th>
                            <th style="padding:10px 14px; text-align:right; color:#6b7280; font-weight:500; border-bottom:1px solid #e5e7eb; white-space:nowrap; position:sticky; top:0; background:#F7F8FC; z-index:1;">% volume</th>
                            <th style="padding:10px 14px; text-align:right; color:#6b7280; font-weight:500; border-bottom:1px solid #e5e7eb; white-space:nowrap; position:sticky; top:0; background:#F7F8FC; z-index:1;">Valeur (DJF)</th>
                            <th style="padding:10px 14px; text-align:right; color:#6b7280; font-weight:500; border-bottom:1px solid #e5e7eb; white-space:nowrap; position:sticky; top:0; background:#F7F8FC; z-index:1;">Frais (DJF)</th>
                            <th style="padding:10px 14px; text-align:right; color:#6b7280; font-weight:500; border-bottom:1px solid #e5e7eb; white-space:nowrap; position:sticky; top:0; background:#F7F8FC; z-index:1;">Commission (DJF)</th>
                            <th style="padding:10px 14px; text-align:right; color:#6b7280; font-weight:500; border-bottom:1px solid #e5e7eb; white-space:nowrap; position:sticky; top:0; background:#F7F8FC; z-index:1;">Taxe (DJF)</th>
                            <th style="padding:10px 14px; text-align:right; color:#6b7280; font-weight:500; border-bottom:1px solid #e5e7eb; white-space:nowrap; position:sticky; top:0; background:#F7F8FC; z-index:1;">Revenus (DJF)</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($summary as $i => $row)
                            @php
                                $pct = $totalVolume > 0
                                    ? round(($row['volume_total'] / $totalVolume) * 100, 1)
                                    : 0;
                            @endphp
                            <tr style="border-bottom:1px solid #f3f4f6;"
                                onmouseover="this.style.background='#F7F8FC'"
                                onmouseout="this.style.background='transparent'">
                                <td style="padding:10px 14px; color:#9ca3af;">{{ $i + 1 }}</td>
                                <td style="padding:10px 14px;">
                                    <span style="background:#E8ECF8; color:#1B2F6E; font-size:10px; font-weight:600; padding:3px 10px; border-radius:12px;">
                                        {{ $row['type'] }}
                                    </span>
                                </td>
                                <td style="padding:10px 14px; text-align:right; font-weight:600; color:#111827;">
                                    {{ number_format($row['nb_transactions'], 0, ',', ' ') }}
                                </td>
                                <td style="padding:10px 14px; text-align:right;">
                                    <div style="display:flex; align-items:center; justify-content:flex-end; gap:6px;">
                                        <div style="width:60px; background:#f3f4f6; border-radius:3px; height:5px; overflow:hidden;">
                                            <div style="height:100%; background:#1B2F6E; border-radius:3px; width:{{ $pct }}%;"></div>
                                        </div>
                                        <span style="font-size:11px; color:#6b7280; min-width:32px; text-align:right;">{{ $pct }}%</span>
                                    </div>
                                </td>
                                <td style="padding:10px 14px; text-align:right; color:#374151;">
                                    {{ number_format($row['volume_total']*100, 0, ',', ' ') }}
                                </td>
                                <td style="padding:10px 14px; text-align:right; color:#374151;">
                                    {{ number_format($row['frais'], 0, ',', ' ') }}
                                </td>
                                <td style="padding:10px 14px; text-align:right; color:#374151;">
                                    {{ number_format($row['commission'], 0, ',', ' ') }}
                                </td>
                                <td style="padding:10px 14px; text-align:right; color:#374151;">
                                    {{ number_format($row['taxe'], 0, ',', ' ') }}
                                </td>
                                <td style="padding:10px 14px; text-align:right; font-weight:600; color:#16a34a;">
                                    {{ number_format($row['revenus'], 0, ',', ' ') }}
                                </td>
                            </tr>
                        @endforeach

                        {{-- Ligne totaux --}}
                        <tr style="background:#F7F8FC; border-top:2px solid #e5e7eb;">
                            <td colspan="2" style="padding:10px 14px; font-weight:700; color:#111827;">Total</td>
                            <td style="padding:10px 14px; text-align:right; font-weight:700; color:#111827;">
                                {{ number_format($totalNb, 0, ',', ' ') }}
                            </td>
                            <td style="padding:10px 14px; text-align:right; font-size:11px; color:#6b7280;">100%</td>
                            <td style="padding:10px 14px; text-align:right; font-weight:700; color:#111827;">
                                {{ number_format($totalVolume, 0, ',', ' ') }}
                            </td>
                            <td style="padding:10px 14px; text-align:right; font-weight:700; color:#111827;">
                                {{ number_format(array_sum(array_column($summary, 'frais')), 0, ',', ' ') }}
                            </td>
                            <td style="padding:10px 14px; text-align:right; font-weight:700; color:#111827;">
                                {{ number_format(array_sum(array_column($summary, 'commission')), 0, ',', ' ') }}
                            </td>
                            <td style="padding:10px 14px; text-align:right; font-weight:700; color:#111827;">
                                {{ number_format(array_sum(array_column($summary, 'taxe')), 0, ',', ' ') }}
                            </td>
                            <td style="padding:10px 14px; text-align:right; font-weight:700; color:#16a34a;">
                                {{ number_format($totalRevenu, 0, ',', ' ') }}
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

    @endif

    

    <style>
        @keyframes spin { from { transform:rotate(0deg); } to { transform:rotate(360deg); } }
    </style>
    
    
</div>