<?php

namespace App\Support;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Chaînes S3 (Cash In → Send Money → Cash Out / W2B), partagé par l'écran
 * detection_de_fraude et l'export.
 *
 * transaction_chains.activity_date est le samedi de la semaine de calcul, la
 * même pour toutes les chaînes de la semaine. La date réelle de début d'une
 * chaîne est celle de son premier maillon (sequence_number = 1, toujours le
 * Cash In) : on la récupère par jointure sur la clé (chain_id, sequence_number).
 *
 * Montants stockés divisés par 100 (comme fact_txn_v2) : ×100 à l'affichage.
 */
class FraudChains
{
    public const AMOUNT_FACTOR = 100;

    /**
     * Chaînes de la période, avec started_at (heure du Cash In initial).
     * Filtres : date_debut, date_fin (sur activity_date, comme l'écran).
     */
    public static function chains(array $f): Builder
    {
        return DB::table('transaction_chains as c')
            ->join('transaction_chain_members as s', function ($j) {
                $j->on('s.chain_id', '=', 'c.chain_id')->where('s.sequence_number', '=', 1);
            })
            ->whereBetween('c.activity_date', [$f['date_debut'], $f['date_fin']])
            ->select('c.*', 's.transaction_time as started_at');
    }

    /**
     * Maillons des chaînes de la période, avec les informations de leur chaîne
     * (une ligne par transaction : utilisé pour l'onglet Transactions et le CSV à plat).
     */
    public static function members(array $f): Builder
    {
        return DB::table('transaction_chain_members as m')
            ->join('transaction_chains as c', 'c.chain_id', '=', 'm.chain_id')
            ->join('transaction_chain_members as s', function ($j) {
                $j->on('s.chain_id', '=', 'c.chain_id')->where('s.sequence_number', '=', 1);
            })
            ->whereBetween('c.activity_date', [$f['date_debut'], $f['date_fin']])
            ->select(
                'm.*',
                'c.activity_date', 'c.origin_agent_id', 'c.origin_customer_id',
                'c.initial_amount', 'c.final_amount', 'c.send_money_hops', 'c.total_transactions',
                'c.duration_seconds', 'c.final_transaction_type',
                's.transaction_time as started_at'
            );
    }

    public static function amount($value): float
    {
        return round((float) $value * self::AMOUNT_FACTOR, 2);
    }

    /** 3725 → « 1h02m », 95 → « 1m35s ». */
    public static function duration(?int $seconds): string
    {
        if ($seconds === null) {
            return '';
        }

        $h = intdiv($seconds, 3600);
        $m = intdiv($seconds % 3600, 60);
        $s = $seconds % 60;

        if ($h > 0) return sprintf('%dh%02dm', $h, $m);
        if ($m > 0) return sprintf('%dm%02ds', $m, $s);

        return "{$s}s";
    }
}
