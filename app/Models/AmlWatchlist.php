<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AmlWatchlist extends Model
{
    public const LIST_TYPES = [
        'ppe'        => 'PPE',
        'black_list' => 'Black list',
        'grey_list'  => 'Liste grise',
    ];

    protected $table = 'aml_watchlist';

    // Table alimentée aussi hors Laravel : pas de created_at / updated_at
    public $timestamps = false;

    protected $fillable = [
        'msisdn',
        'list_type',
        'nom',
        'motif',
        'actif',
        'date_ajout',
        'ajoute_par',
        'date_retrait',
    ];

    protected $casts = [
        'actif'        => 'boolean',
        'date_ajout'   => 'datetime',
        'date_retrait' => 'datetime',
    ];
}
