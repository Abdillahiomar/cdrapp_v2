<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class DrawPrize extends Model
{
    protected $fillable = ['draw_id', 'name', 'quantity', 'substitutes', 'position'];

    public function draw(): BelongsTo
    {
        return $this->belongsTo(Draw::class);
    }

    public function winners(): HasMany
    {
        // Gagnants, puis suppléants, puis tirages complémentaires, chacun dans l'ordre de sortie
        return $this->hasMany(DrawWinner::class)
            ->orderByRaw("CASE kind WHEN 'winner' THEN 0 WHEN 'substitute' THEN 1 ELSE 2 END")
            ->orderBy('rank');
    }
}
