<?php

namespace App\Exports;

use App\Support\FraudChains;
use Carbon\Carbon;
use Maatwebsite\Excel\Concerns\FromQuery;
use Maatwebsite\Excel\Concerns\WithChunkReading;
use Maatwebsite\Excel\Concerns\WithCustomCsvSettings;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\WithStrictNullComparison;

/**
 * Export CSV « à plat » des chaînes S3 : une ligne par transaction, les
 * informations de la chaîne répétées sur chaque ligne (pratique pour les
 * tableaux croisés). La rétention n'est volontairement pas exportée.
 */
class FraudChainsFlatExport extends TextPreservingBinder implements WithStrictNullComparison, FromQuery, WithHeadings, WithMapping, WithChunkReading, WithCustomCsvSettings
{
    public function __construct(private array $filters)
    {
    }

    public function query()
    {
        return FraudChains::members($this->filters)
            ->orderBy('s.transaction_time')
            ->orderBy('m.chain_id')
            ->orderBy('m.sequence_number');
    }

    public function headings(): array
    {
        return [
            'N° chaîne', 'Début chaîne (Cash In)', 'Semaine du', 'Agent origine', 'Client origine',
            'Montant initial chaîne (FDJ)', 'Montant final chaîne (FDJ)', 'Nb Send Money', 'Nb transactions',
            'Durée chaîne', 'Sortie',
            'Ordre', 'Date / heure transaction', 'Type', 'De', 'Vers', 'Montant (FDJ)', 'Commission (FDJ)', 'Transaction ID',
        ];
    }

    public function map($m): array
    {
        return [
            (int) $m->chain_id,
            Carbon::parse($m->started_at)->format('d/m/Y H:i:s'),
            Carbon::parse($m->activity_date)->format('d/m/Y'),
            (string) $m->origin_agent_id,
            (string) $m->origin_customer_id,
            FraudChains::amount($m->initial_amount),
            FraudChains::amount($m->final_amount),
            (int) $m->send_money_hops,
            (int) $m->total_transactions,
            FraudChains::duration($m->duration_seconds !== null ? (int) $m->duration_seconds : null),
            (string) $m->final_transaction_type,
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

    /** « ; » et BOM UTF-8, comme les autres exports CSV du projet. */
    public function getCsvSettings(): array
    {
        return ['delimiter' => ';', 'use_bom' => true];
    }

    public function chunkSize(): int
    {
        return 1000;
    }
}
