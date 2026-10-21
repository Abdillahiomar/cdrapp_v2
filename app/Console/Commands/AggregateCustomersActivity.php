<?php

namespace App\Console\Commands;

use App\Support\Customers;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Activité des clients (module Customers) : un agrégat MENSUEL par client
 * (customer_monthly_activity) et sa première / dernière transaction
 * (customer_last_activity).
 *
 * Un mois est toujours recalculé en entier (suppression puis réinsertion) :
 * la commande est idempotente. Sans option, elle recalcule depuis le dernier
 * mois agrégé jusqu'au mois du dernier jour chargé — chaque nuit, le mois en
 * cours est donc remis à jour.
 */
class AggregateCustomersActivity extends Command
{
    protected $signature = 'customers:aggregate
                            {--month= : Un seul mois (YYYY-MM)}
                            {--from= : Premier mois (YYYY-MM)}
                            {--to= : Dernier mois (YYYY-MM), défaut = mois du dernier jour chargé}
                            {--all : Tout recalculer depuis la première transaction}';

    protected $description = 'Agrège l\'activité mensuelle des clients (module Customers)';

    private const AMOUNT_FACTOR = 100;

    public function handle(): int
    {
        $bounds = DB::selectOne('SELECT MIN(transaction_initiated_time)::date AS mi, MAX(transaction_initiated_time)::date AS ma FROM fact_txn_v2');
        if (!$bounds->ma) {
            $this->warn('Aucune transaction dans fact_txn_v2.');
            return self::SUCCESS;
        }

        $parse = fn (string $m) => Carbon::createFromFormat('!Y-m', $m);

        if ($this->option('month')) {
            $from = $to = $parse($this->option('month'));
        } elseif ($this->option('all')) {
            $from = Carbon::parse($bounds->mi)->startOfMonth();
            $to   = Carbon::parse($bounds->ma)->startOfMonth();
        } elseif ($this->option('from')) {
            $from = $parse($this->option('from'));
            $to   = $this->option('to') ? $parse($this->option('to')) : Carbon::parse($bounds->ma)->startOfMonth();
        } else {
            $last = DB::table('customer_monthly_activity')->max('month');
            if (!$last) {
                $this->error('La table est vide : lancez d\'abord le chargement initial avec --all.');
                return self::FAILURE;
            }
            $from = Carbon::parse($last)->startOfMonth();
            $to   = Carbon::parse($bounds->ma)->startOfMonth();
        }

        $types = DB::table('transaction_types')->pluck('txn_index', 'txn_type_name');
        $idx   = fn (array $names) => implode(',', array_map('intval', array_filter(array_map(fn ($n) => $types[$n] ?? null, $names)))) ?: 'NULL';
        $sets  = [
            'cashin'   => $idx(['Customer Cash In']),
            'cashout'  => $idx(['Customer Cash Out']),
            'p2p'      => $idx(['Customer Send Money']),
            'payment'  => $idx(['Merchant Payment', 'Online Merchant Payment', 'Pay DT Bills', 'Pay EDD Bills', 'Pay ONEAD Bills', 'Pay DT Bundles', 'Tax Payment', 'University Fee']),
        ];

        for ($m = $from->copy(); $m->lte($to); $m->addMonth()) {
            $t    = microtime(true);
            $rows = $this->aggregateMonth($m->copy(), $sets);
            $this->line(sprintf('  %s : %s client(s) actif(s) (%.0f s)', $m->format('m/Y'), number_format($rows, 0, ',', ' '), microtime(true) - $t));
        }

        Cache::forget('customers_reference_date');
        $this->info('✓ ' . number_format(DB::table('customer_last_activity')->count(), 0, ',', ' ') . ' clients avec une activité connue.');

        // Prépare le tableau de bord (calcul lourd) pour que personne ne l'attende à l'ouverture
        $t = microtime(true);
        Customers::cachedDashboard(refresh: true);
        $this->info(sprintf('✓ Tableau de bord préparé (%.0f s).', microtime(true) - $t));

        return self::SUCCESS;
    }

    private function aggregateMonth(Carbon $month, array $i): int
    {
        $start = $month->toDateString() . ' 00:00:00';
        $end   = $month->copy()->addMonth()->toDateString() . ' 00:00:00';
        $f     = self::AMOUNT_FACTOR;

        return DB::transaction(function () use ($month, $start, $end, $f, $i) {
            DB::delete('DELETE FROM customer_monthly_activity WHERE month = ?', [$month->toDateString()]);

            DB::insert("
                INSERT INTO customer_monthly_activity
                    (month, msisdn, txn_count, txn_amount, cashin_count, cashin_amount, cashout_count, cashout_amount,
                     p2p_sent_count, p2p_sent_amount, p2p_received_count, p2p_received_amount, payment_count, payment_amount,
                     first_txn_at, last_txn_at)
                SELECT ?::date, msisdn,
                       COUNT(*), COALESCE(SUM(amt), 0) * {$f},
                       COUNT(*) FILTER (WHERE dir = 'in'  AND txn_index IN ({$i['cashin']})),  COALESCE(SUM(amt) FILTER (WHERE dir = 'in'  AND txn_index IN ({$i['cashin']})), 0) * {$f},
                       COUNT(*) FILTER (WHERE dir = 'out' AND txn_index IN ({$i['cashout']})), COALESCE(SUM(amt) FILTER (WHERE dir = 'out' AND txn_index IN ({$i['cashout']})), 0) * {$f},
                       COUNT(*) FILTER (WHERE dir = 'out' AND txn_index IN ({$i['p2p']})),     COALESCE(SUM(amt) FILTER (WHERE dir = 'out' AND txn_index IN ({$i['p2p']})), 0) * {$f},
                       COUNT(*) FILTER (WHERE dir = 'in'  AND txn_index IN ({$i['p2p']})),     COALESCE(SUM(amt) FILTER (WHERE dir = 'in'  AND txn_index IN ({$i['p2p']})), 0) * {$f},
                       COUNT(*) FILTER (WHERE dir = 'out' AND txn_index IN ({$i['payment']})), COALESCE(SUM(amt) FILTER (WHERE dir = 'out' AND txn_index IN ({$i['payment']})), 0) * {$f},
                       MIN(ts), MAX(ts)
                FROM (
                    SELECT debit_party_identifier AS msisdn, 'out' AS dir, txn_index, actual_amount AS amt, transaction_initiated_time AS ts
                    FROM fact_txn_v2
                    WHERE transaction_initiated_time >= ? AND transaction_initiated_time < ?
                      AND status = 'Completed' AND debit_party_type = 'Customer' AND debit_party_identifier IS NOT NULL
                    UNION ALL
                    SELECT credit_party_identifier, 'in', txn_index, actual_amount, transaction_initiated_time
                    FROM fact_txn_v2
                    WHERE transaction_initiated_time >= ? AND transaction_initiated_time < ?
                      AND status = 'Completed' AND credit_party_type = 'Customer' AND credit_party_identifier IS NOT NULL
                ) s
                GROUP BY msisdn
            ", [$month->toDateString(), $start, $end, $start, $end]);

            DB::statement('
                INSERT INTO customer_last_activity (msisdn, first_activity_date, last_activity_date, last_txn_at)
                SELECT msisdn, first_txn_at::date, last_txn_at::date, last_txn_at FROM customer_monthly_activity WHERE month = ?
                ON CONFLICT (msisdn) DO UPDATE SET
                    first_activity_date = LEAST(customer_last_activity.first_activity_date, EXCLUDED.first_activity_date),
                    last_activity_date  = GREATEST(customer_last_activity.last_activity_date, EXCLUDED.last_activity_date),
                    last_txn_at         = GREATEST(customer_last_activity.last_txn_at, EXCLUDED.last_txn_at)
            ', [$month->toDateString()]);

            return (int) DB::selectOne('SELECT COUNT(*) AS n FROM customer_monthly_activity WHERE month = ?', [$month->toDateString()])->n;
        });
    }
}
