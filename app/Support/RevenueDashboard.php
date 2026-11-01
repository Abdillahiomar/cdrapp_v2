<?php

namespace App\Support;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Données du tableau de bord revenue (/tableau_de_bord_revenue), calculées sur les
 * agrégats de manager:aggregate (manager_daily_stats, manager_hourly_stats,
 * manager_fee_payers_daily) et non sur fact_txn_v2. Montants en FDJ.
 *
 * Revenu d'un type = frais − commissions, sauf Commission Claw Back dont le revenu
 * est le montant de la transaction (frais et commission à 0, reversé au Service Provider).
 * Seules les transactions terminées (Completed) comptent, sauf pour le taux de réussite.
 */
class RevenueDashboard
{
    /** Types exclus du camembert des volumes (mouvements internes de monnaie électronique) */
    private const VOLUME_EXCLUDED = ['E-Money Creation', 'E-Money Destroy', 'Journal Transaction', 'Org Withdrawal of Funds', 'Org Inter-Transfer'];

    /** Familles de types (première règle qui correspond, insensible à la casse) */
    private const FAMILIES = [
        'Airtime'                   => '/top up|airtime/i',
        'Factures & paiements'      => '/bills|bundles|university|merchant payment/i',
        'Banques'                   => '/b2w|w2b|bank initiate/i',
        'Cash in / Cash out'        => '/cash in|cash out/i',
        'Commissions & claw back'   => '/commission/i',
        'Prêts (OD)'                => '/^od|loan/i',
        'Organisations'             => '/^org /i',
        'Transferts'                => '/send money|transfer|bulk|petit cash|sp2/i',
    ];

    private string $from;
    private string $to;
    private string $claw;   // txn_index des Commission Claw Back, pour IN (...)

    public function __construct(string $from, string $to, private int $topLimit = 10)
    {
        $this->from = $from;
        $this->to   = $to;
        $this->claw = implode(',', DB::table('transaction_types')->where('txn_type_name', 'Commission Claw Back')
            ->pluck('txn_index')->map(fn ($v) => (int) $v)->all() ?: [0]);
    }

    public static function lastAggregatedDate(): ?string
    {
        return DB::table('manager_daily_stats')->max('activity_date');
    }

    /** Expression SQL du revenu d'une ligne d'agrégat (alias d) */
    private function revenueSql(): string
    {
        return "CASE WHEN d.txn_index IN ({$this->claw}) THEN d.amount ELSE d.fee - d.commission END";
    }

    public function build(): array
    {
        $byType = $this->byType($this->from, $this->to);

        return [
            'kpi'            => $this->kpi($byType),
            'serie'          => $this->dailySeries(),
            'types'          => $this->volumeShare($byType),
            'revenus_type'   => $this->revenueByType($byType),
            'fee_commission' => $this->feeVsCommission($byType),
            'families'       => $this->families($byType),
            'comparison'     => $this->comparison($byType),
            'avg_per_txn'    => $this->averagePerTransaction($byType),
            'hourly'         => $this->hourly(),
            'cumul'          => $this->monthCumulative(),
            'top_payers'     => $this->topPayers(),
            'last_date'      => self::lastAggregatedDate(),
        ];
    }

    /** Totaux par type sur une période (transactions terminées) */
    private function byType(string $from, string $to): array
    {
        return array_map(fn ($r) => (array) $r, DB::select("
            SELECT tt.txn_type_name AS type,
                   SUM(d.txn_count)          AS nb,
                   SUM(d.amount)             AS volume,
                   SUM(d.fee)                AS fee,
                   SUM(d.commission)         AS commission,
                   SUM({$this->revenueSql()}) AS revenue,
                   bool_or(d.txn_index IN ({$this->claw})) AS is_clawback
              FROM manager_daily_stats d
              JOIN transaction_types tt ON tt.txn_index = d.txn_index
             WHERE d.activity_date BETWEEN ? AND ? AND d.status = 'Completed'
             GROUP BY tt.txn_type_name
        ", [$from, $to]));
    }

    private function kpi(array $byType): array
    {
        $types   = collect($byType);
        $volume  = (float) $types->sum('volume');
        $nb      = (int) $types->sum('nb');
        $claw    = (float) $types->where('is_clawback', true)->sum('volume');
        $revenus = (float) $types->where('is_clawback', false)->sum('revenue');

        // Taux de réussite : terminées / toutes (tous statuts confondus)
        $all = (int) DB::table('manager_daily_stats')->whereBetween('activity_date', [$this->from, $this->to])->sum('txn_count');

        // Période précédente de même durée, juste avant
        $days     = (int) round(Carbon::parse($this->from)->diffInDays(Carbon::parse($this->to), absolute: true)) + 1;
        $prevFrom = Carbon::parse($this->from)->subDays($days)->toDateString();
        $prevTo   = Carbon::parse($this->from)->subDay()->toDateString();
        $prevVol  = (float) DB::table('manager_daily_stats')->whereBetween('activity_date', [$prevFrom, $prevTo])
            ->where('status', 'Completed')->sum('amount');

        return [
            'volume_total'     => $volume,
            'nb_transactions'  => $nb,
            'revenus'          => $revenus,
            'total_clawback'   => $claw,
            'ticket_moyen'     => $nb ? $volume / $nb : 0,
            'taux_reussite'    => $all ? 100 * $nb / $all : 0,
            'volume_precedent' => $prevVol,
            'variation_volume' => $prevVol > 0 ? ($volume - $prevVol) / $prevVol * 100 : null,
        ];
    }

    /** Volume, nombre et revenu (frais, commissions, claw back, net) par jour */
    private function dailySeries(): array
    {
        return array_map(fn ($r) => (array) $r, DB::select("
            SELECT d.activity_date::text AS jour,
                   SUM(d.amount)    AS volume,
                   SUM(d.txn_count) AS nb,
                   SUM(d.fee)       AS fee,
                   SUM(d.commission) AS commission,
                   SUM(CASE WHEN d.txn_index IN ({$this->claw}) THEN d.amount ELSE 0 END) AS clawback,
                   SUM({$this->revenueSql()}) AS net
              FROM manager_daily_stats d
             WHERE d.activity_date BETWEEN ? AND ? AND d.status = 'Completed'
             GROUP BY d.activity_date
             ORDER BY d.activity_date
        ", [$this->from, $this->to]));
    }

    /** Camembert des volumes : 15 premiers types (hors mouvements internes) */
    private function volumeShare(array $byType): array
    {
        return collect($byType)->whereNotIn('type', self::VOLUME_EXCLUDED)
            ->sortByDesc('volume')->take(15)
            ->map(fn ($r) => ['type' => $r['type'], 'volume' => (float) $r['volume']])
            ->values()->all();
    }

    /** Types au revenu non nul, du plus grand au plus petit */
    private function revenueByType(array $byType): array
    {
        return collect($byType)->filter(fn ($r) => round((float) $r['revenue'], 2) != 0)
            ->sortByDesc(fn ($r) => (float) $r['revenue'])
            ->map(fn ($r) => ['type' => $r['type'], 'revenus' => (float) $r['revenue']])
            ->values()->all();
    }

    /** Frais encaissés et commissions versées par type (types avec l'un ou l'autre) */
    private function feeVsCommission(array $byType): array
    {
        return collect($byType)->filter(fn ($r) => (float) $r['fee'] != 0 || (float) $r['commission'] != 0)
            ->sortByDesc(fn ($r) => max((float) $r['fee'], (float) $r['commission']))
            ->map(fn ($r) => ['type' => $r['type'], 'fee' => (float) $r['fee'], 'commission' => (float) $r['commission']])
            ->values()->all();
    }

    public static function family(string $type): string
    {
        foreach (self::FAMILIES as $family => $pattern) {
            if (preg_match($pattern, $type)) {
                return $family;
            }
        }

        return 'Autres';
    }

    /** Revenu net par famille de types */
    private function families(array $byType): array
    {
        return collect($byType)
            ->groupBy(fn ($r) => self::family($r['type']))
            ->map(fn ($rows, $family) => ['family' => $family, 'revenue' => (float) $rows->sum('revenue')])
            ->filter(fn ($r) => round($r['revenue'], 2) != 0)
            ->sortByDesc('revenue')->values()->all();
    }

    /** Revenu par type : période choisie vs période précédente de même durée */
    private function comparison(array $byType): array
    {
        $days     = (int) round(Carbon::parse($this->from)->diffInDays(Carbon::parse($this->to), absolute: true)) + 1;
        $prevFrom = Carbon::parse($this->from)->subDays($days)->toDateString();
        $prevTo   = Carbon::parse($this->from)->subDay()->toDateString();

        $current  = collect($byType)->pluck('revenue', 'type')->map(fn ($v) => (float) $v);
        $previous = collect($this->byType($prevFrom, $prevTo))->pluck('revenue', 'type')->map(fn ($v) => (float) $v);

        return [
            'previous_label' => Carbon::parse($prevFrom)->format('d/m') . ' → ' . Carbon::parse($prevTo)->format('d/m/Y'),
            'rows'           => $current->keys()->merge($previous->keys())->unique()
                ->map(fn ($type) => ['type' => $type, 'current' => $current[$type] ?? 0.0, 'previous' => $previous[$type] ?? 0.0])
                ->filter(fn ($r) => round($r['current'], 2) != 0 || round($r['previous'], 2) != 0)
                ->sortByDesc('current')->values()->all(),
        ];
    }

    /** Revenu moyen par transaction terminée, par type (types au revenu non nul) */
    private function averagePerTransaction(array $byType): array
    {
        return collect($byType)->filter(fn ($r) => (int) $r['nb'] > 0 && round((float) $r['revenue'], 2) != 0)
            ->map(fn ($r) => ['type' => $r['type'], 'avg' => (float) $r['revenue'] / (int) $r['nb'], 'nb' => (int) $r['nb']])
            ->sortByDesc('avg')->values()->all();
    }

    /** Revenu net par heure de la journée (0 → 23), cumulé sur la période */
    private function hourly(): array
    {
        $rows = collect(DB::select("
            SELECT d.hour, SUM({$this->revenueSql()}) AS net, SUM(d.txn_count) AS nb
              FROM manager_hourly_stats d
             WHERE d.activity_date BETWEEN ? AND ? AND d.status = 'Completed'
             GROUP BY d.hour
        ", [$this->from, $this->to]))->keyBy('hour');

        return array_map(fn ($h) => ['hour' => $h, 'net' => (float) ($rows[$h]->net ?? 0), 'nb' => (int) ($rows[$h]->nb ?? 0)], range(0, 23));
    }

    /**
     * Revenu cumulé du mois de la date de fin, et projection en fin de mois au rythme
     * moyen des jours déjà agrégés.
     */
    private function monthCumulative(): array
    {
        $end   = Carbon::parse($this->to);
        $last  = self::lastAggregatedDate();

        // Mois de la date de fin pas encore agrégé (ex. le 1er du mois) : dernier mois agrégé
        if ($last && $last < $end->copy()->startOfMonth()->toDateString()) {
            $end = Carbon::parse($last);
        }

        $start     = $end->copy()->startOfMonth();
        $upTo      = $last && $last < $end->toDateString() ? Carbon::parse($last) : $end;
        $daysInMon = $end->daysInMonth;

        $daily = collect(DB::select("
            SELECT EXTRACT(DAY FROM d.activity_date)::int AS day, SUM({$this->revenueSql()}) AS net
              FROM manager_daily_stats d
             WHERE d.activity_date BETWEEN ? AND ? AND d.status = 'Completed'
             GROUP BY 1
        ", [$start->toDateString(), $upTo->toDateString()]))->pluck('net', 'day');

        $elapsed = $upTo->lt($start) ? 0 : $upTo->day;
        $actual  = [];
        $sum     = 0.0;
        for ($d = 1; $d <= $elapsed; $d++) {
            $sum     += (float) ($daily[$d] ?? 0);
            $actual[] = round($sum, 2);
        }

        $perDay     = $elapsed ? $sum / $elapsed : 0;
        $projection = array_fill(0, $daysInMon, null);
        if ($elapsed) {
            for ($d = $elapsed; $d <= $daysInMon; $d++) {
                $projection[$d - 1] = round($sum + $perDay * ($d - $elapsed), 2);
            }
        }

        return [
            'month'      => ucfirst($start->locale('fr')->isoFormat('MMMM YYYY')),
            'labels'     => range(1, $daysInMon),
            'actual'     => $actual,
            'projection' => $projection,
            'total'      => round($sum, 2),
            'projected'  => round($perDay * $daysInMon, 2),
            'elapsed'    => $elapsed,
        ];
    }

    /** Comptes qui ont payé le plus de frais, 10 à 100 (null si la table n'existe pas encore) */
    public function topPayers(): ?array
    {
        if (!Schema::hasTable('manager_fee_payers_daily')) {
            return null;
        }

        return array_map(fn ($r) => (array) $r, DB::select("
            SELECT party_id, MAX(party_type) AS party_type, MAX(party_name) AS party_name,
                   SUM(txn_count) AS nb, SUM(fee) AS fee
              FROM manager_fee_payers_daily
             WHERE activity_date BETWEEN ? AND ?
             GROUP BY party_id
             ORDER BY fee DESC
             LIMIT ?
        ", [$this->from, $this->to, max(10, min(100, $this->topLimit))]));
    }
}
