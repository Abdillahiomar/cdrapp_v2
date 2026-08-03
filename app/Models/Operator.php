<?php
// app/Models/Operator.php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Operator extends Model
{
    protected $table = 'operators';

    public $timestamps = false;

    protected $fillable = [
        'OPERATOR_ID',
        'CREATE_TIME',
        'ACTIVE_TIME',
        'OWNED_IDENTITY_ID',
        'SP_ID',
        'USER_NAME',
        'RULE_PROFILE_ID',
        'STATUS',
        'STATUS_CHANGE_TIME',
        'IS_ADMIN',
        'PUBLIC_NAME',
        'MODIFY_OPER_ID',
        'MODIFY_TIME',
        'LOAD_DATA_TS',
    ];

    protected $casts = [
        'CREATE_TIME'        => 'datetime',
        'ACTIVE_TIME'        => 'datetime',
        'STATUS_CHANGE_TIME' => 'datetime',
        'MODIFY_TIME'        => 'datetime',
        'LOAD_DATA_TS'       => 'datetime',
        'IS_ADMIN'           => 'boolean',
    ];

    // Un opérateur appartient à une organisation via OWNED_IDENTITY_ID
    public function organization()
    {
        return $this->belongsTo(Organization::class, 'OWNED_IDENTITY_ID', 'BIZ_ORG_ID');
    }
}