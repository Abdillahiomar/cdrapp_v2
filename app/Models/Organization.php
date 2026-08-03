<?php
// app/Models/Organization.php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Organization extends Model
{
    protected $table = 'organizations';

    public $timestamps = false;

    protected $fillable = [
        'BIZ_ORG_ID',
        'ORGANIZATION_TYPE',
        'BIZ_ORG_NAME',
        'TRUST_LEVEL',
        'SHORT_CODE',
        'ORGANIZATION_CODE',
        'REGION_ID',
        'MODIFY_TIME',
        'MODIFY_OPER_ID',
        'CREATE_TIME',
        'CREATE_OPER_ID',
        'SP_ID',
        'STATUS',
        'STATUS_CHANGE_TIME',
        'HIER_LEVEL',
        'IDENTITY_MODEL',
        'PARENT_ID',
        'TOP_BIZ_ORG',
        'AGGREGATOR_ACC',
        'HIER_TYPE',
        'IS_TOP',
    ];

    protected $casts = [
        'CREATE_TIME'        => 'datetime',
        'MODIFY_TIME'        => 'datetime',
        'STATUS_CHANGE_TIME' => 'datetime',
        'IS_TOP'             => 'boolean',
    ];

    

    // Une organisation a plusieurs opérateurs via OWNED_IDENTITY_ID
    public function operators()
    {
        return $this->hasMany(Operator::class, 'OWNED_IDENTITY_ID', 'BIZ_ORG_ID');
    }

    // Une organisation peut avoir une organisation parente
    public function parent()
    {
        return $this->belongsTo(Organization::class, 'PARENT_ID', 'BIZ_ORG_ID');
    }

    // Une organisation peut avoir des enfants
    public function children()
    {
        return $this->hasMany(Organization::class, 'PARENT_ID', 'BIZ_ORG_ID');
    }
}