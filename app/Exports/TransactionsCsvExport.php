<?php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\FromQuery;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\WithChunkReading;
use Maatwebsite\Excel\Concerns\WithCustomCsvSettings;
use Carbon\Carbon;

class TransactionsCsvExport implements FromQuery, WithHeadings, WithMapping, WithChunkReading, WithCustomCsvSettings
{
    private int $counter = 0;

    private $typeNames;
    private $reasonNames;

    public function __construct(protected $query)
    {
        $this->typeNames = \DB::table('transaction_types')
            ->select('txn_index', 'txn_type_name')
            ->get()
            ->keyBy('txn_index')
            ->map(fn($r) => $r->txn_type_name);

        $this->reasonNames = \DB::table('reason_types')
            ->select('reason_index', 'reason_name')
            ->get()
            ->keyBy('reason_index')
            ->map(fn($r) => $r->reason_name);
    }

    public function query()
    {
        return $this->query;
    }

    public function getCsvSettings(): array
    {
        return [
            'delimiter' => ';',
            'use_bom'   => true,
            'enclosure' => '"',
        ];
    }

    public function map($t): array
    {
        $this->counter++;

        return [
            $this->counter,
            $t->transaction_initiated_time
                ? Carbon::parse($t->transaction_initiated_time)->format('d/m/Y H:i')
                : '',
            $t->transaction_id,
            $t->status,
            $t->channel ?? '',
            $this->reasonNames[$t->reason_index] ?? '',
            $this->typeNames[$t->txn_index] ?? $t->transaction_type ?? $t->txn_index,
            $t->debit_party_identifier,
            $t->credit_party_identifier,
            $t->balance_before_debit  * 100,
            $t->balance_after_debit   * 100,
            $t->balance_before_credit * 100,
            $t->balance_after_credit  * 100,
            $t->actual_amount     * 100,
            $t->charge_amount     * 100,
            $t->commission_amount * 100,
        ];
    }

    public function headings(): array
    {
        return [
            '#', 'Date', 'Transaction ID', 'Statut', 'Canal', 'Reason',
            'Transaction Type', 'Debit Party', 'Credit Party',
            'Debit Balance Avant', 'Debit Balance Apres',
            'Credit Balance Avant', 'Credit Balance Apres',
            'Amount', 'Fee', 'Commission',
        ];
    }

    public function chunkSize(): int
    {
        return 1000;
    }
}