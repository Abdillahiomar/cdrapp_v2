<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Campagne de tirage au sort Marketing (voir App\Support\DrawEngine) */
class Draw extends Model
{
    public const STATUSES = [
        'draft'     => ['Brouillon', '#6b7280', '#F3F4F6'],
        'computing' => ['Calcul en cours', '#92400E', '#FEF3C7'],
        'ready'     => ['Éligibles figés', '#1B2F6E', '#E8ECF8'],
        'drawing'   => ['Tirage en cours', '#7C3AED', '#F1EAFE'],
        'drawn'     => ['Tiré', '#005C2B', '#E5F5ED'],
        'closed'    => ['Clôturé', '#374151', '#E5E7EB'],
    ];

    public const CHANCE_MODES = [
        'per_client'      => '1 chance par client',
        'per_transaction' => '1 chance par transaction (favorise les clients les plus actifs)',
    ];

    protected $fillable = [
        'name', 'description', 'period_start', 'period_end', 'txn_indexes', 'min_txn_count', 'min_txn_amount',
        'chance_mode', 'one_prize_per_client', 'exclude_employees', 'exclude_rds_lite', 'exclude_winners_days', 'blacklist',
        'status', 'error', 'funnel', 'eligible_count', 'ticket_count', 'eligible_hash', 'seed',
        'created_by', 'computed_by', 'drawn_by', 'closed_by', 'computed_at', 'drawn_at', 'closed_at',
    ];

    protected $casts = [
        'period_start'         => 'date',
        'period_end'           => 'date',
        'txn_indexes'          => 'array',
        'funnel'               => 'array',
        'min_txn_amount'       => 'decimal:2',
        'one_prize_per_client' => 'boolean',
        'exclude_employees'    => 'boolean',
        'exclude_rds_lite'     => 'boolean',
        'computed_at'          => 'datetime',
        'drawn_at'             => 'datetime',
        'closed_at'            => 'datetime',
    ];

    public function prizes(): HasMany
    {
        return $this->hasMany(DrawPrize::class)->orderBy('position')->orderBy('id');
    }

    public function winners(): HasMany
    {
        return $this->hasMany(DrawWinner::class);
    }

    public function creator(): BelongsTo { return $this->belongsTo(User::class, 'created_by'); }
    public function computer(): BelongsTo { return $this->belongsTo(User::class, 'computed_by'); }
    public function drawer(): BelongsTo { return $this->belongsTo(User::class, 'drawn_by'); }
    public function closer(): BelongsTo { return $this->belongsTo(User::class, 'closed_by'); }

    public function statusLabel(): array
    {
        return self::STATUSES[$this->status] ?? [$this->status, '#6b7280', '#F3F4F6'];
    }

    /** Critères et options modifiables uniquement avant de figer la liste */
    public function isEditable(): bool
    {
        return $this->status === 'draft';
    }

    /** Tirage commencé (au moins une sortie) : liste et lots ne bougent plus */
    public function isDrawn(): bool
    {
        return in_array($this->status, ['drawing', 'drawn', 'closed'], true);
    }

    /** Tirages, remplacements et écarts encore possibles */
    public function isOpenForDrawing(): bool
    {
        return in_array($this->status, ['ready', 'drawing', 'drawn'], true);
    }

    /** Numéros de la liste noire, au format des transactions (2537XXXXXXX) */
    public function blacklistMsisdns(): array
    {
        // Un numéro par ligne (ou séparés par , ;) : les espaces internes « 77 04 65 42 » sont admis
        return collect(preg_split('/[\r\n,;]+/', (string) $this->blacklist))
            ->map(fn ($v) => Employee::normalizeMsisdn($v))
            ->filter()
            ->unique()
            ->values()
            ->all();
    }
}
