<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;

/**
 * Cérémonie de tirage au sort : une liste d'éligibles (Draw) par catégorie de
 * lots, préparée à l'avance ; le jour J, tirage lot par lot dans la salle de
 * tirage. Un client ne gagne qu'un seul lot sur toute la cérémonie.
 */
class DrawCeremony extends Model
{
    public const STATUSES = [
        'preparation' => ['Préparation', '#92400E', '#FEF3C7'],
        'in_progress' => ['Tirage en cours', '#7C3AED', '#F1EAFE'],
        'done'        => ['Tous les lots tirés', '#005C2B', '#E5F5ED'],
        'closed'      => ['Clôturée', '#374151', '#E5E7EB'],
    ];

    /** Libellé affiché quand la préparation est terminée */
    public const READY_LABEL = ['Prête', '#1B2F6E', '#E8ECF8'];

    protected $fillable = [
        'name', 'description', 'ceremony_date', 'status', 'created_by', 'drawn_by', 'closed_by',
        'started_at', 'drawn_at', 'closed_at',
    ];

    protected $casts = [
        'ceremony_date' => 'date',
        'started_at'    => 'datetime',
        'drawn_at'      => 'datetime',
        'closed_at'     => 'datetime',
    ];

    /** Listes d'éligibles, dans l'ordre des catégories (Platinum, Gold, Bronze…) */
    public function draws(): HasMany
    {
        return $this->hasMany(Draw::class)
            ->orderByRaw('(SELECT pc.position FROM prize_categories pc WHERE pc.id = draws.prize_category_id) NULLS FIRST')
            ->orderBy('draws.id');
    }

    public function prizes(): HasManyThrough
    {
        return $this->hasManyThrough(DrawPrize::class, Draw::class);
    }

    public function winners(): HasManyThrough
    {
        return $this->hasManyThrough(DrawWinner::class, Draw::class);
    }

    public function creator(): BelongsTo { return $this->belongsTo(User::class, 'created_by'); }
    public function drawer(): BelongsTo { return $this->belongsTo(User::class, 'drawn_by'); }
    public function closer(): BelongsTo { return $this->belongsTo(User::class, 'closed_by'); }

    /** Toutes les listes figées et chacune a au moins un lot */
    public function isReady(): bool
    {
        $draws = $this->relationLoaded('draws') ? $this->draws : $this->draws()->withCount('prizes')->get();

        return $draws->isNotEmpty() && $draws->every(fn (Draw $d) => in_array($d->status, ['ready', 'drawing', 'drawn'], true)
            && ($d->prizes_count ?? $d->prizes()->count()) > 0);
    }

    /** Au moins un tirage a eu lieu : listes et lots ne bougent plus */
    public function hasStarted(): bool
    {
        return $this->status !== 'preparation';
    }

    public function isClosed(): bool
    {
        return $this->status === 'closed';
    }

    public function statusLabel(): array
    {
        if ($this->status === 'preparation' && $this->isReady()) {
            return self::READY_LABEL;
        }

        return self::STATUSES[$this->status] ?? [$this->status, '#6b7280', '#F3F4F6'];
    }
}
