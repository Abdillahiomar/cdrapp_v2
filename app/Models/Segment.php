<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Segment extends Model
{
    protected $table = 'segments';

    public $timestamps = false;

    protected $fillable = [
        'SEGMENT_ID',
        'SEGMENT_NAME',
        'SEGMENT_DESCRIPTION',
        'IDENTITY_TYPE',
        'KYC_FIELD_ID',
        'KYC_GROUP_ID',
        'STATUS',
        'LOAD_DATA_TS',
    ];

    protected $casts = [
        'LOAD_DATA_TS' => 'datetime',
    ];
}