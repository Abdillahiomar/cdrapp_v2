<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PcaIncident extends Model
{
    protected $fillable = [
        'title',
        'started_at',
        'ended_at',
        'opened_by',
        'closed_by',
        'notes',
    ];

    protected $casts = [
        'started_at' => 'datetime',
        'ended_at'   => 'datetime',
    ];

    /** Incident en cours (mode PCA actif), ou null */
    public static function active(): ?self
    {
        return self::whereNull('ended_at')->latest('started_at')->first();
    }

    public function cashouts(): HasMany
    {
        return $this->hasMany(PcaCashout::class, 'incident_id');
    }

    public function opener(): BelongsTo
    {
        return $this->belongsTo(User::class, 'opened_by');
    }

    public function closer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'closed_by');
    }
}
