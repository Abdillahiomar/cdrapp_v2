<?php

use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Livewire\Volt\Component;

/**
 * Tableau de bord direction.
 *
 * Lit les agrégats manager_hourly_stats / manager_daily_stats (montants déjà
 * en FDJ), alimentés chaque nuit par `php artisan manager:aggregate`.
 *
 * La période de comparaison est la période courante décalée :
 *   - "précédente" : décalage de la durée de la période (n jours, n mois…) ;
 *   - "N-1"        : décalage d'un an.
 * Si la période courante n'est pas terminée (données chargées jusqu'à hier),
 * la comparaison est coupée au même point, pour comparer à durée égale.
 */
new class extends Component {

    public string $txnIndex    = '';
    public string $reasonIndex = '';
    public string $status      = '';
    public string $granularity = 'day';      // hour | day | month | year
    public string $compare     = 'previous'; // previous | yoy

    public string $hourDate  = '';
    public string $dayFrom   = '';
    public string $dayTo     = '';
    public string $monthFrom = '';
    public string $monthTo   = '';
    public string $yearFrom  = '';
    public string $yearTo    = '';

    public ?string $lastDate = null;

    /** Résultat affiché (KPI, séries, tableau) — surveillé côté JS pour redessiner les graphes */
    public array $report = [];

    private const MAX_BUCKETS = ['hour' => 24, 'day' => 366, 'month' => 60, 'year' => 10];

    private const MONTHS = ['janv.', 'févr.', 'mars', 'avr.', 'mai', 'juin', 'juil.', 'août', 'sept.', 'oct.', 'nov.', 'déc.'];

    public function mount(): void
    {
        $this->lastDate = DB::table('manager_daily_stats')->max('activity_date');

        $last = Carbon::parse($this->lastDate ?? Carbon::yesterday());

        $this->hourDate  = $last->toDateString();
        $this->dayFrom   = $last->copy()->subDays(29)->toDateString();
        $this->dayTo     = $last->toDateString();
        $this->monthFrom = $last->copy()->subMonthsNoOverflow(11)->format('Y-m');
        $this->monthTo   = $last->format('Y-m');
        $this->yearFrom  = (string) max((int) $this->firstYear(), $last->year - 2);
        $this->yearTo    = (string) $last->year;

        $this->compute();
    }

    public function updated($property): void
    {
        if ($property === 'txnIndex') {
            $this->reasonIndex = '';
        }

        $this->compute();
    }

    public function setGranularity(string $granularity): void
    {
        $this->granularity = array_key_exists($granularity, self::MAX_BUCKETS) ? $granularity : 'day';
        $this->compute();
    }

    public function setCompare(string $compare): void
    {
        $this->compare = $compare === 'yoy' ? 'yoy' : 'previous';
        $this->compute();
    }

    // ─────────────────────────────── Calcul ───────────────────────────────

    private function compute(): void
    {
        $empty = ['labels' => [], 'cur' => ['count' => [], 'amount' => []], 'cmp' => ['count' => [], 'amount' => []]];

        if (!$this->lastDate) {
            $this->report = $empty + ['error' => 'Aucune donnée agrégée pour le moment : lancez « php artisan manager:aggregate --all » sur le serveur.'];
            return;
        }

        try {
            [$curStart, $curEnd, $count] = $this->currentPeriod();
        } catch (\InvalidArgumentException $e) {
            $this->report = $empty + ['error' => $e->getMessage()];
            return;
        }

        $last   = Carbon::parse($this->lastDate);
        $effEnd = $curEnd->copy()->min($last);            // période en cours : jusqu'au dernier jour chargé
        $shift  = fn (Carbon $d) => $this->shift($d, $count);

        if ($effEnd->lt($curStart)) {
            $this->report = $empty + ['error' => 'Pas encore de données sur cette période (dernier jour chargé : ' . $last->format('d/m/Y') . ').'];
            return;
        }

        $cmpStart  = $shift($curStart);
        $cmpEffEnd = $shift($effEnd);

        $curBuckets = $this->buckets($curStart, $count);
        $cmpBuckets = $this->buckets($cmpStart, $count);

        $curData = $this->series($curStart, $effEnd);
        $cmpData = $this->series($cmpStart, $cmpEffEnd);

        $rows   = [];
        $series = ['labels' => [], 'cur' => ['count' => [], 'amount' => []], 'cmp' => ['count' => [], 'amount' => []]];

        foreach ($curBuckets as $i => $bucket) {
            $cmpBucket = $cmpBuckets[$i];

            // Bucket pas encore disponible (après le dernier jour chargé) : vide, pas zéro
            $curAvailable = $this->granularity === 'hour' || $bucket['start']->lte($effEnd);
            $cmpAvailable = $this->granularity === 'hour' || $cmpBucket['start']->lte($cmpEffEnd);

            $c = $curAvailable ? ($curData[$bucket['key']] ?? ['count' => 0, 'amount' => 0.0]) : null;
            $p = $cmpAvailable ? ($cmpData[$cmpBucket['key']] ?? ['count' => 0, 'amount' => 0.0]) : null;

            $series['labels'][]          = $bucket['label'];
            $series['cur']['count'][]    = $c ? $c['count'] : null;
            $series['cur']['amount'][]   = $c ? round($c['amount'], 2) : null;
            $series['cmp']['count'][]    = $p ? $p['count'] : null;
            $series['cmp']['amount'][]   = $p ? round($p['amount'], 2) : null;

            $rows[] = [
                'label'     => $bucket['label'],
                'cmpLabel'  => $cmpBucket['label'],
                'curCount'  => $c['count'] ?? null,
                'cmpCount'  => $p['count'] ?? null,
                'curAmount' => $c['amount'] ?? null,
                'cmpAmount' => $p['amount'] ?? null,
            ];
        }

        $curTotals = $this->totals($curStart, $effEnd);
        $cmpTotals = $this->totals($cmpStart, $cmpEffEnd);

        $this->report = $series + [
            'error'    => null,
            'curLabel' => $this->periodLabel($curStart, $effEnd),
            'cmpLabel' => $this->periodLabel($cmpStart, $cmpEffEnd),
            'partial'  => $effEnd->lt($curEnd),
            'kpi'      => [
                'count'   => [$curTotals['count'], $cmpTotals['count']],
                'amount'  => [$curTotals['amount'], $cmpTotals['amount']],
                'average' => [$curTotals['average'], $cmpTotals['average']],
                'success' => [$curTotals['success'], $cmpTotals['success']],
            ],
            'rows'     => $rows,
        ];
    }

    /**
     * @return array{0: Carbon, 1: Carbon, 2: int}  début, fin, nombre de points
     */
    private function currentPeriod(): array
    {
        switch ($this->granularity) {
            case 'hour':
                $d = $this->parseDate($this->hourDate, 'la journée');
                return [$d, $d->copy(), 24];

            case 'month':
                $from = $this->parseMonth($this->monthFrom, 'le mois de début');
                $to   = $this->parseMonth($this->monthTo, 'le mois de fin')->endOfMonth()->startOfDay();
                $n    = (int) round($from->diffInMonths($to->copy()->startOfMonth(), absolute: true)) + 1;
                break;

            case 'year':
                if (!ctype_digit($this->yearFrom) || !ctype_digit($this->yearTo)) {
                    throw new \InvalidArgumentException('Choisissez une année de début et une année de fin.');
                }
                $from = Carbon::create((int) $this->yearFrom, 1, 1);
                $to   = Carbon::create((int) $this->yearTo, 12, 31);
                $n    = (int) $this->yearTo - (int) $this->yearFrom + 1;
                break;

            default:
                $from = $this->parseDate($this->dayFrom, 'la date de début');
                $to   = $this->parseDate($this->dayTo, 'la date de fin');
                $n    = (int) round($from->diffInDays($to, absolute: true)) + 1;
        }

        if ($from->gt($to)) {
            throw new \InvalidArgumentException('La date de début doit être avant la date de fin.');
        }

        $max = self::MAX_BUCKETS[$this->granularity];
        if ($n > $max) {
            $unit = ['day' => 'jours', 'month' => 'mois', 'year' => 'années'][$this->granularity];
            throw new \InvalidArgumentException("La période est trop longue ({$n} {$unit}, maximum {$max}). Réduisez-la ou changez de granularité.");
        }

        return [$from, $to, (int) $n];
    }

    /** Décale une date vers la période de comparaison. */
    private function shift(Carbon $d, int $count): Carbon
    {
        $d = $d->copy();

        if ($this->compare === 'yoy') {
            return $d->subYearNoOverflow();
        }

        return match ($this->granularity) {
            'hour'  => $d->subDay(),
            'month' => $d->subMonthsNoOverflow($count),
            'year'  => $d->subYearsNoOverflow($count),
            default => $d->subDays($count),
        };
    }

    /**
     * Points du graphe à partir de $start.
     *
     * @return array<int, array{key: string|int, label: string, start: Carbon}>
     */
    private function buckets(Carbon $start, int $count): array
    {
        $out = [];

        for ($i = 0; $i < $count; $i++) {
            switch ($this->granularity) {
                case 'hour':
                    $out[] = ['key' => $i, 'label' => sprintf('%02dh', $i), 'start' => $start->copy()];
                    break;
                case 'month':
                    $m     = $start->copy()->startOfMonth()->addMonthsNoOverflow($i);
                    $out[] = ['key' => $m->format('Y-m'), 'label' => self::MONTHS[$m->month - 1] . ' ' . $m->year, 'start' => $m];
                    break;
                case 'year':
                    $y     = $start->copy()->startOfYear()->addYears($i);
                    $out[] = ['key' => (string) $y->year, 'label' => (string) $y->year, 'start' => $y];
                    break;
                default:
                    $d     = $start->copy()->addDays($i);
                    $out[] = ['key' => $d->toDateString(), 'label' => $d->format('d/m'), 'start' => $d];
            }
        }

        return $out;
    }

    /**
     * Nombre et valeur par point, sur [$from, $to].
     *
     * @return array<string|int, array{count: int, amount: float}>
     */
    private function series(Carbon $from, Carbon $to): array
    {
        if ($this->granularity === 'hour') {
            $query = $this->filtered('manager_hourly_stats')
                ->where('activity_date', $from->toDateString())
                ->selectRaw('hour AS k, SUM(txn_count) AS c, SUM(amount) AS a')
                ->groupBy('hour');
        } else {
            $key = match ($this->granularity) {
                'month' => "to_char(activity_date, 'YYYY-MM')",
                'year'  => "to_char(activity_date, 'YYYY')",
                default => "to_char(activity_date, 'YYYY-MM-DD')",
            };

            $query = $this->filtered('manager_daily_stats')
                ->whereBetween('activity_date', [$from->toDateString(), $to->toDateString()])
                ->selectRaw("{$key} AS k, SUM(txn_count) AS c, SUM(amount) AS a")
                ->groupByRaw($key);
        }

        return $query->get()
            ->mapWithKeys(fn ($r) => [$r->k => ['count' => (int) $r->c, 'amount' => (float) $r->a]])
            ->all();
    }

    /** Totaux de la période (KPI). */
    private function totals(Carbon $from, Carbon $to): array
    {
        $table = $this->granularity === 'hour' ? 'manager_hourly_stats' : 'manager_daily_stats';

        $r = $this->filtered($table)
            ->whereBetween('activity_date', [$from->toDateString(), $to->toDateString()])
            ->selectRaw("
                COALESCE(SUM(txn_count), 0) AS c,
                COALESCE(SUM(amount), 0) AS a,
                COALESCE(SUM(CASE WHEN status = 'Completed' THEN txn_count END), 0) AS ok
            ")
            ->first();

        $count = (int) $r->c;

        return [
            'count'   => $count,
            'amount'  => (float) $r->a,
            'average' => $count > 0 ? (float) $r->a / $count : 0.0,
            'success' => $count > 0 ? round((int) $r->ok / $count * 100, 1) : null,
        ];
    }

    private function filtered(string $table)
    {
        return DB::table($table)
            ->when($this->txnIndex !== '', fn ($q) => $q->where('txn_index', (int) $this->txnIndex))
            ->when($this->reasonIndex !== '', fn ($q) => $q->where('reason_index', (int) $this->reasonIndex))
            ->when($this->status !== '', fn ($q) => $q->where('status', $this->status));
    }

    private function periodLabel(Carbon $from, Carbon $to): string
    {
        return match ($this->granularity) {
            'hour'  => $from->format('d/m/Y'),
            'month' => self::MONTHS[$from->month - 1] . ' ' . $from->year
                       . ($from->format('Y-m') !== $to->format('Y-m') ? ' → ' . self::MONTHS[$to->month - 1] . ' ' . $to->year : '')
                       . ($to->day !== $to->daysInMonth ? ' (jusqu\'au ' . $to->format('d/m') . ')' : ''),
            'year'  => $from->year . ($from->year !== $to->year ? ' → ' . $to->year : '')
                       . ($to->format('m-d') !== '12-31' ? ' (jusqu\'au ' . $to->format('d/m') . ')' : ''),
            default => $from->format('d/m/Y') . ($from->ne($to) ? ' → ' . $to->format('d/m/Y') : ''),
        };
    }

    private function parseDate(string $value, string $what): Carbon
    {
        $d = \DateTime::createFromFormat('!Y-m-d', $value);
        if (!$d || $d->format('Y-m-d') !== $value) {
            throw new \InvalidArgumentException("Choisissez {$what}.");
        }

        return Carbon::instance($d);
    }

    private function parseMonth(string $value, string $what): Carbon
    {
        $d = \DateTime::createFromFormat('!Y-m', $value);
        if (!$d || $d->format('Y-m') !== $value) {
            throw new \InvalidArgumentException("Choisissez {$what}.");
        }

        return Carbon::instance($d);
    }

    private function firstYear(): string
    {
        return Cache::remember('manager_stats_first_year', 3600, fn () => substr((string) (DB::table('manager_daily_stats')->min('activity_date') ?? now()->toDateString()), 0, 4));
    }

    /**
     * Lignes mises en cache sous forme de tableaux : le cache refuse de
     * restaurer des objets (config cache.serializable_classes = false), une
     * Collection ou un stdClass reviendraient inutilisables.
     */
    private function cachedRows(string $key, \Closure $query): \Illuminate\Support\Collection
    {
        $rows = Cache::remember($key, 3600, fn () => $query()->map(fn ($r) => (array) $r)->all());

        return collect($rows)->map(fn ($r) => (object) $r);
    }

    public function with(): array
    {
        return [
            'types'    => $this->cachedRows('manager_txn_types_rows', fn () => DB::table('transaction_types')
                ->orderBy('txn_type_name')
                ->get(['txn_index', 'txn_type_name'])),
            'reasons'  => $this->cachedRows('manager_reasons_rows_' . ($this->txnIndex ?: 'all'), fn () => DB::table('manager_daily_stats as s')
                ->join('reason_types as r', 'r.reason_index', '=', 's.reason_index')
                ->when($this->txnIndex !== '', fn ($q) => $q->where('s.txn_index', (int) $this->txnIndex))
                ->select('r.reason_index', 'r.reason_name')
                ->distinct()
                ->orderBy('r.reason_name')
                ->get()),
            'statuses' => Cache::remember('manager_status_list', 3600, fn () => DB::table('manager_daily_stats')->distinct()->orderBy('status')->pluck('status')->all()),
            'years'    => range((int) $this->firstYear(), (int) Carbon::parse($this->lastDate ?? now())->year),
        ];
    }
};
?>
<div style="padding:24px; background:#F4F6FB; min-height:100vh;">

@php
    $inputStyle = 'border:1px solid #d1d5db; border-radius:7px; padding:7px 10px; font-size:13px; color:#111827; outline:none; background:#fff;';
    $labelStyle = 'font-size:11px; color:#6b7280; display:block; margin-bottom:4px;';
    $segBtn = fn ($active) => 'font-size:12px; font-weight:600; padding:6px 12px; border-radius:6px; border:none; cursor:pointer; '
        . ($active ? 'background:#1B2F6E; color:#fff;' : 'background:transparent; color:#6b7280;');

    $fmtInt = fn ($v) => $v === null ? '—' : number_format($v, 0, ',', ' ');
    $fmtFdj = fn ($v) => $v === null ? '—' : number_format($v, 0, ',', ' ') . ' FDJ';
    $variation = function ($cur, $prev) {
        if ($cur === null || $prev === null) return null;
        if ((float) $prev == 0.0) return (float) $cur == 0.0 ? 0.0 : null;
        return round(($cur - $prev) / abs($prev) * 100, 1);
    };
    $varBadge = function ($v, $suffix = '%') {
        if ($v === null) return '<span style="color:#9ca3af;">—</span>';
        $up = $v > 0; $flat = $v == 0;
        $color = $flat ? '#6b7280' : ($up ? '#005C2B' : '#B91C1C');
        $arrow = $flat ? '→' : ($up ? '▲' : '▼');
        return '<span style="color:' . $color . '; font-weight:600;">' . $arrow . ' ' . ($up ? '+' : '') . number_format($v, 1, ',', ' ') . $suffix . '</span>';
    };

    $r = $report;
    $hasData = empty($r['error']);
@endphp

    {{-- ── EN-TÊTE ── --}}
    <div style="display:flex; justify-content:space-between; align-items:flex-end; flex-wrap:wrap; gap:12px; margin-bottom:16px;">
        <div>
            <h1 style="font-size:20px; font-weight:700; color:#111827; margin:0;">Tableau de bord direction</h1>
            <p style="font-size:12px; color:#6b7280; margin:4px 0 0;">
                Nombre et valeur des transactions, comparés à une période de référence.
                @if($lastDate) Données jusqu'au {{ \Carbon\Carbon::parse($lastDate)->format('d/m/Y') }}. @endif
            </p>
        </div>
        <div wire:loading style="font-size:12px; color:#1B2F6E; font-weight:600;">Mise à jour…</div>
    </div>

    {{-- ── FILTRES ── --}}
    <div style="background:#fff; border:1px solid #e5e7eb; border-radius:12px; padding:16px; margin-bottom:16px; display:flex; flex-wrap:wrap; gap:14px; align-items:flex-end;">
        <div>
            <label style="{{ $labelStyle }}">Transaction Type</label>
            <select wire:model.live="txnIndex" style="{{ $inputStyle }} width:220px;">
                <option value="">Tous les types</option>
                @foreach($types as $t)
                    <option value="{{ $t->txn_index }}">{{ $t->txn_type_name }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label style="{{ $labelStyle }}">Reason Type</label>
            <select wire:model.live="reasonIndex" style="{{ $inputStyle }} width:240px;">
                <option value="">Toutes les reasons{{ $txnIndex !== '' ? ' de ce type' : '' }}</option>
                @foreach($reasons as $reason)
                    <option value="{{ $reason->reason_index }}">{{ $reason->reason_name }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label style="{{ $labelStyle }}">Statut</label>
            <select wire:model.live="status" style="{{ $inputStyle }} width:150px;">
                <option value="">Tous les statuts</option>
                @foreach($statuses as $s)
                    <option value="{{ $s }}">{{ $s }}</option>
                @endforeach
            </select>
        </div>

        <div>
            <label style="{{ $labelStyle }}">Granularité</label>
            <div style="display:flex; gap:2px; background:#F4F6FB; padding:3px; border-radius:8px; border:1px solid #e5e7eb;">
                @foreach(['hour' => 'Heure', 'day' => 'Jour', 'month' => 'Mois', 'year' => 'Année'] as $key => $label)
                    <button wire:click="setGranularity('{{ $key }}')" style="{{ $segBtn($granularity === $key) }}">{{ $label }}</button>
                @endforeach
            </div>
        </div>

        {{-- Période selon la granularité --}}
        @if($granularity === 'hour')
            <div>
                <label style="{{ $labelStyle }}">Journée</label>
                <input type="date" wire:model.live="hourDate" max="{{ $lastDate }}" style="{{ $inputStyle }}">
            </div>
        @elseif($granularity === 'day')
            <div>
                <label style="{{ $labelStyle }}">Du</label>
                <input type="date" wire:model.live="dayFrom" max="{{ $lastDate }}" style="{{ $inputStyle }}">
            </div>
            <div>
                <label style="{{ $labelStyle }}">Au</label>
                <input type="date" wire:model.live="dayTo" max="{{ $lastDate }}" style="{{ $inputStyle }}">
            </div>
        @elseif($granularity === 'month')
            <div>
                <label style="{{ $labelStyle }}">Du mois</label>
                <input type="month" wire:model.live="monthFrom" style="{{ $inputStyle }}">
            </div>
            <div>
                <label style="{{ $labelStyle }}">Au mois</label>
                <input type="month" wire:model.live="monthTo" style="{{ $inputStyle }}">
            </div>
        @else
            <div>
                <label style="{{ $labelStyle }}">De l'année</label>
                <select wire:model.live="yearFrom" style="{{ $inputStyle }}">
                    @foreach($years as $y) <option value="{{ $y }}">{{ $y }}</option> @endforeach
                </select>
            </div>
            <div>
                <label style="{{ $labelStyle }}">À l'année</label>
                <select wire:model.live="yearTo" style="{{ $inputStyle }}">
                    @foreach($years as $y) <option value="{{ $y }}">{{ $y }}</option> @endforeach
                </select>
            </div>
        @endif

        <div>
            <label style="{{ $labelStyle }}">Comparer à</label>
            <div style="display:flex; gap:2px; background:#F4F6FB; padding:3px; border-radius:8px; border:1px solid #e5e7eb;">
                <button wire:click="setCompare('previous')" style="{{ $segBtn($compare === 'previous') }}">Période précédente</button>
                <button wire:click="setCompare('yoy')" style="{{ $segBtn($compare === 'yoy') }}">Même période N-1</button>
            </div>
        </div>
    </div>

    @if(!$hasData)
        <div style="background:#FFF7E0; border-left:3px solid #F5A800; border-radius:8px; padding:12px 16px; font-size:13px; color:#7A4F00; margin-bottom:16px;">
            {{ $r['error'] }}
        </div>
    @else
        {{-- ── PÉRIODES COMPARÉES ── --}}
        <div style="display:flex; flex-wrap:wrap; gap:18px; font-size:12px; color:#374151; margin-bottom:12px;">
            <span style="display:flex; align-items:center; gap:6px;">
                <span style="width:12px; height:12px; border-radius:3px; background:#2a78d6; display:inline-block;"></span>
                Période analysée : <strong>{{ $r['curLabel'] }}</strong>
            </span>
            <span style="display:flex; align-items:center; gap:6px;">
                <span style="width:14px; border-top:2px dashed #eb6834; display:inline-block;"></span>
                Comparée à : <strong>{{ $r['cmpLabel'] }}</strong>
            </span>
            @if($r['partial'])
                <span style="color:#9ca3af;">Période en cours : la comparaison est coupée au même point pour comparer à durée égale.</span>
            @endif
        </div>

        {{-- ── KPI ── --}}
        @php
            $kpis = [
                ['Nombre de transactions', $fmtInt($r['kpi']['count'][0]),   $fmtInt($r['kpi']['count'][1]),   $variation($r['kpi']['count'][0], $r['kpi']['count'][1]), '%'],
                ['Valeur des transactions', $fmtFdj($r['kpi']['amount'][0]), $fmtFdj($r['kpi']['amount'][1]),  $variation($r['kpi']['amount'][0], $r['kpi']['amount'][1]), '%'],
                ['Valeur moyenne',          $fmtFdj($r['kpi']['average'][0]), $fmtFdj($r['kpi']['average'][1]), $variation($r['kpi']['average'][0], $r['kpi']['average'][1]), '%'],
            ];
            if ($status === '') {
                $s0 = $r['kpi']['success'][0]; $s1 = $r['kpi']['success'][1];
                $kpis[] = ['Taux de réussite', $s0 === null ? '—' : number_format($s0, 1, ',', ' ') . ' %', $s1 === null ? '—' : number_format($s1, 1, ',', ' ') . ' %',
                           ($s0 !== null && $s1 !== null) ? round($s0 - $s1, 1) : null, ' pt'];
            }
        @endphp
        <div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(210px, 1fr)); gap:14px; margin-bottom:16px;">
            @foreach($kpis as [$label, $value, $prev, $var, $suffix])
                <div style="background:#fff; border:1px solid #e5e7eb; border-radius:12px; padding:16px;">
                    <p style="font-size:11px; color:#6b7280; margin:0 0 8px; text-transform:uppercase; letter-spacing:.4px;">{{ $label }}</p>
                    <p style="font-size:22px; font-weight:700; color:#111827; margin:0 0 6px;">{{ $value }}</p>
                    <p style="font-size:12px; margin:0;">
                        {!! $varBadge($var, $suffix) !!}
                        <span style="color:#9ca3af;"> vs {{ $prev }}</span>
                    </p>
                </div>
            @endforeach
        </div>
    @endif

    {{-- ── GRAPHIQUES (toujours présents dans le DOM : redessinés par le script) ── --}}
    <div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(440px, 1fr)); gap:16px; margin-bottom:16px; {{ $hasData ? '' : 'display:none;' }}">
        @foreach(['count' => ['Nombre de transactions', 'mgr-chart-count'], 'amount' => ['Valeur des transactions (FDJ)', 'mgr-chart-amount']] as $key => [$title, $id])
            <div style="background:#fff; border:1px solid #e5e7eb; border-radius:12px; padding:16px 18px;">
                <p style="font-size:13px; font-weight:700; color:#111827; margin:0 0 10px;">{{ $title }}</p>
                <div wire:ignore style="position:relative; height:300px;">
                    <canvas id="{{ $id }}" role="img" aria-label="{{ $title }} : période analysée en barres, période de comparaison en ligne pointillée"></canvas>
                </div>
            </div>
        @endforeach
    </div>

    {{-- ── TABLEAU DÉTAILLÉ ── --}}
    @if($hasData)
        <div style="background:#fff; border:1px solid #e5e7eb; border-radius:12px; overflow:hidden;">
            <div style="padding:12px 16px; border-bottom:1px solid #e5e7eb;">
                <p style="font-size:13px; font-weight:700; color:#111827; margin:0;">Détail par {{ ['hour' => 'heure', 'day' => 'jour', 'month' => 'mois', 'year' => 'année'][$granularity] }}</p>
            </div>
            <div style="overflow:auto; max-height:480px;">
                <table style="width:100%; border-collapse:collapse; font-size:12px;">
                    <thead>
                        <tr style="background:#F7F8FC;">
                            @foreach(['Période', 'Comparée à', 'Nb', 'Nb (comp.)', 'Écart nb', 'Valeur (FDJ)', 'Valeur (comp.)', 'Écart valeur'] as $i => $th)
                                <th style="padding:9px 12px; text-align:{{ $i < 2 ? 'left' : 'right' }}; color:#6b7280; font-weight:500; border-bottom:1px solid #e5e7eb; white-space:nowrap; position:sticky; top:0; background:#F7F8FC;">{{ $th }}</th>
                            @endforeach
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($r['rows'] as $row)
                            <tr style="border-bottom:1px solid #f3f4f6;">
                                <td style="padding:8px 12px; color:#111827; font-weight:600; white-space:nowrap;">{{ $row['label'] }}</td>
                                <td style="padding:8px 12px; color:#9ca3af; white-space:nowrap;">{{ $row['cmpLabel'] }}</td>
                                <td style="padding:8px 12px; text-align:right; color:#111827;">{{ $fmtInt($row['curCount']) }}</td>
                                <td style="padding:8px 12px; text-align:right; color:#6b7280;">{{ $fmtInt($row['cmpCount']) }}</td>
                                <td style="padding:8px 12px; text-align:right;">{!! $varBadge($variation($row['curCount'], $row['cmpCount'])) !!}</td>
                                <td style="padding:8px 12px; text-align:right; color:#111827;">{{ $fmtInt($row['curAmount']) }}</td>
                                <td style="padding:8px 12px; text-align:right; color:#6b7280;">{{ $fmtInt($row['cmpAmount']) }}</td>
                                <td style="padding:8px 12px; text-align:right;">{!! $varBadge($variation($row['curAmount'], $row['cmpAmount'])) !!}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    @endif

{{--
    Chart.js est chargé globalement dans le layout (components/layouts/app).
    Le script doit commencer directement par une déclaration (const) : Alpine,
    qui exécute les blocs @script, ne reconnaît un bloc d'instructions que s'il
    commence par const / let / if. Un commentaire en tête le fait évaluer comme
    une expression → "Unexpected token 'const'".
--}}
@script
<script>
    const COLORS = { current: '#2a78d6', compare: '#eb6834', text: '#52514e', muted: '#6b7280', grid: '#eef0f4' };
    const fmt     = new Intl.NumberFormat('fr-FR', { maximumFractionDigits: 0 });
    const compact = new Intl.NumberFormat('fr-FR', { notation: 'compact', maximumFractionDigits: 1 });
    const charts  = {};

    const specs = [
        { key: 'count',  id: 'mgr-chart-count',  unit: '' },
        { key: 'amount', id: 'mgr-chart-amount', unit: ' FDJ' },
    ];

    function draw(report, attempt = 0) {
        // Chart.js pas encore chargé (navigation wire:navigate) : on réessaie un peu plus tard
        if (typeof Chart === 'undefined') {
            if (attempt < 20) setTimeout(() => draw(report, attempt + 1), 150);
            return;
        }
        if (!report || !report.labels) return;

        // $wire renvoie des proxys réactifs ; Chart.js instrumente les tableaux qu'on lui
        // donne et ne fonctionne pas sur ces proxys : on lui passe une copie simple.
        report = JSON.parse(JSON.stringify(report));

        specs.forEach(({ key, id, unit }) => {
            const canvas = document.getElementById(id);
            if (!canvas) return;

            const data = {
                labels: report.labels,
                datasets: [
                    {
                        type: 'bar',
                        label: 'Période analysée',
                        data: report.cur[key],
                        backgroundColor: COLORS.current,
                        borderRadius: 4,
                        borderSkipped: 'start',
                        maxBarThickness: 28,
                        order: 2,
                    },
                    {
                        type: 'line',
                        label: 'Période de comparaison',
                        data: report.cmp[key],
                        borderColor: COLORS.compare,
                        backgroundColor: COLORS.compare,
                        borderWidth: 2,
                        borderDash: [6, 4],
                        pointRadius: 4,
                        pointHoverRadius: 6,
                        pointBorderColor: '#ffffff',
                        pointBorderWidth: 2,
                        tension: 0.25,
                        spanGaps: false,
                        order: 1,
                    },
                ],
            };

            if (charts[id]) {
                charts[id].data = data;
                charts[id].update();
                return;
            }

            charts[id] = new Chart(canvas, {
                data,
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    interaction: { mode: 'index', intersect: false },
                    plugins: {
                        legend: {
                            position: 'top',
                            align: 'end',
                            labels: { usePointStyle: true, boxWidth: 8, color: COLORS.text, font: { size: 11 } },
                        },
                        tooltip: {
                            callbacks: {
                                label: (c) => ` ${c.dataset.label} : ${c.parsed.y === null ? '—' : fmt.format(c.parsed.y) + unit}`,
                            },
                        },
                    },
                    scales: {
                        x: {
                            grid: { display: false },
                            border: { color: '#e5e7eb' },
                            ticks: { color: COLORS.muted, font: { size: 10 }, maxRotation: 0, autoSkip: true, autoSkipPadding: 8 },
                        },
                        y: {
                            beginAtZero: true,
                            grid: { color: COLORS.grid },
                            border: { display: false },
                            ticks: { color: COLORS.muted, font: { size: 10 }, callback: (v) => compact.format(v) },
                        },
                    },
                },
            });
        });
    }

    draw($wire.report);
    $wire.$watch('report', (report) => draw(report));
</script>
@endscript

</div>
