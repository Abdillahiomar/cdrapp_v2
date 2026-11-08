<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Lot d'un tirage : copie du lot du catalogue au moment de l'ajout (nom,
 * catégorie, image principale), pour que le PV reste fidèle si le catalogue change.
 */
class DrawPrize extends Model
{
    protected $fillable = ['draw_id', 'prize_id', 'name', 'category_name', 'category_color', 'image_path', 'quantity', 'substitutes', 'position'];

    public function draw(): BelongsTo
    {
        return $this->belongsTo(Draw::class);
    }

    public function catalogPrize(): BelongsTo
    {
        return $this->belongsTo(Prize::class, 'prize_id');
    }

    public function winners(): HasMany
    {
        // Gagnants, puis suppléants, puis tirages complémentaires, chacun dans l'ordre de sortie
        return $this->hasMany(DrawWinner::class)
            ->orderByRaw("CASE kind WHEN 'winner' THEN 0 WHEN 'substitute' THEN 1 ELSE 2 END")
            ->orderBy('rank');
    }

    public function imageUrl(): ?string
    {
        return $this->image_path ? route('draws.lot-image', $this) : null;
    }

    /** Gagnants déjà tirés pour ce lot (hors suppléants et tirages complémentaires) */
    public function drawnCount(): int
    {
        return $this->relationLoaded('winners')
            ? $this->winners->where('kind', 'winner')->count()
            : $this->winners()->where('kind', 'winner')->count();
    }

    public function isComplete(): bool
    {
        return $this->drawnCount() >= $this->quantity;
    }
}
