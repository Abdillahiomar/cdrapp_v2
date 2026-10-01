<?php

namespace App\Support;

use App\Models\AmlAlert;
use App\Models\AmlRule;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Moteur des règles AML.
 *
 * 1. buildDailyAggregate() résume une journée de fact_txn_v2 dans
 *    aml_party_daily : une ligne par compte / type de transaction / sens
 *    (in = le compte est crédité, out = le compte est débité).
 * 2. evaluate() applique une règle sur la période (jour, semaine dim→sam,
 *    mois civil) qui contient la date traitée, en sommant cet agrégat.
 *
 * Les montants de fact_txn_v2 sont stockés divisés par 100 : la conversion
 * en FDJ est faite une seule fois, à l'agrégation. Tout ce qui est en aval
 * (agrégat, seuils des règles, alertes) est en FDJ.
 */
class AmlRuleEngine
{
    public const AMOUNT_FACTOR = 100;

    /** Sans activité depuis ce nombre de jours = compte dormant */
    public const DORMANT_DAYS = 90;

    /**
     * Début et fin de la période d'une règle contenant $date.
     *
     * @return array{0: Carbon, 1: Carbon}
     */
    public static function periodBounds(string $period, CarbonInterface $date): array
    {
        $date = Carbon::parse($date)->startOfDay();

        return match ($period) {
            'week'  => [$date->copy()->startOfWeek(CarbonInterface::SUNDAY), $date->copy()->endOfWeek(CarbonInterface::SATURDAY)->startOfDay()],
            'month' => [$date->copy()->startOfMonth(), $date->copy()->endOfMonth()->startOfDay()],
            default => [$date->copy(), $date->copy()],
        };
    }

    /**
     * Reconstruit l'agrégat d'une journée (idempotent : la journée est
     * supprimée puis réinsérée). Retourne le nombre de lignes créées.
     */
    public function buildDailyAggregate(string $date): int
    {
        $start = $date . ' 00:00:00';
        $end   = Carbon::parse($date)->addDay()->format('Y-m-d') . ' 00:00:00';

        return DB::transaction(function () use ($date, $start, $end) {
            DB::delete('DELETE FROM aml_party_daily WHERE activity_date = ?', [$date]);

            DB::insert("
                INSERT INTO aml_party_daily
                    (activity_date, party_id, party_type, party_name, txn_index, direction, txn_count, total_amount)
                SELECT ?::date, party_id, party_type, MAX(party_name), txn_index, direction,
                       COUNT(*), COALESCE(SUM(amount), 0) * " . self::AMOUNT_FACTOR . "
                FROM (
                    SELECT debit_party_identifier AS party_id, debit_party_type AS party_type,
                           debit_party_name AS party_name, txn_index, 'out' AS direction, actual_amount AS amount
                    FROM fact_txn_v2
                    WHERE transaction_initiated_time >= ? AND transaction_initiated_time < ?
                      AND status = 'Completed'
                      AND debit_party_identifier IS NOT NULL
                      AND debit_party_type IN ('Customer', 'Organization')

                    UNION ALL

                    SELECT credit_party_identifier, credit_party_type,
                           credit_party_name, txn_index, 'in', actual_amount
                    FROM fact_txn_v2
                    WHERE transaction_initiated_time >= ? AND transaction_initiated_time < ?
                      AND status = 'Completed'
                      AND credit_party_identifier IS NOT NULL
                      AND credit_party_type IN ('Customer', 'Organization')
                ) s
                GROUP BY party_id, party_type, txn_index, direction
            ", [$date, $start, $end, $start, $end]);

            return (int) DB::selectOne('SELECT COUNT(*) AS total FROM aml_party_daily WHERE activity_date = ?', [$date])->total;
        });
    }

    /**
     * Comptes qui dépassent les seuils de la règle sur la période contenant $date.
     *
     * @return array{rows: array, skipped: ?string, period_start: Carbon, period_end: Carbon}
     */
    public function evaluate(AmlRule $rule, CarbonInterface $date): array
    {
        [$periodStart, $periodEnd] = self::periodBounds($rule->period, $date);

        $result = ['rows' => [], 'skipped' => null, 'period_start' => $periodStart, 'period_end' => $periodEnd];

        if ($rule->count_threshold === null && $rule->amount_threshold === null) {
            $result['skipped'] = 'aucun seuil défini';
            return $result;
        }

        $where    = ['d.activity_date BETWEEN ? AND ?'];
        // La période en cours n'est évaluée que jusqu'à la date traitée
        $bindings = [$periodStart->toDateString(), Carbon::parse($date)->toDateString()];

        [$flowSql, $flowBindings] = $this->flowsClause($rule->flows ?? [], 'd.txn_index', 'd.direction');
        if ($flowSql) {
            $where[]  = $flowSql;
            $bindings = array_merge($bindings, $flowBindings);
        }

        switch ($rule->population) {
            case 'rds':
                $where[] = "d.party_type = 'Customer'";
                break;

            case 'corporate':
                $where[] = "d.party_type = 'Organization'";
                break;

            case 'ppe':
                $where[] = "d.party_id IN (SELECT msisdn FROM aml_watchlist WHERE actif = true AND list_type = 'ppe')";
                break;

            case 'watchlist':
                $where[] = "d.party_id IN (SELECT msisdn FROM aml_watchlist WHERE actif = true AND list_type IN ('black_list', 'grey_list'))";
                break;

            case 'risk_sector':
                $sectors = array_values(array_filter($rule->sectors ?? []));
                if (empty($sectors)) {
                    $result['skipped'] = 'aucun secteur à risque configuré';
                    return $result;
                }
                $where[]  = "d.party_type = 'Organization'";
                $where[]  = 'd.party_id IN (SELECT short_code::text FROM kyc.kyc_organizations WHERE activity IN (' . $this->placeholders($sectors) . '))';
                $bindings = array_merge($bindings, $sectors);
                break;

            case 'dormant':
                // Il faut DORMANT_DAYS jours d'historique dans l'agrégat pour conclure
                $dormantFrom = $periodStart->copy()->subDays(self::DORMANT_DAYS)->toDateString();
                $oldest      = DB::selectOne('SELECT MIN(activity_date) AS d FROM aml_party_daily')->d;
                if (!$oldest || $oldest > $dormantFrom) {
                    $result['skipped'] = "historique insuffisant (agrégat requis depuis le {$dormantFrom})";
                    return $result;
                }
                $where[]  = "d.party_type = 'Customer'";
                $where[]  = 'NOT EXISTS (SELECT 1 FROM aml_party_daily p WHERE p.party_id = d.party_id AND p.activity_date BETWEEN ? AND ?)';
                $bindings = array_merge($bindings, [$dormantFrom, $periodStart->copy()->subDay()->toDateString()]);
                break;
        }

        $op       = $rule->comparison === 'gte' ? '>=' : '>';
        $having   = [];
        $hBinding = [];

        if ($rule->count_threshold !== null) {
            $having[]   = "SUM(d.txn_count) {$op} ?";
            $hBinding[] = $rule->count_threshold;
        }
        if ($rule->amount_threshold !== null) {
            $having[]   = "SUM(d.total_amount) {$op} ?";
            $hBinding[] = $rule->amount_threshold;
        }

        $sql = '
            SELECT d.party_id, d.party_type, MAX(d.party_name) AS party_name,
                   SUM(d.txn_count) AS txn_count, SUM(d.total_amount) AS total_amount
            FROM aml_party_daily d
            WHERE ' . implode(' AND ', $where) . '
            GROUP BY d.party_id, d.party_type
            HAVING ' . implode($rule->logic === 'and' ? ' AND ' : ' OR ', $having);

        $result['rows'] = DB::select($sql, array_merge($bindings, $hBinding));

        return $result;
    }

    /**
     * Transactions à l'origine d'une alerte (flux de la règle, sur la période).
     */
    public function transactionsForAlert(AmlAlert $alert, int $limit = 200): Collection
    {
        $flows = $alert->rule?->flows ?? [];

        $in  = [];
        $out = [];
        foreach ($flows as $flow) {
            if (in_array($flow['direction'], ['in', 'both'])) {
                $in[] = (int) $flow['txn_index'];
            }
            if (in_array($flow['direction'], ['out', 'both'])) {
                $out[] = (int) $flow['txn_index'];
            }
        }

        $party = $alert->party_id;
        $type  = $alert->party_type;

        return DB::table('fact_txn_v2')
            ->where('transaction_initiated_time', '>=', $alert->period_start->format('Y-m-d') . ' 00:00:00')
            ->where('transaction_initiated_time', '<', $alert->period_end->copy()->addDay()->format('Y-m-d') . ' 00:00:00')
            ->where('status', 'Completed')
            ->where(function ($q) use ($flows, $in, $out, $party, $type) {
                if (empty($flows)) {
                    $q->where(fn ($q) => $q->where('credit_party_identifier', $party)->where('credit_party_type', $type))
                      ->orWhere(fn ($q) => $q->where('debit_party_identifier', $party)->where('debit_party_type', $type));
                    return;
                }
                if ($in) {
                    $q->orWhere(fn ($q) => $q->where('credit_party_identifier', $party)->where('credit_party_type', $type)->whereIn('txn_index', $in));
                }
                if ($out) {
                    $q->orWhere(fn ($q) => $q->where('debit_party_identifier', $party)->where('debit_party_type', $type)->whereIn('txn_index', $out));
                }
            })
            ->orderByDesc('transaction_initiated_time')
            ->limit($limit)
            ->get([
                'transaction_id', 'transaction_initiated_time', 'transaction_type', 'channel',
                'debit_party_identifier', 'debit_party_name', 'credit_party_identifier', 'credit_party_name',
                DB::raw('actual_amount * ' . self::AMOUNT_FACTOR . ' AS amount_fdj'),
            ]);
    }

    /**
     * "(txn_index = ? AND direction IN (...)) OR ..." — liste vide = tous les flux.
     *
     * @return array{0: ?string, 1: array}
     */
    private function flowsClause(array $flows, string $indexCol, string $directionCol): array
    {
        $parts    = [];
        $bindings = [];

        foreach ($flows as $flow) {
            $directions = $flow['direction'] === 'both' ? ['in', 'out'] : [$flow['direction']];
            $parts[]    = "({$indexCol} = ? AND {$directionCol} IN (" . $this->placeholders($directions) . '))';
            $bindings   = array_merge($bindings, [(int) $flow['txn_index']], $directions);
        }

        return $parts ? ['(' . implode(' OR ', $parts) . ')', $bindings] : [null, []];
    }

    private function placeholders(array $values): string
    {
        return implode(', ', array_fill(0, count($values), '?'));
    }
}
