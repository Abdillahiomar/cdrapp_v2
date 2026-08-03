<?php
// app/Services/TransactionKpiService.php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

/**
 * Service central de calcul des KPI Transactions D-Money.
 *
 * SOURCE UNIQUE DE VÉRITÉ : le dashboard Livewire et le générateur PPTX
 * appellent tous deux ce service, garantissant des chiffres identiques
 * à l'écran et dans le PowerPoint exporté.
 *
 * Toutes les méthodes prennent une période (mois) et s'appuient sur la
 * table `usecase_mapping` (txn_index -> use-case du rapport), validée
 * sur les chiffres réels d'Avril 2026.
 */
class TransactionKpiService
{
    /** Statut considéré comme transaction aboutie. */
    private const STATUT_OK = 'Completed';

    /**
     * Bornes [début, débutMoisSuivant] d'un mois donné.
     * @param string $mois Format 'YYYY-MM' (ex: '2026-04')
     */
    private function bornes(string $mois): array
    {
        $debut = Carbon::createFromFormat('Y-m', $mois)->startOfMonth();
        $fin   = (clone $debut)->addMonth();
        return [$debut->toDateTimeString(), $fin->toDateTimeString()];
    }

    /**
     * KPI 1 — Répartition par use-case (slide 16).
     * Retourne volume + valeur par use-case, triés selon l'ordre du rapport.
     */
    public function repartitionParUseCase(string $mois): array
    {
        [$debut, $fin] = $this->bornes($mois);

        $rows = DB::table('fact_txn_v2 as f')
            ->join('usecase_mapping as m', 'm.txn_index', '=', 'f.txn_index')
            ->where('f.status', self::STATUT_OK)
            ->where('f.transaction_initiated_time', '>=', $debut)
            ->where('f.transaction_initiated_time', '<', $fin)
            ->groupBy('m.use_case', 'm.ordre')
            ->orderBy('m.ordre')
            ->selectRaw('m.use_case, m.ordre,
                         count(*) as volume,
                         coalesce(sum(f.actual_amount)*100,0) as valeur')
            ->get();

        return $rows->map(fn($r) => [
            'use_case' => $r->use_case,
            'volume'   => (int) $r->volume,
            'valeur'   => (float) $r->valeur,
        ])->all();
    }

    /**
     * KPI 2 — Frais par canal d'origine (slide 15).
     * Sépare les frais issus de l'Airtime (Top-Up) des frais hors Airtime.
     * L'Airtime correspond aux txn_index du use-case 'Recharge top-up'.
     */
    public function fraisParCanal(string $mois): array
    {
        [$debut, $fin] = $this->bornes($mois);

        // txn_index rattachés à l'Airtime (Recharge top-up)
        $airtimeIdx = DB::table('usecase_mapping')
            ->where('use_case', 'Recharge top-up')
            ->pluck('txn_index')->all();

        $cashinIdx = DB::table('usecase_mapping')
            ->where('use_case', 'Cash In')
            ->pluck('txn_index')->all();

        //dd($cashinIdx);

        $base = DB::table('fact_txn_v2 as f')
            ->where('f.status', self::STATUT_OK)
            ->where('f.transaction_initiated_time', '>=', $debut)
            ->where('f.transaction_initiated_time', '<', $fin);

        $fraisAirtime = (clone $base)
            ->whereIn('f.txn_index', $airtimeIdx)
            ->sum('f.charge_amount')*100;

        $fraisHorsAirtime = (clone $base)
            ->whereNotIn('f.txn_index', $airtimeIdx)
            ->sum('f.charge_amount')*100;

        $commission_cashin = (clone $base)
            ->whereIn('f.txn_index', $cashinIdx)
            ->sum('f.commission_amount')*100;
        //dd($commission_cashin);

        return [
            'frais_hors_airtime' => (float) $fraisHorsAirtime,
            'commission_cashin' => (float) $commission_cashin,
            'frais_airtime'      => (float) $fraisAirtime,
            'total_frais'        => (float) $fraisAirtime + (float) $fraisHorsAirtime,
            'revenue' => (float) $fraisAirtime + (float) $fraisHorsAirtime - (float) $commission_cashin,
        ];
    }

    /**
     * KPI 3 — Série mensuelle du volume (et valeur) pour un use-case donné.
     * Sert aux graphiques d'évolution (slides 17-25).
     *
     * @param string $useCase   nom exact du use-case (ex: 'Cash In')
     * @param string $moisFin   dernier mois 'YYYY-MM'
     * @param int    $nbMois    nombre de mois d'historique (défaut 4)
     */
    public function serieMensuelle(string $useCase, string $moisFin, int $nbMois = 4): array
    {
        $idx = DB::table('usecase_mapping')
            ->where('use_case', $useCase)
            ->pluck('txn_index')->all();

        if (empty($idx)) {
            return [];
        }

        $finMois = Carbon::createFromFormat('Y-m', $moisFin)->startOfMonth();
        $debut   = (clone $finMois)->subMonths($nbMois - 1);
        $fin     = (clone $finMois)->addMonth();

        $rows = DB::table('fact_txn_v2 as f')
            ->whereIn('f.txn_index', $idx)
            ->where('f.status', self::STATUT_OK)
            ->where('f.transaction_initiated_time', '>=', $debut->toDateTimeString())
            ->where('f.transaction_initiated_time', '<', $fin->toDateTimeString())
            ->groupByRaw("to_char(f.transaction_initiated_time, 'YYYY-MM')")
            ->orderByRaw("to_char(f.transaction_initiated_time, 'YYYY-MM')")
            ->selectRaw("to_char(f.transaction_initiated_time, 'YYYY-MM') as mois,
                         count(*) as volume,
                         coalesce(sum(f.actual_amount),0) as valeur")
            ->get()
            ->keyBy('mois');

        // Remplir les mois manquants avec 0
        $serie = [];
        for ($i = 0; $i < $nbMois; $i++) {
            $m = (clone $debut)->addMonths($i)->format('Y-m');
            $r = $rows->get($m);
            $serie[] = [
                'mois'   => $m,
                'volume' => $r ? (int) $r->volume : 0,
                'valeur' => $r ? (float) $r->valeur : 0.0,
            ];
        }
        return $serie;
    }

    /**
     * KPI 4 — Comparaison M vs M-1 (variations en %) pour chaque use-case.
     * Utilisé pour les commentaires "en hausse/baisse de X %" du rapport.
     */
    public function comparaisonM1(string $mois): array
    {
        $moisPrec = Carbon::createFromFormat('Y-m', $mois)->subMonth()->format('Y-m');

        $courant = collect($this->repartitionParUseCase($mois))->keyBy('use_case');
        $precedent = collect($this->repartitionParUseCase($moisPrec))->keyBy('use_case');

        $out = [];
        foreach ($courant as $uc => $c) {
            $p = $precedent->get($uc);
            $volPrec = $p['volume'] ?? 0;
            $valPrec = $p['valeur'] ?? 0.0;
            $out[$uc] = [
                'volume'      => $c['volume'],
                'valeur'      => $c['valeur'],
                'var_volume'  => $volPrec ? round(($c['volume'] - $volPrec) / $volPrec * 100, 1) : null,
                'var_valeur'  => $valPrec ? round(($c['valeur'] - $valPrec) / $valPrec * 100, 1) : null,
            ];
        }
        return $out;
    }

    /**
     * KPI 5 — Synthèse globale du mois (cartes du haut du dashboard).
     */
    public function synthese(string $mois): array
    {
        $frais = $this->fraisParCanal($mois);
        $repartition = $this->repartitionParUseCase($mois);

        $volumeTotal = array_sum(array_column($repartition, 'volume'));
        $valeurTotal = array_sum(array_column($repartition, 'valeur'));

        return [
            'mois'                => $mois,
            'volume_total'        => $volumeTotal,
            'valeur_total'        => $valeurTotal,
            'frais_hors_airtime'  => $frais['frais_hors_airtime'],
            'commission_cashin'  => $frais['commission_cashin'],
            'revenue'  => $frais['revenue'],
            'frais_airtime'       => $frais['frais_airtime'],
            'total_frais'         => $frais['total_frais'],
            'nb_use_cases'        => count($repartition),
        ];
    }

    /**
     * Liste des use-cases connus (pour les menus déroulants du dashboard).
     */
    public function listeUseCases(): array
    {
        return DB::table('usecase_mapping')
            ->distinct()
            ->orderBy('ordre')
            ->pluck('use_case')
            ->all();
    }
}