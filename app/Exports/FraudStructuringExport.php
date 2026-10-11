<?php

namespace App\Exports;

use App\Exports\Sheets\StructuringAgentsSheet;
use App\Exports\Sheets\StructuringClientsSheet;
use Maatwebsite\Excel\Concerns\WithMultipleSheets;

/**
 * Export Excel « Structuring » : onglet « Clients » (une ligne par client et
 * par jour, comme l'écran) et onglet « Détail par agent », reliés par la
 * date et le client.
 */
class FraudStructuringExport implements WithMultipleSheets
{
    public function __construct(private array $filters)
    {
    }

    public function sheets(): array
    {
        return [
            new StructuringClientsSheet($this->filters),
            new StructuringAgentsSheet($this->filters),
        ];
    }
}
