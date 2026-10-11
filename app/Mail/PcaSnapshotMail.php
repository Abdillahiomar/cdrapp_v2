<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Email PCA : fichier des derniers soldes en pièce jointe (ZIP chiffré).
 * Le mot de passe n'est jamais envoyé dans l'email.
 */
class PcaSnapshotMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public string $zipPath,
        public int $rows,
        public string $dataUntil,
        public bool $nonZeroOnly,
    ) {
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'PCA D-Money — Derniers soldes des comptes au ' . $this->dataUntil);
    }

    public function content(): Content
    {
        return new Content(htmlString: '
            <p>Bonjour,</p>
            <p>Vous trouverez en pièce jointe les <strong>derniers soldes connus</strong> des comptes D-Money,
            à utiliser en cas d\'indisponibilité du service (plan de continuité d\'activité).</p>
            <ul>
                <li>Données jusqu\'au : <strong>' . e($this->dataUntil) . '</strong></li>
                <li>Nombre de comptes : <strong>' . number_format($this->rows, 0, ',', ' ') . '</strong>'
                    . ($this->nonZeroOnly ? ' (comptes avec un solde non nul)' : '') . '</li>
            </ul>
            <p>Le fichier est un <strong>ZIP protégé par mot de passe</strong> (AES-256). Le mot de passe vous a été
            communiqué séparément par l\'équipe conformité : il n\'est jamais envoyé par email.</p>
            <p>Les transactions effectuées après cette date ne sont pas prises en compte.</p>
            <p style="color:#6b7280;font-size:12px;">Email automatique — CDRAPP.</p>
        ');
    }

    public function attachments(): array
    {
        return [Attachment::fromPath($this->zipPath)->as(basename($this->zipPath))->withMime('application/zip')];
    }
}
