<?php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithStrictNullComparison;
use Maatwebsite\Excel\Concerns\WithStyles;
use Maatwebsite\Excel\Concerns\WithTitle;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * Export Excel d'un tableau déjà préparé (listes de quelques milliers de
 * lignes, générées directement sans file d'attente). Les textes restent du
 * texte (shortcodes, identifiants), les nombres restent des nombres.
 */
class SimpleArrayExport extends TextPreservingBinder implements FromArray, WithHeadings, WithTitle, WithStyles, WithStrictNullComparison, ShouldAutoSize
{
    public function __construct(private array $headings, private array $rows, private string $title = 'Export')
    {
    }

    public function array(): array
    {
        return $this->rows;
    }

    public function headings(): array
    {
        return $this->headings;
    }

    public function title(): string
    {
        return mb_substr($this->title, 0, 31);   // limite Excel des noms d'onglet
    }

    public function styles(Worksheet $sheet): array
    {
        return [1 => [
            'font' => ['bold' => true, 'color' => ['argb' => 'FFFFFFFF']],
            'fill' => ['fillType' => 'solid', 'startColor' => ['argb' => 'FF1B2F6E']],
        ]];
    }
}
