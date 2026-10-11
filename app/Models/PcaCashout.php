<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PcaCashout extends Model
{
    protected $fillable = [
        'incident_id',
        'party_id',
        'party_type',
        'account_type',
        'amount',
        'available_before',
        'location',
        'id_document',
        'notes',
        'recorded_by',
        'cancelled_at',
        'cancelled_by',
        'cancel_reason',
    ];

    protected $casts = [
        'amount'           => 'float',
        'available_before' => 'float',
        'cancelled_at'     => 'datetime',
    ];

    /** Cash-out non annulés */
    public function scopeValid(Builder $q): Builder
    {
        return $q->whereNull('cancelled_at');
    }

    public function incident(): BelongsTo
    {
        return $this->belongsTo(PcaIncident::class, 'incident_id');
    }

    public function recorder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }

    public function canceller(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cancelled_by');
    }
}
