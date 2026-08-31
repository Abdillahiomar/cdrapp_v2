<?php
// app/Models/BankAccount.php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class BankAccount extends Model
{
    protected $fillable = ['bank_id', 'account_label', 'account_number', 'currency', 'is_active'];

    public function bank(): BelongsTo
    {
        return $this->belongsTo(Bank::class);
    }

    public function balances(): HasMany
    {
        return $this->hasMany(BankBalance::class);
    }
}