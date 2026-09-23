<?php
// app/Exports/BankBalancesExport.php

namespace App\Exports;

use Carbon\Carbon;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithStyles;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

class BankBalancesExport implements FromArray, WithStyles, ShouldAutoSize
{
    protected array $data = [];
    protected array $boldRows = [];

    public function __construct(
        protected Collection $entries,
        protected float $grandTotal,
        protected float $moneyEnCirculation,
        protected ?float $ratioEquivalence,
        protected string $balanceDate,
        protected string $moneyCirculationDate,
    ) {
        $this->build();
    }

    protected function build(): void
    {
        $rows = [];

        // ─── Money en Circulation (A1 / B1) ───
        $rows[] = ['Money en Circulation', round($this->moneyEnCirculation, 2)];
        $this->boldRows[] = count($rows);

        $rows[] = ['Date Money en Circulation', Carbon::parse($this->moneyCirculationDate)->format('d/m/Y')];
        $rows[] = [];

        // ─── Détail par compte bancaire ───
        $rows[] = ['Banque', 'Compte', 'Solde (DJF)'];
        $this->boldRows[] = count($rows);

        foreach ($this->entries as $entry) {
            $rows[] = [
                $entry->account->bank->name ?? '—',
                $entry->account->account_label ?? '—',
                round((float) $entry->balance, 2),
            ];
        }

        $rows[] = ['', 'Total général', round($this->grandTotal, 2)];
        $this->boldRows[] = count($rows);

        $rows[] = [];

        // ─── Ratio d'équivalence (dernière ligne) ───
        $rows[] = [
            "Ratio d'équivalence (%)",
            $this->ratioEquivalence !== null ? round($this->ratioEquivalence, 2) : 'N/A',
        ];
        $this->boldRows[] = count($rows);

        $this->data = $rows;
    }

    public function array(): array
    {
        return $this->data;
    }

    public function styles(Worksheet $sheet): array
    {
        $styles = [];

        foreach ($this->boldRows as $rowNumber) {
            $styles[$rowNumber] = [
                'font' => ['bold' => true],
                'fill' => [
                    'fillType'   => 'solid',
                    'startColor' => ['argb' => 'FFF7F8FC'],
                ],
            ];
        }

        return $styles;
    }
}
