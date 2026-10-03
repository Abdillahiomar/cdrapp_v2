<?php

use App\Models\QrBatch;
use App\Models\QrCode;
use App\Support\EmvQrPayload;
use App\Support\QrBatchGenerator;
use App\Support\QrImageGenerator;
use Livewire\Volt\Component;
use Livewire\WithFileUploads;
use Livewire\WithPagination;

new class extends Component {

    use WithFileUploads, WithPagination;

    public string $tab = 'single';   // single | batch | history

    // ── Un QR code ──
    public string $payload      = '';
    public string $merchantName = '';
    public string $shortCode    = '';
    public bool   $withLogo     = false;
    public string $size         = 'medium';
    public ?int   $generatedId  = null;

    // ── En masse ──
    public $file = null;
    public array   $batchRows     = [];
    public ?string $batchError    = null;
    public string  $batchFileName = '';
    public string  $batchFormat   = 'png';
    public bool    $batchLogo     = false;
    public ?string $lastBatchId   = null;

    // ── Historique ──
    public string $search = '';

    public function updated($property): void
    {
        // Toute modification après génération invalide le QR généré
        if (in_array($property, ['payload', 'merchantName', 'shortCode', 'withLogo'])) {
            $this->generatedId = null;
        }

        if ($property === 'search') {
            $this->resetPage();
        }

        if ($property === 'file') {
            $this->analyzeFile();
        }
    }

    public function setTab(string $tab): void
    {
        $this->tab = in_array($tab, ['single', 'batch', 'history']) ? $tab : 'single';
        $this->resetPage();
    }

    // ───────────────────────── Un QR code ─────────────────────────

    public function generate(): void
    {
        $this->validate([
            'merchantName' => 'nullable|string|max:255',
            'shortCode'    => 'nullable|string|max:50',
        ]);

        $qr = EmvQrPayload::parse($this->payload);

        if (!$qr->isValid()) {
            // Le détail des erreurs est déjà affiché sous le champ
            $this->addError('payload', 'Le contenu est invalide : corrigez-le avant de générer le QR code.');
            return;
        }

        $code = QrCode::create([
            'user_id'       => auth()->id(),
            'payload'       => $qr->payload,
            'merchant_name' => $this->merchantName ?: null,
            'short_code'    => $this->shortCode ?: null,
            'with_logo'     => $this->withLogo && QrImageGenerator::logoAvailable(),
        ]);

        $this->generatedId = $code->id;
    }

    public function resetSingle(): void
    {
        $this->reset(['payload', 'merchantName', 'shortCode', 'withLogo', 'generatedId']);
        $this->resetErrorBag();
    }

    // ───────────────────────── En masse ─────────────────────────

    private function analyzeFile(): void
    {
        $this->batchRows   = [];
        $this->batchError  = null;
        $this->lastBatchId = null;

        $this->validate([
            'file' => 'required|file|mimes:xlsx,xls,csv,txt|max:5120',
        ], [
            'file.mimes' => 'Format non accepté : utilisez un fichier Excel (.xlsx) ou CSV.',
            'file.max'   => 'Le fichier dépasse 5 Mo.',
        ]);

        $this->batchFileName = $this->file->getClientOriginalName();

        try {
            $result = app(QrBatchGenerator::class)->analyze($this->file->getRealPath());
        } catch (\Throwable $e) {
            report($e);
            $this->batchError = 'Le fichier n\'a pas pu être lu. Vérifiez qu\'il s\'agit bien d\'un fichier Excel ou CSV non protégé par mot de passe.';
            return;
        }

        $this->batchRows  = $result['rows'];
        $this->batchError = $result['error'];
    }

    public function generateBatch()
    {
        if (empty($this->batchRows) || collect($this->batchRows)->contains(fn ($r) => !empty($r['errors']))) {
            $this->batchError = 'Le lot contient des lignes invalides : corrigez le fichier et importez-le à nouveau.';
            return null;
        }

        set_time_limit(300);

        $batch = app(QrBatchGenerator::class)->generate(
            $this->batchRows,
            in_array($this->batchFormat, ['png', 'svg', 'both']) ? $this->batchFormat : 'png',
            $this->batchLogo && QrImageGenerator::logoAvailable(),
            $this->batchFileName,
            auth()->id()
        );

        $this->lastBatchId = $batch->id;

        return redirect()->route('qrcode.batch', $batch);
    }

    public function resetBatch(): void
    {
        $this->reset(['file', 'batchRows', 'batchError', 'batchFileName', 'lastBatchId']);
        $this->resetErrorBag();
    }

    public function with(QrImageGenerator $images): array
    {
        $data = [
            'logoAvailable' => QrImageGenerator::logoAvailable(),
        ];

        if ($this->tab === 'single') {
            $qr = trim($this->payload) !== '' ? EmvQrPayload::parse($this->payload) : null;

            $data['qr']      = $qr;
            $data['preview'] = $qr?->isValid()
                ? $images->pngDataUri($qr->payload, $this->withLogo && $data['logoAvailable'])
                : null;
        }

        if ($this->tab === 'history') {
            $data['codes'] = QrCode::with(['user:id,name', 'batch:id,file_name'])
                ->when($this->search, function ($q) {
                    $q->where(function ($q) {
                        $q->where('merchant_name', 'ilike', '%' . $this->search . '%')
                          ->orWhere('short_code', 'like', '%' . $this->search . '%')
                          ->orWhere('payload', 'like', '%' . $this->search . '%');
                    });
                })
                ->latest()
                ->paginate(20);

            $data['batches'] = QrBatch::with('user:id,name')->latest()->limit(10)->get();
        }

        return $data;
    }
};
?>
<div style="padding:24px;">

@php
    $inputStyle = 'border:1px solid #d1d5db; border-radius:7px; padding:8px 10px; font-size:13px; color:#111827; outline:none; background:#fff; width:100%; box-sizing:border-box;';
    $labelStyle = 'font-size:11px; color:#6b7280; display:block; margin-bottom:4px;';
    $card       = 'background:#fff; border:1px solid #e5e7eb; border-radius:10px; padding:20px;';
    $btnPrimary = 'background:#1B2F6E; color:#fff; font-size:13px; font-weight:600; padding:9px 16px; border-radius:8px; border:none; cursor:pointer; text-decoration:none; display:inline-flex; align-items:center; gap:6px;';
    $btnLight   = 'background:#fff; color:#374151; font-size:13px; font-weight:600; padding:9px 16px; border-radius:8px; border:1px solid #d1d5db; cursor:pointer; text-decoration:none; display:inline-flex; align-items:center; gap:6px;';
@endphp

    <div style="margin-bottom:16px;">
        <h2 style="font-size:16px; font-weight:700; color:#111827; margin:0 0 4px;">Générateur de QR Code</h2>
        <p style="font-size:12px; color:#9ca3af; margin:0;">Génère les QR codes de paiement marchand à partir de la chaîne fournie (format EMVCo, ex. « 000201010211… »).</p>
    </div>

    {{-- ONGLETS --}}
    <div style="display:flex; gap:4px; border-bottom:1px solid #e5e7eb; margin-bottom:20px; flex-wrap:wrap;">
        @foreach(['single' => 'Un QR code', 'batch' => 'En masse (fichier)', 'history' => 'Historique'] as $key => $label)
            <button wire:click="setTab('{{ $key }}')"
                    style="padding:9px 16px; font-size:13px; background:none; border:none; cursor:pointer; margin-bottom:-1px; border-bottom:2px solid {{ $tab === $key ? '#1B2F6E' : 'transparent' }}; color:{{ $tab === $key ? '#1B2F6E' : '#6b7280' }}; font-weight:{{ $tab === $key ? '600' : '500' }};">
                {{ $label }}
            </button>
        @endforeach
    </div>

    {{-- ═════════════════════ UN QR CODE ═════════════════════ --}}
    @if($tab === 'single')
        <div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(320px, 1fr)); gap:16px; align-items:start;">

            {{-- Formulaire --}}
            <div style="{{ $card }}">
                <div style="margin-bottom:14px;">
                    <label style="{{ $labelStyle }}">Chaîne du QR code <span style="color:#B91C1C;">*</span></label>
                    <textarea wire:model.live.debounce.400ms="payload" rows="4" spellcheck="false"
                              placeholder="Collez la chaîne complète, ex. 000201010211…6304E9B5"
                              style="{{ $inputStyle }} font-family:monospace; font-size:12px; resize:vertical; word-break:break-all;"></textarea>
                    <p style="font-size:11px; color:#9ca3af; margin:4px 0 0;">Copiez la chaîne d'un seul bloc. Elle commence par « 000201 » et se termine par « 6304 » suivi de 4 caractères (code de contrôle).</p>
                </div>

                {{-- Résultat de la vérification --}}
                @if($qr && !$qr->isValid())
                    <div style="background:#FDE8E8; border-left:3px solid #E24B4A; border-radius:6px; padding:12px 14px; margin-bottom:14px;">
                        <p style="font-size:12px; font-weight:700; color:#7F1D1D; margin:0 0 4px;">Contenu invalide — le QR code ne peut pas être généré</p>
                        @foreach($qr->errors as $error)
                            <p style="font-size:12px; color:#7F1D1D; margin:0;">{{ $error }}</p>
                        @endforeach
                        <p style="font-size:11px; color:#991B1B; margin:6px 0 0;">Un QR de paiement avec un contenu altéré serait refusé par l'application au moment du paiement : la génération est bloquée pour éviter d'imprimer un QR inutilisable.</p>
                    </div>
                @elseif($qr)
                    <div style="background:#E5F5ED; border-left:3px solid #00843D; border-radius:6px; padding:12px 14px; margin-bottom:14px;">
                        <p style="font-size:12px; font-weight:700; color:#005C2B; margin:0 0 6px;">Contenu valide — vérifiez qu'il s'agit du bon marchand</p>
                        @foreach($qr->summary() as $label => $value)
                            <p style="font-size:12px; color:#005C2B; margin:0;"><span style="color:#4B8B65;">{{ $label }} :</span> <strong>{{ $value }}</strong></p>
                        @endforeach
                    </div>
                @endif
                @error('payload') <p style="font-size:12px; color:#B91C1C; margin:-6px 0 12px;">{{ $message }}</p> @enderror

                <div style="display:grid; grid-template-columns:repeat(2, minmax(0,1fr)); gap:12px; margin-bottom:14px;">
                    <div>
                        <label style="{{ $labelStyle }}">Nom du marchand</label>
                        <input type="text" wire:model.live.debounce.400ms="merchantName" placeholder="Ex. Boutique Ali" style="{{ $inputStyle }}">
                    </div>
                    <div>
                        <label style="{{ $labelStyle }}">Shortcode</label>
                        <input type="text" wire:model.live.debounce.400ms="shortCode" placeholder="Ex. 1298" style="{{ $inputStyle }}">
                    </div>
                </div>
                <p style="font-size:11px; color:#9ca3af; margin:-6px 0 14px;">Facultatifs : ils apparaissent sous le QR à l'impression, dans le nom du fichier et dans l'historique.</p>

                <div style="margin-bottom:16px;">
                    <label style="display:flex; align-items:center; gap:8px; font-size:13px; color:{{ $logoAvailable ? '#374151' : '#9ca3af' }}; cursor:{{ $logoAvailable ? 'pointer' : 'not-allowed' }};">
                        <input type="checkbox" wire:model.live="withLogo" @disabled(!$logoAvailable)>
                        Ajouter le logo D-Money au centre du QR code
                    </label>
                    @unless($logoAvailable)
                        <p style="font-size:11px; color:#9ca3af; margin:4px 0 0 24px;">Option indisponible : le fichier du logo n'est pas encore installé sur le serveur.</p>
                    @endunless
                </div>

                <div style="display:flex; gap:8px; flex-wrap:wrap;">
                    <button wire:click="generate" @disabled(!$qr || !$qr->isValid())
                            style="{{ $btnPrimary }} {{ (!$qr || !$qr->isValid()) ? 'opacity:.45; cursor:not-allowed;' : '' }}">
                        Générer le QR code
                    </button>
                    <button wire:click="resetSingle" style="{{ $btnLight }}">Effacer</button>
                </div>
            </div>

            {{-- Aperçu --}}
            <div style="{{ $card }} text-align:center;">
                <p style="font-size:13px; font-weight:600; color:#111827; margin:0 0 14px; text-align:left;">Aperçu</p>

                @if($preview)
                    <img src="{{ $preview }}" alt="Aperçu du QR code" style="width:100%; max-width:280px; height:auto; image-rendering:pixelated;">
                    @if($merchantName)
                        <p style="font-size:15px; font-weight:700; color:#111827; margin:10px 0 2px;">{{ $merchantName }}</p>
                    @endif
                    @if($shortCode)
                        <p style="font-size:12px; color:#6b7280; margin:0;">Code marchand : {{ $shortCode }}</p>
                    @endif

                    @if($generatedId)
                        <div style="margin-top:18px; padding-top:16px; border-top:1px solid #f3f4f6; text-align:left;">
                            <p style="font-size:12px; color:#005C2B; font-weight:600; margin:0 0 10px;">✓ QR code généré et enregistré dans l'historique.</p>
                            <label style="{{ $labelStyle }}">Taille du PNG</label>
                            <select wire:model.live="size" style="{{ $inputStyle }} margin-bottom:10px;">
                                @foreach(\App\Support\QrImageGenerator::SIZES as $key => [$label])
                                    <option value="{{ $key }}">{{ $label }}</option>
                                @endforeach
                            </select>
                            <div style="display:flex; gap:8px; flex-wrap:wrap;">
                                <a href="{{ route('qrcode.png', ['qrCode' => $generatedId, 'size' => $size]) }}" style="{{ $btnPrimary }}">Télécharger PNG</a>
                                <a href="{{ route('qrcode.svg', $generatedId) }}" style="{{ $btnLight }}">Télécharger SVG</a>
                                <a href="{{ route('qrcode.print', $generatedId) }}" target="_blank" style="{{ $btnLight }}">Imprimer</a>
                            </div>
                            <p style="font-size:11px; color:#9ca3af; margin:8px 0 0;">PNG : WhatsApp, email, écran. SVG : impression grand format sans perte de qualité.</p>
                        </div>
                    @else
                        <p style="font-size:11px; color:#9ca3af; margin:14px 0 0;">Cliquez sur « Générer le QR code » pour l'enregistrer et le télécharger.</p>
                    @endif
                @else
                    <div style="border:2px dashed #e5e7eb; border-radius:10px; padding:60px 20px; color:#9ca3af; font-size:12px;">
                        L'aperçu s'affiche dès que la chaîne collée est valide.
                    </div>
                @endif
            </div>
        </div>
    @endif

    {{-- ═════════════════════ EN MASSE ═════════════════════ --}}
    @if($tab === 'batch')
        {{-- Mode d'emploi --}}
        <div style="{{ $card }} margin-bottom:16px;">
            <p style="font-size:13px; font-weight:700; color:#111827; margin:0 0 10px;">Comment préparer le fichier</p>
            <ol style="font-size:12px; color:#374151; margin:0 0 12px; padding-left:18px; line-height:1.8;">
                <li>Téléchargez le <strong>modèle Excel</strong> ci-dessous : ses colonnes sont déjà au bon format.</li>
                <li>Remplissez <strong>une ligne par marchand</strong>, avec 3 colonnes :
                    <ul style="margin:2px 0; padding-left:18px;">
                        <li><strong>chaine</strong> : la chaîne complète du QR code (« 000201…6304XXXX ») ;</li>
                        <li><strong>shortcode</strong> : le code du marchand (sert à nommer le fichier image) ;</li>
                        <li><strong>nom_marchand</strong> : le nom affiché sous le QR et dans le nom du fichier.</li>
                    </ul>
                </li>
                <li>Gardez la <strong>première ligne d'en-tête</strong> (chaine, shortcode, nom_marchand). Les autres colonnes éventuelles sont ignorées.</li>
                <li>Maximum <strong>{{ \App\Support\QrBatchGenerator::MAX_ROWS }} lignes</strong> par fichier. Formats acceptés : Excel (.xlsx) ou CSV.</li>
            </ol>
            <div style="background:#FFF7E0; border-left:3px solid #F5A800; border-radius:6px; padding:10px 12px; font-size:12px; color:#7A4F00; margin-bottom:12px;">
                <strong>Attention avec Excel :</strong> si vous utilisez votre propre fichier, mettez les colonnes au format <strong>Texte</strong> <em>avant</em> de coller les chaînes.
                Sinon Excel transforme les longues suites de chiffres en nombres (ex. « 2,01E+95 ») et le QR code devient inutilisable.
            </div>
            <p style="font-size:12px; color:#374151; margin:0 0 12px;">
                Toutes les lignes sont vérifiées avant la génération. <strong>Si une seule ligne est invalide, le lot est bloqué</strong> :
                la liste des erreurs s'affiche avec le numéro de ligne, vous corrigez le fichier et vous l'importez à nouveau.
                Vous obtenez ensuite un fichier ZIP avec un QR code par marchand et un récapitulatif.
            </p>
            <a href="{{ route('qrcode.template') }}" style="{{ $btnLight }}">⬇ Télécharger le modèle Excel</a>
        </div>

        {{-- Import --}}
        <div style="{{ $card }} margin-bottom:16px;">
            <div style="display:flex; gap:16px; flex-wrap:wrap; align-items:flex-end;">
                <div style="flex:1; min-width:240px;">
                    <label style="{{ $labelStyle }}">Fichier à importer</label>
                    <input type="file" wire:model="file" accept=".xlsx,.xls,.csv" style="font-size:13px;">
                    <div wire:loading wire:target="file" style="font-size:12px; color:#1B2F6E; margin-top:6px;">Lecture et vérification du fichier…</div>
                    @error('file') <p style="font-size:12px; color:#B91C1C; margin:6px 0 0;">{{ $message }}</p> @enderror
                </div>
                @if($batchFileName)
                    <button wire:click="resetBatch" style="{{ $btnLight }}">Recommencer</button>
                @endif
            </div>

            @if($batchError)
                <div style="background:#FDE8E8; border-left:3px solid #E24B4A; border-radius:6px; padding:12px 14px; margin-top:14px; font-size:12px; color:#7F1D1D;">
                    {{ $batchError }}
                </div>
            @endif
        </div>

        @if(!empty($batchRows))
            @php
                $invalid = collect($batchRows)->filter(fn ($r) => !empty($r['errors']));
            @endphp

            <div style="{{ $card }} margin-bottom:16px;">
                @if($invalid->isNotEmpty())
                    <div style="background:#FDE8E8; border-left:3px solid #E24B4A; border-radius:6px; padding:12px 14px; margin-bottom:14px;">
                        <p style="font-size:13px; font-weight:700; color:#7F1D1D; margin:0 0 4px;">
                            Lot bloqué : {{ $invalid->count() }} ligne(s) invalide(s) sur {{ count($batchRows) }}
                        </p>
                        <p style="font-size:12px; color:#7F1D1D; margin:0;">Corrigez les lignes en rouge dans « {{ $batchFileName }} », enregistrez, puis importez à nouveau le fichier. Aucun QR code n'a été généré.</p>
                    </div>
                @else
                    <div style="background:#E5F5ED; border-left:3px solid #00843D; border-radius:6px; padding:12px 14px; margin-bottom:14px;">
                        <p style="font-size:13px; font-weight:700; color:#005C2B; margin:0;">{{ count($batchRows) }} ligne(s) valide(s) — prêt à générer</p>
                    </div>

                    <div style="display:flex; gap:16px; flex-wrap:wrap; align-items:flex-end; margin-bottom:14px;">
                        <div>
                            <label style="{{ $labelStyle }}">Format des images</label>
                            <select wire:model="batchFormat" style="{{ $inputStyle }} width:240px;">
                                <option value="png">PNG (≈ 1000 px)</option>
                                <option value="svg">SVG (impression)</option>
                                <option value="both">PNG + SVG</option>
                            </select>
                        </div>
                        <label style="display:flex; align-items:center; gap:8px; font-size:13px; color:{{ $logoAvailable ? '#374151' : '#9ca3af' }}; padding-bottom:9px;">
                            <input type="checkbox" wire:model="batchLogo" @disabled(!$logoAvailable)>
                            Logo D-Money au centre {{ $logoAvailable ? '' : '(indisponible)' }}
                        </label>
                        <button wire:click="generateBatch" wire:loading.attr="disabled" style="{{ $btnPrimary }}">
                            <span wire:loading.remove wire:target="generateBatch">Générer et télécharger le ZIP</span>
                            <span wire:loading wire:target="generateBatch">Génération en cours…</span>
                        </button>
                    </div>
                @endif

                <div style="overflow:auto; max-height:420px; border:1px solid #f3f4f6; border-radius:8px;">
                    <table style="width:100%; border-collapse:collapse; font-size:12px;">
                        <thead>
                            <tr style="background:#F7F8FC;">
                                @foreach(['Ligne', 'Shortcode', 'Nom du marchand', 'Chaîne', 'Vérification'] as $th)
                                    <th style="padding:8px 12px; text-align:left; color:#6b7280; font-weight:500; border-bottom:1px solid #e5e7eb; position:sticky; top:0; background:#F7F8FC;">{{ $th }}</th>
                                @endforeach
                            </tr>
                        </thead>
                        <tbody>
                            {{-- Lignes invalides en premier --}}
                            @foreach(collect($batchRows)->sortBy(fn ($r) => empty($r['errors']) ? 1 : 0) as $row)
                                <tr style="border-bottom:1px solid #f3f4f6; {{ $row['errors'] ? 'background:#FFF5F5;' : '' }}">
                                    <td style="padding:8px 12px; color:#6b7280;">{{ $row['line'] }}</td>
                                    <td style="padding:8px 12px; color:#111827; font-weight:500;">{{ $row['short_code'] ?: '—' }}</td>
                                    <td style="padding:8px 12px; color:#111827;">{{ $row['merchant_name'] ?: '—' }}</td>
                                    <td style="padding:8px 12px; color:#6b7280; font-family:monospace; font-size:11px; max-width:260px; overflow:hidden; text-overflow:ellipsis; white-space:nowrap;" title="{{ $row['payload'] }}">{{ $row['payload'] ?: '—' }}</td>
                                    <td style="padding:8px 12px;">
                                        @if($row['errors'])
                                            @foreach($row['errors'] as $error)
                                                <p style="font-size:11px; color:#B91C1C; margin:0 0 2px;">✗ {{ $error }}</p>
                                            @endforeach
                                        @else
                                            <span style="font-size:11px; color:#005C2B; font-weight:600;">✓ Valide</span>
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
        @endif
    @endif

    {{-- ═════════════════════ HISTORIQUE ═════════════════════ --}}
    @if($tab === 'history')
        @if($batches->isNotEmpty())
            <div style="{{ $card }} margin-bottom:16px; padding:0; overflow:hidden;">
                <p style="font-size:13px; font-weight:600; color:#111827; margin:0; padding:12px 16px; border-bottom:1px solid #e5e7eb;">Derniers lots générés</p>
                <table style="width:100%; border-collapse:collapse; font-size:12px;">
                    <tbody>
                        @foreach($batches as $batch)
                            <tr style="border-bottom:1px solid #f3f4f6;">
                                <td style="padding:10px 16px; color:#111827; font-weight:500;">{{ $batch->file_name }}</td>
                                <td style="padding:10px 16px; color:#6b7280;">{{ $batch->total }} QR code(s) · {{ strtoupper($batch->format === 'both' ? 'png + svg' : $batch->format) }}{{ $batch->with_logo ? ' · logo' : '' }}</td>
                                <td style="padding:10px 16px; color:#6b7280;">{{ $batch->user->name ?? '—' }}</td>
                                <td style="padding:10px 16px; color:#6b7280; white-space:nowrap;">{{ $batch->created_at->format('d/m/Y H:i') }}</td>
                                <td style="padding:10px 16px; text-align:right;">
                                    <a href="{{ route('qrcode.batch', $batch) }}" style="background:#1B2F6E; color:#fff; font-size:11px; font-weight:600; padding:5px 10px; border-radius:6px; text-decoration:none;">ZIP</a>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif

        <div style="{{ $card }} padding:0; overflow:hidden;">
            <div style="padding:12px 16px; border-bottom:1px solid #e5e7eb; display:flex; gap:12px; align-items:center; justify-content:space-between; flex-wrap:wrap;">
                <p style="font-size:13px; font-weight:600; color:#111827; margin:0;">Tous les QR codes générés</p>
                <input type="text" wire:model.live.debounce.400ms="search" placeholder="Marchand, shortcode ou chaîne..."
                       style="{{ $inputStyle }} width:260px;">
            </div>
            <div style="overflow-x:auto;">
                <table style="width:100%; border-collapse:collapse; font-size:12px;">
                    <thead>
                        <tr style="background:#F7F8FC;">
                            @foreach(['Date', 'Marchand', 'Shortcode', 'Chaîne', 'Logo', 'Origine', 'Généré par', ''] as $th)
                                <th style="padding:10px 12px; text-align:left; color:#6b7280; font-weight:500; border-bottom:1px solid #e5e7eb; white-space:nowrap;">{{ $th }}</th>
                            @endforeach
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($codes as $code)
                            <tr wire:key="qr-{{ $code->id }}" style="border-bottom:1px solid #f3f4f6;">
                                <td style="padding:10px 12px; color:#6b7280; white-space:nowrap;">{{ $code->created_at->format('d/m/Y H:i') }}</td>
                                <td style="padding:10px 12px; color:#111827; font-weight:500;">{{ $code->merchant_name ?? '—' }}</td>
                                <td style="padding:10px 12px; color:#111827;">{{ $code->short_code ?? '—' }}</td>
                                <td style="padding:10px 12px; color:#6b7280; font-family:monospace; font-size:11px; max-width:220px; overflow:hidden; text-overflow:ellipsis; white-space:nowrap;" title="{{ $code->payload }}">{{ $code->payload }}</td>
                                <td style="padding:10px 12px; color:#6b7280;">{{ $code->with_logo ? 'Oui' : 'Non' }}</td>
                                <td style="padding:10px 12px; color:#6b7280;">{{ $code->batch ? 'Lot : ' . \Illuminate\Support\Str::limit($code->batch->file_name, 24) : 'Unitaire' }}</td>
                                <td style="padding:10px 12px; color:#6b7280;">{{ $code->user->name ?? '—' }}</td>
                                <td style="padding:10px 12px; white-space:nowrap;">
                                    <a href="{{ route('qrcode.png', $code) }}" style="color:#1B2F6E; font-weight:600; font-size:11px; text-decoration:none; margin-right:8px;">PNG</a>
                                    <a href="{{ route('qrcode.svg', $code) }}" style="color:#1B2F6E; font-weight:600; font-size:11px; text-decoration:none; margin-right:8px;">SVG</a>
                                    <a href="{{ route('qrcode.print', $code) }}" target="_blank" style="color:#1B2F6E; font-weight:600; font-size:11px; text-decoration:none;">Imprimer</a>
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="8" style="padding:24px; text-align:center; color:#9ca3af;">Aucun QR code généré pour l'instant.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            <div style="padding:12px 16px; border-top:1px solid #e5e7eb;">
                {{ $codes->links() }}
            </div>
        </div>
    @endif

</div>
