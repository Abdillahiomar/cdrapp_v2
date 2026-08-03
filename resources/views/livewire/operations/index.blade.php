<?php
// resources/views/livewire/operations/import-msisdn.blade.php

use App\Models\Customer;
use Livewire\Volt\Component;
use Livewire\WithFileUploads;
use Maatwebsite\Excel\Facades\Excel;

new class extends Component {

    use WithFileUploads;

    public $fichier = null;
    public $resultats = [];
    public $erreurs = [];
    public $traite = false;
    public $stats = ['total' => 0, 'trouves' => 0, 'introuvables' => 0];

    public function updatedFichier()
    {
        $this->erreurs = [];
        $this->resultats = [];
        $this->traite = false;
    }

    public function traiter()
    {
        set_time_limit(300);
        ini_set('memory_limit', '512M');

        $this->validate([
            'fichier' => 'required|file|mimes:xlsx,xls,csv|max:10240',
        ]);

        $this->erreurs   = [];
        $this->resultats = [];

        try {
            $path = $this->fichier->getRealPath();

            $import = new \App\Imports\MsisdnImport();
            Excel::import($import, $path);

            // Nettoie chaque MSISDN — on ne garde que les chiffres (retire espaces,
            // tirets, éventuels préfixes "+"), pour un format identique à la base.
            $msisdns = collect($import->getMsisdns())
                ->map(fn($m) => preg_replace('/\D/', '', (string) $m))
                ->filter(fn($m) => $m !== '')
                ->unique()
                ->values();

            if ($msisdns->isEmpty()) {
                $this->erreurs[] = 'Aucun MSISDN trouvé dans le fichier.';
                return;
            }

            // Recherche EXACTE et indexée : une seule requête whereIn.
            // Bien plus rapide que des orWhere LIKE sur une grosse table KYC.
            $found = Customer::whereIn('msisdn', $msisdns->all())
                ->get()
                ->keyBy('msisdn');   // clé = msisdn pour un accès O(1)

            // Mapper chaque MSISDN importé vers le client trouvé
            $this->resultats = $msisdns->map(function ($msisdn) use ($found) {
                $customer = $found->get($msisdn);

                if (!$customer) {
                    return [
                        'msisdn'           => $msisdn,
                        'trouve'           => false,
                        'source_datetime'  => null,
                        'full_name'        => null,
                        'mother_name'      => null,
                        'customer_profile' => null,
                        'channel'          => null,
                        'id_type'          => null,
                        'nationality'      => null,
                    ];
                }

                return [
                    'msisdn'           => $msisdn,
                    'trouve'           => true,
                    'source_datetime'  => $customer->source_datetime,
                    'full_name'        => $customer->full_name,
                    'mother_name'      => $customer->mother_full_name,
                    'customer_profile' => $customer->customer_profile,
                    'channel'          => $customer->channel,
                    'id_type'          => $customer->id_type,
                    'nationality'      => $customer->nationality,
                ];
            })->toArray();

            $this->stats = [
                'total'        => count($this->resultats),
                'trouves'      => collect($this->resultats)->where('trouve', true)->count(),
                'introuvables' => collect($this->resultats)->where('trouve', false)->count(),
            ];

            $this->traite = true;

        } catch (\Exception $e) {
            $this->erreurs[] = 'Erreur : ' . $e->getMessage();
        }
    }

    public function exportResultatsExcel()
    {
        if (empty($this->resultats)) {
            return;
        }

        $rows   = collect($this->resultats);
        $export = new \App\Exports\MsisdnResultatsExport($rows);

        return Excel::download(
            $export,
            'verification_msisdn_' . now()->format('Ymd_His') . '.xlsx'
        );
    }

    public function reinitialiser()
    {
        $this->fichier    = null;
        $this->resultats  = [];
        $this->erreurs    = [];
        $this->traite     = false;
        $this->stats      = ['total' => 0, 'trouves' => 0, 'introuvables' => 0];
    }

};
?>
<div>
<div style="padding:24px;">
    {{-- EN-TÊTE --}}
    <div style="margin-bottom:24px;">
        <h2 style="font-size:16px; font-weight:700; color:#111827; margin:0 0 4px;">Vérification de MSISDN</h2>
        <p style="font-size:12px; color:#9ca3af; margin:0;">Importez un fichier Excel/CSV contenant une colonne MSISDN pour vérifier leur existence dans la base clients KYC.</p>
    </div>

    {{-- ZONE D'IMPORT --}}
    <div style="background:#fff; border:1px solid #e5e7eb; border-radius:12px; padding:24px; margin-bottom:20px;">

        <p style="font-size:13px; font-weight:600; color:#111827; margin-bottom:16px;">
            Importer un fichier
        </p>

        {{-- Dropzone --}}
        <label for="fichier-input"
               style="display:block; border:2px dashed {{ $fichier ? '#1B2F6E' : '#d1d5db' }}; border-radius:10px; padding:36px 20px; text-align:center; cursor:pointer; background:{{ $fichier ? '#F0F3FA' : '#fafafa' }}; transition:all 0.2s;">

            @if(!$fichier)
                <div style="width:48px; height:48px; background:#E8ECF8; border-radius:12px; display:flex; align-items:center; justify-content:center; margin:0 auto 12px;">
                    <svg width="24" height="24" viewBox="0 0 24 24" fill="none">
                        <path d="M12 16V8M12 8l-3 3M12 8l3 3" stroke="#1B2F6E" stroke-width="1.5" stroke-linecap="round"/>
                        <path d="M4 16v2a2 2 0 002 2h12a2 2 0 002-2v-2" stroke="#1B2F6E" stroke-width="1.5" stroke-linecap="round"/>
                    </svg>
                </div>
                <p style="font-size:13px; font-weight:600; color:#1B2F6E; margin:0 0 4px;">Cliquez pour sélectionner un fichier</p>
                <p style="font-size:11px; color:#9ca3af; margin:0;">Excel (.xlsx, .xls) ou CSV — Max 10 Mo</p>
            @else
                <div style="width:48px; height:48px; background:#E8ECF8; border-radius:12px; display:flex; align-items:center; justify-content:center; margin:0 auto 12px;">
                    <svg width="24" height="24" viewBox="0 0 24 24" fill="none">
                        <path d="M5 13l4 4L19 7" stroke="#1B2F6E" stroke-width="2" stroke-linecap="round"/>
                    </svg>
                </div>
                <p style="font-size:13px; font-weight:600; color:#1B2F6E; margin:0 0 4px;">
                    {{ $fichier->getClientOriginalName() }}
                </p>
                <p style="font-size:11px; color:#9ca3af; margin:0;">
                    {{ round($fichier->getSize() / 1024, 1) }} Ko — Cliquez pour changer
                </p>
            @endif
        </label>

        <input
                id="fichier-input"
                type="file"
                wire:model="fichier"
                x-on:change="uploadFichier($event)"
                x-on:livewire-upload-finish="uploadTermine()"
                x-on:livewire-upload-error="uploadErreur()"
                >

        {{-- Erreurs de validation --}}
        @if($errors->has('fichier'))
            <div style="margin-top:10px; background:#FDECEA; border-radius:8px; padding:10px 14px; font-size:12px; color:#7F1D1D;">
                {{ $errors->first('fichier') }}
            </div>
        @endif

        @if(!empty($erreurs))
            <div style="margin-top:10px; background:#FDECEA; border-radius:8px; padding:10px 14px;">
                @foreach($erreurs as $err)
                    <p style="font-size:12px; color:#7F1D1D; margin:0;">⚠ {{ $err }}</p>
                @endforeach
            </div>
        @endif

        {{-- Consigne format --}}
        <div style="margin-top:14px; background:#F7F8FC; border-radius:8px; padding:12px 14px; display:flex; gap:10px; align-items:flex-start;">
            <svg width="16" height="16" viewBox="0 0 16 16" fill="#1B2F6E" style="flex-shrink:0; margin-top:1px;">
                <circle cx="8" cy="8" r="7" stroke="#1B2F6E" stroke-width="1.2" fill="none"/>
                <path d="M8 7v5M8 5v1" stroke="#1B2F6E" stroke-width="1.2" stroke-linecap="round"/>
            </svg>
            <div>
                <p style="font-size:11px; font-weight:600; color:#1B2F6E; margin:0 0 3px;">Format attendu</p>
                <p style="font-size:11px; color:#6b7280; margin:0;">
                    Le fichier doit contenir une colonne intitulée <strong>MSISDN</strong> (majuscule ou minuscule),
                    au format complet <strong>253XXXXXXXX</strong>. Les autres colonnes sont ignorées.
                </p>
            </div>
        </div>

        {{-- Boutons --}}
        <div style="display:flex; gap:10px; margin-top:16px; align-items:center;">

            <button onclick="lancerVerification()"
                    style="background:#1B2F6E; color:#fff; font-size:13px; font-weight:600; padding:9px 22px; border-radius:8px; border:none; cursor:pointer; display:flex; align-items:center; gap:7px;">
                <svg width="14" height="14" viewBox="0 0 16 16" fill="white">
                    <circle cx="6.5" cy="6.5" r="4.5" stroke="white" stroke-width="1.5" fill="none"/>
                    <path d="M10.5 10.5L14 14" stroke="white" stroke-width="1.5" stroke-linecap="round"/>
                </svg>
                Vérifier les MSISDN
            </button>

            @if($traite)
                <button wire:click="reinitialiser"
                        style="background:#f3f4f6; color:#374151; font-size:13px; font-weight:500; padding:9px 18px; border-radius:8px; border:1px solid #e5e7eb; cursor:pointer;">
                    Recommencer
                </button>
            @endif

        </div>
    </div>

    {{-- RÉSULTATS --}}
    @if($traite)

        {{-- STATS --}}
        <div style="display:grid; grid-template-columns:repeat(3, minmax(0,1fr)); gap:12px; margin-bottom:16px;">
            <div style="background:#fff; border:1px solid #e5e7eb; border-radius:10px; padding:16px; border-top:3px solid #1B2F6E;">
                <p style="font-size:11px; color:#6b7280; margin:0 0 6px;">Total importés</p>
                <p style="font-size:24px; font-weight:700; color:#111827; margin:0;">{{ $stats['total'] }}</p>
                <p style="font-size:10px; color:#9ca3af; margin:3px 0 0;">MSISDN uniques</p>
            </div>
            <div style="background:#fff; border:1px solid #e5e7eb; border-radius:10px; padding:16px; border-top:3px solid #16a34a;">
                <p style="font-size:11px; color:#6b7280; margin:0 0 6px;">Trouvés</p>
                <p style="font-size:24px; font-weight:700; color:#16a34a; margin:0;">{{ $stats['trouves'] }}</p>
                <p style="font-size:10px; color:#9ca3af; margin:3px 0 0;">Clients identifiés</p>
            </div>
            <div style="background:#fff; border:1px solid #e5e7eb; border-radius:10px; padding:16px; border-top:3px solid #E24B4A;">
                <p style="font-size:11px; color:#6b7280; margin:0 0 6px;">Introuvables</p>
                <p style="font-size:24px; font-weight:700; color:#E24B4A; margin:0;">{{ $stats['introuvables'] }}</p>
                <p style="font-size:10px; color:#9ca3af; margin:3px 0 0;">Non enregistrés</p>
            </div>
        </div>

        {{-- TABLEAU RÉSULTATS --}}
        <div style="background:#fff; border:1px solid #e5e7eb; border-radius:10px; overflow:hidden;">
            <div style="padding:12px 16px; border-bottom:1px solid #e5e7eb; display:flex; align-items:center; justify-content:space-between;">
                <p style="font-size:13px; font-weight:600; color:#111827; margin:0;">Résultats</p>
                <button onclick="exporterResultats()"
                        style="background:#fff; color:#1B2F6E; font-size:12px; font-weight:600; padding:7px 14px; border-radius:7px; border:1.5px solid #1B2F6E; cursor:pointer; display:flex; align-items:center; gap:6px;">
                    <svg width="13" height="13" viewBox="0 0 16 16" fill="#1B2F6E">
                        <path d="M2 3h9l3 3v8a1 1 0 01-1 1H2a1 1 0 01-1-1V4a1 1 0 011-1z"/>
                        <path d="M9 3v4h4" stroke="#1B2F6E" stroke-width="1" fill="none"/>
                    </svg>
                    Exporter les résultats
                </button>
            </div>

            <div style="overflow-x:auto;">
                <table style="width:100%; border-collapse:collapse; font-size:12px;">
                    <thead>
                        <tr style="background:#F7F8FC;">
                            <th style="padding:10px 14px; text-align:left; color:#6b7280; font-weight:500; border-bottom:1px solid #e5e7eb;">#</th>
                            <th style="padding:10px 14px; text-align:left; color:#6b7280; font-weight:500; border-bottom:1px solid #e5e7eb; white-space:nowrap;">MSISDN</th>
                            <th style="padding:10px 14px; text-align:left; color:#6b7280; font-weight:500; border-bottom:1px solid #e5e7eb; white-space:nowrap;">Statut recherche</th>
                            <th style="padding:10px 14px; text-align:left; color:#6b7280; font-weight:500; border-bottom:1px solid #e5e7eb; white-space:nowrap;">Date</th>
                            <th style="padding:10px 14px; text-align:left; color:#6b7280; font-weight:500; border-bottom:1px solid #e5e7eb; white-space:nowrap;">Nom complet</th>
                            <th style="padding:10px 14px; text-align:left; color:#6b7280; font-weight:500; border-bottom:1px solid #e5e7eb; white-space:nowrap;">Nom de la mère</th>
                            <th style="padding:10px 14px; text-align:left; color:#6b7280; font-weight:500; border-bottom:1px solid #e5e7eb; white-space:nowrap;">Profil</th>
                            <th style="padding:10px 14px; text-align:left; color:#6b7280; font-weight:500; border-bottom:1px solid #e5e7eb; white-space:nowrap;">Canal</th>
                            <th style="padding:10px 14px; text-align:left; color:#6b7280; font-weight:500; border-bottom:1px solid #e5e7eb; white-space:nowrap;">Pièce</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($resultats as $i => $row)
                            <tr style="border-bottom:1px solid #f3f4f6;"
                                onmouseover="this.style.background='#F7F8FC'"
                                onmouseout="this.style.background='transparent'">
                                <td style="padding:10px 14px; color:#9ca3af;">{{ $i + 1 }}</td>
                                <td style="padding:10px 14px; font-weight:600; color:#111827;">{{ $row['msisdn'] }}</td>
                                <td style="padding:10px 14px;">
                                    @if($row['trouve'])
                                        <span style="background:#E5F5ED; color:#005C2B; font-size:10px; font-weight:600; padding:2px 8px; border-radius:12px;">Trouvé</span>
                                    @else
                                        <span style="background:#FDECEA; color:#7F1D1D; font-size:10px; font-weight:600; padding:2px 8px; border-radius:12px;">Introuvable</span>
                                    @endif
                                </td>
                                <td style="padding:10px 14px; color:#6b7280;">
                                    {{ $row['source_datetime'] ? \Carbon\Carbon::parse($row['source_datetime'])->format('d/m/Y H:i') : '—' }}
                                </td>
                                <td style="padding:10px 14px; color:#374151;">{{ $row['full_name'] ?: '—' }}</td>
                                <td style="padding:10px 14px; color:#374151;">{{ $row['mother_name'] ?: '—' }}</td>
                                <td style="padding:10px 14px;">
                                    @if($row['trouve'] && $row['customer_profile'])
                                        @if($row['customer_profile'] === 'RDS')
                                            <span style="background:#E5F5ED; color:#005C2B; font-size:10px; font-weight:600; padding:2px 8px; border-radius:12px;">RDS</span>
                                        @elseif($row['customer_profile'] === 'RDS_LITE')
                                            <span style="background:#E8ECF8; color:#1B2F6E; font-size:10px; font-weight:600; padding:2px 8px; border-radius:12px;">RDS_LITE</span>
                                        @else
                                            <span style="background:#F3F4F6; color:#6b7280; font-size:10px; font-weight:600; padding:2px 8px; border-radius:12px;">{{ $row['customer_profile'] }}</span>
                                        @endif
                                    @else
                                        <span style="color:#9ca3af;">—</span>
                                    @endif
                                </td>
                                <td style="padding:10px 14px; color:#374151;">{{ $row['channel'] ?: '—' }}</td>
                                <td style="padding:10px 14px; color:#374151;">{{ $row['id_type'] ?: '—' }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    @endif


    <style>
        @keyframes spin { from { transform:rotate(0deg); } to { transform:rotate(360deg); } }
    </style>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/sweetalert2/11.10.5/sweetalert2.all.min.js"></script>
    <script>
        function uploadFichier(event) {
            const file = event.target.files[0];
            if (!file) return;

            Swal.fire({
                title: 'Chargement du fichier',
                html: `
                    <div style="font-size:13px; color:#6b7280; margin-bottom:12px;">
                        <strong style="color:#111827;">${file.name}</strong><br>
                        ${(file.size / 1024).toFixed(1)} Ko
                    </div>
                    <div style="width:100%; height:6px; background:#e5e7eb; border-radius:10px; overflow:hidden; margin-bottom:8px;">
                        <div id="swal-progress-fill" style="
                            height:100%; width:0%; background:#1B2F6E;
                            border-radius:10px; transition:width 0.4s ease;">
                        </div>
                    </div>
                    <div id="swal-progress-text" style="font-size:11px; color:#9ca3af;">Envoi en cours...</div>
                `,
                allowOutsideClick: false,
                showConfirmButton: false,
            });

            let fakeProgress = 0;
            const getFill = () => document.getElementById('swal-progress-fill');
            const getText = () => document.getElementById('swal-progress-text');

            const interval = setInterval(() => {
                fakeProgress += (95 - fakeProgress) * 0.15;
                if (getFill()) getFill().style.width = fakeProgress.toFixed(1) + '%';
                if (getText()) getText().textContent = Math.round(fakeProgress) + '%';
            }, 100);

            const unsubFinished = Livewire.on('upload:finished', () => {
                clearInterval(interval);
                unsubFinished();

                if (getFill()) {
                    getFill().style.width = '100%';
                    getFill().style.background = '#16a34a';
                }
                if (getText()) getText().textContent = '100% — Terminé !';

                setTimeout(() => {
                    Swal.fire({
                        title: 'Fichier prêt !',
                        html: `<span style="font-size:13px; color:#6b7280;">
                            <strong style="color:#111827;">${file.name}</strong> a été chargé.<br>
                            Cliquez sur <strong>Vérifier les MSISDN</strong> pour continuer.
                        </span>`,
                        icon: 'success',
                        confirmButtonText: 'OK',
                        confirmButtonColor: '#1B2F6E',
                        timer: 4000,
                        timerProgressBar: true,
                    });
                }, 400);
            });

            const unsubError = Livewire.on('upload:errored', () => {
                clearInterval(interval);
                unsubError();
                Swal.fire({
                    title: 'Erreur d\'upload',
                    text: 'Le fichier n\'a pas pu être chargé.',
                    icon: 'error',
                    confirmButtonColor: '#E24B4A',
                });
            });
        }

        function uploadTermine() {
            const fill = document.getElementById('swal-progress-fill');
            const text = document.getElementById('swal-progress-text');
            if (fill) { fill.style.width = '100%'; fill.style.background = '#16a34a'; }
            if (text) { text.textContent = '100% — Terminé !'; }

            setTimeout(() => {
                Swal.fire({
                    title: 'Fichier prêt !',
                    text: 'Cliquez sur "Vérifier les MSISDN".',
                    icon: 'success',
                    confirmButtonColor: '#1B2F6E'
                });
            }, 500);
        }

        function uploadErreur() {
            Swal.fire({
                title: 'Erreur',
                text: 'Le fichier n\'a pas pu être chargé.',
                icon: 'error'
            });
        }

        function exporterResultats() {
            Swal.fire({
                title:              'Exporter les résultats ?',
                text:               'Un fichier Excel sera généré avec tous les résultats.',
                icon:               'question',
                showCancelButton:   true,
                confirmButtonText:  'Exporter',
                cancelButtonText:   'Annuler',
                confirmButtonColor: '#1B2F6E',
                cancelButtonColor:  '#6b7280',
                reverseButtons:     true,
            }).then((result) => {
                if (!result.isConfirmed) return;

                Swal.fire({
                    title:             'Génération en cours...',
                    text:              'Préparation du fichier Excel.',
                    allowOutsideClick: false,
                    showConfirmButton: false,
                    didOpen: () => {
                        Swal.showLoading();
                        @this.exportResultatsExcel()
                            .then(() => {
                                Swal.fire({
                                    title:             'Exporté !',
                                    text:              'Votre fichier Excel a été téléchargé.',
                                    icon:              'success',
                                    confirmButtonColor: '#1B2F6E',
                                    timer:             2500,
                                    timerProgressBar:  true,
                                });
                            })
                            .catch(() => {
                                Swal.fire({
                                    title: 'Erreur',
                                    text:  'Une erreur est survenue lors de l\'export.',
                                    icon:  'error',
                                    confirmButtonColor: '#E24B4A',
                                });
                            });
                    }
                });
            });
        }

        function lancerVerification() {
            if (!@this.fichier) {
                Swal.fire({
                    title:             'Aucun fichier sélectionné',
                    text:              'Veuillez d\'abord importer un fichier Excel ou CSV.',
                    icon:              'warning',
                    confirmButtonColor: '#1B2F6E',
                });
                return;
            }

            Swal.fire({
                title:             'Vérification en cours...',
                html: `
                    <div style="font-size:13px; color:#6b7280; margin-bottom:16px;">
                        Recherche des MSISDN dans la base clients KYC.
                    </div>
                    <div style="width:100%; height:6px; background:#e5e7eb; border-radius:10px; overflow:hidden; margin-bottom:8px;">
                        <div id="verif-progress-fill" style="
                            height:100%; width:0%; background:#1B2F6E;
                            border-radius:10px; transition:width 0.4s ease;">
                        </div>
                    </div>
                    <div id="verif-progress-text" style="font-size:11px; color:#9ca3af;">Initialisation...</div>
                `,
                allowOutsideClick: false,
                showConfirmButton: false,
                didOpen: () => {
                    let progress = 0;
                    const messages = [
                        'Lecture du fichier...',
                        'Extraction des MSISDN...',
                        'Interrogation de la base...',
                        'Comparaison des données...',
                        'Finalisation...',
                    ];
                    let msgIndex = 0;
                    const getFill = () => document.getElementById('verif-progress-fill');
                    const getText = () => document.getElementById('verif-progress-text');

                    const interval = setInterval(() => {
                        progress += (92 - progress) * 0.08;
                        msgIndex = Math.min(
                            Math.floor((progress / 92) * messages.length),
                            messages.length - 1
                        );
                        if (getFill()) getFill().style.width = progress.toFixed(1) + '%';
                        if (getText()) getText().textContent = messages[msgIndex];
                    }, 200);

                    @this.traiter()
                        .then(() => {
                            clearInterval(interval);
                            if (getFill()) { getFill().style.width = '100%'; getFill().style.background = '#16a34a'; }
                            if (getText()) getText().textContent = 'Terminé !';

                            setTimeout(() => {
                                Swal.fire({
                                    title:             'Vérification terminée !',
                                    html: `
                                        <div style="display:flex; gap:12px; justify-content:center; margin-top:8px;">
                                            <div style="text-align:center;">
                                                <div style="font-size:22px; font-weight:700; color:#1B2F6E;" id="swal-total">—</div>
                                                <div style="font-size:10px; color:#9ca3af;">Total</div>
                                            </div>
                                            <div style="text-align:center;">
                                                <div style="font-size:22px; font-weight:700; color:#16a34a;" id="swal-trouves">—</div>
                                                <div style="font-size:10px; color:#9ca3af;">Trouvés</div>
                                            </div>
                                            <div style="text-align:center;">
                                                <div style="font-size:22px; font-weight:700; color:#E24B4A;" id="swal-introuvables">—</div>
                                                <div style="font-size:10px; color:#9ca3af;">Introuvables</div>
                                            </div>
                                        </div>
                                    `,
                                    icon:              'success',
                                    confirmButtonText: 'Voir les résultats',
                                    confirmButtonColor: '#1B2F6E',
                                    didOpen: () => {
                                        document.getElementById('swal-total').textContent        = @this.stats.total;
                                        document.getElementById('swal-trouves').textContent      = @this.stats.trouves;
                                        document.getElementById('swal-introuvables').textContent = @this.stats.introuvables;
                                    }
                                });
                            }, 400);
                        })
                        .catch(() => {
                            clearInterval(interval);
                            Swal.fire({
                                title:             'Erreur',
                                text:              'Une erreur est survenue lors de la vérification.',
                                icon:              'error',
                                confirmButtonColor: '#E24B4A',
                            });
                        });
                }
            });
        }
    </script>
</div>
</div>