<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CdrappTxnType extends Model
{
     protected $table = 'txn_types';

      protected $fillable = [
        'name', 
    ];
}
