<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Transaction extends Model
{
    protected $table = 'fact_txn_v2';

    protected $fillable = [
       "linked_transaction_id",
        "transaction_initiated_time",
        "transaction_finish_time",
        "service_name",
        "transaction_type",
        "reason_type",
        "channel",
        "status",
        "failure_reason",
        "reason",
        "initiator_type",
        "initiator",
        "debit_party_type",
        "debit_party_identifier",
        "debit_party_name",
        "debit_party_account_type",
        "debit_party_account",
        "credit_party_type",
        "credit_party_identifier",
        "credit_party_name",
        "credit_party_account_type",
        "credit_party_account",
        "original_amount",
        "actual_amount",
        "charge_amount",
        "commission_amount",
        "balance_before_debit",
        "balance_after_debit",
        "balance_before_credit",
        "balance_after_credit",
        "currency",
        "checker",
        "original_conversation_id",
    ];

    public $timestamps = false;

    public function transactionType()
    {
        return $this->belongsTo(\App\Models\TransactionType::class, 'txn_index', 'txn_index');
    }


    public function reasonType()
    {
        return $this->belongsTo(\App\Models\ReasonType::class, 'reason_index', 'reason_index');
    }
}
