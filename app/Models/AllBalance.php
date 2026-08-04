<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AllBalance extends Model
{
    protected $table = 'all_balances';
    public $timestamps = false;

    protected $fillable = [
        'accounte_date',
        'identity_type',
        'account_type',
        'account_status',
        'balance',
        'reserved_balance',
        'unclear_balance',
        'total',
    ];

    protected $casts = [
        'account_date'     => 'date',
        'balance'          => 'float',
        'reserved_balance' => 'float',
        'unclear_balance'  => 'float',
        'total'            => 'float',
    ];
}