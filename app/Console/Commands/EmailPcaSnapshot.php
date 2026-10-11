<?php

namespace App\Console\Commands;

use App\Mail\PcaSnapshotMail;
use App\Models\PcaSetting;
use App\Support\PcaBalances;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Envoie par email le fichier des derniers soldes (ZIP chiffré) aux
 * destinataires définis dans l'écran PCA.
 *
 * Planifié chaque jour après pca:refresh-balances ; n'envoie rien si l'envoi
 * quotidien est désactivé, sauf avec --force (bouton « Envoyer maintenant »).
 */
class EmailPcaSnapshot extends Command
{
    protected $signature = 'pca:email-snapshot {--force : Envoie même si l\'envoi quotidien est désactivé}';

    protected $description = 'Envoie par email les derniers soldes des comptes (PCA)';

    public function handle(): int
    {
        if (!$this->option('force') && PcaSetting::get('email_daily') !== '1') {
            $this->info('Envoi quotidien désactivé : rien à faire.');
            return self::SUCCESS;
        }

        $recipients = PcaSetting::recipients();
        $password   = PcaSetting::zipPassword();

        if (empty($recipients) || !$password) {
            $this->fail('Destinataires ou mot de passe du ZIP non configurés dans l\'écran PCA.');
        }

        $zipPath = null;

        try {
            $nonZero = PcaSetting::get('email_nonzero_only', '1') === '1';
            $snap    = PcaBalances::buildSnapshotZip($password, $nonZero);
            $zipPath = $snap['path'];

            $until = DB::table('pca_balances')->max('as_of');
            Mail::to($recipients)->send(new PcaSnapshotMail(
                $zipPath,
                $snap['rows'],
                $until ? Carbon::parse($until)->format('d/m/Y H:i') : '—',
                $nonZero
            ));

            PcaSetting::put('last_email_at', now()->format('Y-m-d H:i:s'));
            PcaSetting::put('last_email_status', 'Envoyé à ' . count($recipients) . ' destinataire(s), ' . number_format($snap['rows'], 0, ',', ' ') . ' comptes, ' . round(filesize($zipPath) / 1048576, 1) . ' Mo');

            $this->info('✓ ' . PcaSetting::get('last_email_status'));
        } catch (Throwable $e) {
            PcaSetting::put('last_email_at', now()->format('Y-m-d H:i:s'));
            PcaSetting::put('last_email_status', 'ÉCHEC : ' . $e->getMessage());
            report($e);
            $this->error($e->getMessage());

            return self::FAILURE;
        } finally {
            // Le fichier contient des données sensibles : il n'est pas conservé sur le serveur
            if ($zipPath && is_file($zipPath)) {
                unlink($zipPath);
            }
        }

        return self::SUCCESS;
    }
}
