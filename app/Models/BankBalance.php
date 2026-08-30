<?php
// app/Models/BankBalance.php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Casts\Attribute;

class BankBalance extends Model
{
    protected $fillable = [
        'balance_date', 'bank_name', 'account_label',
        'balance', 'currency', 'notes', 'created_by', 'updated_by',
    ];

    protected $casts = [
        'balance_date' => 'date',
        'balance'      => 'decimal:2',
    ];
}