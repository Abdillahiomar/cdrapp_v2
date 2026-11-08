<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Une sortie du tirage : gagnant, suppléant ou tirage complémentaire (extra,
 * quand plus aucun suppléant n'est disponible). Jamais supprimée.
 */
class DrawWinner extends Model
{
    public const KINDS = [
        'winner'     => 'Gagnant',
        'substitute' => 'Suppléant',
        'extra'      => 'Tirage complémentaire',
    ];

    protected $fillable = [
        'draw_id', 'draw_prize_id', 'msisdn', 'name', 'txn_count', 'kind', 'rank', 'seed', 'status',
        'replaces_id', 'promoted_at', 'invalidated_reason', 'invalidated_by', 'invalidated_at',
    ];

    protected $casts = [
        'promoted_at'    => 'datetime',
        'invalidated_at' => 'datetime',
    ];

    public function draw(): BelongsTo { return $this->belongsTo(Draw::class); }
    public function prize(): BelongsTo { return $this->belongsTo(DrawPrize::class, 'draw_prize_id'); }
    public function replaces(): BelongsTo { return $this->belongsTo(self::class, 'replaces_id'); }
    public function invalidator(): BelongsTo { return $this->belongsTo(User::class, 'invalidated_by'); }

    /** Gagnants retenus : gagnants d'origine, suppléants promus et tirages complémentaires encore valides */
    public function scopeEffective(Builder $q): Builder
    {
        return $q->where('status', 'valid')
            ->where(fn ($q) => $q->whereIn('kind', ['winner', 'extra'])->orWhereNotNull('promoted_at'));
    }

    public function isEffective(): bool
    {
        return $this->status === 'valid' && ($this->kind !== 'substitute' || $this->promoted_at !== null);
    }

    /** Suppléant encore en attente (ni promu, ni invalidé) */
    public function isWaitingSubstitute(): bool
    {
        return $this->kind === 'substitute' && $this->status === 'valid' && $this->promoted_at === null;
    }
}
