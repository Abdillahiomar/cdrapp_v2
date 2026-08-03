<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ReasonType extends Model
{
    protected $table = 'reason_types';

    protected $fillable = [
        'UNIQUE_ID',
        'REASON_INDEX',
        'REASON_NAME',
        'TXN_INDEX',
        'CHANNELS',
        'STATUS',
    ];

    public $timestamps = false;
}