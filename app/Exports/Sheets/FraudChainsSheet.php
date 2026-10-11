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

class FraudChainsSheet extends TextPreservingBinder implements WithStrictNullComparison, FromQuery, WithTitle, WithHeadings, WithMapping, WithChunkReading, WithStyles
{
    public function __construct(private array $filters)
    {
    }

    public function query()
    {
        return FraudChains::chains($this->filters)
            ->orderBy('s.transaction_time')
            ->orderBy('c.chain_id');
    }

    public function title(): string
    {
        return 'Chaînes';
    }

    public function headings(): array
    {
        return [
            'N° chaîne', 'Début (Cash In)', 'Semaine du', 'Agent origine', 'Client origine',
            'Montant initial (FDJ)', 'Montant final (FDJ)', 'Commission initiale (FDJ)',
            'Nb Send Money', 'Nb transactions', 'Durée', 'Sortie',
            'Transaction initiale', 'Transaction finale',
        ];
    }

    public function map($c): array
    {
        return [
            (int) $c->chain_id,
            Carbon::parse($c->started_at)->format('d/m/Y H:i:s'),
            Carbon::parse($c->activity_date)->format('d/m/Y'),
            (string) $c->origin_agent_id,
            (string) $c->origin_customer_id,
            FraudChains::amount($c->initial_amount),
            FraudChains::amount($c->final_amount),
            FraudChains::amount($c->initial_commission),
            (int) $c->send_money_hops,
            (int) $c->total_transactions,
            FraudChains::duration($c->duration_seconds !== null ? (int) $c->duration_seconds : null),
            (string) $c->final_transaction_type,
            (string) $c->initial_transaction_id,
            (string) $c->final_transaction_id,
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
