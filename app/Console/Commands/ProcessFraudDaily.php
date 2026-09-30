<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

class ProcessFraudDaily extends Command
{
    protected $signature = 'fraud:process-daily
                            {date? : Date à traiter au format YYYY-MM-DD}';

    protected $description = 'Traitement quotidien des données de détection de fraude';

    /**
     * Types considérés comme des sorties finales.
     */
    private array $exitTypes = [
        'Customer Cash Out',

        'Bank Initiate W2B',
        'BCIMR W2B',
        'BOA W2B',
        'CAC W2B',
        'EAB W2B',
        'SABA W2B',
        'Exim W2B',
    ];

    /**
     * Nombre maximum de SEND MONEY dans une chaîne.
     *
     * Cela évite les chaînes infinies ou extrêmement longues.
     */
    private int $maxHops = 10;

    public function handle(): int
    {
        $date = $this->argument('date');
        $startedAt = microtime(true);

        if (!$date) {
            /*
             * Si aucune date n'est fournie,
             * on traite automatiquement hier.
             */
            $date = now()->subDay()->toDateString();
        }

        if (!$this->isValidDate($date)) {
            $this->error("Date invalide : {$date}");
            $this->line('Format attendu : YYYY-MM-DD');

            return self::FAILURE;
        }

        $this->info("==============================================");
        $this->info(" FRAUD DAILY PROCESSING");
        $this->info(" Date : {$date}");
        $this->info("==============================================");

        try {

            /*
             * IMPORTANT :
             *
             * On vérifie d'abord que les données transactionnelles
             * existent pour cette journée.
             */
            $transactionCount = $this->countTransactions($date);

            if ($transactionCount === 0) {
                $this->warn(
                    "Aucune transaction Completed trouvée pour {$date}."
                );

                return self::SUCCESS;
            }

            $this->info(
                "Transactions trouvées : {$transactionCount}"
            );

            /*
             * S1
             */
            $this->runStep(
                $date,
                'agent_customer_daily_activity',
                fn () => $this->processAgentCustomerDaily($date)
            );

            /*
             * S2
             */
            $this->runStep(
                $date,
                'customer_multi_agent_daily',
                fn () => $this->processCustomerMultiAgentDaily($date)
            );

            

            $duration = microtime(true) - $startedAt;

            $this->info("");
            $this->info("==============================================");
            $this->info(" TRAITEMENT TERMINE");
            $this->info(" Date : {$date}");
            $this->info(" Durée : " . $this->formatDuration($duration));
            $this->info("==============================================");

            return self::SUCCESS;

        } catch (Throwable $e) {

            $this->error("");
            $this->error("ERREUR GENERALE");
            $this->error($e->getMessage());

            report($e);

            return self::FAILURE;
        }
    }

    /**
     * Vérifie le format YYYY-MM-DD.
     */
    private function isValidDate(string $date): bool
    {
        $parsed = \DateTime::createFromFormat(
            'Y-m-d',
            $date
        );

        return $parsed
            && $parsed->format('Y-m-d') === $date;
    }

    /**
     * Vérifie qu'il existe des transactions Completed.
     */
    private function countTransactions(string $date): int
    {
        return (int) DB::selectOne(
            "
            SELECT COUNT(*) AS total
            FROM fraud_transactions
            WHERE transaction_type IS NOT NULL
              AND DATE(transaction_time) = ?
            ",
            [$date]
        )->total;
    }

    /**
     * Exécute une étape avec suivi dans fraud_processing_runs.
     */
    private function runStep(
        string $date,
        string $processName,
        callable $callback
        ): void {

        $startedAt = now();

        DB::table('fraud_processing_runs')->updateOrInsert(
            [
                'activity_date' => $date,
                'process_name' => $processName,
            ],
            [
                'status' => 'RUNNING',
                'started_at' => $startedAt,
                'finished_at' => null,
                'rows_processed' => 0,
                'error_message' => null,
                'updated_at' => now(),
                'created_at' => now(),
            ]
        );

        $this->line("");
        $this->info("→ {$processName}");

        try {

            $rows = $callback();

            DB::table('fraud_processing_runs')
                ->where('activity_date', $date)
                ->where('process_name', $processName)
                ->update([
                    'status' => 'SUCCESS',
                    'finished_at' => now(),
                    'rows_processed' => $rows,
                    'updated_at' => now(),
                ]);

            $this->info(
                "  ✓ SUCCESS : {$rows} lignes"
            );

        } catch (Throwable $e) {

            DB::table('fraud_processing_runs')
                ->where('activity_date', $date)
                ->where('process_name', $processName)
                ->update([
                    'status' => 'FAILED',
                    'finished_at' => now(),
                    'error_message' => $e->getMessage(),
                    'updated_at' => now(),
                ]);

            $this->error(
                "  ✗ FAILED : {$e->getMessage()}"
            );

            throw $e;
        }
    }

    /**
     * =========================================================
     * S1
     * =========================================================
     *
     * Agent -> Customer
     *
     * Agrégation quotidienne des CASH IN.
     */
    private function processAgentCustomerDaily(string $date): int
    {
        return DB::transaction(function () use ($date) {

            /*
             * On supprime d'abord les données de cette journée.
             *
             * Pourquoi ?
             *
             * Parce que le traitement devient totalement idempotent.
             * Si on relance la journée, elle est reconstruite proprement.
             */
            DB::statement(
                "
                DELETE FROM agent_customer_daily_activity
                WHERE activity_date = ?
                ",
                [$date]
            );

            DB::statement(
                "
                INSERT INTO agent_customer_daily_activity (
                    activity_date,
                    agent_id,
                    customer_id,
                    cashin_count,
                    cashin_amount,
                    created_at,
                    updated_at
                )
                SELECT
                    DATE(transaction_time) AS activity_date,

                    debited_msisdn AS agent_id,

                    credited_msisdn AS customer_id,

                    COUNT(*) AS cashin_count,

                    COALESCE(SUM(amount), 0) AS cashin_amount,

                    CURRENT_TIMESTAMP,

                    CURRENT_TIMESTAMP

                FROM fraud_transactions

                WHERE transaction_type = 'Customer Cash In'

                  AND DATE(transaction_time) = ?

                  AND debited_msisdn IS NOT NULL
                  AND credited_msisdn IS NOT NULL

                GROUP BY
                    DATE(transaction_time),
                    debited_msisdn,
                    credited_msisdn
                ",
                [$date]
            );

            return (int) DB::selectOne(
                "
                SELECT COUNT(*) AS total
                FROM agent_customer_daily_activity
                WHERE activity_date = ?
                ",
                [$date]
            )->total;
        });
    }

    /**
     * =========================================================
     * S2
     * =========================================================
     *
     * Customer alimenté par plusieurs agents.
     */
    private function processCustomerMultiAgentDaily(string $date): int
    {
        return DB::transaction(function () use ($date) {

            DB::statement(
                "
                DELETE FROM customer_multi_agent_daily
                WHERE activity_date = ?
                ",
                [$date]
            );

            DB::statement(
                "
                INSERT INTO customer_multi_agent_daily (
                    activity_date,
                    customer_id,
                    unique_agents,
                    total_cashin_count,
                    total_cashin_amount,
                    created_at,
                    updated_at
                )
                SELECT
                    activity_date,

                    customer_id,

                    COUNT(DISTINCT agent_id) AS unique_agents,

                    SUM(cashin_count) AS total_cashin_count,

                    COALESCE(SUM(cashin_amount), 0)
                        AS total_cashin_amount,

                    CURRENT_TIMESTAMP,

                    CURRENT_TIMESTAMP

                FROM agent_customer_daily_activity

                WHERE activity_date = ?

                GROUP BY
                    activity_date,
                    customer_id
                ",
                [$date]
            );

            return (int) DB::selectOne(
                "
                SELECT COUNT(*) AS total
                FROM customer_multi_agent_daily
                WHERE activity_date = ?
                ",
                [$date]
            )->total;
        });
    }

    

    /**
     * Ratio :
     *
     * final_amount / initial_amount
     */
    private function calculateRetentionRatio(
        $initialAmount,
        $finalAmount
    ): float {

        $initial = (float) $initialAmount;
        $final = (float) $finalAmount;

        if ($initial <= 0) {
            return 0;
        }

        return round(
            $final / $initial,
            4
        );
    }

    /**
     * PostgreSQL retourne parfois les tableaux complexes
     * sous forme textuelle.
     *
     * Cette méthode est volontairement conservatrice.
     *
     * Pour la première version, on pourra remplacer cette partie
     * par une table temporaire SQL beaucoup plus propre si ton
     * driver PostgreSQL retourne un format différent.
     */
    private function parsePgArrayOfRecords($value): array
    {
        if (is_array($value)) {
            return $value;
        }

        return [];
    }
}