<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/** Lot du catalogue Marketing, réutilisable d'un tirage à l'autre */
class Prize extends Model
{
    protected $fillable = ['prize_category_id', 'name', 'description', 'value', 'is_active'];

    protected $casts = [
        'value'     => 'decimal:2',
        'is_active' => 'boolean',
    ];

    public function category(): BelongsTo
    {
        return $this->belongsTo(PrizeCategory::class, 'prize_category_id');
    }

    public function images(): HasMany
    {
        return $this->hasMany(PrizeImage::class)->orderBy('position')->orderBy('id');
    }

    public function mainImage(): HasOne
    {
        return $this->hasOne(PrizeImage::class)->orderBy('position')->orderBy('id');
    }

    /** Déjà utilisé dans un tirage : archivable, pas supprimable */
    public function isUsed(): bool
    {
        return DrawPrize::where('prize_id', $this->id)->exists();
    }
}
