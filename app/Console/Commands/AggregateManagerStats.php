<?php

namespace App\Console\Commands;

use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Alimente les agrégats du tableau de bord direction.
 *
 * Par journée : fact_txn_v2 → manager_hourly_stats (heure × type × reason ×
 * statut), puis manager_hourly_stats → manager_daily_stats. Idempotent : une
 * journée recalculée remplace l'ancienne.
 *
 * Montants convertis en FDJ ici (fact_txn_v2 les stocke divisés par 100).
 *
 * Sans option : recalcule depuis le dernier jour agrégé (qui pouvait être
 * incomplet) jusqu'au dernier jour présent dans fact_txn_v2.
 */
class AggregateManagerStats extends Command
{
    protected $signature = 'manager:aggregate
                            {--date= : Un seul jour (YYYY-MM-DD)}
                            {--from= : Début de plage (YYYY-MM-DD)}
                            {--to= : Fin de plage incluse (YYYY-MM-DD), défaut = dernier jour chargé}
                            {--all : Tout recalculer depuis la première transaction}';

    protected $description = 'Agrège les transactions par heure et par jour pour le tableau de bord direction';

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
            $from = DB::table('manager_daily_stats')->max('activity_date');
            $to   = $lastLoaded;

            if (!$from) {
                $this->error('Les tables sont vides : lancez d\'abord le chargement initial avec --all.');
                return self::FAILURE;
            }
        }

        foreach (['from' => $from, 'to' => $to] as $name => $value) {
            if (!$this->isValidDate((string) $value)) {
                $this->error("Date {$name} invalide : {$value} (format attendu YYYY-MM-DD)");
                return self::FAILURE;
            }
        }

        $start = Carbon::parse($from);
        $end   = Carbon::parse($to);

        if ($start->gt($end)) {
            $this->error("La date de début ({$from}) est après la date de fin ({$to}).");
            return self::FAILURE;
        }

        $days = (int) round($start->diffInDays($end, absolute: true)) + 1;
        $this->info("Agrégation du {$start->toDateString()} au {$end->toDateString()} ({$days} jour(s))");

        $bar = $this->output->createProgressBar($days);
        $bar->start();

        for ($d = $start->copy(); $d->lte($end); $d->addDay()) {
            $this->aggregateDay($d->toDateString());
            $bar->advance();
        }

        $bar->finish();
        $this->newLine();
        $this->info('✓ Terminé.');

        return self::SUCCESS;
    }

    private function aggregateDay(string $date): void
    {
        $start = $date . ' 00:00:00';
        $end   = Carbon::parse($date)->addDay()->toDateString() . ' 00:00:00';
        $f     = self::AMOUNT_FACTOR;

        DB::transaction(function () use ($date, $start, $end, $f) {
            DB::delete('DELETE FROM manager_hourly_stats WHERE activity_date = ?', [$date]);
            DB::delete('DELETE FROM manager_daily_stats WHERE activity_date = ?', [$date]);

            DB::insert("
                INSERT INTO manager_hourly_stats
                    (activity_date, hour, txn_index, reason_index, status, txn_count, amount, fee, commission)
                SELECT ?::date,
                       EXTRACT(HOUR FROM transaction_initiated_time)::int,
                       txn_index,
                       reason_index,
                       COALESCE(status, 'Inconnu'),
                       COUNT(*),
                       COALESCE(SUM(actual_amount), 0) * {$f},
                       COALESCE(SUM(charge_amount), 0) * {$f},
                       COALESCE(SUM(commission_amount), 0) * {$f}
                FROM fact_txn_v2
                WHERE transaction_initiated_time >= ? AND transaction_initiated_time < ?
                  AND txn_index IS NOT NULL
                GROUP BY 2, 3, 4, 5
            ", [$date, $start, $end]);

            // Le journalier se déduit de l'horaire : pas de second scan de fact_txn_v2
            DB::insert('
                INSERT INTO manager_daily_stats
                    (activity_date, txn_index, reason_index, status, txn_count, amount, fee, commission)
                SELECT activity_date, txn_index, reason_index, status,
                       SUM(txn_count), SUM(amount), SUM(fee), SUM(commission)
                FROM manager_hourly_stats
                WHERE activity_date = ?
                GROUP BY activity_date, txn_index, reason_index, status
            ', [$date]);
        });
    }

    private function isValidDate(string $date): bool
    {
        $parsed = \DateTime::createFromFormat('Y-m-d', $date);

        return $parsed && $parsed->format('Y-m-d') === $date;
    }
}
