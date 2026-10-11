<?php

namespace App\Exports;

use App\Exports\Sheets\FraudChainMembersSheet;
use App\Exports\Sheets\FraudChainsSheet;
use Maatwebsite\Excel\Concerns\WithMultipleSheets;

/**
 * Export Excel des chaînes S3 : onglet « Chaînes » (une ligne par chaîne) et
 * onglet « Transactions » (une ligne par maillon), reliés par le n° de chaîne.
 * La rétention n'est volontairement pas exportée.
 */
class FraudChainsExport implements WithMultipleSheets
{
    public function __construct(private array $filters)
    {
    }

    public function sheets(): array
    {
        return [
            new FraudChainsSheet($this->filters),
            new FraudChainMembersSheet($this->filters),
        ];
    }
}
