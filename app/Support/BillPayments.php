<?php

namespace App\Support;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Paiements de factures (DT, EDD, ONEAD, forfaits DT, taxes, frais universitaires).
 *
 * Partagé par l'écran (transactions/bill_payments) et le job d'export, pour
 * que la liste affichée et le fichier exporté contiennent exactement les mêmes
 * lignes. Le client est le compte débité (MSISDN ou shortcode), le facturier
 * le compte crédité.
 */
class BillPayments
{
    public const TYPE_NAMES = [
        'Pay DT Bills',
        'Pay EDD Bills',
        'Pay ONEAD Bills',
        'Pay DT Bundles',
        'Tax Payment',
        'University Fee',
    ];

    /** Durée maximale d'une recherche */
    public const MAX_DAYS = 92;

    private const AMOUNT_FACTOR = 100;

    /**
     * Colonnes exportables : clé => libellé. L'ordre est celui du fichier.
     */
    public const COLUMNS = [
        'date'          => 'Date',
        'transaction_id'=> 'Transaction ID',
        'type'          => 'Type de facture',
        'reason'        => 'Reason',
        'client'        => 'Client (MSISDN / shortcode)',
        'client_type'   => 'Type de client',
        'client_name'   => 'Nom du client',
        'biller'        => 'Facturier (compte)',
        'biller_name'   => 'Facturier (nom)',
        'ref_value'     => 'Référence facture',
        'amount'        => 'Montant (FDJ)',
        'fee'           => 'Frais (FDJ)',
        'commission'    => 'Commission (FDJ)',
        'status'        => 'Statut',
        'channel'       => 'Canal',
    ];

    public const DEFAULT_COLUMNS = [
        'date', 'transaction_id', 'type', 'client', 'client_name', 'biller_name', 'ref_value', 'amount', 'status',
    ];

    /** Colonnes numériques (gardées en nombre dans Excel, le reste en texte) */
    public const NUMERIC_COLUMNS = ['amount', 'fee', 'commission'];

    /**
     * txn_index des types de facture, [txn_index => libellé].
     * Mis en cache en tableau simple (le cache ne restaure pas les objets).
     */
    public static function types(): array
    {
        return Cache::remember('bill_payment_type_list', 3600, fn () => DB::table('transaction_types')
            ->whereIn('txn_type_name', self::TYPE_NAMES)
            ->orderBy('txn_type_name')
            ->pluck('txn_type_name', 'txn_index')
            ->all());
    }

    /**
     * Facturiers (comptes crédités) vus sur les 30 derniers jours, avec les
     * types de facture qu'ils reçoivent :
     *   [['id' => 'BLCEDD', 'name' => 'BLC EDD', 'types' => [24041]], ...]
     *
     * Déduits des transactions (un nouveau facturier apparaît tout seul).
     * Requête lourde sur fact_txn_v2 : 30 jours et cache 24 h (sur 180 jours
     * elle prend ~30 s). Cache en tableau simple (pas d'objets).
     */
    public static function billers(): array
    {
        return Cache::remember('bill_payment_billers', 86400, function () {
            $rows = DB::table('fact_txn_v2')
                ->whereIn('txn_index', array_keys(self::types()))
                ->where('transaction_initiated_time', '>=', now()->subDays(30)->toDateString())
                ->whereNotNull('credit_party_identifier')
                ->groupBy('credit_party_identifier', 'txn_index')
                ->selectRaw('credit_party_identifier AS id, MAX(credit_party_name) AS name, txn_index')
                ->get();

            return $rows->groupBy('id')
                ->map(fn ($g, $id) => [
                    'id'    => (string) $id,
                    'name'  => (string) ($g->first()->name ?: $id),
                    'types' => $g->pluck('txn_index')->map(fn ($v) => (int) $v)->values()->all(),
                ])
                ->sortBy('name')
                ->values()
                ->all();
        });
    }

    /**
     * Filtres attendus : date_debut, date_fin (obligatoires), types[], biller, status, search.
     */
    public static function apply(Builder $query, array $f): Builder
    {
        $allowed = array_keys(self::types());
        $types   = array_values(array_intersect(array_map('intval', $f['types'] ?? []), $allowed));

        // Jamais d'autres types que les factures, même si le filtre est vide ou falsifié
        $query->whereIn('txn_index', $types ?: $allowed);

        $query->where('transaction_initiated_time', '>=', $f['date_debut'] . ' 00:00:00')
              ->where('transaction_initiated_time', '<', Carbon::parse($f['date_fin'])->addDay()->format('Y-m-d') . ' 00:00:00');

        if (!empty($f['biller'])) {
            $query->where('credit_party_identifier', $f['biller']);
        }

        if (!empty($f['status'])) {
            $query->where('status', $f['status']);
        }

        if (!empty($f['search'])) {
            $term = '%' . trim($f['search']) . '%';
            $query->where(function ($q) use ($term) {
                $q->where('debit_party_identifier', 'like', $term)
                  ->orWhere('transaction_id', 'like', $term)
                  ->orWhere('ref_value', 'like', $term);
            });
        }

        return $query;
    }

    /**
     * Valeur d'une colonne pour une ligne de fact_txn_v2.
     *
     * @param array{types: array, reasons: array} $labels  libellés pré-chargés
     */
    public static function value(object $t, string $column, array $labels): string|float|null
    {
        return match ($column) {
            'date'           => $t->transaction_initiated_time ? Carbon::parse($t->transaction_initiated_time)->format('d/m/Y H:i:s') : '',
            'transaction_id' => (string) $t->transaction_id,
            'type'           => $labels['types'][$t->txn_index] ?? (string) $t->transaction_type,
            'reason'         => $labels['reasons'][$t->reason_index] ?? '',
            'client'         => (string) $t->debit_party_identifier,
            'client_type'    => $t->debit_party_type === 'Organization' ? 'Organisation' : ($t->debit_party_type === 'Customer' ? 'Client RDS' : (string) $t->debit_party_type),
            'client_name'    => (string) $t->debit_party_name,
            'biller'         => (string) $t->credit_party_identifier,
            'biller_name'    => (string) $t->credit_party_name,
            'ref_value'      => (string) $t->ref_value,
            'amount'         => round((float) $t->actual_amount * self::AMOUNT_FACTOR, 2),
            'fee'            => round((float) $t->charge_amount * self::AMOUNT_FACTOR, 2),
            'commission'     => round((float) $t->commission_amount * self::AMOUNT_FACTOR, 2),
            'status'         => (string) $t->status,
            'channel'        => (string) $t->channel,
            default          => null,
        };
    }

    /**
     * Libellés des types et reasons, chargés une fois (petites tables).
     *
     * @return array{types: array, reasons: array}
     */
    public static function labels(): array
    {
        return [
            'types'   => self::types(),
            'reasons' => DB::table('reason_types')->pluck('reason_name', 'reason_index')->all(),
        ];
    }

    /** Colonnes valides, dans l'ordre de COLUMNS. */
    public static function sanitizeColumns(?array $columns): array
    {
        $columns = array_values(array_filter(array_keys(self::COLUMNS), fn ($c) => in_array($c, $columns ?? [], true)));

        return $columns ?: self::DEFAULT_COLUMNS;
    }
}
