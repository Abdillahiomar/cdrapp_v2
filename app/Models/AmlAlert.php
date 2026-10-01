<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Cache;

class AmlAlert extends Model
{
    public const STATUSES = [
        'new'       => ['Nouvelle',             '#FDE8E8', '#7F1D1D'],
        'in_review' => ["En cours d'analyse",   '#DBEAFE', '#1E40AF'],
        'closed'    => ['Clôturée (fausse alerte)', '#F3F4F6', '#374151'],
        'reported'  => ['Déclarée (DOS)',       '#FEF3C7', '#92400E'],
    ];

    /** Statuts dont le passage exige un commentaire de l'analyste */
    public const STATUSES_REQUIRING_COMMENT = ['closed', 'reported'];

    private const NEW_COUNT_CACHE_KEY = 'aml_new_alerts_count';

    protected $fillable = [
        'rule_id',
        'party_id',
        'party_type',
        'party_name',
        'period',
        'period_start',
        'period_end',
        'txn_count',
        'total_amount',
        'count_threshold',
        'amount_threshold',
        'logic',
        'comparison',
        'severity',
        'status',
        'assigned_to',
        'first_detected_at',
        'last_detected_at',
        'closed_at',
    ];

    protected $casts = [
        'period_start'      => 'date',
        'period_end'        => 'date',
        'total_amount'      => 'decimal:2',
        'amount_threshold'  => 'decimal:2',
        'first_detected_at' => 'datetime',
        'last_detected_at'  => 'datetime',
        'closed_at'         => 'datetime',
    ];

    public function rule(): BelongsTo
    {
        return $this->belongsTo(AmlRule::class, 'rule_id');
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    public function events(): HasMany
    {
        return $this->hasMany(AmlAlertEvent::class, 'alert_id')->latest('created_at');
    }

    public function thresholdLabel(): string
    {
        return AmlRule::formatThresholds($this->count_threshold, $this->amount_threshold, $this->logic, $this->comparison);
    }

    /**
     * Nombre d'alertes non traitées, affiché dans le menu et la cloche.
     * Mis en cache 60 s pour ne pas requêter à chaque rendu de page.
     */
    public static function newCount(): int
    {
        return Cache::remember(self::NEW_COUNT_CACHE_KEY, 60, fn () => self::where('status', 'new')->count());
    }

    public static function forgetNewCount(): void
    {
        Cache::forget(self::NEW_COUNT_CACHE_KEY);
    }
}
