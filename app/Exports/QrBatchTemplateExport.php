<?php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithColumnFormatting;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithStyles;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * Modèle de fichier pour la génération de QR codes en masse.
 * Toutes les colonnes sont au format Texte : sinon Excel transforme les
 * chaînes numériques en nombres (2,01E+95) et supprime les zéros de tête.
 */
class QrBatchTemplateExport implements FromArray, WithHeadings, WithColumnFormatting, WithStyles, ShouldAutoSize
{
    public function array(): array
    {
        return [
            ['000201010211293600161287937970713601010412980204129880300002010102000214202409211515086304E9B5', '1298', 'Exemple Marchand (à remplacer)'],
        ];
    }

    public function headings(): array
    {
        return ['chaine', 'shortcode', 'nom_marchand'];
    }

    public function columnFormats(): array
    {
        return [
            'A' => NumberFormat::FORMAT_TEXT,
            'B' => NumberFormat::FORMAT_TEXT,
            'C' => NumberFormat::FORMAT_TEXT,
        ];
    }

    public function styles(Worksheet $sheet): array
    {
        // Format texte aussi sur les lignes vides, pour les lignes que l'équipe va saisir
        $sheet->getStyle('A1:C' . 1000)->getNumberFormat()->setFormatCode(NumberFormat::FORMAT_TEXT);

        return [
            1 => [
                'font' => ['bold' => true, 'color' => ['argb' => 'FFFFFFFF']],
                'fill' => ['fillType' => 'solid', 'startColor' => ['argb' => 'FF1B2F6E']],
            ],
        ];
    }
}
