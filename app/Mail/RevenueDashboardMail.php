<?php

namespace App\Mail;

use Carbon\Carbon;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Tableau de bord des revenus (mois en cours) : chiffres clés dans le corps
 * de l'email, page complète avec les graphiques en PDF joint.
 */
class RevenueDashboardMail extends Mailable
{
    use Queueable, SerializesModels;

    /** @param array<string, float|int|null> $kpi */
    public function __construct(
        public string $pdfPath,
        public string $periodFrom,
        public string $periodTo,
        public array $kpi,
        public ?string $lastDate,
    ) {
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'D-Money — Tableau de bord des revenus, ' . $this->monthLabel() . ' (au ' . Carbon::parse($this->periodTo)->format('d/m/Y') . ')');
    }

    public function content(): Content
    {
        $n   = fn ($v, $d = 0) => number_format((float) $v, $d, ',', ' ');
        $k   = $this->kpi;
        $var = $k['variation_volume'] ?? null;

        $rows = [
            ['Valeur totale', $n($k['volume_total']) . ' FDJ'
                . ($var !== null ? ' <span style="color:' . ($var >= 0 ? '#005C2B' : '#B42318') . ';">(' . ($var >= 0 ? '▲' : '▼') . ' ' . $n(abs($var), 1) . ' % vs période préc.)</span>' : '')],
            ['Nombre de transactions', $n($k['nb_transactions'])],
            ['Revenus (frais − commissions)', $n($k['revenus']) . ' FDJ'],
            ['Claw back', $n($k['total_clawback']) . ' FDJ'],
            ['Revenu total', '<strong>' . $n($k['revenus'] + $k['total_clawback']) . ' FDJ</strong>'],
            ['Ticket moyen', $n($k['ticket_moyen']) . ' FDJ'],
            ['Taux de réussite', $n($k['taux_reussite'], 1) . ' %'],
        ];

        $table = collect($rows)->map(fn ($r) => '<tr><td style="padding:6px 12px;color:#6b7280;border-bottom:1px solid #eee;">' . $r[0]
            . '</td><td style="padding:6px 12px;text-align:right;border-bottom:1px solid #eee;">' . $r[1] . '</td></tr>')->implode('');

        $late = $this->lastDate && $this->lastDate < $this->periodTo
            ? '<p style="color:#92400E;">Les chiffres s\'arrêtent au ' . Carbon::parse($this->lastDate)->format('d/m/Y') . ' (dernier jour agrégé).</p>'
            : '';

        return new Content(htmlString: '
            <div style="font-family:Arial,sans-serif;font-size:14px;color:#111827;">
            <p>Bonjour,</p>
            <p>Voici le tableau de bord des revenus D-Money pour <strong>' . e($this->monthLabel()) . '</strong>,
            du ' . Carbon::parse($this->periodFrom)->format('d/m/Y') . ' au ' . Carbon::parse($this->periodTo)->format('d/m/Y') . '.</p>
            ' . $late . '
            <table style="border-collapse:collapse;min-width:340px;">' . $table . '</table>
            <p>Le tableau de bord complet, avec tous les graphiques et le suivi Airtime, est en pièce jointe (PDF).</p>
            <p style="color:#6b7280;font-size:12px;">Email automatique — CDRAPP. Les chiffres sont ceux de la page « Tableau de bord ».</p>
            </div>
        ');
    }

    public function attachments(): array
    {
        return [Attachment::fromPath($this->pdfPath)
            ->as('tableau-de-bord-revenus-' . Carbon::parse($this->periodTo)->format('Y-m-d') . '.pdf')
            ->withMime('application/pdf')];
    }

    private function monthLabel(): string
    {
        return ucfirst(Carbon::parse($this->periodTo)->locale('fr')->translatedFormat('F Y'));
    }
}
