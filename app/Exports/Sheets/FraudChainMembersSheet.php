<?php

namespace App\Exports\Sheets;

use App\Exports\TextPreservingBinder;
use App\Support\FraudChains;
use Carbon\Carbon;
use Maatwebsite\Excel\Concerns\FromQuery;
use Maatwebsite\Excel\Concerns\WithChunkReading;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\WithStrictNullComparison;
use Maatwebsite\Excel\Concerns\WithStyles;
use Maatwebsite\Excel\Concerns\WithTitle;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

class FraudChainMembersSheet extends TextPreservingBinder implements WithStrictNullComparison, FromQuery, WithTitle, WithHeadings, WithMapping, WithChunkReading, WithStyles
{
    public function __construct(private array $filters)
    {
    }

    public function query()
    {
        // Même ordre de chaînes que l'onglet « Chaînes », puis maillons dans l'ordre
        return FraudChains::members($this->filters)
            ->orderBy('s.transaction_time')
            ->orderBy('m.chain_id')
            ->orderBy('m.sequence_number');
    }

    public function title(): string
    {
        return 'Transactions';
    }

    public function headings(): array
    {
        return ['N° chaîne', 'Ordre', 'Date / heure', 'Type', 'De', 'Vers', 'Montant (FDJ)', 'Commission (FDJ)', 'Transaction ID'];
    }

    public function map($m): array
    {
        return [
            (int) $m->chain_id,
            (int) $m->sequence_number,
            Carbon::parse($m->transaction_time)->format('d/m/Y H:i:s'),
            (string) $m->transaction_type,
            (string) $m->from_msisdn,
            (string) $m->to_msisdn,
            FraudChains::amount($m->amount),
            FraudChains::amount($m->commission),
            (string) $m->transaction_id,
        ];
    }

    public function chunkSize(): int
    {
        return 1000;
    }

    public function styles(Worksheet $sheet): array
    {
        return [1 => [
            'font' => ['bold' => true, 'color' => ['argb' => 'FFFFFFFF']],
            'fill' => ['fillType' => 'solid', 'startColor' => ['argb' => 'FF1B2F6E']],
        ]];
    }
}
