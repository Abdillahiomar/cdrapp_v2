<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Catégorie du catalogue des lots (Platinum, Gold, Bronze…) */
class PrizeCategory extends Model
{
    protected $fillable = ['name', 'color', 'position'];

    public function prizes(): HasMany
    {
        return $this->hasMany(Prize::class);
    }
}
