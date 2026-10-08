<?php

namespace App\Exports;

use App\Support\BillPayments;
use Maatwebsite\Excel\Concerns\FromQuery;
use Maatwebsite\Excel\Concerns\WithChunkReading;
use Maatwebsite\Excel\Concerns\WithCustomCsvSettings;
use Maatwebsite\Excel\Concerns\WithCustomValueBinder;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\WithStyles;
use PhpOffice\PhpSpreadsheet\Cell\Cell;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Cell\DefaultValueBinder;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * Export des paiements de factures, avec les colonnes choisies par l'utilisateur.
 * Sert pour l'Excel et le CSV (seul le format d'écriture change).
 *
 * Les valeurs texte sont écrites telles quelles : sinon Excel convertit les
 * Transaction ID (000419952199) et les références en nombres et perd les zéros
 * de tête. Seuls les montants restent numériques.
 */
class BillPaymentsExport extends DefaultValueBinder implements FromQuery, WithHeadings, WithMapping, WithChunkReading, WithStyles, WithCustomValueBinder, WithCustomCsvSettings
{
    private array $labels;

    public function __construct(protected $query, protected array $columns)
    {
        $this->columns = BillPayments::sanitizeColumns($columns);
        $this->labels  = BillPayments::labels();
    }

    public function query()
    {
        return $this->query;
    }

    public function headings(): array
    {
        return array_map(fn ($c) => BillPayments::COLUMNS[$c], $this->columns);
    }

    public function map($t): array
    {
        return array_map(fn ($c) => BillPayments::value($t, $c, $this->labels), $this->columns);
    }

    public function bindValue(Cell $cell, $value): bool
    {
        if (is_int($value) || is_float($value)) {
            $cell->setValueExplicit($value, DataType::TYPE_NUMERIC);
        } else {
            $cell->setValueExplicit((string) $value, DataType::TYPE_STRING);
        }

        return true;
    }

    /**
     * CSV comme les autres exports du projet : « ; » (Excel en français) et BOM
     * UTF-8 pour que les accents s'affichent correctement à l'ouverture.
     */
    public function getCsvSettings(): array
    {
        return [
            'delimiter' => ';',
            'use_bom'   => true,
        ];
    }

    public function chunkSize(): int
    {
        return 1000;
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
