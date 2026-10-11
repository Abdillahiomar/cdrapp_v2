<?php

namespace App\Console\Commands;

use App\Models\PcaSetting;
use App\Support\PcaBalances;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Met à jour les derniers soldes connus (pca_balances) à partir des
 * transactions chargées par l'ETL.
 *
 * Sans option : depuis le dernier passage (moins un jour de recouvrement,
 * pour les chargements tardifs) jusqu'à la dernière transaction chargée.
 * --full : tout l'historique, mois par mois (chargement initial).
 */
class RefreshPcaBalances extends Command
{
    protected $signature = 'pca:refresh-balances {--full : Recalcule depuis la première transaction (chargement initial)}';

    protected $description = 'Met à jour les derniers soldes connus de chaque compte pour le PCA';

    public function handle(): int
    {
        $bounds = DB::selectOne('SELECT MIN(transaction_initiated_time) AS mi, MAX(transaction_initiated_time) AS ma FROM fact_txn_v2');

        if (!$bounds->ma) {
            $this->warn('Aucune transaction dans fact_txn_v2.');
            return self::SUCCESS;
        }

        $end       = Carbon::parse($bounds->ma)->addSecond();
        $watermark = PcaSetting::get('refresh_watermark');

        if ($this->option('full') || !$watermark) {
            if (!$this->option('full')) {
                $this->info('Premier passage : chargement complet.');
            }
            $start = Carbon::parse($bounds->mi)->startOfMonth();
        } else {
            $start = Carbon::parse($watermark)->subDay();
        }

        $this->info("Transactions du {$start->format('d/m/Y H:i')} au {$end->format('d/m/Y H:i')}");

        // Par mois : chaque fenêtre ne garde que la dernière transaction de chaque compte
        $total = 0;
        for ($from = $start->copy(); $from->lt($end); $from = $to) {
            $to   = $from->copy()->startOfMonth()->addMonth()->min($end);
            $t    = microtime(true);
            $rows = PcaBalances::refreshWindow($from->format('Y-m-d H:i:s'), $to->format('Y-m-d H:i:s'));
            $total += $rows;

            $this->line(sprintf('  %s → %s : %s compte(s) mis à jour (%.1f s)', $from->format('d/m/Y'), $to->format('d/m/Y'), number_format($rows, 0, ',', ' '), microtime(true) - $t));
        }

        PcaSetting::put('refresh_watermark', $end->format('Y-m-d H:i:s'));
        PcaSetting::put('last_refresh_at', now()->format('Y-m-d H:i:s'));

        $this->info('✓ ' . number_format(DB::table('pca_balances')->count(), 0, ',', ' ') . ' comptes connus, données jusqu\'au ' . Carbon::parse($bounds->ma)->format('d/m/Y H:i:s'));

        return self::SUCCESS;
    }
}
