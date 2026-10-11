<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Artisan;

/**
 * « Envoyer maintenant » depuis l'écran PCA : la génération du fichier peut
 * prendre une minute, elle passe donc par le worker de queue.
 */
class SendPcaSnapshot implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $timeout = 900;

    public function handle(): void
    {
        Artisan::call('pca:email-snapshot', ['--force' => true]);
    }
}
