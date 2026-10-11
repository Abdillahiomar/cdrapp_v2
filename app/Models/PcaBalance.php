<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PcaBalance extends Model
{
    public const CREATED_AT = null;

    /** Compte principal par type de titulaire : celui sur lequel se fait le cash-out */
    public const MAIN_ACCOUNTS = [
        'Customer'     => 'Customer Main Account',
        'Organization' => 'Org Main Account',
    ];

    protected $fillable = [
        'party_id',
        'party_type',
        'account_type',
        'party_name',
        'balance',
        'as_of',
        'last_transaction_id',
        'source',
    ];

    protected $casts = [
        'balance' => 'float',
        'as_of'   => 'datetime',
    ];
}
