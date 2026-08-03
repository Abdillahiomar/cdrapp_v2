<?php
// app/Exports/MsisdnResultatsExport.php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithStyles;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithMapping;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use Carbon\Carbon;

class MsisdnResultatsExport implements FromCollection, WithHeadings, WithStyles, ShouldAutoSize
{
    public function __construct(protected $rows) {}

    public function collection()
    {
        return $this->rows->map(function ($row, $i) {

            $trustLevel = match((int) $row['trust_level']) {
                9       => 'Full KYC',
                3       => 'Lite Customer',
                1       => 'Unregistered Customer',
                default => '—',
            };

            $status = match($row['status'] ?? '') {
                '03'    => 'Active',
                '06'    => 'Closed',
                '02'    => 'Pending Active',
                default => $row['trouve'] ? 'Inactif' : '—',
            };

            return [
                $i + 1,
                $row['msisdn'],
                $row['trouve'] ? 'Trouvé' : 'Introuvable',
                $row['create_time']
                    ? Carbon::parse($row['create_time'])->format('d/m/Y H:i')
                    : '—',
                $row['full_name']   ?? '—',
                $row['mother_name'] ?? '—',
                $row['trouve'] ? $trustLevel : '—',
                $row['trouve'] ? $status     : '—',
            ];
        });
    }

    public function headings(): array
    {
        return [
            '#',
            'MSISDN',
            'Statut recherche',
            'Date création',
            'Nom complet',
            'Nom de la mère',
            'Trust Level',
            'Statut compte',
        ];
    }

    public function styles(Worksheet $sheet): array
    {
        return [
            1 => [
                'font' => [
                    'bold'  => true,
                    'color' => ['argb' => 'FFFFFFFF'],
                ],
                'fill' => [
                    'fillType'   => 'solid',
                    'startColor' => ['argb' => 'FF1B2F6E'],
                ],
            ],
        ];
    }
}