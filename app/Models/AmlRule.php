<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AmlRule extends Model
{
    public const POPULATIONS = [
        'rds'         => 'RDS (clients)',
        'corporate'   => 'Corporate (organisations)',
        'ppe'         => 'PPE',
        'watchlist'   => 'Black list / liste grise',
        'dormant'     => 'Comptes dormants (3 mois)',
        'risk_sector' => 'Secteurs à risque',
        'all'         => 'Tous les comptes',
    ];

    public const PERIODS = [
        'day'   => 'Jour',
        'week'  => 'Semaine (dim → sam)',
        'month' => 'Mois',
    ];

    public const SEVERITIES = [
        'low'    => 'Faible',
        'medium' => 'Moyenne',
        'high'   => 'Élevée',
    ];

    public const DIRECTIONS = [
        'in'   => 'Entrant',
        'out'  => 'Sortant',
        'both' => 'Les deux',
    ];

    protected $fillable = [
        'code',
        'name',
        'description',
        'population',
        'period',
        'flows',
        'sectors',
        'count_threshold',
        'amount_threshold',
        'logic',
        'comparison',
        'severity',
        'enabled',
    ];

    protected $casts = [
        'flows'            => 'array',
        'sectors'          => 'array',
        'count_threshold'  => 'integer',
        'amount_threshold' => 'decimal:2',
        'enabled'          => 'boolean',
    ];

    public function alerts(): HasMany
    {
        return $this->hasMany(AmlAlert::class, 'rule_id');
    }

    /**
     * Libellé lisible des seuils, ex. "Nb > 5 OU Montant > 50 000 FDJ".
     */
    public function thresholdLabel(): string
    {
        return self::formatThresholds($this->count_threshold, $this->amount_threshold, $this->logic, $this->comparison);
    }

    public static function formatThresholds($count, $amount, string $logic, string $comparison): string
    {
        $op    = $comparison === 'gte' ? '≥' : '>';
        $parts = [];

        if ($count !== null) {
            $parts[] = "Nb {$op} " . number_format($count, 0, ',', ' ');
        }
        if ($amount !== null) {
            $parts[] = "Montant {$op} " . number_format((float) $amount, 0, ',', ' ') . ' FDJ';
        }

        return implode($logic === 'and' ? ' ET ' : ' OU ', $parts) ?: '—';
    }
}
