<?php

use App\Exports\PcaCashoutsExport;
use App\Jobs\SendPcaSnapshot;
use App\Models\PcaBalance;
use App\Models\PcaCashout;
use App\Models\PcaIncident;
use App\Models\PcaSetting;
use App\Support\PcaBalances;
use App\Support\PcaHuaweiImport;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Livewire\Volt\Component;
use Livewire\WithFileUploads;
use Livewire\WithPagination;
use Maatwebsite\Excel\Facades\Excel;

/**
 * PCA (plan de continuité d'activité) : en cas de panne du service D-Money,
 * consulter le dernier solde connu de chaque compte et enregistrer les
 * cash-out payés manuellement, pour ne jamais payer deux fois un client.
 */
new class extends Component {

    use WithFileUploads, WithPagination;

    public string $tab = 'consultation';   // consultation | incident | huawei | email

    // ── Consultation ──
    public string  $search       = '';
    public ?string $partyId      = null;
    public ?string $partyType    = null;

    // ── Saisie d'un cash-out ──
    public string $coAccount  = '';
    public string $coAmount   = '';
    public string $coDocument = '';
    public string $coNotes    = '';

    // ── Annulation ──
    public ?int   $cancelId     = null;
    public string $cancelReason = '';

    // ── Incident ──
    public string $incidentTitle = '';
    public string $filterAgency  = '';
    public string $closeNotes    = '';

    // ── Import Huawei ──
    public $huaweiFile       = null;
    public string $huaweiAsOf    = '';
    public bool   $huaweiDiv100  = false;
    public array  $huaweiPreview = [];

    // ── Email ──
    public string $emailRecipients = '';
    public bool   $emailDaily      = false;
    public bool   $emailNonZero    = true;
    public string $zipPassword     = '';

    public function mount(): void
    {
        $this->emailRecipients = (string) PcaSetting::get('email_recipients', '');
        $this->emailDaily      = PcaSetting::get('email_daily') === '1';
        $this->emailNonZero    = PcaSetting::get('email_nonzero_only', '1') === '1';
        $this->huaweiAsOf      = now()->format('Y-m-d\TH:i');
    }

    public function updatedFilterAgency(): void
    {
        $this->resetPage();
    }

    public function setTab(string $tab): void
    {
        $this->tab = in_array($tab, ['consultation', 'incident', 'huawei', 'email']) ? $tab : 'consultation';
        $this->resetErrorBag();
        $this->resetPage();
    }

    private function requirePermission(string $permission): void
    {
        abort_unless(auth()->user()->can($permission), 403);
    }

    private function log(string $partyId, string $action): void
    {
        DB::table('pca_access_logs')->insert([
            'user_id'    => auth()->id(),
            'party_id'   => $partyId,
            'action'     => $action,
            'created_at' => now(),
        ]);
    }

    // ───────────────────────────── Consultation ─────────────────────────────

    public function selectParty(string $partyId, string $partyType): void
    {
        $this->partyId   = $partyId;
        $this->partyType = $partyType;
        $this->coAccount = PcaBalance::MAIN_ACCOUNTS[$partyType] ?? '';
        $this->reset(['coAmount', 'coNotes', 'cancelId', 'cancelReason']);
        $this->resetErrorBag();

        $identity          = PcaBalances::identity($partyId, $partyType);
        $this->coDocument  = $identity['Pièce'] ?? '';

        $this->log($partyId, 'view');
    }

    public function closeParty(): void
    {
        $this->partyId = $this->partyType = null;
    }

    /**
     * Enregistre un cash-out payé au guichet. Verrou sur la ligne de solde : deux
     * agences qui paient le même client au même moment ne peuvent pas dépasser le solde.
     */
    public function recordCashout(): void
    {
        $this->requirePermission('pca.cashout');

        $incident = PcaIncident::active();
        if (!$incident || !$this->partyId) {
            $this->addError('coAmount', 'Le mode PCA n\'est pas actif : aucun cash-out ne peut être enregistré.');
            return;
        }

        // L'agence est celle de l'utilisateur connecté, jamais saisie
        $agency = auth()->user()->agency;
        if (!$agency || !$agency->is_active) {
            $this->addError('coAmount', $agency
                ? "Votre agence ({$agency->label()}) est désactivée : contactez l'administrateur."
                : 'Votre compte n\'est rattaché à aucune agence : contactez l\'administrateur.');
            return;
        }

        $this->validate([
            'coAccount'  => 'required|string',
            'coAmount'   => 'required|numeric|min:1',
            'coDocument' => 'required|string|min:3|max:255',
            'coNotes'    => 'nullable|string|max:1000',
        ], [
            'coAmount.required'   => 'Indiquez le montant payé.',
            'coAmount.min'        => 'Le montant doit être supérieur à 0.',
            'coDocument.required' => 'Indiquez la pièce d\'identité vérifiée.',
        ]);

        $amount = round((float) $this->coAmount, 2);

        $result = DB::transaction(function () use ($incident, $amount, $agency) {
            $balance = PcaBalance::where('party_id', $this->partyId)
                ->where('party_type', $this->partyType)
                ->where('account_type', $this->coAccount)
                ->lockForUpdate()
                ->first();

            if (!$balance) {
                return 'Solde inconnu pour ce compte : cash-out impossible.';
            }

            $paid = (float) PcaCashout::valid()
                ->where('incident_id', $incident->id)
                ->where('party_id', $this->partyId)
                ->where('party_type', $this->partyType)
                ->where('account_type', $this->coAccount)
                ->sum('amount');

            $available = round($balance->balance - $paid, 2);

            if ($amount > $available) {
                return 'Montant supérieur au solde PCA disponible (' . number_format($available, 0, ',', ' ') . ' FDJ).';
            }

            PcaCashout::create([
                'incident_id'      => $incident->id,
                'party_id'         => $this->partyId,
                'party_type'       => $this->partyType,
                'account_type'     => $this->coAccount,
                'amount'           => $amount,
                'available_before' => $available,
                'agency_id'        => $agency->id,
                'location'         => $agency->label(),   // copie du nom au moment du paiement
                'id_document'      => $this->coDocument,
                'notes'            => $this->coNotes ?: null,
                'recorded_by'      => auth()->id(),
            ]);

            return null;
        });

        if ($result) {
            $this->addError('coAmount', $result);
            return;
        }

        $this->log($this->partyId, 'cashout');
        $this->reset(['coAmount', 'coNotes']);
        session()->flash('pca-message', 'Cash-out de ' . number_format($amount, 0, ',', ' ') . ' FDJ enregistré pour ' . $this->partyId . '.');
    }

    public function askCancel(int $id): void
    {
        $this->requirePermission('pca.manage');
        $this->cancelId     = $id;
        $this->cancelReason = '';
    }

    public function cancelCashout(): void
    {
        $this->requirePermission('pca.manage');

        $this->validate(['cancelReason' => 'required|string|min:5'], ['cancelReason.required' => 'Le motif d\'annulation est obligatoire.', 'cancelReason.min' => 'Le motif doit contenir au moins 5 caractères.']);

        $cashout = PcaCashout::valid()->findOrFail($this->cancelId);
        $cashout->update(['cancelled_at' => now(), 'cancelled_by' => auth()->id(), 'cancel_reason' => $this->cancelReason]);

        $this->log($cashout->party_id, 'cancel');
        $this->cancelId = null;
        session()->flash('pca-message', 'Cash-out n°' . $cashout->id . ' annulé.');
    }

    // ───────────────────────────── Incident ─────────────────────────────

    public function startIncident(): void
    {
        $this->requirePermission('pca.manage');

        if (PcaIncident::active()) {
            return;
        }

        $this->validate(['incidentTitle' => 'required|string|min:3|max:255'], ['incidentTitle.required' => 'Donnez un intitulé à l\'incident (ex. « Panne plateforme D-Money du 12/10 »).']);

        PcaIncident::create(['title' => $this->incidentTitle, 'started_at' => now(), 'opened_by' => auth()->id()]);

        $this->incidentTitle = '';
        session()->flash('pca-message', 'Mode PCA activé : les cash-out peuvent être enregistrés.');
    }

    public function closeIncident(): void
    {
        $this->requirePermission('pca.manage');

        $incident = PcaIncident::active();
        if (!$incident) {
            return;
        }

        $incident->update(['ended_at' => now(), 'closed_by' => auth()->id(), 'notes' => $this->closeNotes ?: null]);

        $this->closeNotes = '';
        session()->flash('pca-message', 'Mode PCA désactivé. Exportez les cash-out de l\'incident pour leur régularisation dans D-Money.');
    }

    public function exportIncident(int $incidentId)
    {
        $this->requirePermission('pca.manage');

        $incident = PcaIncident::findOrFail($incidentId);

        return Excel::download(new PcaCashoutsExport($incident), 'cashout_pca_incident_' . $incident->id . '_' . now()->format('Ymd_His') . '.xlsx');
    }

    // ───────────────────────────── Fichier Huawei ─────────────────────────────

    public function analyzeHuawei(): void
    {
        $this->requirePermission('pca.manage');

        $this->validate([
            'huaweiFile' => 'required|file|mimes:xlsx,xls,csv,txt|max:51200',
            'huaweiAsOf' => 'required|date',
        ], [
            'huaweiFile.required' => 'Choisissez le fichier fourni par Huawei.',
            'huaweiAsOf.required' => 'Indiquez la date et l\'heure des soldes du fichier.',
        ]);

        set_time_limit(300);
        $result = PcaHuaweiImport::analyze($this->huaweiFile->getRealPath(), $this->huaweiDiv100);

        if ($result['error']) {
            $this->huaweiPreview = ['error' => $result['error']];
            return;
        }

        $rows   = collect($result['rows']);
        $valid  = $rows->whereNull('error');
        $sample = $valid->take(10)->values();

        // Solde actuel des comptes de l'échantillon, pour repérer une erreur d'unité (×100)
        $current = PcaBalance::whereIn('party_id', $sample->pluck('party_id'))->get()
            ->mapWithKeys(fn ($b) => [PcaBalances::key($b->party_id, $b->party_type, $b->account_type) => $b->balance]);

        $this->huaweiPreview = [
            'error'   => null,
            'total'   => $rows->count(),
            'valid'   => $valid->count(),
            'errors'  => $rows->whereNotNull('error')->take(20)->values()->all(),
            'nbErr'   => $rows->whereNotNull('error')->count(),
            'sample'  => $sample->map(fn ($r) => $r + ['current' => $current[PcaBalances::key($r['party_id'], $r['party_type'], $r['account_type'])] ?? null])->all(),
            'columns' => $result['columns'],
        ];
    }

    public function applyHuawei(): void
    {
        $this->requirePermission('pca.manage');

        if (empty($this->huaweiPreview) || $this->huaweiPreview['error'] || !$this->huaweiFile) {
            return;
        }

        set_time_limit(600);
        $asOf   = Carbon::parse($this->huaweiAsOf)->format('Y-m-d H:i:s');
        $result = PcaHuaweiImport::analyze($this->huaweiFile->getRealPath(), $this->huaweiDiv100);
        $done   = PcaHuaweiImport::apply($result['rows'], $asOf);

        DB::table('pca_imports')->insert([
            'file_name'    => $this->huaweiFile->getClientOriginalName(),
            'as_of'        => $asOf,
            'rows_total'   => count($result['rows']),
            'rows_applied' => $done['applied'],
            'rows_skipped' => $done['skipped'],
            'user_id'      => auth()->id(),
            'created_at'   => now(),
        ]);

        $this->reset(['huaweiFile', 'huaweiPreview']);
        session()->flash('pca-message', number_format($done['applied'], 0, ',', ' ') . ' solde(s) mis à jour depuis le fichier Huawei ; ' . number_format($done['skipped'], 0, ',', ' ') . ' ignoré(s) (erreur ou solde déjà plus récent).');
    }

    // ───────────────────────────── Email ─────────────────────────────

    public function saveEmailSettings(): void
    {
        $this->requirePermission('pca.manage');

        $emails  = array_values(array_filter(array_map('trim', preg_split('/[\s,;]+/', $this->emailRecipients))));
        $invalid = array_filter($emails, fn ($e) => !filter_var($e, FILTER_VALIDATE_EMAIL));

        if ($invalid) {
            $this->addError('emailRecipients', 'Adresse(s) invalide(s) : ' . implode(', ', $invalid));
            return;
        }
        if ($this->emailDaily && empty($emails)) {
            $this->addError('emailRecipients', 'Ajoutez au moins un destinataire pour activer l\'envoi quotidien.');
            return;
        }
        if ($this->zipPassword !== '' && strlen($this->zipPassword) < 10) {
            $this->addError('zipPassword', 'Le mot de passe doit contenir au moins 10 caractères.');
            return;
        }
        if ($this->emailDaily && $this->zipPassword === '' && !PcaSetting::zipPassword()) {
            $this->addError('zipPassword', 'Définissez le mot de passe du fichier ZIP avant d\'activer l\'envoi.');
            return;
        }

        PcaSetting::put('email_recipients', implode(', ', $emails));
        PcaSetting::put('email_daily', $this->emailDaily ? '1' : '0');
        PcaSetting::put('email_nonzero_only', $this->emailNonZero ? '1' : '0');
        if ($this->zipPassword !== '') {
            PcaSetting::setZipPassword($this->zipPassword);
        }

        $this->emailRecipients = implode(', ', $emails);
        $this->zipPassword     = '';
        session()->flash('pca-message', 'Réglages d\'envoi enregistrés.');
    }

    public function sendNow(): void
    {
        $this->requirePermission('pca.manage');

        if (empty(PcaSetting::recipients()) || !PcaSetting::zipPassword()) {
            $this->addError('emailRecipients', 'Enregistrez d\'abord les destinataires et le mot de passe du ZIP.');
            return;
        }

        SendPcaSnapshot::dispatch();
        session()->flash('pca-message', 'Envoi lancé en arrière-plan : le résultat apparaîtra dans « Dernier envoi » d\'ici une à deux minutes.');
    }

    // ───────────────────────────── Données ─────────────────────────────

    public function with(): array
    {
        $incident = PcaIncident::active();

        $data = [
            'myAgency'    => auth()->user()->agency,
            'incident'    => $incident,
            'dataUntil'   => DB::table('pca_balances')->where('source', 'transactions')->max('as_of'),
            'lastHuawei'  => DB::table('pca_imports')->latest('created_at')->first(),
            'nbAccounts'  => DB::table('pca_balances')->count(),
        ];

        if ($this->tab === 'consultation') {
            $data['results'] = $this->searchResults();

            if ($this->partyId) {
                $paid = PcaBalances::paidDuringIncident($incident, $this->partyId);

                $data['accounts'] = PcaBalance::where('party_id', $this->partyId)
                    ->where('party_type', $this->partyType)
                    ->orderByRaw("CASE WHEN account_type IN ('Customer Main Account', 'Org Main Account') THEN 0 ELSE 1 END")
                    ->orderBy('account_type')
                    ->get()
                    ->map(function ($b) use ($paid) {
                        $b->paid      = $paid[PcaBalances::key($b->party_id, $b->party_type, $b->account_type)] ?? 0.0;
                        $b->available = round($b->balance - $b->paid, 2);
                        return $b;
                    });
                $data['identity']  = PcaBalances::identity($this->partyId, $this->partyType);
                $data['watchlist'] = DB::table('aml_watchlist')->where('msisdn', $this->partyId)->where('actif', true)->get();
                $data['partyCashouts'] = $incident
                    ? PcaCashout::with('recorder:id,name')->where('incident_id', $incident->id)->where('party_id', $this->partyId)->latest()->get()
                    : collect();
            }
        }

        if ($this->tab === 'incident') {
            $data['cashouts'] = $incident
                ? PcaCashout::with(['recorder:id,name', 'canceller:id,name'])
                    ->where('incident_id', $incident->id)
                    ->when($this->filterAgency !== '', fn ($q) => $q->where('agency_id', (int) $this->filterAgency))
                    ->latest()->paginate(50)
                : null;
            // Récapitulatif par agence (cash-out valides)
            $data['byAgency'] = $incident
                ? PcaCashout::valid()->where('pca_cashouts.incident_id', $incident->id)
                    ->leftJoin('agencies', 'agencies.id', '=', 'pca_cashouts.agency_id')
                    ->selectRaw("pca_cashouts.agency_id, COALESCE(agencies.code || ' — ' || agencies.name, 'Sans agence') AS agency, COUNT(*) AS n, SUM(amount) AS total")
                    ->groupBy('pca_cashouts.agency_id', 'agencies.code', 'agencies.name')
                    ->orderByDesc('total')
                    ->get()
                : collect();
            $data['incidentStats'] = $incident
                ? PcaCashout::valid()->where('incident_id', $incident->id)->selectRaw('COUNT(*) n, COALESCE(SUM(amount), 0) total, COUNT(DISTINCT party_id) clients')->first()
                : null;
            $data['history'] = PcaIncident::with(['opener:id,name', 'closer:id,name'])
                ->whereNotNull('ended_at')
                ->withCount(['cashouts as nb_valid' => fn ($q) => $q->whereNull('cancelled_at')])
                ->withSum(['cashouts as total_valid' => fn ($q) => $q->whereNull('cancelled_at')], 'amount')
                ->latest('started_at')->limit(10)->get();
        }

        if ($this->tab === 'huawei') {
            $data['imports'] = DB::table('pca_imports as i')->leftJoin('users as u', 'u.id', '=', 'i.user_id')
                ->select('i.*', 'u.name as user_name')->orderByDesc('i.created_at')->limit(10)->get();
        }

        if ($this->tab === 'email') {
            $data['passwordSet'] = (bool) PcaSetting::get('zip_password');
            $data['lastEmailAt'] = PcaSetting::get('last_email_at');
            $data['lastEmail']   = PcaSetting::get('last_email_status');
            $data['mailer']      = config('mail.default');
        }

        return $data;
    }

    /** Recherche par MSISDN / shortcode, nom (KYC ou organisation) ou numéro de pièce. */
    private function searchResults(): array
    {
        $term = trim($this->search);
        if (mb_strlen($term) < 3) {
            return [];
        }

        $ids = collect();

        if (preg_match('/^\d+$/', $term)) {
            $ids = $ids->merge(DB::table('pca_balances')->where('party_id', 'like', $term . '%')->distinct()->limit(20)->pluck('party_id'));
        }

        $ids = $ids->merge(DB::table('kyc.kyc_customers')
            ->where(fn ($q) => $q->where('full_name', 'ilike', '%' . $term . '%')->orWhere('id_number', $term))
            ->limit(20)->pluck('msisdn'));

        $ids = $ids->merge(DB::table('organizations')->where('BIZ_ORG_NAME', 'ilike', '%' . $term . '%')->limit(20)->pluck('SHORT_CODE')->map(fn ($v) => (string) $v));

        // Nom porté par les transactions (organisations surtout : la table organizations peut être vide)
        $ids = $ids->merge(DB::table('pca_balances')->where('party_name', 'ilike', '%' . $term . '%')->distinct()->limit(20)->pluck('party_id'));

        $ids = $ids->filter()->unique()->take(30)->values();
        if ($ids->isEmpty()) {
            return [];
        }

        $names = DB::table('kyc.kyc_customers')->whereIn('msisdn', $ids)->pluck('full_name', 'msisdn')
            ->union(DB::table('organizations')->whereIn(DB::raw('"SHORT_CODE"::text'), $ids)->pluck('BIZ_ORG_NAME', 'SHORT_CODE')->mapWithKeys(fn ($v, $k) => [(string) $k => $v]));

        return DB::table('pca_balances')
            ->whereIn('party_id', $ids)
            ->selectRaw("party_id, party_type, COUNT(*) AS accounts, MAX(as_of) AS as_of, MAX(party_name) AS tx_name,
                         SUM(CASE WHEN account_type IN ('Customer Main Account', 'Org Main Account') THEN balance ELSE 0 END) AS main_balance")
            ->groupBy('party_id', 'party_type')
            ->orderBy('party_id')
            ->get()
            ->map(fn ($r) => (array) $r + ['name' => $names[$r->party_id] ?? $r->tx_name])
            ->all();
    }
};
?>
<div style="padding:24px;">

@php
    $inputStyle = 'border:1px solid #d1d5db; border-radius:7px; padding:8px 10px; font-size:13px; color:#111827; outline:none; background:#fff; box-sizing:border-box;';
    $labelStyle = 'font-size:11px; color:#6b7280; display:block; margin-bottom:4px;';
    $card       = 'background:#fff; border:1px solid #e5e7eb; border-radius:10px; padding:18px;';
    $btnPrimary = 'background:#1B2F6E; color:#fff; font-size:13px; font-weight:600; padding:9px 16px; border-radius:8px; border:none; cursor:pointer;';
    $btnLight   = 'background:#fff; color:#374151; font-size:13px; font-weight:600; padding:9px 16px; border-radius:8px; border:1px solid #d1d5db; cursor:pointer;';
    $fdj        = fn ($v) => number_format((float) $v, 0, ',', ' ');
    $err        = fn ($m) => '<p style="font-size:11px; color:#B91C1C; margin:4px 0 0;">' . e($m) . '</p>';
    $canManage  = auth()->user()->can('pca.manage');
    $canCashout = auth()->user()->can('pca.cashout');
@endphp

    {{-- ── EN-TÊTE ── --}}
    <div style="margin-bottom:14px;">
        <h2 style="font-size:16px; font-weight:700; color:#111827; margin:0 0 4px;">PCA — Soldes des comptes</h2>
        <p style="font-size:12px; color:#6b7280; margin:0;">
            Dernier solde connu de chaque compte, pour payer les cash-out en cas de panne du service D-Money.
            {{ number_format($nbAccounts, 0, ',', ' ') }} comptes ·
            transactions jusqu'au <strong>{{ $dataUntil ? \Carbon\Carbon::parse($dataUntil)->format('d/m/Y H:i') : '—' }}</strong>
            @if($lastHuawei) · dernier fichier Huawei : soldes au <strong>{{ \Carbon\Carbon::parse($lastHuawei->as_of)->format('d/m/Y H:i') }}</strong> @endif
        </p>
    </div>

    {{-- ── BANDEAU INCIDENT ── --}}
    @if($incident)
        <div style="background:#FDE8E8; border:1px solid #F5B5B5; border-left:4px solid #B91C1C; border-radius:8px; padding:12px 16px; margin-bottom:14px; display:flex; justify-content:space-between; align-items:center; gap:10px; flex-wrap:wrap;">
            <div>
                <p style="font-size:13px; font-weight:700; color:#7F1D1D; margin:0;">⚠ Mode PCA actif — {{ $incident->title }}</p>
                <p style="font-size:12px; color:#7F1D1D; margin:2px 0 0;">Depuis le {{ $incident->started_at->format('d/m/Y à H:i') }}. Chaque cash-out payé doit être enregistré ici : il est déduit du solde disponible du client.</p>
            </div>
        </div>
    @endif

    @if(session('pca-message'))
        <div style="background:#E5F5ED; color:#005C2B; border:1px solid #A7E3C1; border-radius:8px; padding:10px 14px; font-size:12px; margin-bottom:14px;">{{ session('pca-message') }}</div>
    @endif

    {{-- ── ONGLETS ── --}}
    <div style="display:flex; gap:4px; border-bottom:1px solid #e5e7eb; margin-bottom:18px; flex-wrap:wrap;">
        @foreach(['consultation' => 'Consultation & cash-out', 'incident' => 'Incident', 'huawei' => 'Fichier Huawei', 'email' => 'Envoi par email'] as $key => $label)
            @if(in_array($key, ['huawei', 'email']) && !$canManage) @continue @endif
            <button wire:click="setTab('{{ $key }}')"
                    style="padding:9px 16px; font-size:13px; background:none; border:none; cursor:pointer; margin-bottom:-1px; border-bottom:2px solid {{ $tab === $key ? '#1B2F6E' : 'transparent' }}; color:{{ $tab === $key ? '#1B2F6E' : '#6b7280' }}; font-weight:{{ $tab === $key ? '600' : '500' }};">
                {{ $label }}
                @if($key === 'incident' && $incident)<span style="background:#B91C1C; color:#fff; font-size:9px; font-weight:700; padding:1px 6px; border-radius:8px; margin-left:4px;">ACTIF</span>@endif
            </button>
        @endforeach
    </div>

    {{-- ═════════════════════ CONSULTATION ═════════════════════ --}}
    @if($tab === 'consultation')
        <div style="{{ $card }} margin-bottom:16px;">
            <label style="{{ $labelStyle }}">Rechercher un compte</label>
            <input type="text" wire:model.live.debounce.400ms="search" placeholder="MSISDN, shortcode, nom du client ou de l'organisation, numéro de pièce…"
                   style="{{ $inputStyle }} width:100%;">
            <p style="font-size:11px; color:#9ca3af; margin:4px 0 0;">3 caractères minimum.</p>

            @if(!empty($results))
                <table style="width:100%; border-collapse:collapse; font-size:12px; margin-top:12px;">
                    <thead>
                        <tr style="background:#F7F8FC;">
                            @foreach(['Compte', 'Nom', 'Titulaire', 'Comptes', 'Solde principal (FDJ)', 'Solde au', ''] as $th)
                                <th style="padding:8px 12px; text-align:left; color:#6b7280; font-weight:500; border-bottom:1px solid #e5e7eb;">{{ $th }}</th>
                            @endforeach
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($results as $r)
                            <tr wire:key="res-{{ $r['party_id'] }}-{{ $r['party_type'] }}" style="border-bottom:1px solid #f3f4f6; cursor:pointer; {{ $partyId === $r['party_id'] ? 'background:#E8ECF8;' : '' }}"
                                wire:click="selectParty('{{ $r['party_id'] }}', '{{ $r['party_type'] }}')">
                                <td style="padding:8px 12px; font-family:monospace; font-weight:600; color:#111827;">{{ $r['party_id'] }}</td>
                                <td style="padding:8px 12px; color:#374151;">{{ $r['name'] ?? '—' }}</td>
                                <td style="padding:8px 12px; color:#6b7280;">{{ $r['party_type'] === 'Organization' ? 'Organisation' : 'Client' }}</td>
                                <td style="padding:8px 12px; color:#6b7280;">{{ $r['accounts'] }}</td>
                                <td style="padding:8px 12px; color:#111827; font-weight:600;">{{ $fdj($r['main_balance']) }}</td>
                                <td style="padding:8px 12px; color:#6b7280;">{{ \Carbon\Carbon::parse($r['as_of'])->format('d/m/Y H:i') }}</td>
                                <td style="padding:8px 12px; color:#1B2F6E; font-weight:600;">Ouvrir →</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @elseif(mb_strlen(trim($search)) >= 3)
                <p style="font-size:12px; color:#9ca3af; margin:12px 0 0;">Aucun compte trouvé. Un compte sans transaction depuis le 19/08/2024 n'a pas de solde connu.</p>
            @endif
        </div>

        @if($partyId)
            <div style="{{ $card }}">
                <div style="display:flex; justify-content:space-between; align-items:flex-start; gap:10px; margin-bottom:14px;">
                    <div>
                        <p style="font-size:16px; font-weight:700; color:#111827; margin:0; font-family:monospace;">{{ $partyId }}</p>
                        <p style="font-size:12px; color:#6b7280; margin:2px 0 0;">{{ $partyType === 'Organization' ? 'Organisation' : 'Client' }}</p>
                    </div>
                    <button wire:click="closeParty" style="background:none; border:none; font-size:22px; color:#9ca3af; cursor:pointer; line-height:1;">×</button>
                </div>

                {{-- Alerte listes de surveillance --}}
                @foreach($watchlist as $w)
                    <div style="background:#FDE8E8; border-left:3px solid #B91C1C; border-radius:6px; padding:10px 12px; margin-bottom:10px; font-size:12px; color:#7F1D1D;">
                        <strong>⚠ Compte en {{ ['ppe' => 'liste PPE', 'black_list' => 'BLACK LIST', 'grey_list' => 'liste grise'][$w->list_type] ?? $w->list_type }}</strong>
                        @if($w->motif) — {{ $w->motif }} @endif. Consultez la conformité avant tout paiement.
                    </div>
                @endforeach

                {{-- Identité KYC --}}
                <p style="font-size:12px; font-weight:600; color:#111827; margin:0 0 8px;">Identité (à vérifier avec la pièce présentée)</p>
                @if($identity)
                    <div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(180px, 1fr)); gap:8px; margin-bottom:16px;">
                        @foreach($identity as $label => $value)
                            <div style="background:#F7F8FC; border-radius:6px; padding:8px 10px;">
                                <p style="font-size:10px; color:#9ca3af; margin:0 0 2px; text-transform:uppercase;">{{ $label }}</p>
                                <p style="font-size:12px; color:#111827; font-weight:600; margin:0;">{{ $value }}</p>
                            </div>
                        @endforeach
                    </div>
                @else
                    <p style="font-size:12px; color:#B91C1C; margin:0 0 16px;">Aucune fiche KYC trouvée pour ce compte : identité non vérifiable.</p>
                @endif

                {{-- Soldes --}}
                <p style="font-size:12px; font-weight:600; color:#111827; margin:0 0 8px;">Soldes</p>
                <table style="width:100%; border-collapse:collapse; font-size:12px; margin-bottom:16px;">
                    <thead>
                        <tr style="background:#F7F8FC;">
                            @foreach(array_merge(['Type de compte', 'Dernier solde (FDJ)', 'Solde au', 'Source'], $incident ? ['Déjà payé PCA', 'Disponible PCA'] : []) as $th)
                                <th style="padding:8px 12px; text-align:left; color:#6b7280; font-weight:500; border-bottom:1px solid #e5e7eb;">{{ $th }}</th>
                            @endforeach
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($accounts as $a)
                            <tr style="border-bottom:1px solid #f3f4f6;">
                                <td style="padding:8px 12px; color:#111827; font-weight:500;">{{ $a->account_type }}</td>
                                <td style="padding:8px 12px; color:#111827; font-weight:700; font-size:14px;">{{ $fdj($a->balance) }}</td>
                                <td style="padding:8px 12px; color:#6b7280;">{{ $a->as_of->format('d/m/Y H:i:s') }}</td>
                                <td style="padding:8px 12px; color:#6b7280;">{{ $a->source === 'huawei' ? 'Fichier Huawei' : 'Transactions' }}</td>
                                @if($incident)
                                    <td style="padding:8px 12px; color:#92400E;">{{ $fdj($a->paid) }}</td>
                                    <td style="padding:8px 12px; color:{{ $a->available > 0 ? '#005C2B' : '#B91C1C' }}; font-weight:700; font-size:14px;">{{ $fdj($a->available) }}</td>
                                @endif
                            </tr>
                        @endforeach
                    </tbody>
                </table>

                {{-- Saisie d'un cash-out --}}
                @if($incident && $canCashout)
                    <div style="border:1px solid #e5e7eb; border-radius:8px; padding:14px; margin-bottom:16px; background:#FAFBFC;">
                        <p style="font-size:12px; font-weight:600; color:#111827; margin:0 0 10px;">Enregistrer un cash-out payé</p>
                        @if(!$myAgency || !$myAgency->is_active)
                            <div style="background:#FDE8E8; border-left:3px solid #B91C1C; border-radius:6px; padding:9px 12px; font-size:12px; color:#7F1D1D; margin-bottom:10px;">
                                {{ $myAgency ? 'Votre agence est désactivée' : 'Votre compte n\'est rattaché à aucune agence' }} : vous ne pouvez pas enregistrer de cash-out.
                                Demandez à l'administrateur de vous rattacher à une agence (Administration → Agences).
                            </div>
                        @endif
                        <div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(190px, 1fr)); gap:10px; margin-bottom:10px;">
                            <div>
                                <label style="{{ $labelStyle }}">Compte débité</label>
                                <select wire:model="coAccount" style="{{ $inputStyle }} width:100%;">
                                    @foreach($accounts as $a)
                                        <option value="{{ $a->account_type }}">{{ $a->account_type }} ({{ $fdj($a->available) }} dispo.)</option>
                                    @endforeach
                                </select>
                            </div>
                            <div>
                                <label style="{{ $labelStyle }}">Montant payé (FDJ)</label>
                                <input type="number" min="1" step="1" wire:model="coAmount" style="{{ $inputStyle }} width:100%;">
                                @error('coAmount') {!! $err($message) !!} @enderror
                            </div>
                            <div>
                                <label style="{{ $labelStyle }}">Agence (déduite de votre compte)</label>
                                <div style="{{ $inputStyle }} width:100%; background:#F3F4F6; color:{{ $myAgency ? '#111827' : '#B91C1C' }}; font-weight:600;">
                                    {{ $myAgency ? $myAgency->label() : 'Aucune agence' }}
                                </div>
                            </div>
                            <div>
                                <label style="{{ $labelStyle }}">Pièce d'identité vérifiée</label>
                                <input type="text" wire:model="coDocument" placeholder="Type et numéro" style="{{ $inputStyle }} width:100%;">
                                @error('coDocument') {!! $err($message) !!} @enderror
                            </div>
                        </div>
                        <div style="display:flex; gap:10px; align-items:flex-end; flex-wrap:wrap;">
                            <div style="flex:1; min-width:220px;">
                                <label style="{{ $labelStyle }}">Notes (facultatif)</label>
                                <input type="text" wire:model="coNotes" style="{{ $inputStyle }} width:100%;">
                            </div>
                            <button wire:click="recordCashout" wire:loading.attr="disabled" wire:target="recordCashout"
                                    wire:confirm="Confirmer le cash-out ? Vérifiez le montant et la pièce d'identité."
                                    style="{{ $btnPrimary }} background:#00843D;">
                                Enregistrer le cash-out
                            </button>
                        </div>
                    </div>
                @elseif(!$incident)
                    <p style="font-size:11px; color:#9ca3af; margin:0 0 16px;">La saisie des cash-out n'est possible que lorsque le mode PCA est actif (onglet Incident).</p>
                @endif

                {{-- Cash-out de ce client pendant l'incident --}}
                @if($incident && $partyCashouts->isNotEmpty())
                    <p style="font-size:12px; font-weight:600; color:#111827; margin:0 0 8px;">Cash-out de ce compte pendant l'incident</p>
                    @include('livewire.pca.partials.cashouts-table', ['cashouts' => $partyCashouts])
                @endif
            </div>
        @endif
    @endif

    {{-- ═════════════════════ INCIDENT ═════════════════════ --}}
    @if($tab === 'incident')
        @if(!$incident)
            <div style="{{ $card }} margin-bottom:16px;">
                <p style="font-size:13px; font-weight:700; color:#111827; margin:0 0 4px;">Aucun incident en cours</p>
                <p style="font-size:12px; color:#6b7280; margin:0 0 14px;">
                    Activez le mode PCA dès que le service D-Money est indisponible. Les agences pourront alors enregistrer chaque cash-out payé,
                    qui sera déduit du solde disponible du client pour éviter tout double paiement.
                </p>
                @if($canManage)
                    <div style="display:flex; gap:10px; align-items:flex-end; flex-wrap:wrap;">
                        <div style="flex:1; min-width:260px;">
                            <label style="{{ $labelStyle }}">Intitulé de l'incident</label>
                            <input type="text" wire:model="incidentTitle" placeholder="Ex. Panne plateforme D-Money du 12/10/2026" style="{{ $inputStyle }} width:100%;">
                            @error('incidentTitle') {!! $err($message) !!} @enderror
                        </div>
                        <button wire:click="startIncident" wire:confirm="Activer le mode PCA maintenant ?" style="{{ $btnPrimary }} background:#B91C1C;">Activer le mode PCA</button>
                    </div>
                @endif
            </div>
        @else
            <div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(180px, 1fr)); gap:12px; margin-bottom:16px;">
                @foreach([
                    ['Cash-out payés', number_format($incidentStats->n, 0, ',', ' ')],
                    ['Montant total payé', $fdj($incidentStats->total) . ' FDJ'],
                    ['Clients servis', number_format($incidentStats->clients, 0, ',', ' ')],
                    ['Début de l\'incident', $incident->started_at->format('d/m/Y H:i')],
                ] as [$label, $value])
                    <div style="{{ $card }} padding:14px; border-top:3px solid #B91C1C;">
                        <p style="font-size:11px; color:#6b7280; margin:0 0 6px;">{{ $label }}</p>
                        <p style="font-size:18px; font-weight:700; color:#111827; margin:0;">{{ $value }}</p>
                    </div>
                @endforeach
            </div>

            @if($byAgency->isNotEmpty())
                <div style="{{ $card }} margin-bottom:16px;">
                    <p style="font-size:13px; font-weight:700; color:#111827; margin:0 0 10px;">Par agence</p>
                    <table style="width:100%; border-collapse:collapse; font-size:12px;">
                        <tbody>
                            @foreach($byAgency as $a)
                                <tr style="border-bottom:1px solid #f3f4f6;">
                                    <td style="padding:7px 0; color:#111827; font-weight:500;">{{ $a->agency }}</td>
                                    <td style="padding:7px; color:#6b7280;">{{ number_format($a->n, 0, ',', ' ') }} cash-out</td>
                                    <td style="padding:7px 0; text-align:right; color:#111827; font-weight:700;">{{ $fdj($a->total) }} FDJ</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif

            <div style="{{ $card }} margin-bottom:16px;">
                <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:12px; gap:10px; flex-wrap:wrap;">
                    <p style="font-size:13px; font-weight:700; color:#111827; margin:0;">Cash-out de l'incident</p>
                    <div style="display:flex; gap:8px; align-items:center;">
                        <select wire:model.live="filterAgency" style="{{ $inputStyle }}">
                            <option value="">Toutes les agences</option>
                            @foreach($byAgency->whereNotNull('agency_id') as $a)
                                <option value="{{ $a->agency_id }}">{{ $a->agency }}</option>
                            @endforeach
                        </select>
                        @if($canManage)
                            <button wire:click="exportIncident({{ $incident->id }})" style="{{ $btnLight }}">⬇ Exporter (Excel)</button>
                        @endif
                    </div>
                </div>
                @if($cashouts->isEmpty())
                    <p style="font-size:12px; color:#9ca3af; margin:0;">Aucun cash-out enregistré pour l'instant.</p>
                @else
                    @include('livewire.pca.partials.cashouts-table', ['cashouts' => $cashouts, 'showParty' => true])
                    <div style="padding-top:10px;">{{ $cashouts->links() }}</div>
                @endif
            </div>

            @if($canManage)
                <div style="{{ $card }} margin-bottom:16px; border-color:#F5B5B5;">
                    <p style="font-size:13px; font-weight:700; color:#111827; margin:0 0 4px;">Fin de l'incident</p>
                    <p style="font-size:12px; color:#6b7280; margin:0 0 10px;">Quand le service D-Money est rétabli : désactivez le mode PCA, puis exportez les cash-out pour les régulariser dans le système.</p>
                    <div style="display:flex; gap:10px; align-items:flex-end; flex-wrap:wrap;">
                        <div style="flex:1; min-width:260px;">
                            <label style="{{ $labelStyle }}">Notes de clôture (facultatif)</label>
                            <input type="text" wire:model="closeNotes" style="{{ $inputStyle }} width:100%;">
                        </div>
                        <button wire:click="closeIncident" wire:confirm="Désactiver le mode PCA ? Plus aucun cash-out ne pourra être enregistré pour cet incident." style="{{ $btnPrimary }}">Désactiver le mode PCA</button>
                    </div>
                </div>
            @endif
        @endif

        @if($history->isNotEmpty())
            <div style="{{ $card }}">
                <p style="font-size:13px; font-weight:700; color:#111827; margin:0 0 10px;">Incidents précédents</p>
                <table style="width:100%; border-collapse:collapse; font-size:12px;">
                    <tbody>
                        @foreach($history as $h)
                            <tr style="border-bottom:1px solid #f3f4f6;">
                                <td style="padding:8px 0; color:#111827; font-weight:500;">{{ $h->title }}</td>
                                <td style="padding:8px; color:#6b7280;">{{ $h->started_at->format('d/m/Y H:i') }} → {{ $h->ended_at->format('d/m/Y H:i') }}</td>
                                <td style="padding:8px; color:#6b7280;">{{ $h->nb_valid }} cash-out · {{ $fdj($h->total_valid) }} FDJ</td>
                                <td style="padding:8px; color:#9ca3af;">{{ $h->opener->name ?? '—' }} / {{ $h->closer->name ?? '—' }}</td>
                                <td style="padding:8px 0; text-align:right;">
                                    @if($canManage)
                                        <button wire:click="exportIncident({{ $h->id }})" style="background:none; border:none; color:#1B2F6E; font-weight:600; font-size:12px; cursor:pointer;">⬇ Excel</button>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    @endif

    {{-- ═════════════════════ FICHIER HUAWEI ═════════════════════ --}}
    @if($tab === 'huawei' && $canManage)
        <div style="{{ $card }} margin-bottom:16px;">
            <p style="font-size:13px; font-weight:700; color:#111827; margin:0 0 4px;">Importer un fichier de soldes Huawei</p>
            <p style="font-size:12px; color:#6b7280; margin:0 0 12px;">
                Pendant un incident, l'équipe Huawei fournit les soldes à jour : ils remplacent les soldes calculés à partir des transactions,
                s'ils sont plus récents. Le fichier (Excel ou CSV) doit avoir une ligne d'en-tête avec une colonne <strong>compte</strong>
                (ou msisdn, shortcode…) et une colonne <strong>solde</strong> (ou balance) ; une colonne <strong>type_compte</strong> est facultative
                (à défaut : compte principal).
            </p>
            <div style="display:flex; gap:12px; flex-wrap:wrap; align-items:flex-end; margin-bottom:10px;">
                <div>
                    <label style="{{ $labelStyle }}">Fichier</label>
                    <input type="file" wire:model="huaweiFile" accept=".xlsx,.xls,.csv" style="font-size:13px;">
                    <div wire:loading wire:target="huaweiFile" style="font-size:11px; color:#1B2F6E;">Envoi du fichier…</div>
                    @error('huaweiFile') {!! $err($message) !!} @enderror
                </div>
                <div>
                    <label style="{{ $labelStyle }}">Soldes valables au</label>
                    <input type="datetime-local" wire:model="huaweiAsOf" style="{{ $inputStyle }}">
                    @error('huaweiAsOf') {!! $err($message) !!} @enderror
                </div>
                <label style="display:flex; align-items:center; gap:6px; font-size:12px; color:#374151; padding-bottom:9px;">
                    <input type="checkbox" wire:model="huaweiDiv100"> Montants divisés par 100 dans le fichier
                </label>
                <button wire:click="analyzeHuawei" wire:loading.attr="disabled" wire:target="analyzeHuawei" style="{{ $btnLight }}">
                    <span wire:loading.remove wire:target="analyzeHuawei">Vérifier le fichier</span>
                    <span wire:loading wire:target="analyzeHuawei">Lecture…</span>
                </button>
            </div>

            @if(!empty($huaweiPreview))
                @if($huaweiPreview['error'])
                    <div style="background:#FDE8E8; border-left:3px solid #E24B4A; border-radius:6px; padding:10px 12px; font-size:12px; color:#7F1D1D;">{{ $huaweiPreview['error'] }}</div>
                @else
                    <div style="background:#F7F8FC; border-radius:8px; padding:12px 14px; margin-top:6px;">
                        <p style="font-size:12px; color:#111827; margin:0 0 8px;">
                            <strong>{{ number_format($huaweiPreview['total'], 0, ',', ' ') }}</strong> ligne(s) ·
                            <strong style="color:#005C2B;">{{ number_format($huaweiPreview['valid'], 0, ',', ' ') }}</strong> valide(s) ·
                            <strong style="color:{{ $huaweiPreview['nbErr'] ? '#B91C1C' : '#6b7280' }};">{{ number_format($huaweiPreview['nbErr'], 0, ',', ' ') }}</strong> en erreur (ignorées)
                        </p>
                        <p style="font-size:11px; color:#6b7280; margin:0 0 6px;">Vérifiez sur l'échantillon que les nouveaux soldes sont du même ordre que les soldes actuels (sinon : problème d'unité, cochez ou décochez « ÷ 100 »).</p>
                        <table style="width:100%; border-collapse:collapse; font-size:12px; margin-bottom:10px;">
                            <thead><tr>
                                @foreach(['Ligne', 'Compte', 'Type de compte', 'Solde actuel', 'Nouveau solde'] as $th)
                                    <th style="padding:5px 8px; text-align:left; color:#6b7280; font-weight:500; border-bottom:1px solid #e5e7eb;">{{ $th }}</th>
                                @endforeach
                            </tr></thead>
                            <tbody>
                                @foreach($huaweiPreview['sample'] as $s)
                                    <tr>
                                        <td style="padding:5px 8px; color:#9ca3af;">{{ $s['line'] }}</td>
                                        <td style="padding:5px 8px; font-family:monospace;">{{ $s['party_id'] }}</td>
                                        <td style="padding:5px 8px; color:#6b7280;">{{ $s['account_type'] }}</td>
                                        <td style="padding:5px 8px; color:#6b7280;">{{ $s['current'] !== null ? $fdj($s['current']) : 'inconnu' }}</td>
                                        <td style="padding:5px 8px; font-weight:600;">{{ $fdj($s['balance']) }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                        @foreach($huaweiPreview['errors'] as $e)
                            <p style="font-size:11px; color:#B91C1C; margin:0;">Ligne {{ $e['line'] }} : {{ $e['error'] }}</p>
                        @endforeach
                        <button wire:click="applyHuawei" wire:loading.attr="disabled" wire:target="applyHuawei"
                                wire:confirm="Appliquer les {{ $huaweiPreview['valid'] }} soldes valides, valables au {{ \Carbon\Carbon::parse($huaweiAsOf)->format('d/m/Y H:i') }} ?"
                                style="{{ $btnPrimary }} margin-top:10px;">
                            <span wire:loading.remove wire:target="applyHuawei">Appliquer les soldes</span>
                            <span wire:loading wire:target="applyHuawei">Application…</span>
                        </button>
                    </div>
                @endif
            @endif
        </div>

        @if($imports->isNotEmpty())
            <div style="{{ $card }}">
                <p style="font-size:13px; font-weight:700; color:#111827; margin:0 0 10px;">Fichiers importés</p>
                <table style="width:100%; border-collapse:collapse; font-size:12px;">
                    <tbody>
                        @foreach($imports as $i)
                            <tr style="border-bottom:1px solid #f3f4f6;">
                                <td style="padding:7px 0; color:#111827;">{{ $i->file_name }}</td>
                                <td style="padding:7px; color:#6b7280;">soldes au {{ \Carbon\Carbon::parse($i->as_of)->format('d/m/Y H:i') }}</td>
                                <td style="padding:7px; color:#6b7280;">{{ number_format($i->rows_applied, 0, ',', ' ') }} appliqué(s) / {{ number_format($i->rows_total, 0, ',', ' ') }}</td>
                                <td style="padding:7px; color:#9ca3af;">{{ $i->user_name ?? '—' }}, le {{ \Carbon\Carbon::parse($i->created_at)->format('d/m/Y H:i') }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    @endif

    {{-- ═════════════════════ EMAIL ═════════════════════ --}}
    @if($tab === 'email' && $canManage)
        @if($mailer === 'log')
            <div style="background:#FFF7E0; border-left:3px solid #F5A800; border-radius:8px; padding:10px 14px; font-size:12px; color:#7A4F00; margin-bottom:14px;">
                L'envoi d'emails n'est pas configuré sur ce serveur (MAIL_MAILER=log) : les emails sont écrits dans les logs au lieu d'être envoyés.
            </div>
        @endif

        <div style="{{ $card }} margin-bottom:16px;">
            <p style="font-size:13px; font-weight:700; color:#111827; margin:0 0 4px;">Envoi des soldes par email</p>
            <p style="font-size:12px; color:#6b7280; margin:0 0 14px;">
                Une copie des derniers soldes est envoyée aux destinataires choisis, pour rester disponible même si CDRAPP est inaccessible.
                Le fichier est un <strong>ZIP chiffré (AES-256)</strong> : communiquez le mot de passe aux destinataires par un autre moyen (jamais par email).
            </p>

            <div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(260px, 1fr)); gap:14px; margin-bottom:12px;">
                <div>
                    <label style="{{ $labelStyle }}">Destinataires (séparés par des virgules ou des retours à la ligne)</label>
                    <textarea wire:model="emailRecipients" rows="4" placeholder="conformite@d-money.dj, …" style="{{ $inputStyle }} width:100%; resize:vertical;"></textarea>
                    @error('emailRecipients') {!! $err($message) !!} @enderror
                </div>
                <div>
                    <label style="{{ $labelStyle }}">Mot de passe du ZIP {{ $passwordSet ? '(défini — laisser vide pour le conserver)' : '(non défini)' }}</label>
                    <input type="password" wire:model="zipPassword" autocomplete="new-password" placeholder="10 caractères minimum" style="{{ $inputStyle }} width:100%;">
                    @error('zipPassword') {!! $err($message) !!} @enderror

                    <label style="display:flex; align-items:center; gap:8px; font-size:12px; color:#374151; margin-top:12px;">
                        <input type="checkbox" wire:model="emailDaily"> Envoi automatique chaque jour (après la mise à jour des soldes)
                    </label>
                    <label style="display:flex; align-items:center; gap:8px; font-size:12px; color:#374151; margin-top:6px;">
                        <input type="checkbox" wire:model="emailNonZero"> Seulement les comptes avec un solde non nul (fichier plus léger)
                    </label>
                </div>
            </div>

            <div style="display:flex; gap:8px; flex-wrap:wrap;">
                <button wire:click="saveEmailSettings" style="{{ $btnPrimary }}">Enregistrer</button>
                <button wire:click="sendNow" wire:confirm="Envoyer maintenant les soldes aux destinataires enregistrés ?" style="{{ $btnLight }}">Envoyer maintenant</button>
            </div>
        </div>

        <div style="{{ $card }}">
            <p style="font-size:13px; font-weight:700; color:#111827; margin:0 0 6px;">Dernier envoi</p>
            <p style="font-size:12px; color:{{ str_starts_with((string) $lastEmail, 'ÉCHEC') ? '#B91C1C' : '#374151' }}; margin:0;">
                @if($lastEmailAt) {{ \Carbon\Carbon::parse($lastEmailAt)->format('d/m/Y H:i') }} — {{ $lastEmail }} @else Aucun envoi pour l'instant. @endif
            </p>
        </div>
    @endif

</div>
