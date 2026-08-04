<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class RevenueAccount extends Model
{
     protected $table = 'accounts_balance';
    public $timestamps = false;
    protected $fillable = [
        'DATA_DATE',
        'ACCOUNT_NO',
        'ALIAS',
        'ACCOUNT_TYPE_ID',
        'IDENTITY_TYPE',
        'IDENTITY_ID',
        'VALUE_TYPE',
        'CURRENCY',
        'BALANCE',
        'RESERVED_BALANCE',
        'UNCLEAR_BALANCE',
        'ACCOUNT_STATUS',
        'LOAD_DATA_TS',
    ];

    protected $casts = [
        'DATA_DATE'        => 'date',
        'LOAD_DATA_TS'     => 'datetime',
        'BALANCE'          => 'float',
        'RESERVED_BALANCE' => 'float',
        'UNCLEAR_BALANCE'  => 'float',
    ];
}
