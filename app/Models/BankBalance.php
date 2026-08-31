<?php
// app/Models/BankBalance.php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BankBalance extends Model
{
    protected $fillable = ['bank_account_id', 'balance_date', 'balance', 'notes', 'created_by', 'updated_by'];

    protected $casts = [
        'balance_date' => 'date',
        'balance'      => 'decimal:2',
    ];

    public function account(): BelongsTo
    {
        return $this->belongsTo(BankAccount::class, 'bank_account_id');
    }
}