<?php
// app/Exports/MsisdnResultatsExport.php

namespace App\Exports;

use Carbon\Carbon;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithColumnFormatting;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithStyles;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * Résultats de la vérification de MSISDN (operations/bulk_search),
 * mêmes colonnes que le tableau affiché.
 */
class MsisdnResultatsExport implements FromCollection, WithHeadings, WithStyles, WithColumnFormatting, ShouldAutoSize
{
    private const MATCH_LABELS = ['oui' => 'Oui', 'proche' => 'Proche', 'non' => 'Non'];

    public function __construct(protected $rows) {}

    public function collection()
    {
        return $this->rows->values()->map(fn ($row, $i) => [
            $i + 1,
            $row['line'],
            $row['msisdn'],
            $row['trouve'] ? 'Trouvé' : 'Introuvable',
            $row['source_datetime'] ? Carbon::parse($row['source_datetime'])->format('d/m/Y H:i') : '',
            $row['file_name'] ?? '',
            $row['full_name'] ?? '',
            self::MATCH_LABELS[$row['name_match'] ?? ''] ?? '',
            $row['name_score'] !== null ? $row['name_score'] . ' %' : '',
            $row['mother_name'] ?? '',
            $row['customer_profile'] ?? '',
            $row['channel'] ?? '',
            $row['id_type'] ?? '',
        ]);
    }

    public function headings(): array
    {
        return [
            '#',
            'Ligne du fichier',
            'MSISDN',
            'Statut recherche',
            'Date',
            'Nom (fichier)',
            'Nom (base KYC)',
            'Même nom',
            'Ressemblance',
            'Nom de la mère',
            'Profil',
            'Canal',
            'Pièce',
        ];
    }

    public function columnFormats(): array
    {
        // MSISDN en texte : sinon Excel l'affiche en notation scientifique
        return ['C' => NumberFormat::FORMAT_TEXT];
    }

    public function styles(Worksheet $sheet): array
    {
        return [
            1 => [
                'font' => ['bold' => true, 'color' => ['argb' => 'FFFFFFFF']],
                'fill' => ['fillType' => 'solid', 'startColor' => ['argb' => 'FF1B2F6E']],
            ],
        ];
    }
}
