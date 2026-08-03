<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TransactionType extends Model
{
     protected $table = 'transaction_types';

    protected $fillable = [
        'UNIQUE_ID', 
        'TXN_INDEX',
        'TXN_TYPE_NAME', 
        'ALIAS', 
        'IS_BULK', 
        'IS_REVERSAL', 
        'CANBE_REVERSED'
    ];

    public $timestamps = false;

    public function reasonTypes()
    {
        return $this->hasMany(ReasonType::class, 'TXN_INDEX', 'TXN_INDEX');
    }

    
}
