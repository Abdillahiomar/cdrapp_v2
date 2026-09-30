<?php

namespace App\Support;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;

/**
 * Applique les filtres de recherche transactions à une requête.
 * Utilisé à la fois par le composant Livewire (recherche/affichage) et par
 * le job d'export en arrière-plan, pour garantir un résultat identique.
 */
class TransactionFilters
{
    public static function apply(Builder $query, array $f): Builder
    {
        if (!empty($f['ORDERID'])) {
            $query->where('transaction_id', 'like', '%' . $f['ORDERID'] . '%');
        }

        if (!empty($f['DEBIT_MSISDN'])) {
            $query->where('debit_party_identifier', $f['DEBIT_MSISDN']);
        }

        if (!empty($f['CREDIT_MSISDN'])) {
            $query->where('credit_party_identifier', $f['CREDIT_MSISDN']);
        }

        if (!empty($f['TXN_INDEXES'])) {
            $query->whereIn('txn_index', $f['TXN_INDEXES']);
        }

        if (!empty($f['REASON_NAMES'])) {
            $query->whereIn('reason_index', $f['REASON_NAMES']);
        }

        if (!empty($f['TRANS_STATUS'])) {
            $query->where('status', $f['TRANS_STATUS']);
        }

        if (!empty($f['CHANNEL'])) {
            $query->where('channel', $f['CHANNEL']);
        }

        if (!empty($f['date_debut'])) {
            $query->where('transaction_initiated_time', '>=', $f['date_debut'] . ' 00:00:00');
        }

        if (!empty($f['date_fin'])) {
            $query->where(
                'transaction_initiated_time',
                '<',
                Carbon::parse($f['date_fin'])->addDay()->format('Y-m-d') . ' 00:00:00'
            );
        }

        if (!empty($f['DEBIT_SEGMENT'])) {
            $query->where('debit_party_type', $f['DEBIT_SEGMENT']);
        }

        if (!empty($f['CREDIT_SEGMENT'])) {
            $query->where('credit_party_type', $f['CREDIT_SEGMENT']);
        }

        return $query;
    }
}
