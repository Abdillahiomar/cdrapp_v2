<?php

namespace App\Console\Commands;

use App\Models\AmlAlert;
use App\Models\AmlRule;
use App\Support\AmlRuleEngine;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Détection quotidienne des alertes AML (règles AL1, AL2… de aml_rules).
 *
 * Pour chaque journée traitée :
 *   1. agrégation de fact_txn_v2 dans aml_party_daily ;
 *   2. évaluation de chaque règle active sur la période qui contient la
 *      journée (jour, semaine dim→sam, mois civil).
 *
 * Une seule alerte par règle / compte / période : si le compte continue de
 * dépasser le seuil dans la semaine ou le mois, l'alerte existante est mise
 * à jour (nb, montant) sans toucher à son statut de traitement.
 *
 * Sans argument, la commande rattrape toutes les journées depuis le dernier
 * traitement réussi jusqu'à hier (31 jours max), pour qu'une nuit où le cron
 * n'a pas tourné ne fasse perdre aucune alerte.
 */
class DetectAmlAlerts extends Command
{
    protected $signature = 'aml:detect
                            {date? : Journée à traiter (YYYY-MM-DD)}
                            {--from= : Début de plage (YYYY-MM-DD)}
                            {--to= : Fin de plage (YYYY-MM-DD), défaut = hier}
                            {--aggregate-only : Construit seulement l\'agrégat (rattrapage d\'historique, ex. comptes dormants)}';

    protected $description = 'Agrège les transactions et génère les alertes AML';

    private const PROCESS_NAME = 'aml_detect';

    private const AGGREGATE_PROCESS_NAME = 'aml_aggregate';

    private const MAX_CATCH_UP_DAYS = 31;

    public function handle(AmlRuleEngine $engine): int
    {
        $dates = $this->datesToProcess();

        if ($dates === null) {
            return self::FAILURE;
        }

        if (empty($dates)) {
            $this->info('Rien à traiter : déjà à jour.');
            return self::SUCCESS;
        }

        $aggregateOnly = (bool) $this->option('aggregate-only');

        foreach ($dates as $date) {
            $this->line('');
            $this->info("══ {$date} ══");

            if (!$this->hasTransactions($date)) {
                // Données pas encore chargées par l'ETL : on s'arrête pour réessayer au prochain passage
                $this->warn("Aucune transaction pour {$date} : arrêt.");
                break;
            }

            try {
                // Suivi séparé pour l'agrégat seul : il ne doit pas compter comme journée analysée
                $process = $aggregateOnly ? self::AGGREGATE_PROCESS_NAME : self::PROCESS_NAME;

                $this->trackRun($date, $process, function () use ($engine, $date, $aggregateOnly) {
                    $rows = $engine->buildDailyAggregate($date);
                    $this->line("  Agrégat : {$rows} lignes");

                    return $aggregateOnly ? $rows : $this->detect($engine, $date);
                });
            } catch (Throwable $e) {
                $this->error("  ✗ {$e->getMessage()}");
                report($e);

                return self::FAILURE;
            }
        }

        AmlAlert::forgetNewCount();

        return self::SUCCESS;
    }

    /**
     * Évalue toutes les règles actives et enregistre les alertes.
     * Retourne le nombre d'alertes nouvelles.
     */
    private function detect(AmlRuleEngine $engine, string $date): int
    {
        $now      = now();
        $newCount = 0;

        foreach (AmlRule::where('enabled', true)->orderBy('id')->get() as $rule) {
            $result = $engine->evaluate($rule, Carbon::parse($date));

            if ($result['skipped']) {
                $this->warn("  {$rule->code} ignorée : {$result['skipped']}");
                continue;
            }

            $values = array_map(fn ($row) => [
                'rule_id'           => $rule->id,
                'party_id'          => $row->party_id,
                'party_type'        => $row->party_type,
                'party_name'        => $row->party_name,
                'period'            => $rule->period,
                'period_start'      => $result['period_start']->toDateString(),
                'period_end'        => $result['period_end']->toDateString(),
                'txn_count'         => $row->txn_count,
                'total_amount'      => $row->total_amount,
                'count_threshold'   => $rule->count_threshold,
                'amount_threshold'  => $rule->amount_threshold,
                'logic'             => $rule->logic,
                'comparison'        => $rule->comparison,
                'severity'          => $rule->severity,
                'status'            => 'new',
                'first_detected_at' => $now,
                'last_detected_at'  => $now,
                'created_at'        => $now,
                'updated_at'        => $now,
            ], $result['rows']);

            foreach (array_chunk($values, 500) as $chunk) {
                DB::table('aml_alerts')->upsert(
                    $chunk,
                    ['rule_id', 'party_type', 'party_id', 'period_start'],
                    [
                        'party_name',
                        // GREATEST : retraiter un jour ancien de la période ne doit pas faire baisser les cumuls
                        'txn_count'        => DB::raw('GREATEST(aml_alerts.txn_count, excluded.txn_count)'),
                        'total_amount'     => DB::raw('GREATEST(aml_alerts.total_amount, excluded.total_amount)'),
                        'last_detected_at',
                        'updated_at',
                    ]
                );
            }

            $created = AmlAlert::where('rule_id', $rule->id)->where('first_detected_at', $now)->count();
            $newCount += $created;

            $this->line(sprintf('  %-5s %4d compte(s) au-dessus du seuil, %d nouvelle(s) alerte(s)', $rule->code, count($values), $created));
        }

        return $newCount;
    }

    /**
     * @return string[]|null  null = arguments invalides
     */
    private function datesToProcess(): ?array
    {
        $yesterday = now()->subDay()->toDateString();

        foreach (['date' => $this->argument('date'), 'from' => $this->option('from'), 'to' => $this->option('to')] as $name => $value) {
            if ($value && !$this->isValidDate($value)) {
                $this->error("{$name} invalide : {$value} (format attendu YYYY-MM-DD)");
                return null;
            }
        }

        if ($this->argument('date')) {
            return [$this->argument('date')];
        }

        if ($this->option('from')) {
            $from = $this->option('from');
            $to   = $this->option('to') ?: $yesterday;
        } else {
            $last = DB::table('fraud_processing_runs')
                ->where('process_name', self::PROCESS_NAME)
                ->where('status', 'SUCCESS')
                ->max('activity_date');

            $from = $last
                ? Carbon::parse($last)->addDay()->toDateString()
                : $yesterday;
            $from = max($from, now()->subDays(self::MAX_CATCH_UP_DAYS)->toDateString());
            $to   = $yesterday;
        }

        $dates = [];
        for ($d = Carbon::parse($from); $d->lte(Carbon::parse($to)); $d->addDay()) {
            $dates[] = $d->toDateString();
        }

        return $dates;
    }

    private function hasTransactions(string $date): bool
    {
        return DB::table('fact_txn_v2')
            ->where('transaction_initiated_time', '>=', $date . ' 00:00:00')
            ->where('transaction_initiated_time', '<', Carbon::parse($date)->addDay()->toDateString() . ' 00:00:00')
            ->exists();
    }

    /**
     * Suivi dans fraud_processing_runs, comme fraud:process-daily.
     */
    private function trackRun(string $date, string $process, callable $callback): void
    {
        $key = ['activity_date' => $date, 'process_name' => $process];

        DB::table('fraud_processing_runs')->updateOrInsert($key, [
            'status'         => 'RUNNING',
            'started_at'     => now(),
            'finished_at'    => null,
            'rows_processed' => 0,
            'error_message'  => null,
            'updated_at'     => now(),
            'created_at'     => now(),
        ]);

        try {
            $rows = $callback();

            DB::table('fraud_processing_runs')->where($key)->update([
                'status'         => 'SUCCESS',
                'finished_at'    => now(),
                'rows_processed' => $rows,
                'updated_at'     => now(),
            ]);

            $this->info($process === self::PROCESS_NAME ? "  ✓ {$rows} nouvelle(s) alerte(s)" : '  ✓ OK');
        } catch (Throwable $e) {
            DB::table('fraud_processing_runs')->where($key)->update([
                'status'        => 'FAILED',
                'finished_at'   => now(),
                'error_message' => $e->getMessage(),
                'updated_at'    => now(),
            ]);

            throw $e;
        }
    }

    private function isValidDate(string $date): bool
    {
        $parsed = \DateTime::createFromFormat('Y-m-d', $date);

        return $parsed && $parsed->format('Y-m-d') === $date;
    }
}
