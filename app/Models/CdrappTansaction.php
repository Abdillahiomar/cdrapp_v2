<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CdrappTansaction extends Model
{
    protected $table = 'transactions_partitioned';

    protected $fillable = [
        'transactionId', 
        'transactionType',
        'transactionState', 
        'detailedStatus',  
        'transactionMediumCode', 
        'transactionCreationDate', 
        'debitedMSISDN',
        'debitedProfileCode',
        'creditedMSISDN',
        'creditedProfileCode',
        'deliveredSubscriber',
        'transactionAmount',
        'transactionFeeAmount',
        'transactionCommisionAmount',
        'transactionTaxAmount'
    ];

    public $timestamps = false;
}
