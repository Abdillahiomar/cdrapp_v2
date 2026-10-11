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

class StructuringClientsSheet extends TextPreservingBinder implements WithStrictNullComparison, FromQuery, WithTitle, WithHeadings, WithMapping, WithChunkReading, WithStyles
{
    public function __construct(private array $filters)
    {
    }

    public function query()
    {
        return FraudReports::structuring($this->filters);
    }

    public function title(): string
    {
        return 'Clients';
    }

    public function headings(): array
    {
        return ['Date', 'Client', 'Agents distincts', 'Nb cash-in', 'Montant total (FDJ)'];
    }

    public function map($r): array
    {
        return [
            Carbon::parse($r->activity_date)->format('d/m/Y'),
            (string) $r->customer_id,
            (int) $r->unique_agents,
            (int) $r->total_cashin_count,
            FraudChains::amount($r->total_cashin_amount),
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
