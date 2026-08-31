<?php
// app/Models/Bank.php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Bank extends Model
{
    protected $fillable = ['name', 'is_active'];

    public function accounts(): HasMany
    {
        return $this->hasMany(BankAccount::class);
    }
}