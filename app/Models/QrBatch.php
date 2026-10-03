<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class QrBatch extends Model
{
    use HasUuids;

    protected $fillable = [
        'user_id',
        'file_name',
        'total',
        'format',
        'with_logo',
        'zip_path',
    ];

    protected $casts = [
        'with_logo' => 'boolean',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function codes(): HasMany
    {
        return $this->hasMany(QrCode::class, 'batch_id');
    }
}
