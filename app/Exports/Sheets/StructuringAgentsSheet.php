<?php

namespace App\Exports\Sheets;

use App\Exports\TextPreservingBinder;
use App\Support\FraudChains;
use App\Support\FraudReports;
use Carbon\Carbon;
use Maatwebsite\Excel\Concerns\FromQuery;
use Maatwebsite\Excel\Concerns\WithChunkReading;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\WithStrictNullComparison;
use Maatwebsite\Excel\Concerns\WithStyles;
use Maatwebsite\Excel\Concerns\WithTitle;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

class StructuringAgentsSheet extends TextPreservingBinder implements WithStrictNullComparison, FromQuery, WithTitle, WithHeadings, WithMapping, WithChunkReading, WithStyles
{
    public function __construct(private array $filters)
    {
    }

    public function query()
    {
        return FraudReports::structuringDetail($this->filters);
    }

    public function title(): string
    {
        return 'Détail par agent';
    }

    public function headings(): array
    {
        return ['Date', 'Client', 'Agent', 'Nb cash-in', 'Montant (FDJ)'];
    }

    public function map($r): array
    {
        return [
            Carbon::parse($r->activity_date)->format('d/m/Y'),
            (string) $r->customer_id,
            (string) $r->agent_id,
            (int) $r->cashin_count,
            FraudChains::amount($r->cashin_amount),
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
