<?php

namespace App\Exports;

use App\Support\Customers;
use Carbon\Carbon;
use Maatwebsite\Excel\Concerns\FromQuery;
use Maatwebsite\Excel\Concerns\WithChunkReading;
use Maatwebsite\Excel\Concerns\WithCustomCsvSettings;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\WithStrictNullComparison;

/**
 * Export des clients du module Customers (annuaire filtré ou liste de contrôle).
 * Peut dépasser 100 000 lignes : toujours généré par la file d'attente.
 */
class CustomersBaseExport extends TextPreservingBinder implements WithStrictNullComparison, FromQuery, WithHeadings, WithMapping, WithChunkReading, WithCustomCsvSettings
{
    public function __construct(private array $filters)
    {
    }

    public function query()
    {
        return Customers::applyFilters(Customers::customers(true), $this->filters)->orderBy('c.msisdn');
    }

    public function headings(): array
    {
        return [
            'MSISDN', 'Segment', 'Produit', 'Statut', 'Compte principal', 'Activité', 'Dernière transaction', 'Première transaction',
            'Inscrit le', 'Activé le', 'Pièce', 'Date de naissance', 'Dans le référentiel',
        ];
    }

    public function map($c): array
    {
        $date = fn ($v, $f = 'd/m/Y') => $v ? Carbon::parse($v)->format($f) : '';

        return [
            (string) $c->msisdn, (string) $c->segment, (string) $c->products, (string) $c->identity_status,
            (string) ($c->main_account_status ?? 'Absent'),
            Customers::ACTIVITY[$c->activity][0] ?? $c->activity,
            $date($c->last_txn_at, 'd/m/Y H:i'), $date($c->first_activity_date),
            $date($c->registered_at), $date($c->activated_at), (string) $c->id_type, $date($c->date_of_birth),
            $c->in_last_full ? 'Oui' : 'Non',
        ];
    }

    public function getCsvSettings(): array
    {
        return ['delimiter' => ';', 'use_bom' => true];
    }

    public function chunkSize(): int
    {
        return 2000;
    }
}
