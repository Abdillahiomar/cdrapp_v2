<?php

namespace App\Console\Commands;

use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Activité quotidienne des organisations du réseau de distribution.
 *
 * Par journée : fact_txn_v2 → org_daily_activity (une ligne par organisation),
 * puis mise à jour de org_last_activity (première / dernière journée active).
 * Idempotent : une journée recalculée remplace l'ancienne.
 *
 * Sans option : depuis le dernier jour agrégé (qui pouvait être incomplet)
 * jusqu'au dernier jour présent dans fact_txn_v2.
 */
class AggregateNetworkActivity extends Command
{
    protected $signature = 'network:aggregate
                            {--date= : Un seul jour (YYYY-MM-DD)}
                            {--from= : Début de plage (YYYY-MM-DD)}
                            {--to= : Fin de plage incluse (YYYY-MM-DD), défaut = dernier jour chargé}
                            {--all : Tout recalculer depuis la première transaction}';

    protected $description = 'Agrège l\'activité quotidienne des organisations du réseau de distribution';

    private const AMOUNT_FACTOR = 100;

    public function handle(): int
    {
        $lastLoaded = DB::selectOne('SELECT MAX(transaction_initiated_time)::date AS d FROM fact_txn_v2')->d;

        if (!$lastLoaded) {
            $this->warn('Aucune transaction dans fact_txn_v2.');
            return self::SUCCESS;
        }

        if ($this->option('date')) {
            $from = $to = $this->option('date');
        } elseif ($this->option('all')) {
            $from = DB::selectOne('SELECT MIN(transaction_initiated_time)::date AS d FROM fact_txn_v2')->d;
            $to   = $lastLoaded;
        } elseif ($this->option('from')) {
            $from = $this->option('from');
            $to   = $this->option('to') ?: $lastLoaded;
        } else {
            $from = DB::table('org_daily_activity')->max('activity_date');
            $to   = $lastLoaded;

            if (!$from) {
                $this->error('La table est vide : lancez d\'abord le chargement initial avec --all.');
                return self::FAILURE;
            }
        }

        $start = Carbon::parse($from);
        $end   = Carbon::parse($to);

        if ($start->gt($end)) {
            $this->error("La date de début ({$from}) est après la date de fin ({$to}).");
            return self::FAILURE;
        }

        $types = DB::table('transaction_types')->pluck('txn_index', 'txn_type_name');
        $idx   = fn (array $names) => implode(',', array_map('intval', array_filter(array_map(fn ($n) => $types[$n] ?? null, $names)))) ?: 'NULL';

        $sql = [
            'cashin'     => $idx(['Customer Cash In']),
            'cashout'    => $idx(['Customer Cash Out']),
            'merchant'   => $idx(['Merchant Payment', 'Online Merchant Payment']),
            'commission' => $idx(['Commission Payment']),
        ];

        $days = (int) round($start->diffInDays($end, absolute: true)) + 1;
        $this->info("Activité du réseau du {$start->toDateString()} au {$end->toDateString()} ({$days} jour(s))");

        $bar = $this->output->createProgressBar($days);
        $bar->start();

        for ($d = $start->copy(); $d->lte($end); $d->addDay()) {
            $this->aggregateDay($d->toDateString(), $sql);
            $bar->advance();
        }

        $bar->finish();
        $this->newLine();

        Cache::forget('network_reference_date');
        $this->info('✓ ' . number_format(DB::table('org_last_activity')->count(), 0, ',', ' ') . ' organisations avec une activité connue.');

        return self::SUCCESS;
    }

    private function aggregateDay(string $date, array $idx): void
    {
        $start = $date . ' 00:00:00';
        $end   = Carbon::parse($date)->addDay()->toDateString() . ' 00:00:00';
        $f     = self::AMOUNT_FACTOR;

        DB::transaction(function () use ($date, $start, $end, $f, $idx) {
            DB::delete('DELETE FROM org_daily_activity WHERE activity_date = ?', [$date]);

            // Chaque transaction compte pour l'organisation débitée (out) et/ou créditée (in)
            DB::insert("
                INSERT INTO org_daily_activity
                    (activity_date, org_id, txn_count, txn_amount, cashin_count, cashin_amount, cashout_count, cashout_amount,
                     merchant_count, merchant_amount, commission_amount, last_txn_at)
                SELECT ?::date, org_id,
                       COUNT(*), COALESCE(SUM(amt), 0) * {$f},
                       COUNT(*) FILTER (WHERE dir = 'out' AND txn_index IN ({$idx['cashin']})),
                       COALESCE(SUM(amt) FILTER (WHERE dir = 'out' AND txn_index IN ({$idx['cashin']})), 0) * {$f},
                       COUNT(*) FILTER (WHERE dir = 'in' AND txn_index IN ({$idx['cashout']})),
                       COALESCE(SUM(amt) FILTER (WHERE dir = 'in' AND txn_index IN ({$idx['cashout']})), 0) * {$f},
                       COUNT(*) FILTER (WHERE dir = 'in' AND txn_index IN ({$idx['merchant']})),
                       COALESCE(SUM(amt) FILTER (WHERE dir = 'in' AND txn_index IN ({$idx['merchant']})), 0) * {$f},
                       COALESCE(SUM(amt) FILTER (WHERE dir = 'in' AND txn_index IN ({$idx['commission']})), 0) * {$f},
                       MAX(ts)
                FROM (
                    SELECT debit_party_identifier AS org_id, 'out' AS dir, txn_index, actual_amount AS amt, transaction_initiated_time AS ts
                    FROM fact_txn_v2
                    WHERE transaction_initiated_time >= ? AND transaction_initiated_time < ?
                      AND status = 'Completed' AND debit_party_type = 'Organization' AND debit_party_identifier IS NOT NULL
                    UNION ALL
                    SELECT credit_party_identifier, 'in', txn_index, actual_amount, transaction_initiated_time
                    FROM fact_txn_v2
                    WHERE transaction_initiated_time >= ? AND transaction_initiated_time < ?
                      AND status = 'Completed' AND credit_party_type = 'Organization' AND credit_party_identifier IS NOT NULL
                ) s
                GROUP BY org_id
            ", [$date, $start, $end, $start, $end]);

            DB::statement('
                INSERT INTO org_last_activity (org_id, first_activity_date, last_activity_date, last_txn_at)
                SELECT org_id, activity_date, activity_date, last_txn_at FROM org_daily_activity WHERE activity_date = ?
                ON CONFLICT (org_id) DO UPDATE SET
                    first_activity_date = LEAST(org_last_activity.first_activity_date, EXCLUDED.first_activity_date),
                    last_activity_date  = GREATEST(org_last_activity.last_activity_date, EXCLUDED.last_activity_date),
                    last_txn_at         = GREATEST(org_last_activity.last_txn_at, EXCLUDED.last_txn_at)
            ', [$date]);
        });
    }
}
