<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AmlAlertEvent extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'alert_id',
        'user_id',
        'action',
        'from_status',
        'to_status',
        'comment',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
