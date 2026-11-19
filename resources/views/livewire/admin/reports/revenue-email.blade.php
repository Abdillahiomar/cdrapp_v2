<?php

use App\Jobs\SendRevenueReport;
use App\Models\ReportSetting;
use App\Support\RevenueReport;
use Livewire\Volt\Component;

/**
 * Réglages de l'envoi du tableau de bord des revenus par email
 * (vendredi et samedi à 06:00, mois en cours). Permission : reports.email.manage.
 */
new class extends Component {

    /** @var string[] */
    public array $recipients = [];

    public string $newEmail = '';

    public bool $enabled = false;

    public function boot(): void
    {
        abort_unless(auth()->user()?->can('reports.email.manage'), 403);
    }

    public function mount(): void
    {
        $this->recipients = ReportSetting::revenueRecipients();
        $this->enabled    = ReportSetting::revenueEnabled();
    }

    public function addRecipient(): void
    {
        $this->resetErrorBag();
        $email = mb_strtolower(trim($this->newEmail));

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->addError('newEmail', 'Adresse email invalide.');
            return;
        }
        if (in_array($email, $this->recipients, true)) {
            $this->addError('newEmail', 'Cette adresse est déjà dans la liste.');
            return;
        }

        $this->recipients[] = $email;
        $this->newEmail     = '';
        $this->persist('Destinataire ajouté.');
    }

    public function removeRecipient(int $i): void
    {
        unset($this->recipients[$i]);
        $this->recipients = array_values($this->recipients);

        if (empty($this->recipients) && $this->enabled) {
            $this->enabled = false;
        }
        $this->persist('Destinataire retiré.');
    }

    public function updatedEnabled(): void
    {
        $this->resetErrorBag();
        if ($this->enabled && empty($this->recipients)) {
            $this->enabled = false;
            $this->addError('newEmail', 'Ajoutez au moins un destinataire avant d\'activer l\'envoi.');
            return;
        }
        $this->persist($this->enabled ? 'Envoi automatique activé.' : 'Envoi automatique désactivé.');
    }

    public function sendNow(): void
    {
        if (empty($this->recipients)) {
            $this->addError('newEmail', 'Ajoutez au moins un destinataire.');
            return;
        }

        SendRevenueReport::dispatch();
        session()->flash('report-message', 'Envoi lancé en arrière-plan : le résultat apparaîtra dans « Dernier envoi » d\'ici une à deux minutes.');
    }

    private function persist(string $message): void
    {
        ReportSetting::setRevenueRecipients($this->recipients);
        ReportSetting::put('revenue_enabled', $this->enabled ? '1' : '0');
        session()->flash('report-message', $message);
    }

    public function with(): array
    {
        [$from, $to] = RevenueReport::period();

        return [
            'from'    => $from,
            'to'      => $to,
            'preview' => RevenueReport::signedPath($from, $to),
            'lastAt'  => ReportSetting::get('revenue_last_at'),
            'last'    => ReportSetting::get('revenue_last_status'),
            'mailer'  => config('mail.default'),
        ];
    }
};
?>
@php
    $card       = 'background:#fff; border:1px solid #e5e7eb; border-radius:10px; padding:18px; margin-bottom:16px;';
    $inputStyle = 'border:1px solid #d1d5db; border-radius:7px; padding:8px 10px; font-size:13px; color:#111827; outline:none; background:#fff;';
    $btnPrimary = 'background:#1B2F6E; color:#fff; font-size:13px; font-weight:600; padding:9px 16px; border-radius:8px; border:none; cursor:pointer;';
    $btnLight   = 'background:#fff; color:#1B2F6E; font-size:13px; font-weight:600; padding:8px 16px; border-radius:8px; border:1px solid #d1d5db; cursor:pointer; text-decoration:none; display:inline-block;';
@endphp
<div style="padding:24px; max-width:900px;">

    <p style="font-size:16px; font-weight:700; color:#111827; margin-bottom:4px;">Envoi du tableau de bord par email</p>
    <p style="font-size:12px; color:#6b7280; margin-bottom:16px;">
        Le <strong>tableau de bord des revenus</strong> du mois en cours (jusqu'à la veille) est envoyé
        <strong>le vendredi et le samedi à 06:00</strong> : chiffres clés dans l'email et page complète avec les graphiques en PDF joint.
    </p>

    @if($mailer === 'log')
        <div style="background:#FFF7E0; border-left:3px solid #F5A800; border-radius:8px; padding:10px 14px; font-size:12px; color:#7A4F00; margin-bottom:14px;">
            L'envoi d'emails n'est pas configuré sur ce serveur (MAIL_MAILER=log) : les emails sont écrits dans les logs au lieu d'être envoyés.
        </div>
    @endif

    @if(session('report-message'))
        <div style="background:#E6F4EC; border-left:3px solid #00843D; border-radius:8px; padding:10px 14px; font-size:12px; color:#005C2B; margin-bottom:14px;">
            {{ session('report-message') }}
        </div>
    @endif

    <div style="{{ $card }}">
        <p style="font-size:13px; font-weight:700; color:#111827; margin-bottom:10px;">Destinataires ({{ count($recipients) }})</p>

        <form wire:submit="addRecipient" style="display:flex; gap:8px; flex-wrap:wrap; margin-bottom:6px;">
            <input type="email" wire:model="newEmail" placeholder="prenom.nom@d-money.dj" style="{{ $inputStyle }} flex:1; min-width:240px;">
            <button type="submit" style="{{ $btnPrimary }}">+ Ajouter</button>
        </form>
        @error('newEmail') <p style="font-size:11px; color:#B91C1C; margin-bottom:6px;">{{ $message }}</p> @enderror

        @forelse($recipients as $i => $email)
            <div wire:key="r-{{ $email }}" style="display:flex; align-items:center; justify-content:space-between; gap:8px; padding:8px 10px; border-bottom:1px solid #f3f4f6; font-size:13px; color:#111827;">
                <span>{{ $email }}</span>
                <button wire:click="removeRecipient({{ $i }})" wire:confirm="Retirer {{ $email }} de la liste ?"
                        style="background:none; border:none; color:#B91C1C; font-size:12px; cursor:pointer;">Retirer</button>
            </div>
        @empty
            <p style="font-size:12px; color:#9ca3af; padding:8px 0;">Aucun destinataire pour l'instant.</p>
        @endforelse

        <label style="display:flex; align-items:center; gap:8px; font-size:13px; color:#374151; margin-top:14px;">
            <input type="checkbox" wire:model.live="enabled"> Envoi automatique le vendredi et le samedi à 06:00
        </label>
    </div>

    <div style="{{ $card }}">
        <p style="font-size:13px; font-weight:700; color:#111827; margin-bottom:6px;">Prochain envoi</p>
        <p style="font-size:12px; color:#374151; margin-bottom:12px;">
            Un envoi fait aujourd'hui couvrirait la période du <strong>{{ \Carbon\Carbon::parse($from)->format('d/m/Y') }}</strong>
            au <strong>{{ \Carbon\Carbon::parse($to)->format('d/m/Y') }}</strong>.
        </p>
        <div style="display:flex; gap:8px; flex-wrap:wrap;">
            <a href="{{ $preview }}" target="_blank" style="{{ $btnLight }}">Aperçu de la page envoyée ↗</a>
            <button wire:click="sendNow" wire:confirm="Envoyer maintenant le tableau de bord aux {{ count($recipients) }} destinataire(s) ?" style="{{ $btnPrimary }}">Envoyer maintenant</button>
        </div>
    </div>

    <div style="{{ $card }}">
        <p style="font-size:13px; font-weight:700; color:#111827; margin-bottom:6px;">Dernier envoi</p>
        <p style="font-size:12px; color:{{ str_starts_with((string) $last, 'ÉCHEC') ? '#B91C1C' : '#374151' }};">
            @if($lastAt) {{ \Carbon\Carbon::parse($lastAt)->format('d/m/Y H:i') }} — {{ $last }} @else Aucun envoi pour l'instant. @endif
        </p>
    </div>
</div>
