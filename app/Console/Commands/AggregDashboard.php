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
    protected $signature = 'dashboard:agreger
        {--date=  : Un seul jour Y-m-d (défaut: hier)}
        {--from=  : Date de début Y-m-d (plage)}
        {--to=    : Date de fin Y-m-d (plage, incluse)}
        {--all    : Tout recalculer depuis le début}';

    protected $description = 'Agrège les transactions dans dashboard_aggregations (tous statuts)';

    public function handle(): void
    {
        // Priorité : --all > plage (--from/--to) > --date > hier
        if ($this->option('all')) {
            $this->aggregerTout();
            return;
        }

        if ($this->option('from') || $this->option('to')) {
            $this->aggregerPlage();
            return;
        }

        $date = $this->option('date')
            ? Carbon::parse($this->option('date'))->toDateString()
            : Carbon::yesterday()->toDateString();

        $this->aggregerJour($date);
    }

    private function aggregerPlage(): void
    {
        $from = $this->option('from')
            ? Carbon::parse($this->option('from'))->startOfDay()
            : Carbon::parse($this->option('to'))->startOfDay();

        $to = $this->option('to')
            ? Carbon::parse($this->option('to'))->startOfDay()
            : Carbon::parse($this->option('from'))->startOfDay();

        if ($from->gt($to)) {
            $this->error("La date de début ({$from->toDateString()}) est après la date de fin ({$to->toDateString()}).");
            return;
        }

        $nbJours = $from->diffInDays($to) + 1;
        $this->info("Agrégation de la plage {$from->toDateString()} → {$to->toDateString()} ({$nbJours} jour(s))");

        $bar = $this->output->createProgressBar($nbJours);
        $bar->start();

        $curseur = $from->copy();
        while ($curseur->lte($to)) {
            $this->aggregerJour($curseur->toDateString());
            $bar->advance();
            $curseur->addDay();
        }

        $bar->finish();
        $this->newLine();
        $this->info('✓ Plage agrégée.');
    }

    private function aggregerJour(string $date): void
    {
        // Bornes de journée (comparaison directe = index utilisé, pas de cast ::date)
        $debut = $date . ' 00:00:00';
        $fin   = Carbon::parse($date)->addDay()->format('Y-m-d') . ' 00:00:00';

        // Supprime les anciennes lignes du jour pour recalculer proprement
        DashboardAggregation::whereDate('jour', $date)->delete();

        // Tous statuts confondus (Completed, Failed, etc.)
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

        $nbLignes = $rows->count();

        foreach ($rows as $row) {
            DashboardAggregation::create($row->toArray());
        }

        // Libère la mémoire entre les jours (utile sur VM à faible RAM)
        unset($rows);
        gc_collect_cycles();

        $this->line("  {$date} : {$nbLignes} lignes");
    }
    
    private function aggregerTout(): void
    {
        $this->warn('Recalcul complet — suppression de toutes les agrégations...');
        DashboardAggregation::truncate();

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