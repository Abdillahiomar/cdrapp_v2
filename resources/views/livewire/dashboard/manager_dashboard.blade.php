<?php

use App\Models\DashboardAggregation;
use Livewire\Volt\Component;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

new class extends Component {

    public string $periode   = 'last_day';   // last_day | 7j | 30j | mois
    public ?string $dernierJour = null;

    public function mount(): void
    {
        // Dernier jour réellement présent dans les agrégats
        $this->dernierJour = DashboardAggregation::max('jour');
    }

    /**
     * Renvoie [début, fin] de la période courante ET de la période
     * précédente (même durée, juste avant), pour calculer les variations.
     */
    private function bornes(): array
    {
        $ref = $this->dernierJour
            ? Carbon::parse($this->dernierJour)
            : Carbon::yesterday();

        return match ($this->periode) {
            '7j' => [
                $ref->copy()->subDays(6)->toDateString(), $ref->toDateString(),
                $ref->copy()->subDays(13)->toDateString(), $ref->copy()->subDays(7)->toDateString(),
            ],
            '30j' => [
                $ref->copy()->subDays(29)->toDateString(), $ref->toDateString(),
                $ref->copy()->subDays(59)->toDateString(), $ref->copy()->subDays(30)->toDateString(),
            ],
            'mois' => [
                $ref->copy()->startOfMonth()->toDateString(), $ref->toDateString(),
                $ref->copy()->subMonthNoOverflow()->startOfMonth()->toDateString(),
                $ref->copy()->subMonthNoOverflow()->endOfMonth()->toDateString(),
            ],
            default => [ // last_day : le jour + le jour d'avant
                $ref->toDateString(), $ref->toDateString(),
                $ref->copy()->subDay()->toDateString(), $ref->copy()->subDay()->toDateString(),
            ],
        };
    }

    /** Agrège les 4 mesures clés sur une plage de dates. */
    private function mesures(string $debut, string $fin): array
    {
        $row = DashboardAggregation::query()
            ->whereBetween('jour', [$debut, $fin])
            ->selectRaw("
                COALESCE(SUM(nb_transactions), 0)                                              AS total_txn,
                COALESCE(SUM(CASE WHEN trans_status = 'Completed' THEN volume_total END), 0)    AS volume,
                COALESCE(SUM(CASE WHEN trans_status = 'Completed' THEN nb_transactions END), 0) AS nb_completed,
                COALESCE(SUM(revenus), 0)                                                       AS revenus
            ")
            ->first();

        $total     = (int) $row->total_txn;
        $completed = (int) $row->nb_completed;
        $taux      = $total > 0 ? round($completed / $total * 100, 1) : 0.0;

        return [
            'volume'   => (float) $row->volume,
            'total'    => $total,
            'taux'     => $taux,
            'revenus'  => (float) $row->revenus,
        ];
    }

    /** Variation % entre deux valeurs. */
    private function variation(float $actuel, float $precedent): ?float
    {
        if ($precedent == 0.0) {
            return $actuel == 0.0 ? 0.0 : null; // null = pas de base de comparaison
        }
        return round(($actuel - $precedent) / abs($precedent) * 100, 1);
    }

    /** Sparkline : volume Completed par jour sur la période courante. */
    private function sparkVolume(string $debut, string $fin): array
    {
        return DashboardAggregation::query()
            ->where('trans_status', 'Completed')
            ->whereBetween('jour', [$debut, $fin])
            ->groupBy('jour')
            ->orderBy('jour')
            ->selectRaw('jour, SUM(volume_total) as v')
            ->pluck('v')
            ->map(fn ($v) => (float) $v)
            ->all();
    }

    private function sparkTxn(string $debut, string $fin): array
    {
        return DashboardAggregation::query()
            ->whereBetween('jour', [$debut, $fin])
            ->groupBy('jour')
            ->orderBy('jour')
            ->selectRaw('jour, SUM(nb_transactions) as v')
            ->pluck('v')
            ->map(fn ($v) => (int) $v)
            ->all();
    }

    /** Séries jour par jour pour le graphique principal. */
    private function seriesGraphique(string $debut, string $fin): array
    {
        // Volume abouti (Completed) par jour
        $volume = DashboardAggregation::query()
            ->where('trans_status', 'Completed')
            ->whereBetween('jour', [$debut, $fin])
            ->groupBy('jour')
            ->orderBy('jour')
            ->selectRaw('jour, SUM(volume_total) as v')
            ->pluck('v', 'jour');

        // Nombre de transactions (tous statuts) par jour
        $txn = DashboardAggregation::query()
            ->whereBetween('jour', [$debut, $fin])
            ->groupBy('jour')
            ->orderBy('jour')
            ->selectRaw('jour, SUM(nb_transactions) as v')
            ->pluck('v', 'jour');

        // Union ordonnée des jours présents (les deux séries alignées)
        $jours = $txn->keys()->merge($volume->keys())->unique()->sort()->values();

        return [
            'jours'  => $jours->all(),
            'volume' => $jours->map(fn ($j) => (float) ($volume[$j] ?? 0))->all(),
            'txn'    => $jours->map(fn ($j) => (int) ($txn[$j] ?? 0))->all(),
        ];
    }

    public function with(): array
    {
        [$debut, $fin, $debutPrec, $finPrec] = $this->bornes();

        $graph = $this->seriesGraphique($debut, $fin);

        $actuel    = $this->mesures($debut, $fin);
        $precedent = $this->mesures($debutPrec, $finPrec);

        return [
            'debut'   => $debut,
            'fin'     => $fin,
            'serieJours'  => $graph['jours'],    // ← ces
            'serieVolume' => $graph['volume'],   // ← trois
            'serieTxn'    => $graph['txn'],      // ← lignes
            'kpi'     => [
                'volume' => [
                    'valeur'    => $actuel['volume'],
                    'variation' => $this->variation($actuel['volume'], $precedent['volume']),
                    'spark'     => $this->sparkVolume($debut, $fin),
                ],
                'total' => [
                    'valeur'    => $actuel['total'],
                    'variation' => $this->variation($actuel['total'], $precedent['total']),
                    'spark'     => $this->sparkTxn($debut, $fin),
                ],
                'taux' => [
                    'valeur'    => $actuel['taux'],
                    'variation' => $this->variation($actuel['taux'], $precedent['taux']),
                ],
                'revenus' => [
                    'valeur'    => $actuel['revenus'],
                    'variation' => $this->variation($actuel['revenus'], $precedent['revenus']),
                ],
                    'serieJours'  => $graph['jours'],
                    'serieVolume' => $graph['volume'],
                    'serieTxn'    => $graph['txn'],
            ],
        ];
    }
};
?>

<div style="padding:24px; background:#F4F6FB; min-height:100vh;">

    {{-- ── BARRE DE CONTRÔLE ── --}}
    <div style="display:flex; align-items:center; justify-content:space-between; flex-wrap:wrap; gap:14px; margin-bottom:22px;">
        <div>
            <h1 style="font-size:20px; font-weight:700; color:#111827; margin:0;">Vue d'ensemble</h1>
            <p style="font-size:12px; color:#6b7280; margin:4px 0 0;">
                Du {{ \Carbon\Carbon::parse($debut)->format('d/m/Y') }}
                @if($debut !== $fin) au {{ \Carbon\Carbon::parse($fin)->format('d/m/Y') }} @endif
            </p>
        </div>

        <div style="display:flex; gap:6px; background:#fff; padding:5px; border-radius:10px; border:1px solid #e5e7eb;">
            @foreach(['last_day' => 'Dernier jour', '7j' => '7 jours', '30j' => '30 jours', 'mois' => 'Ce mois'] as $val => $label)
                <button wire:click="$set('periode', '{{ $val }}')"
                        style="font-size:12px; font-weight:600; padding:7px 14px; border-radius:7px; border:none; cursor:pointer;
                               {{ $periode === $val
                                   ? 'background:#1B2F6E; color:#fff;'
                                   : 'background:transparent; color:#6b7280;' }}">
                    {{ $label }}
                </button>
            @endforeach
        </div>
    </div>

    {{-- ── KPI CARDS ── --}}
    <div style="display:grid; grid-template-columns:repeat(4, minmax(0,1fr)); gap:16px;"
         wire:key="kpis-{{ $periode }}">

        {{-- Volume traité --}}
        @include('livewire.dashboard-partials.kpi-card', [
            'label'     => 'Volume traité',
            'valeur'    => number_format($kpi['volume']['valeur'], 0, ',', ' ') . ' DJF',
            'variation' => $kpi['volume']['variation'],
            'accent'    => '#1B2F6E',
            'spark'     => $kpi['volume']['spark'] ?? [],
            'sparkId'   => 'spark-volume',
            'sparkColor'=> '#1B2F6E',
            'hint'      => 'Transactions abouties',
        ])

        {{-- Transactions --}}
        @include('livewire.dashboard-partials.kpi-card', [
            'label'     => 'Transactions',
            'valeur'    => number_format($kpi['total']['valeur'], 0, ',', ' '),
            'variation' => $kpi['total']['variation'],
            'accent'    => '#378ADD',
            'spark'     => $kpi['total']['spark'] ?? [],
            'sparkId'   => 'spark-txn',
            'sparkColor'=> '#378ADD',
            'hint'      => 'Tous statuts',
        ])

        {{-- Taux de réussite --}}
        @include('livewire.dashboard-partials.kpi-card', [
            'label'     => 'Taux de réussite',
            'valeur'    => number_format($kpi['taux']['valeur'], 1, ',', ' ') . ' %',
            'variation' => $kpi['taux']['variation'],
            'accent'    => '#005C2B',
            'spark'     => [],
            'sparkId'   => 'spark-taux',
            'sparkColor'=> '#005C2B',
            'hint'      => 'Completed / total',
        ])

        {{-- Revenus nets --}}
        @include('livewire.dashboard-partials.kpi-card', [
            'label'     => 'Revenus nets',
            'valeur'    => number_format($kpi['revenus']['valeur'], 0, ',', ' ') . ' DJF',
            'variation' => $kpi['revenus']['variation'],
            'accent'    => '#FFC72C',
            'spark'     => [],
            'sparkId'   => 'spark-revenus',
            'sparkColor'=> '#B8860B',
            'hint'      => 'Commission − frais',
        ])
    </div>

    {{-- Graphique narratif principal (étape 2) --}}
    @include('livewire.dashboard-partials.main-chart', [
        'serieJours'  => $serieJours,
        'serieVolume' => $serieVolume,
        'serieTxn'    => $serieTxn,
        'periode'     => $periode,
    ])

</div>