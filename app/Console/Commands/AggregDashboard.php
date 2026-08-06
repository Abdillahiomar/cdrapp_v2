<?php
// app/Console/Commands/AggregDashboard.php

namespace App\Console\Commands;

use App\Models\DashboardAggregation;
use App\Models\Transaction;
use App\Models\TransactionType;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class AggregDashboard extends Command
{
    protected $signature   = 'dashboard:agreger {--date= : Date Y-m-d (défaut: hier)} {--all : Tout recalculer depuis le début}';
    protected $description = 'Agrège les transactions dans dashboard_aggregations (tous statuts)';

    public function handle(): void
    {
        if ($this->option('all')) {
            $this->aggregerTout();
        } else {
            $date = $this->option('date')
                ? Carbon::parse($this->option('date'))->toDateString()
                : Carbon::yesterday()->toDateString();

            $this->aggregerJour($date);
        }
    }

    private function aggregerJour(string $date): void
    {
        $this->info("Agrégation du {$date}...");

        // Bornes de journée (comparaison directe = index utilisé, pas de cast ::date)
        $debut = $date . ' 00:00:00';
        $fin   = Carbon::parse($date)->addDay()->format('Y-m-d') . ' 00:00:00';

        // Supprime les anciennes lignes du jour pour recalculer proprement
        DashboardAggregation::whereDate('jour', $date)->delete();

        // NOTE : plus de filtre sur le statut — on agrège TOUS les statuts
        // (Completed, Failed, etc.) car le dashboard a besoin des échecs
        // pour calculer taux de réussite, taux d'échec et motifs d'échec.
        $rows = Transaction::query()
            ->join('transaction_types', 'fact_txn_v2.txn_index', '=', 'transaction_types.txn_index')
            ->where('fact_txn_v2.transaction_initiated_time', '>=', $debut)
            ->where('fact_txn_v2.transaction_initiated_time', '<',  $fin)
            ->selectRaw("
                fact_txn_v2.transaction_initiated_time::date  AS jour,
                fact_txn_v2.txn_index                         AS txn_index,
                transaction_types.txn_type_name               AS txn_type_name,
                transaction_types.alias                       AS alias,
                fact_txn_v2.status                            AS trans_status,
                COUNT(*)                                       AS nb_transactions,
                SUM(fact_txn_v2.actual_amount)                AS volume_total,
                SUM(fact_txn_v2.commission_amount - fact_txn_v2.charge_amount) AS revenus,
                SUM(fact_txn_v2.charge_amount)                AS frais,
                SUM(fact_txn_v2.commission_amount)            AS commission
            ")
            ->groupBy(
                DB::raw('fact_txn_v2.transaction_initiated_time::date'),
                'fact_txn_v2.txn_index',
                'transaction_types.txn_type_name',
                'transaction_types.alias',
                'fact_txn_v2.status'
            )
            ->get();

        foreach ($rows as $row) {
            DashboardAggregation::create($row->toArray());
        }

        $this->info("✓ {$rows->count()} lignes insérées pour le {$date}.");
    }

    private function aggregerTout(): void
    {
        $this->warn('Recalcul complet — suppression de toutes les agrégations...');
        DashboardAggregation::truncate();

        // Toutes les dates distinctes (tous statuts confondus)
        $dates = Transaction::query()
            ->selectRaw('transaction_initiated_time::date as jour')
            ->groupBy(DB::raw('transaction_initiated_time::date'))
            ->orderBy('jour')
            ->pluck('jour');

        $bar = $this->output->createProgressBar($dates->count());
        $bar->start();

        foreach ($dates as $date) {
            $jour = $date instanceof \DateTimeInterface
                ? $date->format('Y-m-d')
                : Carbon::parse($date)->toDateString();

            $this->aggregerJour($jour);
            $bar->advance();
        }

        $bar->finish();
        $this->newLine();
        $this->info('✓ Agrégation complète terminée.');
    }
}