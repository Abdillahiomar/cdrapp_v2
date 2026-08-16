<?php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\FromQuery;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\WithStyles;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithChunkReading;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use Carbon\Carbon;

class TransactionsExcelExport implements FromQuery, WithHeadings, WithMapping, WithStyles, ShouldAutoSize, WithChunkReading
{
    private int $counter = 0;

    public function __construct(protected $query) {}

    public function query()
    {
        return $this->query->with('transactionType');
    }

    public function map($t): array
    {
        $this->counter++;

        return [
            $this->counter,
            $t->transaction_initiated_time
                ? Carbon::parse($t->transaction_initiated_time)->format('d/m/Y H:i')
                : '—',
            $t->transaction_id,
            $t->status,
            $t->channel ?? '—',
            $t->reason ?? '—',
            $t->transactionType?->txn_type_name ?? $t->transaction_type ?? $t->txn_index,
            $t->debit_party_identifier,
            $t->credit_party_identifier,
            $t->actual_amount,
            $t->charge_amount,
            $t->commission_amount,
        ];
    }

    public function headings(): array
    {
        return [
            '#', 'Date', 'Transaction ID', 'Statut', 'Canal', 'Reason',
            'Type de transaction', 'Debit Party', 'Credit Party',
            'Montant (DJF)', 'Charge', 'Commission',
        ];
    }

    public function chunkSize(): int
    {
        return 1000;
    }

    public function styles(Worksheet $sheet): array
    {
        return [
            1 => [
                'font' => ['bold' => true, 'color' => ['argb' => 'FFFFFFFF']],
                'fill' => ['fillType' => 'solid', 'startColor' => ['argb' => 'FF1B2F6E']],
            ],
        ];
    }
}