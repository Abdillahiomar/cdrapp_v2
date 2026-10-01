<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ExportRequest extends Model
{
    protected $fillable = [
        'user_id',
        'type',
        'status',
        'filters',
        'file_path',
        'total_rows',
        'error',
        'completed_at',
        'downloaded_at',
        'deleted_at',
    ];

    protected $casts = [
        'filters'       => 'array',
        'completed_at'  => 'datetime',
        'downloaded_at' => 'datetime',
        'deleted_at'    => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function fileName(): string
    {
        $ext = $this->type === 'excel' ? 'xlsx' : 'csv';

        return 'transactions_' . $this->created_at->format('Ymd_His') . '.' . $ext;
    }
}
