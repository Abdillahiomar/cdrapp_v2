<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Artisan;

/**
 * « Envoyer maintenant » depuis l'écran de réglages : la capture de la page
 * prend une à deux minutes, elle passe donc par le worker de queue.
 */
class SendRevenueReport implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $timeout = 600;

    public int $tries = 1;

    public function handle(): void
    {
        Artisan::call('dashboard:email-revenue', ['--force' => true]);
    }
}
