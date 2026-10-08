<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

class ExportRequest extends Model
{
    protected $fillable = [
        'user_id',
        'type',
        'source',
        'status',
        'filters',
        'columns',
        'file_path',
        'total_rows',
        'error',
        'completed_at',
        'downloaded_at',
        'deleted_at',
    ];

    protected $casts = [
        'filters'       => 'array',
        'columns'       => 'array',
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
        $ext    = $this->type === 'excel' ? 'xlsx' : 'csv';
        $prefix = $this->source === 'bill_payments' ? 'paiements_factures_' : 'transactions_';

        return $prefix . $this->created_at->format('Ymd_His') . '.' . $ext;
    }

    /**
     * Chemin du fichier sur le disque local. Un export en échec n'a pas de
     * file_path : on reconstruit le chemin prévu par GenerateTransactionsExport.
     */
    public function storagePath(): string
    {
        return $this->file_path ?? 'exports/' . $this->id . '_' . $this->fileName();
    }

    /**
     * Supprime le fichier du disque et passe l'export en "deleted" (la ligne
     * est conservée pour l'historique). Retourne false si le fichier existe
     * mais n'a pas pu être supprimé (permissions).
     */
    public function deleteFile(): bool
    {
        $disk = Storage::disk('local');
        $path = $this->storagePath();

        if ($disk->exists($path) && !$disk->delete($path)) {
            return false;
        }

        $this->update([
            'status'     => 'deleted',
            'file_path'  => null,
            'deleted_at' => now(),
        ]);

        return true;
    }
}
