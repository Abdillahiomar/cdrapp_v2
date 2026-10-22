<?php

use App\Exports\CustomerVerificationExport;
use App\Imports\MsisdnImport;
use App\Support\CustomerVerification;
use Livewire\Volt\Component;
use Livewire\WithFileUploads;
use Maatwebsite\Excel\Facades\Excel;

/**
 * Vérification clients (nouvelle version) : MSISDN et nom vérifiés contre les
 * nouvelles tables clients (kyc.customer_master, comptes, KYC, activité).
 * La page d'origine operations/index (« Import MSISDN ») reste inchangée.
 */
new class extends Component {

    use WithFileUploads;

    public $fichier = null;
    public array $resultats = [];
    public array $erreurs = [];
    public bool  $avecNom = false;
    public bool  $traite  = false;

    public string $filtreResultat = '';
    public string $filtreNom      = '';

    public function updatedFichier(): void
    {
        $this->reset(['resultats', 'erreurs', 'traite', 'filtreResultat', 'filtreNom']);
    }

    public function verifier(): void
    {
        set_time_limit(300);
        ini_set('memory_limit', '512M');

        $this->validate(['fichier' => 'required|file|mimes:xlsx,xls,csv,txt|max:10240'], [
            'fichier.required' => 'Choisissez un fichier.',
            'fichier.mimes'    => 'Format accepté : Excel (.xlsx, .xls) ou CSV.',
        ]);

        $this->reset(['resultats', 'erreurs', 'filtreResultat', 'filtreNom']);

        try {
            $import = new MsisdnImport();
            Excel::import($import, $this->fichier->getRealPath());

            $this->avecNom = $import->hasNameColumn();

            // Une entrée par ligne du fichier ; MSISDN réduit à ses chiffres
            $lignes = collect($import->getRows())
                ->map(fn ($r) => array_merge($r, ['msisdn' => preg_replace('/\D/', '', $r['msisdn'])]))
                ->filter(fn ($r) => $r['msisdn'] !== '')
                ->values()
                ->all();

            if (empty($lignes)) {
                $this->erreurs[] = 'Aucun MSISDN trouvé dans le fichier (colonne « MSISDN » attendue).';
                return;
            }

            $this->resultats = CustomerVerification::verify($lignes);
            $this->traite    = true;
        } catch (\Throwable $e) {
            report($e);
            $this->erreurs[] = 'Erreur : ' . $e->getMessage();
        }
    }

    public function reinitialiser(): void
    {
        $this->reset();
    }

    private function lignes(): \Illuminate\Support\Collection
    {
        return collect($this->resultats)
            ->when($this->filtreResultat !== '', fn ($c) => $c->where('result', $this->filtreResultat))
            ->when($this->filtreNom !== '', fn ($c) => $c->where('name_match', $this->filtreNom));
    }

    public function exporter()
    {
        if (empty($this->resultats)) {
            return null;
        }

        return Excel::download(new CustomerVerificationExport($this->lignes()), 'verification_clients_' . now()->format('Ymd_His') . '.xlsx');
    }

    public function with(): array
    {
        $all = collect($this->resultats);

        return [
            'lignes'  => $this->lignes(),
            'stats'   => [
                'total'  => $all->count(),
                'result' => collect(array_keys(CustomerVerification::RESULTS))->mapWithKeys(fn ($k) => [$k => $all->where('result', $k)->count()])->all(),
                'name'   => ['oui' => $all->where('name_match', 'oui')->count(), 'proche' => $all->where('name_match', 'proche')->count(), 'non' => $all->where('name_match', 'non')->count()],
            ],
            'canFiche' => auth()->user()->can('customer360.view') && \Illuminate\Support\Facades\Route::has('customer360.directory'),
        ];
    }
};
?>
<div style="padding:24px;">

@php
    $card  = 'background:#fff; border:1px solid #e5e7eb; border-radius:10px;';
    $pill  = fn ($bg, $c, $txt) => "<span style=\"background:{$bg}; color:{$c}; font-size:10px; font-weight:600; padding:2px 8px; border-radius:12px; white-space:nowrap;\">" . e($txt) . '</span>';
    $th    = 'padding:9px 12px; text-align:left; color:#6b7280; font-weight:500; border-bottom:1px solid #e5e7eb; white-space:nowrap;';
    $td    = 'padding:8px 12px; color:#374151;';
    $int   = fn ($n) => number_format($n, 0, ',', ' ');
    $date  = fn ($v, $f = 'd/m/Y') => $v ? \Carbon\Carbon::parse($v)->format($f) : '—';
@endphp

    <div style="margin-bottom:18px;">
        <h2 style="font-size:16px; font-weight:700; color:#111827; margin:0 0 4px;">Vérification clients</h2>
        <p style="font-size:12px; color:#9ca3af; margin:0;">
            Vérifie une liste de MSISDN (et leurs noms) dans le référentiel clients à jour : statut du client, compte principal, dossier KYC et activité.
        </p>
    </div>

    {{-- IMPORT --}}
    <div style="{{ $card }} padding:18px; margin-bottom:16px;">
        <div style="display:flex; gap:14px; flex-wrap:wrap; align-items:flex-end;">
            <div style="flex:1; min-width:260px;">
                <label style="font-size:11px; color:#6b7280; display:block; margin-bottom:4px;">Fichier Excel ou CSV</label>
                <input type="file" wire:model="fichier" accept=".xlsx,.xls,.csv" style="font-size:13px;">
                <div wire:loading wire:target="fichier" style="font-size:11px; color:#1B2F6E; margin-top:4px;">Envoi du fichier…</div>
                @error('fichier') <p style="font-size:11px; color:#B91C1C; margin:4px 0 0;">{{ $message }}</p> @enderror
            </div>
            <button wire:click="verifier" wire:loading.attr="disabled" wire:target="verifier,fichier"
                    style="background:#1B2F6E; color:#fff; font-size:13px; font-weight:600; padding:9px 20px; border-radius:8px; border:none; cursor:pointer;">
                <span wire:loading.remove wire:target="verifier">Vérifier</span>
                <span wire:loading wire:target="verifier">Vérification en cours…</span>
            </button>
            @if($traite)
                <button wire:click="reinitialiser" style="background:#f3f4f6; color:#374151; font-size:13px; padding:9px 16px; border-radius:8px; border:1px solid #e5e7eb; cursor:pointer;">Recommencer</button>
            @endif
        </div>

        @foreach($erreurs as $e)
            <p style="font-size:12px; color:#7F1D1D; background:#FDECEA; border-radius:6px; padding:8px 12px; margin:12px 0 0;">⚠ {{ $e }}</p>
        @endforeach

        <div style="margin-top:14px; background:#F7F8FC; border-radius:8px; padding:12px 14px; font-size:11px; color:#6b7280; line-height:1.7;">
            <strong style="color:#1B2F6E;">Format attendu :</strong> une colonne <strong>MSISDN</strong> (format 253XXXXXXXX) et, pour comparer les noms, une colonne
            <strong>full_name</strong> (ou « Nom complet »). Chaque ligne du fichier est vérifiée, doublons compris.<br>
            <strong style="color:#1B2F6E;">Vérification :</strong>
            <em>Client actif</em> = dans le référentiel, statut Active et compte principal actif ·
            <em>Client bloqué</em> = statut ou compte principal non actif (le détail précise lequel) ·
            <em>Retiré du référentiel</em> = absent du dernier fichier complet ·
            <em>Ancien client</em> = connu seulement dans l'ancienne base KYC · <em>Introuvable</em> = inconnu partout.<br>
            <strong style="color:#1B2F6E;">Nom :</strong> comparé au nom du dossier KYC (récent, sinon ancien) ; majuscules, accents et ponctuation ignorés, ordre des mots respecté.
        </div>
    </div>

    @if($traite)
        {{-- STATISTIQUES --}}
        <div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(150px, 1fr)); gap:10px; margin-bottom:14px;">
            <div style="{{ $card }} padding:14px; border-top:3px solid #1B2F6E;">
                <p style="font-size:11px; color:#6b7280; margin:0 0 4px;">Lignes vérifiées</p>
                <p style="font-size:22px; font-weight:700; color:#111827; margin:0;">{{ $int($stats['total']) }}</p>
            </div>
            @foreach(\App\Support\CustomerVerification::RESULTS as $key => [$label, $bg, $color])
                <button wire:click="$set('filtreResultat', '{{ $filtreResultat === $key ? '' : $key }}')"
                        style="{{ $card }} padding:14px; border-top:3px solid {{ $color }}; text-align:left; cursor:pointer; {{ $filtreResultat === $key ? 'background:' . $bg . ';' : '' }}">
                    <p style="font-size:11px; color:#6b7280; margin:0 0 4px;">{{ $label }}</p>
                    <p style="font-size:22px; font-weight:700; color:{{ $color }}; margin:0;">{{ $int($stats['result'][$key]) }}</p>
                </button>
            @endforeach
            @if($avecNom)
                <div style="{{ $card }} padding:14px; border-top:3px solid #F5A800;">
                    <p style="font-size:11px; color:#6b7280; margin:0 0 4px;">Comparaison des noms</p>
                    <p style="font-size:12px; margin:0; line-height:1.6;">
                        <strong style="color:#005C2B;">{{ $int($stats['name']['oui']) }}</strong> identique(s) ·
                        <strong style="color:#92400E;">{{ $int($stats['name']['proche']) }}</strong> proche(s) ·
                        <strong style="color:#B91C1C;">{{ $int($stats['name']['non']) }}</strong> différent(s)
                    </p>
                </div>
            @endif
        </div>

        {{-- TABLEAU --}}
        <div style="{{ $card }} overflow:hidden;">
            <div style="padding:10px 16px; border-bottom:1px solid #e5e7eb; display:flex; justify-content:space-between; align-items:center; gap:10px; flex-wrap:wrap;">
                <div style="display:flex; align-items:center; gap:10px; flex-wrap:wrap;">
                    <p style="font-size:13px; font-weight:600; color:#111827; margin:0;">Résultats <span style="color:#9ca3af; font-weight:500;">({{ $int($lignes->count()) }})</span></p>
                    @if($filtreResultat !== '')
                        <button wire:click="$set('filtreResultat', '')" style="background:#E8ECF8; color:#1B2F6E; font-size:11px; font-weight:600; padding:3px 10px; border-radius:12px; border:none; cursor:pointer;">{{ \App\Support\CustomerVerification::RESULTS[$filtreResultat][0] }} ✕</button>
                    @endif
                    @if($avecNom)
                        <div style="display:flex; gap:2px; background:#F4F6FB; padding:3px; border-radius:8px; border:1px solid #e5e7eb;">
                            @foreach(['' => 'Tous les noms', 'non' => 'Non', 'proche' => 'Proche', 'oui' => 'Oui'] as $val => $lab)
                                <button wire:click="$set('filtreNom', '{{ $val }}')" style="font-size:11px; font-weight:600; padding:4px 10px; border-radius:6px; border:none; cursor:pointer; {{ $filtreNom === $val ? 'background:#1B2F6E; color:#fff;' : 'background:transparent; color:#6b7280;' }}">{{ $lab }}</button>
                            @endforeach
                        </div>
                    @endif
                </div>
                <button wire:click="exporter" style="background:#fff; color:#1B2F6E; font-size:12px; font-weight:600; padding:7px 14px; border-radius:7px; border:1px solid #1B2F6E; cursor:pointer;">⬇ Exporter (Excel)</button>
            </div>

            <div style="overflow-x:auto;">
                <table style="width:100%; border-collapse:collapse; font-size:12px;">
                    <thead><tr style="background:#F7F8FC;">
                        <th style="{{ $th }}">Ligne</th><th style="{{ $th }}">MSISDN</th><th style="{{ $th }}">Vérification</th>
                        @if($avecNom) <th style="{{ $th }}">Nom (fichier)</th> @endif
                        <th style="{{ $th }}">Nom (base)</th>
                        @if($avecNom) <th style="{{ $th }}">Même nom</th> @endif
                        <th style="{{ $th }}">Statut client</th><th style="{{ $th }}">Compte principal</th><th style="{{ $th }}">Segment</th>
                        <th style="{{ $th }}">Inscrit / activé</th><th style="{{ $th }}">Dernière transaction</th><th style="{{ $th }}">Pièce</th>
                    </tr></thead>
                    <tbody>
                        @forelse($lignes as $r)
                            @php [$rl, $rbg, $rc] = \App\Support\CustomerVerification::RESULTS[$r['result']]; @endphp
                            <tr wire:key="v-{{ $r['line'] }}" style="border-bottom:1px solid #f3f4f6;">
                                <td style="{{ $td }} color:#9ca3af;">{{ $r['line'] }}</td>
                                <td style="{{ $td }} font-family:monospace; font-weight:600; white-space:nowrap;">
                                    @if($canFiche && in_array($r['result'], ['active', 'blocked', 'removed']))
                                        <a href="{{ route('customer360.directory', ['client' => $r['msisdn']]) }}" target="_blank" style="color:#1B2F6E; text-decoration:none;" title="Ouvrir la fiche client">{{ $r['msisdn'] }} ↗</a>
                                    @else {{ $r['msisdn'] }} @endif
                                </td>
                                <td style="{{ $td }}">{!! $pill($rbg, $rc, $rl) !!} @if($r['reason']) <br><span style="font-size:10px; color:#6b7280;">{{ $r['reason'] }}</span> @endif</td>
                                @if($avecNom) <td style="{{ $td }}">{{ $r['file_name'] ?: '—' }}</td> @endif
                                <td style="{{ $td }}">
                                    {{ $r['db_name'] ?: '—' }}
                                    @if($r['name_source']) <br><span style="font-size:10px; color:#9ca3af;">{{ $r['name_source'] }}</span> @endif
                                </td>
                                @if($avecNom)
                                    <td style="{{ $td }} white-space:nowrap;">
                                        @if($r['name_match'] === 'oui') {!! $pill('#E5F5ED', '#005C2B', '✓ Oui') !!}
                                        @elseif($r['name_match'] === 'proche') {!! $pill('#FEF3C7', '#92400E', '≈ Proche · ' . $r['name_score'] . ' %') !!}
                                        @elseif($r['name_match'] === 'non') {!! $pill('#FDECEA', '#7F1D1D', '✗ Non · ' . $r['name_score'] . ' %') !!}
                                        @else <span style="color:#9ca3af;">—</span> @endif
                                    </td>
                                @endif
                                <td style="{{ $td }}">{{ $r['identity_status'] ?? '—' }}</td>
                                <td style="{{ $td }}">{{ $r['main_account'] ?? '—' }}</td>
                                <td style="{{ $td }}">{{ $r['segment'] ?? '—' }}<br><span style="font-size:10px; color:#9ca3af;">{{ $r['product'] }}</span></td>
                                <td style="{{ $td }} white-space:nowrap;">{{ $date($r['registered_at']) }}<br><span style="font-size:10px; color:#9ca3af;">activé {{ $date($r['activated_at']) }}</span></td>
                                <td style="{{ $td }} white-space:nowrap;">
                                    {{ $date($r['last_txn_at'], 'd/m/Y H:i') }}
                                    @if($r['activity'])
                                        @php [$al, $abg, $ac] = \App\Support\Customers::ACTIVITY[$r['activity']]; @endphp
                                        <br>{!! $pill($abg, $ac, $al) !!}
                                    @endif
                                </td>
                                <td style="{{ $td }}">{{ $r['id_type'] ?? '—' }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="12" style="padding:24px; text-align:center; color:#9ca3af;">Aucune ligne pour ce filtre.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    @endif

</div>
