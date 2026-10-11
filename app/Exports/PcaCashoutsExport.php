<?php

namespace App\Exports;

use App\Models\PcaCashout;
use App\Models\PcaIncident;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithStrictNullComparison;
use Maatwebsite\Excel\Concerns\WithStyles;
use Maatwebsite\Excel\Concerns\WithTitle;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * Cash-out payés pendant un incident PCA, pour leur régularisation dans le
 * système D-Money après le retour du service. Les annulations sont incluses
 * (colonne Statut) pour garder la trace complète.
 */
class PcaCashoutsExport extends TextPreservingBinder implements FromCollection, WithTitle, WithHeadings, WithStyles, WithStrictNullComparison, ShouldAutoSize
{
    public function __construct(private PcaIncident $incident)
    {
    }

    public function collection()
    {
        return PcaCashout::with(['recorder:id,name', 'canceller:id,name'])
            ->where('incident_id', $this->incident->id)
            ->orderBy('created_at')
            ->get()
            ->map(fn (PcaCashout $c) => [
                (int) $c->id,
                $c->created_at->format('d/m/Y H:i:s'),
                (string) $c->party_id,
                $c->party_type === 'Organization' ? 'Organisation' : 'Client',
                (string) $c->account_type,
                (float) $c->amount,
                (float) $c->available_before,
                (string) $c->location,
                (string) $c->id_document,
                (string) ($c->recorder->name ?? ''),
                $c->cancelled_at ? 'Annulé' : 'Payé',
                $c->cancelled_at ? $c->cancelled_at->format('d/m/Y H:i') . ' par ' . ($c->canceller->name ?? '?') . ' : ' . $c->cancel_reason : '',
                (string) $c->notes,
            ]);
    }

    public function title(): string
    {
        return 'Cash-out PCA';
    }

    public function headings(): array
    {
        return [
            'N°', 'Date / heure', 'Compte', 'Titulaire', 'Type de compte', 'Montant payé (FDJ)', 'Solde PCA disponible avant (FDJ)',
            'Agence / agent', 'Pièce vérifiée', 'Saisi par', 'Statut', 'Annulation', 'Notes',
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
