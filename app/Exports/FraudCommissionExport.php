<?php

namespace App\Exports;

use App\Support\FraudChains;
use App\Support\FraudReports;
use Carbon\Carbon;
use Maatwebsite\Excel\Concerns\FromQuery;
use Maatwebsite\Excel\Concerns\WithChunkReading;
use Maatwebsite\Excel\Concerns\WithCustomCsvSettings;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\WithStrictNullComparison;
use Maatwebsite\Excel\Concerns\WithStyles;
use Maatwebsite\Excel\Concerns\WithTitle;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * Export « Commission indue » : une ligne par couple agent/client, mêmes
 * colonnes que l'écran. Sert pour l'Excel et le CSV.
 */
class FraudCommissionExport extends TextPreservingBinder implements WithStrictNullComparison, FromQuery, WithTitle, WithHeadings, WithMapping, WithChunkReading, WithStyles, WithCustomCsvSettings
{
    public function __construct(private array $filters)
    {
    }

    public function query()
    {
        return FraudReports::commission($this->filters);
    }

    public function title(): string
    {
        return 'Commission indue';
    }

    public function headings(): array
    {
        return ['Période du', 'au', 'Agent', 'Client', 'Nb cash-in', 'Montant total (FDJ)', 'Commission totale (FDJ)'];
    }

    public function map($r): array
    {
        return [
            Carbon::parse($this->filters['date_debut'])->format('d/m/Y'),
            Carbon::parse($this->filters['date_fin'])->format('d/m/Y'),
            (string) $r->agent_id,
            (string) $r->customer_id,
            (int) $r->nb_cashin,
            FraudChains::amount($r->total_amount),
            FraudChains::amount($r->total_commission),
        ];
    }

    public function getCsvSettings(): array
    {
        return ['delimiter' => ';', 'use_bom' => true];
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
