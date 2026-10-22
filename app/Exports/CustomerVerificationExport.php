<?php

namespace App\Exports;

use App\Support\CustomerVerification;
use App\Support\Customers;
use Carbon\Carbon;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithStrictNullComparison;
use Maatwebsite\Excel\Concerns\WithStyles;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * Résultats de la page « Vérification clients » (operations/verification),
 * mêmes colonnes que le tableau affiché.
 */
class CustomerVerificationExport extends TextPreservingBinder implements FromCollection, WithHeadings, WithStyles, WithStrictNullComparison, ShouldAutoSize
{
    private const MATCH = ['oui' => 'Oui', 'proche' => 'Proche', 'non' => 'Non'];

    public function __construct(private $rows)
    {
    }

    public function collection()
    {
        $date = fn ($v) => $v ? Carbon::parse($v)->format('d/m/Y') : '';

        return $this->rows->values()->map(fn ($r) => [
            (int) $r['line'],
            (string) $r['msisdn'],
            CustomerVerification::RESULTS[$r['result']][0] ?? $r['result'],
            (string) $r['reason'],
            (string) $r['file_name'],
            (string) $r['db_name'],
            (string) $r['name_source'],
            self::MATCH[$r['name_match'] ?? ''] ?? '',
            $r['name_score'] !== null ? $r['name_score'] . ' %' : '',
            (string) $r['mother_name'],
            (string) $r['identity_status'],
            (string) $r['main_account'],
            (string) $r['segment'],
            (string) $r['product'],
            (string) $r['id_type'],
            $date($r['registered_at']),
            $date($r['activated_at']),
            $r['last_txn_at'] ? Carbon::parse($r['last_txn_at'])->format('d/m/Y H:i') : '',
            Customers::ACTIVITY[$r['activity'] ?? ''][0] ?? '',
        ]);
    }

    public function headings(): array
    {
        return [
            'Ligne du fichier', 'MSISDN', 'Vérification', 'Détail', 'Nom (fichier)', 'Nom (base)', 'Source du nom', 'Même nom', 'Ressemblance',
            'Nom de la mère', 'Statut client', 'Compte principal', 'Segment', 'Produit', 'Pièce', 'Inscrit le', 'Activé le', 'Dernière transaction', 'Activité',
        ];
    }

    public function styles(Worksheet $sheet): array
    {
        return [1 => [
            'font' => ['bold' => true, 'color' => ['argb' => 'FFFFFFFF']],
            'fill' => ['fillType' => 'solid', 'startColor' => ['argb' => 'FF1B2F6E']],
        ]];
    }
}
