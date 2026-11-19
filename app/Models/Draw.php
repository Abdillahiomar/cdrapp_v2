<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Liste d'éligibles d'une catégorie de lots au sein d'une cérémonie (critères,
 * liste figée, lots de cette catégorie). Voir DrawCeremony et App\Support\DrawEngine.
 * prize_category_id NULL : ancien tirage repris tel quel (toutes catégories).
 */
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
        'draw_ceremony_id', 'prize_category_id', 'name', 'description', 'period_start', 'period_end', 'txn_indexes', 'reason_indexes', 'min_txn_count', 'min_txn_amount',
        'chance_mode', 'one_prize_per_client', 'exclude_employees', 'exclude_rds_lite', 'exclude_winners_days', 'blacklist',
        'status', 'error', 'funnel', 'eligible_count', 'ticket_count', 'eligible_hash', 'seed',
        'created_by', 'computed_by', 'drawn_by', 'closed_by', 'computed_at', 'drawn_at', 'closed_at',
    ];

    protected $casts = [
        'period_start'         => 'date',
        'period_end'           => 'date',
        'txn_indexes'          => 'array',
        'reason_indexes'       => 'array',
        'funnel'               => 'array',
        'min_txn_amount'       => 'decimal:2',
        'one_prize_per_client' => 'boolean',
        'exclude_employees'    => 'boolean',
        'exclude_rds_lite'     => 'boolean',
        'computed_at'          => 'datetime',
        'drawn_at'             => 'datetime',
        'closed_at'            => 'datetime',
    ];

    public function ceremony(): BelongsTo
    {
        return $this->belongsTo(DrawCeremony::class, 'draw_ceremony_id');
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(PrizeCategory::class, 'prize_category_id');
    }

    /** « Platinum », ou « Toutes catégories » pour un ancien tirage */
    public function categoryName(): string
    {
        return $this->category?->name ?? 'Toutes catégories';
    }

    public function categoryColor(): string
    {
        return $this->category?->color ?? '#1B2F6E';
    }

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

    /**
     * Reasons retenus, groupés par type : [txn_index => [reason_index, …]].
     * Un type absent garde tous ses reasons.
     */
    public function reasonsByType(): array
    {
        $reasons = array_map('intval', $this->reason_indexes ?? []);
        if (!$reasons) {
            return [];
        }

        return DB::table('reason_types')
            ->whereIn('reason_index', $reasons)
            ->whereIn('txn_index', array_map('strval', $this->txn_indexes ?? []))
            ->get(['txn_index', 'reason_index'])
            ->groupBy(fn ($r) => (int) $r->txn_index)
            ->map(fn ($g) => $g->pluck('reason_index')->map(fn ($v) => (int) $v)->unique()->sort()->values()->all())
            ->all();
    }

    /** Un libellé par type : « Merchant Payment (via Main Acc, via Sub Acc) », ou le nom seul s'il n'est pas restreint */
    public function typeLabels(): Collection
    {
        $types   = DB::table('transaction_types')->whereIn('txn_index', array_map('intval', $this->txn_indexes ?? []))->pluck('txn_type_name', 'txn_index');
        $reasons = DB::table('reason_types')->whereIn('reason_index', array_map('intval', $this->reason_indexes ?? []))->pluck('reason_name', 'reason_index');
        $byType  = $this->reasonsByType();

        return collect($this->txn_indexes ?? [])
            ->map(fn ($i) => ($types[(int) $i] ?? $i) . (isset($byType[(int) $i])
                ? ' (' . collect($byType[(int) $i])->map(fn ($r) => $reasons[$r] ?? $r)->implode(', ') . ')'
                : ''))
            ->sort()
            ->values();
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
