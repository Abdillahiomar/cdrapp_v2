<?php

namespace App\Console\Commands;

use App\Mail\RevenueDashboardMail;
use App\Models\ReportSetting;
use App\Support\RevenueDashboard;
use App\Support\RevenueReport;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Envoie par email le tableau de bord des revenus du mois en cours
 * (chiffres clés + PDF de la page) aux destinataires de l'écran de réglages.
 *
 * Planifié le vendredi et le samedi à 06:00 (crontab) ; n'envoie rien si l'envoi
 * automatique est désactivé, sauf avec --force (bouton « Envoyer maintenant »).
 */
class EmailRevenueDashboard extends Command
{
    protected $signature = 'dashboard:email-revenue
                            {--force : Envoie même si l\'envoi automatique est désactivé}
                            {--to= : Envoie seulement à cette adresse (test)}';

    protected $description = 'Envoie par email le tableau de bord des revenus du mois en cours';

    public function handle(): int
    {
        if (!$this->option('force') && !$this->option('to') && !ReportSetting::revenueEnabled()) {
            $this->info('Envoi automatique désactivé : rien à faire.');
            return self::SUCCESS;
        }

        $recipients = $this->option('to') ? [$this->option('to')] : ReportSetting::revenueRecipients();
        if (empty($recipients)) {
            $this->fail('Aucun destinataire configuré.');
        }

        [$from, $to] = RevenueReport::period();
        $pdf = null;

        try {
            $dash = (new RevenueDashboard($from, $to))->build();
            $pdf  = RevenueReport::pdf($from, $to);

            Mail::to($recipients)->send(new RevenueDashboardMail($pdf, $from, $to, $dash['kpi'], $dash['last_date']));

            $status = 'Envoyé à ' . count($recipients) . ' destinataire(s), période du ' . date('d/m/Y', strtotime($from))
                . ' au ' . date('d/m/Y', strtotime($to)) . ', PDF ' . round(filesize($pdf) / 1048576, 1) . ' Mo';
            $this->record($status);
            $this->info('✓ ' . $status);
        } catch (Throwable $e) {
            $this->record('ÉCHEC : ' . $e->getMessage());
            report($e);
            $this->error($e->getMessage());

            return self::FAILURE;
        } finally {
            if ($pdf && is_file($pdf)) {
                unlink($pdf);
            }
        }

        return self::SUCCESS;
    }

    private function record(string $status): void
    {
        if ($this->option('to')) {
            return; // envoi de test en ligne de commande : pas d'historique
        }
        ReportSetting::put('revenue_last_at', now()->format('Y-m-d H:i:s'));
        ReportSetting::put('revenue_last_status', mb_substr($status, 0, 1000));
    }
}
