<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Image d'un lot, stockée sur le disque privé et servie par une route authentifiée */
class PrizeImage extends Model
{
    protected $fillable = ['prize_id', 'path', 'position'];

    public function prize(): BelongsTo
    {
        return $this->belongsTo(Prize::class);
    }

    public function url(): string
    {
        return route('draws.prize-image', $this);
    }
}
