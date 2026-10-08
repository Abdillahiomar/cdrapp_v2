<?php

namespace App\Console\Commands;

use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * S3 : reconstruction des chaînes
 *
 *   CASH IN → SEND MONEY (1..N) → CASH OUT / W2B
 *
 * Fenêtre hebdomadaire : samedi 00:00:00 (inclus) → samedi suivant 00:00:00 (exclu)
 * Chaque jour appartient donc à une seule semaine (pas de double comptage).
 *
 * Exécution manuelle :
 *   php artisan fraud:chains-weekly 2026-09-19 2026-09-26
 *   → traite du 19/09 00:00:00 au 25/09 23:59:59
 *
 * Exécution automatique (sans argument) : dernière semaine complète.
 *
 * Optimisation : tout est fait en UNE seule requête PostgreSQL
 * (CTE récursif + INSERT des chaînes + INSERT des membres).
 * Aucune donnée ne transite par PHP.
 */
class ProcessFraudChainsWeekly extends Command
{
    protected $signature = 'fraud:chains-weekly
                            {start_date? : Samedi de début (inclus)}
                            {end_date? : Samedi de fin (exclu)}
                            {--max-hops=10 : Nombre maximum de Send Money dans une chaîne}';

    protected $description =
        'Reconstruit les chaînes S3 sur une fenêtre hebdomadaire samedi → samedi';

    /**
     * Nom de la clé primaire de transaction_chains.
     * À adapter si la vérification donne un autre nom.
     */
    private const CHAIN_PK = 'chain_id';

    private const CASH_IN = 'Customer Cash In';

    private const SEND_MONEY = 'Customer Send Money';

    private const TERMINAL_TYPES = [
        'Customer Cash Out',
        'Bank Initiate W2B',
        'BCIMR W2B',
        'BOA W2B',
        'CAC W2B',
        'EAB W2B',
        'SABA W2B',
    ];

    public function handle(): int
    {
        $startedAt = microtime(true);

        /*
        |--------------------------------------------------------------------------
        | Fenêtre
        |--------------------------------------------------------------------------
        */

        $startArg = $this->argument('start_date');
        $endArg   = $this->argument('end_date');

        if ($startArg xor $endArg) {
            $this->error('Donnez les deux dates (début et fin) ou aucune.');
            return self::FAILURE;
        }

        if ($startArg && $endArg) {
            $startDate = Carbon::parse($startArg)->startOfDay();
            $endDate   = Carbon::parse($endArg)->startOfDay(); // exclu
        } else {
            // Dernier samedi 00:00 (aujourd'hui si on est samedi)
            $today   = Carbon::today();
            $endDate = $today->isSaturday()
                ? $today
                : $today->copy()->previous(Carbon::SATURDAY);

            $startDate = $endDate->copy()->subWeek();
        }

        /*
        |--------------------------------------------------------------------------
        | Validation
        |--------------------------------------------------------------------------
        */

        if (!$startDate->isSaturday()) {
            $this->error('La date de début doit être un samedi.');
            return self::FAILURE;
        }

        if (!$endDate->isSaturday()) {
            $this->error('La date de fin doit être un samedi.');
            return self::FAILURE;
        }

        if (!$endDate->greaterThan($startDate)) {
            $this->error('La période est invalide.');
            return self::FAILURE;
        }

        $maxHops = (int) $this->option('max-hops');

        if ($maxHops < 1) {
            $this->error('--max-hops doit être supérieur ou égal à 1.');
            return self::FAILURE;
        }

        /*
        |--------------------------------------------------------------------------
        | Affichage
        |--------------------------------------------------------------------------
        */

        $this->newLine();
        $this->info('==============================================');
        $this->info(' FRAUD S3 - WEEKLY CHAIN PROCESSING');
        $this->info('==============================================');
        $this->info('Début : ' . $startDate->format('Y-m-d H:i:s'));
        $this->info('Fin   : ' . $endDate->copy()->subSecond()->format('Y-m-d H:i:s'));
        $this->info("Max Send Money : {$maxHops}");

        /*
        |--------------------------------------------------------------------------
        | Nombre de Cash In (points de départ) — utilise l'index partiel
        |--------------------------------------------------------------------------
        */

        $cashInCount = DB::table('fraud_transactions')
            ->where('transaction_type', self::CASH_IN)
            ->where('transaction_time', '>=', $startDate)
            ->where('transaction_time', '<', $endDate)
            ->count();

        $this->info("Cash In à analyser : {$cashInCount}");

        if ($cashInCount === 0) {
            $this->warn('Aucun Cash In trouvé pour cette période.');
            return self::SUCCESS;
        }

        /*
        |--------------------------------------------------------------------------
        | S3
        |--------------------------------------------------------------------------
        */

        $stepStartedAt = microtime(true);

        [$chains, $members] = $this->processChains($startDate, $endDate, $maxHops);

        $this->info("✓ S3 terminé : {$chains} chaînes, {$members} membres");
        $this->info('Durée S3 : ' . $this->formatDuration(microtime(true) - $stepStartedAt));

        $this->newLine();
        $this->info('==============================================');
        $this->info(' TRAITEMENT TERMINE');
        $this->info('==============================================');
        $this->info('Durée totale : ' . $this->formatDuration(microtime(true) - $startedAt));

        return self::SUCCESS;
    }

    /**
     * Reconstruction S3 en une seule requête.
     *
     * @return array{0:int,1:int} [nombre de chaînes, nombre de membres]
     */
    private function processChains(Carbon $startDate, Carbon $endDate, int $maxHops): array
    {
        $pk = self::CHAIN_PK;

        $sendMoney = $this->quote(self::SEND_MONEY);
        $cashIn    = $this->quote(self::CASH_IN);
        $terminals = $this->sqlList(self::TERMINAL_TYPES);

        // Même ordre que dans l'index partiel (Send Money + terminaux)
        $relevant = $this->sqlList(
            array_merge([self::SEND_MONEY], self::TERMINAL_TYPES)
        );

        $start        = $startDate->format('Y-m-d H:i:s');
        $end          = $endDate->format('Y-m-d H:i:s');
        $activityDate = $startDate->toDateString();

        return DB::transaction(function () use (
            $pk, $sendMoney, $cashIn, $terminals, $relevant,
            $start, $end, $activityDate, $maxHops
        ) {

            /*
            | Paramètres de session (valables uniquement pour cette transaction)
            | - work_mem : tris / hash en mémoire au lieu du disque
            | - jit off  : la compilation JIT ralentit les CTE récursifs
            */
            DB::statement("SET LOCAL work_mem = '256MB'");
            DB::statement('SET LOCAL jit = off');

            /*
            |--------------------------------------------------------------------------
            | Supprimer les anciennes chaînes de cette fenêtre (membres d'abord)
            |--------------------------------------------------------------------------
            */

            DB::delete(
                "DELETE FROM transaction_chain_members m
                 USING transaction_chains c
                 WHERE m.chain_id = c.{$pk}
                   AND c.activity_date = ?",
                [$activityDate]
            );

            DB::delete(
                'DELETE FROM transaction_chains WHERE activity_date = ?',
                [$activityDate]
            );

            /*
            |--------------------------------------------------------------------------
            | CTE récursif + insertions
            |--------------------------------------------------------------------------
            */

            $sql = <<<SQL
WITH RECURSIVE transaction_graph AS (

    /* 1. ANCHOR : chaque Cash In de la fenêtre */
    SELECT
        c.transaction_id     AS initial_transaction_id,
        c.transaction_id     AS cur_transaction_id,
        c.transaction_time   AS initial_time,
        c.transaction_time   AS cur_time,
        c.debited_msisdn     AS origin_agent_id,
        c.credited_msisdn    AS origin_customer_id,
        c.credited_msisdn    AS cur_account,
        c.amount             AS initial_amount,
        c.amount             AS cur_amount,
        c.commission         AS initial_commission,
        c.transaction_type   AS cur_type,
        0                    AS send_money_hops,
        1                    AS total_transactions,
        jsonb_build_array(jsonb_build_object(
            'transaction_id',   c.transaction_id,
            'transaction_time', c.transaction_time,
            'from_msisdn',      c.debited_msisdn,
            'to_msisdn',        c.credited_msisdn,
            'transaction_type', c.transaction_type,
            'amount',           c.amount,
            'commission',       c.commission
        ))                   AS members
    FROM fraud_transactions c
    WHERE c.transaction_type = {$cashIn}
      AND c.transaction_time >= :start_anchor
      AND c.transaction_time <  :end_anchor
      AND c.debited_msisdn  IS NOT NULL
      AND c.credited_msisdn IS NOT NULL

    UNION ALL

    /* 2. RECURSION : prochaine opération pertinente du compte courant */
    SELECT
        g.initial_transaction_id,
        n.transaction_id,
        g.initial_time,
        n.transaction_time,
        g.origin_agent_id,
        g.origin_customer_id,
        n.credited_msisdn,
        g.initial_amount,
        n.amount,
        g.initial_commission,
        n.transaction_type,
        g.send_money_hops + (n.transaction_type = {$sendMoney})::int,
        g.total_transactions + 1,
        g.members || jsonb_build_object(
            'transaction_id',   n.transaction_id,
            'transaction_time', n.transaction_time,
            'from_msisdn',      n.debited_msisdn,
            'to_msisdn',        n.credited_msisdn,
            'transaction_type', n.transaction_type,
            'amount',           n.amount,
            'commission',       n.commission
        )
    FROM transaction_graph g
    CROSS JOIN LATERAL (
        /* Colonnes explicites → index-only scan possible */
        SELECT
            n.transaction_id,
            n.transaction_time,
            n.debited_msisdn,
            n.credited_msisdn,
            n.transaction_type,
            n.amount,
            n.commission
        FROM fraud_transactions n
        WHERE n.debited_msisdn = g.cur_account
          AND n.transaction_time > g.cur_time   -- strictement après : pas de boucle possible
          AND n.transaction_time < :end_rec
          AND n.transaction_type IN ({$relevant})
        ORDER BY n.transaction_time
        LIMIT 1
    ) n
    WHERE g.cur_type NOT IN ({$terminals})       -- arrêt sur Cash Out / W2B
      AND g.send_money_hops <= {$maxHops}        -- une chaîne à max_hops peut encore se terminer
),

/* 3. Chaînes complètes : au moins 1 Send Money et fin sur Cash Out / W2B */
completed AS MATERIALIZED (
    SELECT DISTINCT ON (initial_transaction_id) *
    FROM transaction_graph
    WHERE cur_type IN ({$terminals})
      AND send_money_hops BETWEEN 1 AND {$maxHops}
    ORDER BY initial_transaction_id, total_transactions DESC
),

/* 4. Insertion des chaînes */
inserted_chains AS (
    INSERT INTO transaction_chains (
        activity_date,
        origin_agent_id,
        origin_customer_id,
        initial_transaction_id,
        final_transaction_id,
        initial_amount,
        final_amount,
        initial_commission,
        send_money_hops,
        total_transactions,
        duration_seconds,
        amount_retention_ratio,
        final_transaction_type,
        created_at
    )
    SELECT
        CAST(:activity_date AS date),
        origin_agent_id,
        origin_customer_id,
        initial_transaction_id,
        cur_transaction_id,
        initial_amount,
        cur_amount,
        initial_commission,
        send_money_hops,
        total_transactions,
        EXTRACT(EPOCH FROM (cur_time - initial_time))::bigint,
        ROUND(cur_amount::numeric / NULLIF(initial_amount::numeric, 0), 4),
        cur_type,
        CAST(:created_at AS timestamp)
    FROM completed
    RETURNING {$pk} AS new_chain_id, initial_transaction_id
),

/* 5. Insertion des membres (types convertis automatiquement via jsonb_populate_record) */
inserted_members AS (
    INSERT INTO transaction_chain_members (
        chain_id,
        sequence_number,
        transaction_id,
        transaction_time,
        from_msisdn,
        to_msisdn,
        transaction_type,
        amount,
        commission
    )
    SELECT
        ic.new_chain_id,
        m.ord,
        r.transaction_id,
        r.transaction_time,
        r.from_msisdn,
        r.to_msisdn,
        r.transaction_type,
        r.amount,
        r.commission
    FROM inserted_chains ic
    JOIN completed c
      ON c.initial_transaction_id = ic.initial_transaction_id
    CROSS JOIN LATERAL jsonb_array_elements(c.members) WITH ORDINALITY AS m(elem, ord)
    CROSS JOIN LATERAL jsonb_populate_record(NULL::transaction_chain_members, m.elem) AS r
    RETURNING 1
)

SELECT
    (SELECT COUNT(*) FROM inserted_chains)  AS chains,
    (SELECT COUNT(*) FROM inserted_members) AS members
SQL;

            $result = DB::selectOne($sql, [
                'start_anchor'  => $start,
                'end_anchor'    => $end,
                'end_rec'       => $end,
                'activity_date' => $activityDate,
                // Heure de l'application, pas NOW() : l'horloge du serveur PostgreSQL n'est pas fiable
                'created_at'    => now()->format('Y-m-d H:i:s'),
            ]);

            return [(int) $result->chains, (int) $result->members];
        });
    }

    private function quote(string $value): string
    {
        return DB::getPdo()->quote($value);
    }

    private function sqlList(array $values): string
    {
        return implode(', ', array_map(fn ($v) => $this->quote($v), $values));
    }

    private function formatDuration(float $seconds): string
    {
        $hours   = (int) floor($seconds / 3600);
        $minutes = (int) floor(fmod($seconds, 3600) / 60);
        $secs    = fmod($seconds, 60);

        return sprintf('%02dh %02dm %06.3fs', $hours, $minutes, $secs);
    }
}