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

/**
 * Export CSV « à plat » du structuring : une ligne par agent, les totaux du
 * client (pour ce jour) répétés sur chaque ligne.
 */
class FraudStructuringFlatExport extends TextPreservingBinder implements WithStrictNullComparison, FromQuery, WithHeadings, WithMapping, WithChunkReading, WithCustomCsvSettings
{
    public function __construct(private array $filters)
    {
    }

    public function query()
    {
        return FraudReports::structuringDetail($this->filters);
    }

    public function headings(): array
    {
        return [
            'Date', 'Client', 'Agents distincts (client)', 'Nb cash-in (client)', 'Montant total client (FDJ)',
            'Agent', 'Nb cash-in (agent)', 'Montant agent (FDJ)',
        ];
    }

    public function map($r): array
    {
        return [
            Carbon::parse($r->activity_date)->format('d/m/Y'),
            (string) $r->customer_id,
            (int) $r->unique_agents,
            (int) $r->total_cashin_count,
            FraudChains::amount($r->total_cashin_amount),
            (string) $r->agent_id,
            (int) $r->cashin_count,
            FraudChains::amount($r->cashin_amount),
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
}
