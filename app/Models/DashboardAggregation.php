<?php
// app/Models/DashboardAggregation.php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DashboardAggregation extends Model
{
    protected $table = 'dashboard_aggregations';

    protected $fillable = [
        'jour',
        'txn_index',
        'txn_type_name',
        'alias',
        'trans_status',
        'nb_transactions',
        'volume_total',
        'revenus',
        'frais',
        'commission',
        'taxe',
    ];

    protected $casts = [
        'jour'            => 'date',
        'nb_transactions' => 'integer',
        'volume_total'    => 'float',
        'revenus'         => 'float',
        'frais'           => 'float',
        'commission'      => 'float',
        'taxe'            => 'float',
    ];
}