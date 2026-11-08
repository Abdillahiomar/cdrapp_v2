<?php

namespace App\Jobs;

use App\Models\Draw;
use App\Support\DrawEngine;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Throwable;

/**
 * Calcul de la liste des éligibles d'un tirage : sur une longue période le
 * scan de fact_txn_v2 dépasse la durée d'une requête web, il passe donc par
 * le worker de queue. La page du tirage se rafraîchit jusqu'à la fin.
 */
class ComputeDrawEligibles implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $timeout = 1800;
    public int $tries   = 1;

    public function __construct(public int $drawId, public ?int $userId = null)
    {
    }

    public function handle(DrawEngine $engine): void
    {
        $draw = Draw::find($this->drawId);

        if ($draw && $draw->status === 'computing') {
            $engine->computeEligibles($draw, $this->userId);
        }
    }

    public function failed(Throwable $e): void
    {
        Draw::whereKey($this->drawId)->where('status', 'computing')->update([
            'status' => 'draft',
            'error'  => 'Échec du calcul des éligibles : ' . mb_substr($e->getMessage(), 0, 500),
        ]);
    }
}
