<?php

use App\Models\DashboardAggregation;
use Livewire\Volt\Component;
use Carbon\Carbon;

/**
 * Transaction Summary : synthèse des transactions abouties (Completed) par
 * type et par nature, à partir de dashboard_aggregations (rempli chaque nuit
 * par dashboard:agreger pour la veille).
 *
 * Vocabulaire : « Volume » = nombre de transactions, « Valeur » = montant.
 * Les montants de dashboard_aggregations sont stockés divisés par 100 : ×100 ici.
 */
new class extends Component {

    public string $date_debut = '';
    public string $date_fin   = '';
    public bool   $searched   = false;

    /** Exclure les opérations internes (création/destruction de monnaie, trésorerie, commissions) */
    public bool $excludeInternal = false;

    public ?string $dernierJour = null;

    /**
     * Opérations internes : mouvements de trésorerie et écritures techniques,
     * pas des transactions clients. Incluses par défaut, exclusion au choix.
     */
    public const INTERNAL_TYPES = [
        'e-money creation', 'e-money destroy',
        'org deposit of funds', 'org withdrawal of funds',
        'sp deposit of funds', 'sp withdrawal of funds',
        'commission payment', 'commission roll up', 'commission roll down',
        'commission claw back', 'commission transaction reversal',
        'journal transaction', 'od revenue settlement b2b', 'term loan revenue settlement',
    ];

    /** Natures (groupes de types), comparées au nom ou à l'alias du type, sans tenir compte de la casse */
    private const GROUPES = [
        'Airtime'           => ['self top up', 'third top up', 'bulk buy airtime', 'purchase airtime package'],
        'Cash In'           => ['customer cash in', 'business cash in'],
        'Cash Out'          => ['customer cash out', 'business cash out'],
        'Virement de Masse' => ['bulk b2b payment', 'bulk b2c payment'],
        'Send Money'        => ['customer send money'],
        'W2B'               => ['bank initiate w2b', 'eab w2b', 'cac w2b', 'boa w2b', 'bcimr w2b', 'saba w2b'],
        'B2W'               => ['bank initiate b2w', 'eab b2w', 'cac b2w', 'boa b2w', 'bcimr b2w', 'saba b2w'],
        'Pay Bills'         => ['pay edd bills', 'pay onead bills', 'pay dt bills', 'pay dt bundles'],
        'Merchant Payment'  => ['merchant payment', 'online merchant payment'],
        'Amana'             => ['odloanpayment'],
        'Amana Repayment'   => ['od loan auto repayment'],
    ];

    public function mount()
    {
        $this->dernierJour = DashboardAggregation::max('jour');

        // Par défaut : la veille (le jour en cours n'est agrégé que la nuit suivante)
        $hier = Carbon::yesterday()->format('Y-m-d');
        $this->date_debut = $hier;
        $this->date_fin   = $hier;
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

    private static function isInternal(?string $name, ?string $alias): bool
    {
        return in_array(strtolower(trim((string) $name)), self::INTERNAL_TYPES, true)
            || in_array(strtolower(trim((string) $alias)), self::INTERNAL_TYPES, true);
    }

    /**
     * Une ligne par type de transaction (Completed) sur la période.
     */
    private function getSummary(): array
    {
        if (!$this->searched) return [];

        return DashboardAggregation::query()
            ->where('jour', '>=', $this->date_debut)
            ->where('jour', '<=', $this->date_fin)
            ->where('trans_status', 'Completed')
            ->selectRaw('
                txn_type_name,
                alias,
                SUM(nb_transactions)      AS nb_transactions,
                SUM(volume_total) * 100   AS valeur,
                SUM(revenus) * 100        AS revenus,
                SUM(frais) * 100          AS frais,
                SUM(commission) * 100     AS commission,
                SUM(taxe) * 100           AS taxe
            ')
            ->groupBy('txn_type_name', 'alias')
            ->orderByDesc('nb_transactions')
            ->get()
            ->filter(fn ($r) => !$this->excludeInternal || !self::isInternal($r->txn_type_name, $r->alias))
            ->map(fn ($r) => [
                'type'       => $r->alias ?? $r->txn_type_name ?? '—',
                'name'       => strtolower(trim((string) $r->txn_type_name)),
                'alias'      => strtolower(trim((string) $r->alias)),
                'internal'   => self::isInternal($r->txn_type_name, $r->alias),
                'volume'     => (int)   $r->nb_transactions,
                'valeur'     => (float) $r->valeur,
                'revenus'    => (float) $r->revenus,
                'frais'      => (float) $r->frais,
                'commission' => (float) $r->commission,
                'taxe'       => (float) $r->taxe,
            ])
            ->values()
            ->toArray();
    }

    /**
     * Synthèse par nature, calculée à partir des mêmes lignes que le détail :
     * les types internes vont dans « Opérations internes », les types non
     * classés dans « Autres », pour que la somme des natures = le total réel.
     */
    private function getGrouped(array $summary): array
    {
        $rows   = collect($summary);
        $result = [];
        $used   = [];

        foreach (self::GROUPES as $label => $types) {
            $matching = $rows->filter(fn ($r) => !$r['internal'] && (in_array($r['name'], $types, true) || in_array($r['alias'], $types, true)));
            $used     = array_merge($used, $matching->keys()->all());

            $result[] = ['groupe' => $label, 'volume' => $matching->sum('volume'), 'valeur' => $matching->sum('valeur'), 'revenus' => $matching->sum('revenus')];
        }

        $internal = $rows->filter(fn ($r) => $r['internal']);
        if ($internal->isNotEmpty()) {
            $result[] = ['groupe' => 'Opérations internes', 'volume' => $internal->sum('volume'), 'valeur' => $internal->sum('valeur'), 'revenus' => $internal->sum('revenus')];
            $used = array_merge($used, $internal->keys()->all());
        }

        $autres = $rows->except($used);
        if ($autres->isNotEmpty()) {
            $result[] = [
                'groupe'  => 'Autres',
                'volume'  => $autres->sum('volume'),
                'valeur'  => $autres->sum('valeur'),
                'revenus' => $autres->sum('revenus'),
                'types'   => $autres->pluck('type')->implode(', '),
            ];
        }

        return $result;
    }

    public function with(): array
    {
        $summary = $this->getSummary();

        return [
            'summary'       => $summary,
            'grouped'       => $this->getGrouped($summary),
            'totalVolume'   => array_sum(array_column($summary, 'volume')),
            'totalValeur'   => array_sum(array_column($summary, 'valeur')),
            'totalRevenu'   => array_sum(array_column($summary, 'revenus')),
            'internalTypes' => self::INTERNAL_TYPES,
        ];
    }
};
?>

<div style="padding:24px;">

    {{-- FILTRES --}}
    <div style="background:#fff; border:1px solid #e5e7eb; border-radius:12px; padding:20px; margin-bottom:20px;">
        <p style="font-size:14px; font-weight:700; color:#111827; margin-bottom:4px;">Transaction Summary</p>
        <p style="font-size:11px; color:#9ca3af; margin:0 0 16px;">
            Transactions abouties (Completed). Volume = nombre de transactions, Valeur = montant.
            @if($dernierJour) Données disponibles jusqu'au <strong style="color:#6b7280;">{{ \Carbon\Carbon::parse($dernierJour)->format('d/m/Y') }}</strong>. @endif
        </p>

        <div style="display:flex; align-items:flex-end; gap:12px; flex-wrap:wrap;">
            <div>
                <label style="font-size:11px; color:#6b7280; display:block; margin-bottom:4px;">Date début</label>
                <input type="date" wire:model="date_debut" max="{{ $dernierJour }}"
                       style="border:1px solid #d1d5db; border-radius:7px; padding:8px 12px; font-size:13px; color:#111827; outline:none;">
                @error('date_debut')
                    <p style="font-size:10px; color:#E24B4A; margin:3px 0 0;">{{ $message }}</p>
                @enderror
            </div>

            <div>
                <label style="font-size:11px; color:#6b7280; display:block; margin-bottom:4px;">Date fin</label>
                <input type="date" wire:model="date_fin" max="{{ $dernierJour }}"
                       style="border:1px solid #d1d5db; border-radius:7px; padding:8px 12px; font-size:13px; color:#111827; outline:none;">
                @error('date_fin')
                    <p style="font-size:10px; color:#E24B4A; margin:3px 0 0;">{{ $message }}</p>
                @enderror
            </div>

            <label style="display:flex; align-items:center; gap:8px; font-size:12px; color:#374151; padding-bottom:9px; cursor:pointer;"
                   title="{{ implode(', ', array_map('ucwords', $internalTypes)) }}">
                <input type="checkbox" wire:model="excludeInternal">
                Exclure les opérations internes
                <span style="color:#9ca3af; font-size:11px;">(création / destruction de monnaie, trésorerie, commissions)</span>
            </label>

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
            <p style="font-size:12px; color:#9ca3af;">Par défaut, la veille. Cliquez sur <strong>Afficher</strong>.</p>
        </div>

    @elseif(empty($summary))
        <div style="text-align:center; padding:60px 20px; background:#fff; border:1px solid #e5e7eb; border-radius:10px;">
            <p style="font-size:14px; font-weight:600; color:#111827; margin-bottom:4px;">Aucune donnée</p>
            <p style="font-size:12px; color:#9ca3af;">
                Aucune transaction trouvée pour cette période.
                @if($dernierJour && $date_debut > $dernierJour) Les données ne sont disponibles que jusqu'au {{ \Carbon\Carbon::parse($dernierJour)->format('d/m/Y') }}. @endif
            </p>
        </div>

    @else
        @php
            $periodeLabel = \Carbon\Carbon::parse($date_debut)->format('d/m/Y')
                . ($date_debut !== $date_fin ? ' — ' . \Carbon\Carbon::parse($date_fin)->format('d/m/Y') : '');
            $partielle = $dernierJour && $date_fin > $dernierJour;
        @endphp

        @if($partielle)
            <div style="background:#FFF7E0; border-left:3px solid #F5A800; border-radius:8px; padding:10px 14px; font-size:12px; color:#7A4F00; margin-bottom:16px;">
                Les données s'arrêtent au {{ \Carbon\Carbon::parse($dernierJour)->format('d/m/Y') }} : la période demandée n'est couverte qu'en partie.
            </div>
        @endif

        {{-- KPIs --}}
        <div style="display:grid; grid-template-columns:repeat(3, minmax(0,1fr)); gap:12px; margin-bottom:20px;">
            <div style="background:#fff; border:1px solid #e5e7eb; border-radius:10px; padding:16px; border-top:3px solid #1B2F6E;">
                <p style="font-size:11px; color:#6b7280; margin:0 0 6px;">Volume total</p>
                <p style="font-size:24px; font-weight:700; color:#111827; margin:0;">{{ number_format($totalVolume, 0, ',', ' ') }}</p>
                <p style="font-size:10px; color:#9ca3af; margin:4px 0 0;">transactions · {{ count($summary) }} types</p>
            </div>
            <div style="background:#fff; border:1px solid #e5e7eb; border-radius:10px; padding:16px; border-top:3px solid #FFC72C;">
                <p style="font-size:11px; color:#6b7280; margin:0 0 6px;">Valeur totale</p>
                <p style="font-size:24px; font-weight:700; color:#111827; margin:0;">{{ number_format($totalValeur, 0, ',', ' ') }}</p>
                <p style="font-size:10px; color:#9ca3af; margin:4px 0 0;">DJF{{ $excludeInternal ? ' · hors opérations internes' : '' }}</p>
            </div>
            <div style="background:#fff; border:1px solid #e5e7eb; border-radius:10px; padding:16px; border-top:3px solid #16a34a;">
                <p style="font-size:11px; color:#6b7280; margin:0 0 6px;">Revenus nets</p>
                <p style="font-size:24px; font-weight:700; color:#111827; margin:0;">{{ number_format($totalRevenu, 0, ',', ' ') }}</p>
                <p style="font-size:10px; color:#9ca3af; margin:4px 0 0;">DJF (Frais − Commissions)</p>
            </div>
        </div>

        {{-- GRAPHIQUES : wire:key change avec la recherche → Livewire recrée le bloc et Alpine redessine --}}
        <div wire:key="charts-{{ $date_debut }}-{{ $date_fin }}-{{ (int) $excludeInternal }}"
             style="display:grid; grid-template-columns:repeat(2, minmax(0,1fr)); gap:12px; margin-bottom:20px;">

            {{-- Graphique barres — Valeur par nature --}}
            <div style="background:#fff; border:1px solid #e5e7eb; border-radius:10px; overflow:hidden;">
                <div style="padding:14px 16px; border-bottom:1px solid #e5e7eb;">
                    <p style="font-size:13px; font-weight:600; color:#111827; margin:0;">Valeur par nature (DJF)</p>
                </div>
                <div style="padding:16px;"
                    x-data="{
                        chart: null,
                        grouped: {{ Js::from($grouped) }},
                        palette: ['#1B2F6E','#FFC72C','#16a34a','#E24B4A','#9333ea','#f97316','#0891b2','#db2777','#65a30d','#7c3aed','#ea580c','#0284c7','#6b7280'],
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
                                        label: 'Valeur (DJF)',
                                        data: grouped.map(r => r.valeur),
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

            {{-- Graphique anneau — Répartition du volume --}}
            <div style="background:#fff; border:1px solid #e5e7eb; border-radius:10px; overflow:hidden;">
                <div style="padding:14px 16px; border-bottom:1px solid #e5e7eb;">
                    <p style="font-size:13px; font-weight:600; color:#111827; margin:0;">Répartition du volume par nature</p>
                </div>
                <div style="padding:16px; display:flex; justify-content:center;"
                    x-data="{
                        chart: null,
                        grouped: {{ Js::from($grouped) }},
                        palette: ['#1B2F6E','#FFC72C','#16a34a','#E24B4A','#9333ea','#f97316','#0891b2','#db2777','#65a30d','#7c3aed','#ea580c','#0284c7','#6b7280'],
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
                                        data: grouped.map(r => r.volume),
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
                                                    return ' ' + new Intl.NumberFormat('fr-FR').format(ctx.raw) + ' transactions (' + pct + '%)';
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
        <div style="background:#fff; border:1px solid #e5e7eb; border-radius:10px; overflow:hidden; margin-bottom:20px;">
            <div style="padding:12px 16px; border-bottom:1px solid #e5e7eb; display:flex; align-items:center; justify-content:space-between;">
                <p style="font-size:13px; font-weight:600; color:#111827; margin:0;">Synthèse par nature</p>
                <span style="background:#E8ECF8; color:#1B2F6E; font-size:11px; font-weight:600; padding:3px 10px; border-radius:20px;">{{ $periodeLabel }}</span>
            </div>

            <div style="overflow-x:auto;">
                <table style="width:100%; border-collapse:collapse; font-size:12px;">
                    <thead>
                        <tr style="background:#F7F8FC;">
                            @foreach(['#' => 'left', 'Nature' => 'left', 'Volume' => 'right', '% volume' => 'right', 'Valeur (DJF)' => 'right', '% valeur' => 'right', 'Revenus nets (DJF)' => 'right'] as $th => $align)
                                <th style="padding:10px 14px; text-align:{{ $align }}; color:#6b7280; font-weight:500; border-bottom:1px solid #e5e7eb; white-space:nowrap;">{{ $th }}</th>
                            @endforeach
                        </tr>
                    </thead>
                    <tbody>
                        @php
                            $colors = [
                                'Airtime'             => ['bg' => '#E8ECF8', 'color' => '#1B2F6E'],
                                'Cash In'             => ['bg' => '#E5F5ED', 'color' => '#005C2B'],
                                'Cash Out'            => ['bg' => '#FDECEA', 'color' => '#7F1D1D'],
                                'Virement de Masse'   => ['bg' => '#8edab4', 'color' => '#446c56'],
                                'Send Money'          => ['bg' => '#E0F2FE', 'color' => '#075985'],
                                'W2B'                 => ['bg' => '#E6F1FB', 'color' => '#0C447C'],
                                'B2W'                 => ['bg' => '#FEF3C7', 'color' => '#92400E'],
                                'Pay Bills'           => ['bg' => '#FFF3D0', 'color' => '#7A4F00'],
                                'Merchant Payment'    => ['bg' => '#F3E8FF', 'color' => '#6B21A8'],
                                'Amana'               => ['bg' => '#E5F5ED', 'color' => '#5b9174'],
                                'Amana Repayment'     => ['bg' => '#ECFDF5', 'color' => '#047857'],
                                'Opérations internes' => ['bg' => '#F3F4F6', 'color' => '#4B5563'],
                                'Autres'              => ['bg' => '#F3F4F6', 'color' => '#374151'],
                            ];
                        @endphp

                        @foreach($grouped as $i => $row)
                            @php
                                // Pourcentages calculés sur le total réel de la période
                                $pctVolume = $totalVolume > 0 ? round(($row['volume'] / $totalVolume) * 100, 1) : 0;
                                $pctValeur = $totalValeur > 0 ? round(($row['valeur'] / $totalValeur) * 100, 1) : 0;
                                $c         = $colors[$row['groupe']] ?? ['bg' => '#f3f4f6', 'color' => '#374151'];
                            @endphp
                            <tr style="border-bottom:1px solid #f3f4f6;"
                                onmouseover="this.style.background='#F7F8FC'"
                                onmouseout="this.style.background='transparent'">

                                <td style="padding:10px 14px; color:#9ca3af;">{{ $i + 1 }}</td>

                                <td style="padding:10px 14px;">
                                    <span style="background:{{ $c['bg'] }}; color:{{ $c['color'] }}; font-size:11px; font-weight:700; padding:4px 12px; border-radius:12px;"
                                          @isset($row['types']) title="{{ $row['types'] }}" @endisset>
                                        {{ $row['groupe'] }}
                                    </span>
                                </td>

                                <td style="padding:10px 14px; text-align:right; font-weight:600; color:#111827;">
                                    {{ number_format($row['volume'], 0, ',', ' ') }}
                                </td>

                                <td style="padding:10px 14px; text-align:right;">
                                    <div style="display:flex; align-items:center; justify-content:flex-end; gap:6px;">
                                        <div style="width:60px; background:#f3f4f6; border-radius:3px; height:5px; overflow:hidden;">
                                            <div style="height:100%; background:{{ $c['color'] }}; border-radius:3px; width:{{ $pctVolume }}%;"></div>
                                        </div>
                                        <span style="font-size:11px; color:#6b7280; min-width:32px; text-align:right;">{{ $pctVolume }}%</span>
                                    </div>
                                </td>

                                <td style="padding:10px 14px; text-align:right; color:#374151;">
                                    {{ number_format($row['valeur'], 0, ',', ' ') }}
                                </td>

                                <td style="padding:10px 14px; text-align:right;">
                                    <div style="display:flex; align-items:center; justify-content:flex-end; gap:6px;">
                                        <div style="width:60px; background:#f3f4f6; border-radius:3px; height:5px; overflow:hidden;">
                                            <div style="height:100%; background:{{ $c['color'] }}; border-radius:3px; width:{{ $pctValeur }}%;"></div>
                                        </div>
                                        <span style="font-size:11px; color:#6b7280; min-width:32px; text-align:right;">{{ $pctValeur }}%</span>
                                    </div>
                                </td>

                                <td style="padding:10px 14px; text-align:right; font-weight:600; color:#16a34a;">
                                    {{ number_format($row['revenus'], 0, ',', ' ') }}
                                </td>
                            </tr>
                        @endforeach

                        {{-- Ligne totaux : identique aux cartes du haut (Opérations internes + Autres inclus) --}}
                        <tr style="background:#F7F8FC; border-top:2px solid #e5e7eb;">
                            <td colspan="2" style="padding:10px 14px; font-weight:700; color:#111827;">Total</td>
                            <td style="padding:10px 14px; text-align:right; font-weight:700; color:#111827;">{{ number_format($totalVolume, 0, ',', ' ') }}</td>
                            <td style="padding:10px 14px; text-align:right; font-size:11px; color:#6b7280;">100%</td>
                            <td style="padding:10px 14px; text-align:right; font-weight:700; color:#111827;">{{ number_format($totalValeur, 0, ',', ' ') }}</td>
                            <td style="padding:10px 14px; text-align:right; font-size:11px; color:#6b7280;">100%</td>
                            <td style="padding:10px 14px; text-align:right; font-weight:700; color:#16a34a;">{{ number_format($totalRevenu, 0, ',', ' ') }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        {{-- TABLEAU DÉTAILLÉ --}}
        <div style="background:#fff; border:1px solid #e5e7eb; border-radius:10px; overflow:hidden;">
            <div style="padding:12px 16px; border-bottom:1px solid #e5e7eb; display:flex; align-items:center; justify-content:space-between;">
                <p style="font-size:13px; font-weight:600; color:#111827; margin:0;">Détail par type de transaction</p>
                <span style="background:#E8ECF8; color:#1B2F6E; font-size:11px; font-weight:600; padding:3px 10px; border-radius:20px;">{{ $periodeLabel }}</span>
            </div>

            <div style="overflow-x:auto; overflow-y:auto; max-height:480px;">
                <table style="width:100%; border-collapse:collapse; font-size:12px;">
                    <thead>
                        <tr style="background:#F7F8FC;">
                            @foreach(['#' => 'left', 'Type de transaction' => 'left', 'Volume' => 'right', '% volume' => 'right', 'Valeur (DJF)' => 'right', '% valeur' => 'right', 'Frais (DJF)' => 'right', 'Commission (DJF)' => 'right', 'Taxe (DJF)' => 'right', 'Revenus nets (DJF)' => 'right'] as $th => $align)
                                <th style="padding:10px 14px; text-align:{{ $align }}; color:#6b7280; font-weight:500; border-bottom:1px solid #e5e7eb; white-space:nowrap; position:sticky; top:0; background:#F7F8FC; z-index:1;"
                                    @if($th === 'Taxe (DJF)') title="La taxe n'est pas fournie par les données sources : elle ne peut pas encore être calculée." @endif>
                                    {{ $th }}
                                </th>
                            @endforeach
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($summary as $i => $row)
                            @php
                                $pctVolume = $totalVolume > 0 ? round(($row['volume'] / $totalVolume) * 100, 1) : 0;
                                $pctValeur = $totalValeur > 0 ? round(($row['valeur'] / $totalValeur) * 100, 1) : 0;
                            @endphp
                            <tr style="border-bottom:1px solid #f3f4f6;"
                                onmouseover="this.style.background='#F7F8FC'"
                                onmouseout="this.style.background='transparent'">
                                <td style="padding:10px 14px; color:#9ca3af;">{{ $i + 1 }}</td>
                                <td style="padding:10px 14px;">
                                    <span style="background:{{ $row['internal'] ? '#F3F4F6' : '#E8ECF8' }}; color:{{ $row['internal'] ? '#4B5563' : '#1B2F6E' }}; font-size:10px; font-weight:600; padding:3px 10px; border-radius:12px;"
                                          @if($row['internal']) title="Opération interne" @endif>
                                        {{ $row['type'] }}
                                    </span>
                                </td>
                                <td style="padding:10px 14px; text-align:right; font-weight:600; color:#111827;">{{ number_format($row['volume'], 0, ',', ' ') }}</td>
                                <td style="padding:10px 14px; text-align:right;">
                                    <div style="display:flex; align-items:center; justify-content:flex-end; gap:6px;">
                                        <div style="width:60px; background:#f3f4f6; border-radius:3px; height:5px; overflow:hidden;">
                                            <div style="height:100%; background:#1B2F6E; border-radius:3px; width:{{ $pctVolume }}%;"></div>
                                        </div>
                                        <span style="font-size:11px; color:#6b7280; min-width:32px; text-align:right;">{{ $pctVolume }}%</span>
                                    </div>
                                </td>
                                <td style="padding:10px 14px; text-align:right; color:#374151;">{{ number_format($row['valeur'], 0, ',', ' ') }}</td>
                                <td style="padding:10px 14px; text-align:right; font-size:11px; color:#6b7280;">{{ $pctValeur }}%</td>
                                <td style="padding:10px 14px; text-align:right; color:#374151;">{{ number_format($row['frais'], 0, ',', ' ') }}</td>
                                <td style="padding:10px 14px; text-align:right; color:#374151;">{{ number_format($row['commission'], 0, ',', ' ') }}</td>
                                <td style="padding:10px 14px; text-align:right; color:#9ca3af;" title="Non disponible dans les données sources">—</td>
                                <td style="padding:10px 14px; text-align:right; font-weight:600; color:#16a34a;">{{ number_format($row['revenus'], 0, ',', ' ') }}</td>
                            </tr>
                        @endforeach

                        {{-- Ligne totaux --}}
                        <tr style="background:#F7F8FC; border-top:2px solid #e5e7eb;">
                            <td colspan="2" style="padding:10px 14px; font-weight:700; color:#111827;">Total</td>
                            <td style="padding:10px 14px; text-align:right; font-weight:700; color:#111827;">{{ number_format($totalVolume, 0, ',', ' ') }}</td>
                            <td style="padding:10px 14px; text-align:right; font-size:11px; color:#6b7280;">100%</td>
                            <td style="padding:10px 14px; text-align:right; font-weight:700; color:#111827;">{{ number_format($totalValeur, 0, ',', ' ') }}</td>
                            <td style="padding:10px 14px; text-align:right; font-size:11px; color:#6b7280;">100%</td>
                            <td style="padding:10px 14px; text-align:right; font-weight:700; color:#111827;">{{ number_format(array_sum(array_column($summary, 'frais')), 0, ',', ' ') }}</td>
                            <td style="padding:10px 14px; text-align:right; font-weight:700; color:#111827;">{{ number_format(array_sum(array_column($summary, 'commission')), 0, ',', ' ') }}</td>
                            <td style="padding:10px 14px; text-align:right; color:#9ca3af;">—</td>
                            <td style="padding:10px 14px; text-align:right; font-weight:700; color:#16a34a;">{{ number_format($totalRevenu, 0, ',', ' ') }}</td>
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
