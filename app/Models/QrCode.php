<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class QrCode extends Model
{
    protected $fillable = [
        'user_id',
        'payload',
        'merchant_name',
        'short_code',
        'with_logo',
        'batch_id',
    ];

    protected $casts = [
        'with_logo' => 'boolean',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function batch(): BelongsTo
    {
        return $this->belongsTo(QrBatch::class, 'batch_id');
    }

    /**
     * Nom de fichier sans extension, ex. "1298_boutique-ali".
     */
    public function fileBaseName(): string
    {
        $parts = array_filter([
            $this->short_code,
            $this->merchant_name ? Str::slug($this->merchant_name) : null,
        ]);

        return $parts ? implode('_', $parts) : 'qrcode_' . $this->id;
    }
}
