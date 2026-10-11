<?php

namespace App\Support;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Requêtes des sections « Commission indue » et « Structuring » de
 * detection_de_fraude, partagées par l'écran et l'export (mêmes lignes,
 * même ordre). Montants stockés divisés par 100 : ×100 à l'affichage.
 */
class FraudReports
{
    /**
     * Couples agent/client avec au moins min_cashin Cash In sur la période.
     * Filtres : date_debut, date_fin, min_cashin.
     */
    public static function commission(array $f): Builder
    {
        return DB::table('fraud_transactions')
            ->select('debited_msisdn as agent_id', 'credited_msisdn as customer_id')
            ->selectRaw('COUNT(*) as nb_cashin')
            ->selectRaw('COALESCE(SUM(amount), 0) as total_amount')
            ->selectRaw('COALESCE(SUM(commission), 0) as total_commission')
            ->where('transaction_type', 'Customer Cash In')
            ->whereBetween('transaction_time', [$f['date_debut'] . ' 00:00:00', $f['date_fin'] . ' 23:59:59'])
            ->groupBy('debited_msisdn', 'credited_msisdn')
            ->havingRaw('COUNT(*) >= ?', [(int) $f['min_cashin']])
            ->orderByDesc('total_commission')
            // Départage stable (nécessaire à l'export par lots)
            ->orderBy('debited_msisdn')
            ->orderBy('credited_msisdn');
    }

    /**
     * Clients alimentés par au moins min_agents agents distincts dans une journée.
     * Filtres : date_debut, date_fin, min_agents.
     */
    public static function structuring(array $f): Builder
    {
        return DB::table('customer_multi_agent_daily')
            ->whereBetween('activity_date', [$f['date_debut'], $f['date_fin']])
            ->where('unique_agents', '>=', (int) $f['min_agents'])
            ->orderByDesc('unique_agents')
            ->orderByDesc('total_cashin_amount')
            ->orderBy('activity_date')
            ->orderBy('customer_id');
    }

    /**
     * Détail par agent de chaque ligne de structuring : les agents qui ont fait
     * un Cash In au client CE JOUR-LÀ (une ligne de structuring = un client × un jour).
     */
    public static function structuringDetail(array $f): Builder
    {
        return DB::table('customer_multi_agent_daily as s')
            ->join('agent_customer_daily_activity as a', function ($j) {
                $j->on('a.customer_id', '=', 's.customer_id')->on('a.activity_date', '=', 's.activity_date');
            })
            ->whereBetween('s.activity_date', [$f['date_debut'], $f['date_fin']])
            ->where('s.unique_agents', '>=', (int) $f['min_agents'])
            ->select(
                's.activity_date', 's.customer_id', 's.unique_agents', 's.total_cashin_count', 's.total_cashin_amount',
                'a.agent_id', 'a.cashin_count', 'a.cashin_amount'
            )
            ->orderByDesc('s.unique_agents')
            ->orderByDesc('s.total_cashin_amount')
            ->orderBy('s.activity_date')
            ->orderBy('s.customer_id')
            ->orderByDesc('a.cashin_amount')
            ->orderBy('a.agent_id');
    }
}
