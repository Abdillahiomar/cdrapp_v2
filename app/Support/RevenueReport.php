<?php

namespace App\Support;

use Carbon\Carbon;
use Illuminate\Support\Facades\URL;
use Spatie\Browsershot\Browsershot;
use Spatie\Browsershot\Enums\Polling;

/**
 * Rapport « Tableau de bord des revenus » envoyé par email à la direction.
 *
 * Le PDF est une capture de la vraie page (/tableau_de_bord_revenue) ouverte par un
 * navigateur sans affichage (Chromium via Browsershot) sur une adresse signée à durée
 * limitée : mêmes chiffres et mêmes graphiques qu'à l'écran.
 */
class RevenueReport
{
    /**
     * Mois en cours jusqu'à la veille (les agrégats sont calculés chaque nuit).
     * Le 1er du mois, c'est le mois précédent complet.
     *
     * @return array{0: string, 1: string}
     */
    public static function period(?Carbon $today = null): array
    {
        $end = ($today ?? Carbon::now())->copy()->subDay()->startOfDay();

        return [$end->copy()->startOfMonth()->toDateString(), $end->toDateString()];
    }

    /** Adresse signée de la page en mode rapport (valable 30 minutes) */
    public static function signedPath(string $from, string $to): string
    {
        return URL::temporarySignedRoute('reports.revenue', now()->addMinutes(30), ['debut' => $from, 'fin' => $to], absolute: false);
    }

    /** Génère le PDF de la page ; renvoie le chemin du fichier temporaire */
    public static function pdf(string $from, string $to): string
    {
        $base = rtrim((string) (config('services.browsershot.base_url') ?: config('app.url')), '/');
        $path = storage_path('app/tmp/tableau-de-bord-revenus-' . $from . '-' . $to . '-' . uniqid() . '.pdf');

        if (!is_dir(dirname($path))) {
            mkdir(dirname($path), 0775, true);
        }

        $shot = Browsershot::url($base . self::signedPath($from, $to))
            ->windowSize(1240, 900)
            ->emulateMedia('screen')
            ->showBackground()
            ->format('A4')
            ->landscape()
            ->margins(6, 6, 6, 6)
            ->ignoreHttpsErrors()
            // La page signale qu'elle a fini de dessiner ses graphiques
            ->waitForFunction('window.__reportReady === true', Polling::RequestAnimationFrame, 240000)
            ->timeout(300);

        if ($v = config('services.browsershot.node_binary')) {
            $shot->setNodeBinary($v);
        }
        if ($v = config('services.browsershot.npm_binary')) {
            $shot->setNpmBinary($v);
        }
        if ($v = config('services.browsershot.chrome_path')) {
            $shot->setChromePath($v);
        }
        if ($v = config('services.browsershot.node_modules')) {
            $shot->setNodeModulePath($v);
        }
        if (config('services.browsershot.no_sandbox')) {
            $shot->noSandbox();
        }

        $shot->savePdf($path);

        return $path;
    }
}
