<?php

namespace App\Console\Commands;

use App\Models\ExportRequest;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Storage;

/**
 * Supprime du disque les fichiers d'export de transactions arrivés à expiration.
 *
 * Règles de rétention :
 *   - uploaded (déjà téléchargé)   : 24 h après le téléchargement
 *   - done (jamais téléchargé)     : 7 jours après la génération
 *   - failed (fichier partiel)     : 7 jours après l'échec
 *
 * La ligne export_requests est conservée pour l'historique (qui a exporté
 * quoi, quand, avec quels filtres) : status = deleted, file_path = null,
 * deleted_at renseigné.
 *
 * À lancer sous l'utilisateur du serveur web (www-data), propriétaire des
 * fichiers générés par le worker.
 */
class CleanupExports extends Command
{
    protected $signature = 'exports:cleanup {--dry-run : Affiche les exports concernés sans rien supprimer}';

    protected $description = "Supprime les fichiers d'export expirés et passe leur statut à deleted";

    private const UPLOADED_RETENTION_HOURS = 24;
    private const DONE_RETENTION_DAYS      = 7;
    private const FAILED_RETENTION_DAYS    = 7;

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $disk   = Storage::disk('local');

        $uploadedCutoff = now()->subHours(self::UPLOADED_RETENTION_HOURS);
        $doneCutoff     = now()->subDays(self::DONE_RETENTION_DAYS);
        $failedCutoff   = now()->subDays(self::FAILED_RETENTION_DAYS);

        $query = ExportRequest::query()->where(function (Builder $q) use ($uploadedCutoff, $doneCutoff, $failedCutoff) {
            $q->where(function (Builder $q) use ($uploadedCutoff) {
                // downloaded_at absent pour les exports téléchargés avant son ajout
                $q->where('status', 'uploaded')
                  ->whereRaw('COALESCE(downloaded_at, updated_at) <= ?', [$uploadedCutoff]);
            })->orWhere(function (Builder $q) use ($doneCutoff) {
                $q->where('status', 'done')
                  ->whereRaw('COALESCE(completed_at, created_at) <= ?', [$doneCutoff]);
            })->orWhere(function (Builder $q) use ($failedCutoff) {
                $q->where('status', 'failed')
                  ->where('updated_at', '<=', $failedCutoff);
            });
        });

        $count = 0;

        $query->chunkById(200, function ($exports) use ($disk, $dryRun, &$count) {
            foreach ($exports as $export) {
                // Un export en échec n'a pas de file_path : on reconstruit le chemin prévu par le job
                $path = $export->file_path ?? 'exports/' . $export->id . '_' . $export->fileName();

                $this->line(sprintf('#%d  %-8s  %s', $export->id, $export->status, $path));

                if ($dryRun) {
                    $count++;
                    continue;
                }

                if ($disk->exists($path) && !$disk->delete($path)) {
                    $this->error("  Impossible de supprimer {$path} (permissions ?)");
                    continue;
                }

                $export->update([
                    'status'     => 'deleted',
                    'file_path'  => null,
                    'deleted_at' => now(),
                ]);

                $count++;
            }
        });

        $this->info($dryRun
            ? "{$count} export(s) seraient supprimés (dry-run)."
            : "{$count} export(s) supprimés.");

        return self::SUCCESS;
    }
}
