<?php

use Livewire\Volt\Component;
use App\Models\CdrappTansaction;
use Livewire\WithPagination;

new class extends Component {
        
    use WithPagination;

    public string $transactionType       = '';
    public string $transactionState      = '';
    public string $transactionMediumCode = '';
    public string $debitedMSISDN         = '';
    public string $debitedProfileCode    = '';
    public string $creditedMSISDN        = '';
    public string $creditedProfileCode   = '';
    public string $date_debut            = '';
    public string $date_fin              = '';
    public bool   $searched              = false;

    public function search(): void
    {
        $this->resetPage();
        $this->searched = true;
    }

    public function resetFilters(): void
    {
        $this->transactionType       = '';
        $this->transactionState      = '';
        $this->transactionMediumCode = '';
        $this->debitedMSISDN         = '';
        $this->debitedProfileCode    = '';
        $this->creditedMSISDN        = '';
        $this->creditedProfileCode   = '';
        $this->date_debut            = '';
        $this->date_fin              = '';
        $this->searched              = false;
        $this->resetPage();
    }

    private function buildQuery()
    {
        $query = CdrappTansaction::query();

        if ($this->debitedMSISDN) {
            $query->where('debitedMSISDN', 'like', '%'.$this->debitedMSISDN.'%');
        }
        if ($this->creditedMSISDN) {
            $query->where('creditedMSISDN', 'like', '%'.$this->creditedMSISDN.'%');
        }
        if ($this->debitedProfileCode) {
            $query->where('debitedProfileCode', $this->debitedProfileCode);
        }
        if ($this->creditedProfileCode) {
            $query->where('creditedProfileCode', $this->creditedProfileCode);
        }
        if ($this->transactionType) {
            $query->where('transactionType', $this->transactionType);
        }
        if ($this->transactionState) {
            $query->where('transactionState', $this->transactionState);
        }
        if ($this->transactionMediumCode) {
            $query->where('transactionMediumCode', $this->transactionMediumCode);
        }
        if ($this->date_debut) {
            $query->whereDate('transactionCreationDate', '>=', $this->date_debut);
        }
        if ($this->date_fin) {
            $query->whereDate('transactionCreationDate', '<=', $this->date_fin);
        }

        return $query;
    }

    public function exportExcel()
    {
        return \Maatwebsite\Excel\Facades\Excel::download(
            new \App\Exports\CdrappTransactionsExport($this->buildQuery()->get()),
            'cdrapp_transactions_' . now()->format('Ymd_His') . '.xlsx'
        );
    }

    public function exportCsv()
    {
        set_time_limit(300);
        ini_set('memory_limit', '512M');

        $filename = 'cdrapp_transactions_' . now()->format('Ymd_His') . '.csv';

        $conditions = [];
        $bindings   = [];

        if ($this->debitedMSISDN)      { $conditions[] = "debitedMSISDN LIKE ?";      $bindings[] = '%'.$this->debitedMSISDN.'%'; }
        if ($this->creditedMSISDN)     { $conditions[] = "creditedMSISDN LIKE ?";     $bindings[] = '%'.$this->creditedMSISDN.'%'; }
        if ($this->debitedProfileCode) { $conditions[] = "debitedProfileCode = ?";    $bindings[] = $this->debitedProfileCode; }
        if ($this->creditedProfileCode){ $conditions[] = "creditedProfileCode = ?";   $bindings[] = $this->creditedProfileCode; }
        if ($this->transactionType)    { $conditions[] = "transactionType = ?";        $bindings[] = $this->transactionType; }
        if ($this->transactionState)   { $conditions[] = "transactionState = ?";       $bindings[] = $this->transactionState; }
        if ($this->transactionMediumCode){ $conditions[] = "transactionMediumCode = ?"; $bindings[] = $this->transactionMediumCode; }
        if ($this->date_debut)         { $conditions[] = "DATE(transactionCreationDate) >= ?"; $bindings[] = $this->date_debut; }
        if ($this->date_fin)           { $conditions[] = "DATE(transactionCreationDate) <= ?"; $bindings[] = $this->date_fin; }

        $where = !empty($conditions) ? 'WHERE '.implode(' AND ', $conditions) : '';

        $sql = "
            SELECT
                transactionCreationDate,
                transactionId,
                transactionState,
                detailedStatus,
                transactionType,
                transactionMediumCode,
                debitedMSISDN,
                debitedProfileCode,
                creditedMSISDN,
                creditedProfileCode,
                deliveredSubscriber,
                transactionAmount,
                transactionFeeAmount,
                transactionCommisionAmount,
                transactionTaxAmount
            FROM transactions_partitionned
            {$where}
            ORDER BY transactionCreationDate DESC
        ";

        return response()->streamDownload(function () use ($sql, $bindings) {
            if (ob_get_level()) ob_end_clean();
            $handle = fopen('php://output', 'w');
            fprintf($handle, chr(0xEF).chr(0xBB).chr(0xBF));
            fputcsv($handle, [
                '#', 'Date', 'TXN ID', 'Statut', 'Détail statut', 'Type', 'Canal',
                'Debit MSISDN', 'Debit Profile', 'Credit MSISDN', 'Credit Profile',
                'Delivered', 'Montant', 'Frais', 'Commission', 'Taxe'
            ], ';');

            $pdo  = \DB::connection()->getPdo();
            $stmt = $pdo->prepare($sql);
            $stmt->execute($bindings);

            $index = 1;
            while ($row = $stmt->fetch(\PDO::FETCH_ASSOC)) {
                fputcsv($handle, [
                    $index++,
                    $row['transactionCreationDate'],
                    $row['transactionId'],
                    $row['transactionState'],
                    $row['detailedStatus'],
                    $row['transactionType'],
                    $row['transactionMediumCode'],
                    $row['debitedMSISDN'],
                    $row['debitedProfileCode'],
                    $row['creditedMSISDN'],
                    $row['creditedProfileCode'],
                    $row['deliveredSubscriber'],
                    $row['transactionAmount'],
                    $row['transactionFeeAmount'],
                    $row['transactionCommisionAmount'],
                    $row['transactionTaxAmount'],
                ], ';');
                if ($index % 5000 === 0) flush();
            }
            fclose($handle);
        }, $filename, [
            'Content-Type'        => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="'.$filename.'"',
            'X-Accel-Buffering'   => 'no',
        ]);
    }

    public function with(): array
    {
        // Types distincts depuis la table
        $types = CdrappTansaction::select('transactionType')
            ->distinct()
            ->orderBy('transactionType')
            ->pluck('transactionType');

        if (!$this->searched) {
            return [
                'transactions' => null,
                'types'        => $types,
            ];
        }

        return [
            'transactions' => $this->buildQuery()
                ->orderByDesc('transactionCreationDate')
                ->paginate(100),
            'types' => $types,
        ];
    }
};
?>
<div>


<div style="padding:24px;">

    {{-- FILTRES --}}
    <div style="background:#fff; border:1px solid #e5e7eb; border-radius:10px; padding:20px; margin-bottom:20px;">

        <p style="font-size:13px; font-weight:600; color:#111827; margin-bottom:14px;">
            Ancien CDRAPP — Filtres de recherche
        </p>

        <div style="display:grid; grid-template-columns:repeat(3, 1fr); gap:12px; margin-bottom:12px;">

            <div>
                <label style="font-size:11px; color:#6b7280; display:block; margin-bottom:4px;">Date début</label>
                <input type="date" wire:model="date_debut"
                       style="width:100%; border:1px solid #d1d5db; border-radius:7px; padding:8px 10px; font-size:13px; color:#111827; outline:none;">
            </div>

            <div>
                <label style="font-size:11px; color:#6b7280; display:block; margin-bottom:4px;">Date fin</label>
                <input type="date" wire:model="date_fin"
                       style="width:100%; border:1px solid #d1d5db; border-radius:7px; padding:8px 10px; font-size:13px; color:#111827; outline:none;">
            </div>

            <div>
                <label style="font-size:11px; color:#6b7280; display:block; margin-bottom:4px;">Debit MSISDN</label>
                <input type="text" wire:model="debitedMSISDN"
                       placeholder="Ex: 25377000000"
                       style="width:100%; border:1px solid #d1d5db; border-radius:7px; padding:8px 10px; font-size:13px; color:#111827; outline:none;">
            </div>

            <div>
                <label style="font-size:11px; color:#6b7280; display:block; margin-bottom:4px;">Credit MSISDN</label>
                <input type="text" wire:model="creditedMSISDN"
                       placeholder="Ex: 25377000000"
                       style="width:100%; border:1px solid #d1d5db; border-radius:7px; padding:8px 10px; font-size:13px; color:#111827; outline:none;">
            </div>

            <div>
                <label style="font-size:11px; color:#6b7280; display:block; margin-bottom:4px;">Debit Profile</label>
                <select wire:model="debitedProfileCode"
                        style="width:100%; border:1px solid #d1d5db; border-radius:7px; padding:8px 10px; font-size:13px; color:#111827; outline:none; background:#fff;">
                    <option value="">Tous les profils</option>
                    @foreach(['ASERI_MCH_GROUPE100','BATCH_PROFILE','BLC','BLCDT','BLC_EDD','BSM','BSMCACBANQUE','BSMCLONE','BSM_AGENT_AGREE','BSM_EAB','CAC_CAISSE_ENGEUILLA','CAC_MANAGER_ENGEUILLA','CAISSEBAWADIMALL','CAISSEBRICODISCOUNT','CAISSECOUBECHECASINO','CAISSECOUBECHEGEANT','CAISSEEAB','CAISSEGXA','CAISSEVICTORHUGO','CAISSE_ALGAMIL','CAISSE_AL_AWLA','CAISSE_BOA_BAWADI_MALL','CAISSE_BOA_CENTRALE','CAISSE_DISCORAMA','CAISSE_EAB_AVENUE_13','CAISSE_EAB_CHEICK_MOUSSA','CAISSE_EAB_HAYABLEH','CAISSE_EAB_RIYAD','CAISSE_EXIM_1','CAISSE_GROS_COMPTE','CAISSE_MOONLIGHT','CAISSE_NOUGAPRIX','CAISSE_SABA_CENTRALE','CAISSE_VICTOR_HUGO','CAISSIER_PRINCIPAL','CCST','CCSTUNICEF','CCST_3POUR100','CCST_DT','CCST_NATIONS_UNIS','CC_ADMIN','COM123','COMCCSTDT','COMMISSIONSDAHABSHIIL','COMPTADT','COMPTE_FINANCE','COMPTE_INTERCO','COM_COM','DAHABSHIILBSM','DAHABSHIILCAISSE','DAHABSHIILMANAGER','DODAI_CAISSE','EAB_CAISSE_ALISAHIEH','EAB_CAISSE_PK_12','EAB_CAISSE_TADJOURAH','EMONEY_INCENTIVE_MANAGER','ENREGISTREUR','ENREGISTREUREAB','FAD','IRMAN_CAISSE','MANAGER','MANAGERBAWADIMALL','MANAGERBRICODISCOUNT','MANAGERCOUBECHECASINO','MANAGERCOUBECHEGEANT','MANAGERSDKIKIDROP','MANAGERVICTORHUGO','MANAGER_ALGAMIL','MANAGER_DISCORAMA','MANAGER_EAB_CENTRE_VILLE','MANAGER_EAB_CHEICK_MOUSSA','MANAGER_EAB_RIYAD','MANAGER_MOONLIGHT','MANAGER_NOUGAPRIX','MANAGER_VICTOR_HUGO','MAN_AGENT_AGREE','MAN_GROS_COMPTE','MASSBSM','MCH','MCH1','MCHT','MCH_LIGHT','MCH_MASS_ISSS','MCH_MENFOP','MCH_RUBIS','MCH_SANS_PROMO','MJC_CCST_POSTPAID','MT1','MT1_RUBIS','MT1_SANS_PROMO','NR','OPGW2POURCENT','OPGW_0_POURCENT','PCA','PCCA','PCCAPROMO','PETITE_CAISSE','PORTEFEUILLE_EAB','PROMO_THEATRE','RCT','RCTCORPORATE','RCTCORPORATEPOSTPAID','RCTCORPORATEPREPAID','RDS','RDSMARCHAND','RDST','RDS_2','RDS_ASERI','RDS_ASERI_GROUPE_1','RDS_DT','RDS_PROMO','RECRUTEUR_INTERNE','RSM','SD1','SD2','SDBAJAJ','SDPROMO','SD_KIKIDROP','SD_MOONLIGHT','SD_VICTOR_HUGO','SMCH','SOLIDARITE','SUPERVISEUR','SUPERVISEURBRICODISCOUNT','SUPERVISEURCOUBECHE','SUPERVISEURGXA','SUPERVISEUR_1_POURCENT','SUPERVISEUR_ALGAMIL','SUPER_SD','UD_RECEIVER'] as $p)
                        <option value="{{ trim($p) }}">{{ trim($p) }}</option>
                    @endforeach
                </select>
            </div>

            <div>
                <label style="font-size:11px; color:#6b7280; display:block; margin-bottom:4px;">Credit Profile</label>
                <select wire:model="creditedProfileCode"
                        style="width:100%; border:1px solid #d1d5db; border-radius:7px; padding:8px 10px; font-size:13px; color:#111827; outline:none; background:#fff;">
                    <option value="">Tous les profils</option>
                    @foreach(['ASERI_MCH_GROUPE100','BATCH_PROFILE','BLC','BLCDT','BLC_EDD','BSM','BSMCACBANQUE','BSMCLONE','BSM_AGENT_AGREE','BSM_EAB','CAC_CAISSE_ENGEUILLA','CAC_MANAGER_ENGEUILLA','CAISSEBAWADIMALL','CAISSEBRICODISCOUNT','CAISSECOUBECHECASINO','CAISSECOUBECHEGEANT','CAISSEEAB','CAISSEGXA','CAISSEVICTORHUGO','CAISSE_ALGAMIL','CAISSE_AL_AWLA','CAISSE_BOA_BAWADI_MALL','CAISSE_BOA_CENTRALE','CAISSE_DISCORAMA','CAISSE_EAB_AVENUE_13','CAISSE_EAB_CHEICK_MOUSSA','CAISSE_EAB_HAYABLEH','CAISSE_EAB_RIYAD','CAISSE_EXIM_1','CAISSE_GROS_COMPTE','CAISSE_MOONLIGHT','CAISSE_NOUGAPRIX','CAISSE_SABA_CENTRALE','CAISSE_VICTOR_HUGO','CAISSIER_PRINCIPAL','CCST','CCSTUNICEF','CCST_3POUR100','CCST_DT','CCST_NATIONS_UNIS','CC_ADMIN','COM123','COMCCSTDT','COMMISSIONSDAHABSHIIL','COMPTADT','COMPTE_FINANCE','COMPTE_INTERCO','COM_COM','DAHABSHIILBSM','DAHABSHIILCAISSE','DAHABSHIILMANAGER','DODAI_CAISSE','EAB_CAISSE_ALISAHIEH','EAB_CAISSE_PK_12','EAB_CAISSE_TADJOURAH','EMONEY_INCENTIVE_MANAGER','ENREGISTREUR','ENREGISTREUREAB','FAD','IRMAN_CAISSE','MANAGER','MANAGERBAWADIMALL','MANAGERBRICODISCOUNT','MANAGERCOUBECHECASINO','MANAGERCOUBECHEGEANT','MANAGERSDKIKIDROP','MANAGERVICTORHUGO','MANAGER_ALGAMIL','MANAGER_DISCORAMA','MANAGER_EAB_CENTRE_VILLE','MANAGER_EAB_CHEICK_MOUSSA','MANAGER_EAB_RIYAD','MANAGER_MOONLIGHT','MANAGER_NOUGAPRIX','MANAGER_VICTOR_HUGO','MAN_AGENT_AGREE','MAN_GROS_COMPTE','MASSBSM','MCH','MCH1','MCHT','MCH_LIGHT','MCH_MASS_ISSS','MCH_MENFOP','MCH_RUBIS','MCH_SANS_PROMO','MJC_CCST_POSTPAID','MT1','MT1_RUBIS','MT1_SANS_PROMO','NR','OPGW2POURCENT','OPGW_0_POURCENT','PCA','PCCA','PCCAPROMO','PETITE_CAISSE','PORTEFEUILLE_EAB','PROMO_THEATRE','RCT','RCTCORPORATE','RCTCORPORATEPOSTPAID','RCTCORPORATEPREPAID','RDS','RDSMARCHAND','RDST','RDS_2','RDS_ASERI','RDS_ASERI_GROUPE_1','RDS_DT','RDS_PROMO','RECRUTEUR_INTERNE','RSM','SD1','SD2','SDBAJAJ','SDPROMO','SD_KIKIDROP','SD_MOONLIGHT','SD_VICTOR_HUGO','SMCH','SOLIDARITE','SUPERVISEUR','SUPERVISEURBRICODISCOUNT','SUPERVISEURCOUBECHE','SUPERVISEURGXA','SUPERVISEUR_1_POURCENT','SUPERVISEUR_ALGAMIL','SUPER_SD','UD_RECEIVER'] as $p)
                        <option value="{{ trim($p) }}">{{ trim($p) }}</option>
                    @endforeach
                </select>
            </div>

            <div>
                <label style="font-size:11px; color:#6b7280; display:block; margin-bottom:4px;">Canal</label>
                <select wire:model="transactionMediumCode"
                        style="width:100%; border:1px solid #d1d5db; border-radius:7px; padding:8px 10px; font-size:13px; color:#111827; outline:none; background:#fff;">
                    <option value="">Tous les canaux</option>
                    <option value="USSD">USSD</option>
                    <option value="MOBILE_APP">MOBILE_APP</option>
                    <option value="SYSTEM">SYSTEM</option>
                    <option value="API">API</option>
                    <option value="WEB">WEB</option>
                </select>
            </div>

            <div>
                <label style="font-size:11px; color:#6b7280; display:block; margin-bottom:4px;">Type de transaction</label>
                <select wire:model="transactionType"
                        style="width:100%; border:1px solid #d1d5db; border-radius:7px; padding:8px 10px; font-size:13px; color:#111827; outline:none; background:#fff;">
                    <option value="">Tous les types</option>
                    @foreach($types as $type)
                        <option value="{{ $type }}">{{ $type }}</option>
                    @endforeach
                </select>
            </div>

            <div>
                <label style="font-size:11px; color:#6b7280; display:block; margin-bottom:4px;">Statut</label>
                <select wire:model="transactionState"
                        style="width:100%; border:1px solid #d1d5db; border-radius:7px; padding:8px 10px; font-size:13px; color:#111827; outline:none; background:#fff;">
                    <option value="">Tous les statuts</option>
                    <option value="SUCCESS">SUCCESS</option>
                    <option value="FAILED">FAILED</option>
                    
                </select>
            </div>

        </div>

        {{-- Boutons --}}
        <div style="display:flex; align-items:center; gap:10px; margin-top:4px;">
            
            <button wire:click="search"
                    wire:loading.attr="disabled"
                    wire:target="search"
                    style="background:#00843D; color:#fff; font-size:13px; font-weight:600; padding:9px 22px; border-radius:8px; border:none; cursor:pointer; display:flex; align-items:center; gap:7px;">

                {{-- Icône search — visible hors chargement --}}
                <span wire:loading.remove wire:target="search" style="display:flex; align-items:center; gap:7px;">
                    <svg width="14" height="14" viewBox="0 0 16 16" fill="white">
                        <circle cx="6.5" cy="6.5" r="4.5" stroke="white" stroke-width="1.5" fill="none"/>
                        <path d="M10.5 10.5L14 14" stroke="white" stroke-width="1.5" stroke-linecap="round"/>
                    </svg>
                    Rechercher
                </span>

                {{-- Spinner — visible uniquement pendant le chargement --}}
                <span wire:loading.inline-flex wire:target="search" style="align-items:center; gap:7px;">
                    <svg width="14" height="14" viewBox="0 0 40 40" fill="none" style="animation:spin 0.8s linear infinite;">
                        <circle cx="20" cy="20" r="16" stroke="rgba(255,255,255,0.3)" stroke-width="4"/>
                        <path d="M20 4a16 16 0 0116 16" stroke="white" stroke-width="4" stroke-linecap="round"/>
                    </svg>
                    Chargement...
                </span>

            </button>

            @if($searched)
                <button wire:click="resetFilters"
                        style="background:#f3f4f6; color:#374151; font-size:13px; font-weight:500; padding:9px 18px; border-radius:8px; border:1px solid #e5e7eb; cursor:pointer;">
                    Réinitialiser
                </button>
            @endif

            @if($searched && $transactions && $transactions->total() > 0)
                <button onclick="lancerExport({{ $transactions->total() }})"
                        style="background:#fff; color:#1B2F6E; font-size:13px; font-weight:600; padding:9px 18px; border-radius:8px; border:1.5px solid #1B2F6E; cursor:pointer; display:flex; align-items:center; gap:7px;">
                    <svg width="14" height="14" viewBox="0 0 16 16" fill="#1B2F6E">
                        <path d="M2 3h9l3 3v8a1 1 0 01-1 1H2a1 1 0 01-1-1V4a1 1 0 011-1z"/>
                        <path d="M9 3v4h4" stroke="#1B2F6E" stroke-width="1" fill="none"/>
                        <path d="M8 8v5M5 11l3 2 3-2" stroke="#1B2F6E" stroke-width="1.2" fill="none" stroke-linecap="round"/>
                    </svg>
                    Exporter
                </button>
            @endif
        </div>
    </div>

    {{-- ÉTAT INITIAL --}}
    @if(!$searched)
        <div style="text-align:center; padding:60px 20px; background:#fff; border:1px solid #e5e7eb; border-radius:10px;">
            <div style="width:52px; height:52px; background:#E8ECF8; border-radius:14px; display:flex; align-items:center; justify-content:center; margin:0 auto 14px;">
                <svg width="26" height="26" viewBox="0 0 24 24" fill="none">
                    <circle cx="10" cy="10" r="7" stroke="#1B2F6E" stroke-width="1.5"/>
                    <path d="M15.5 15.5L20 20" stroke="#1B2F6E" stroke-width="1.5" stroke-linecap="round"/>
                </svg>
            </div>
            <p style="font-size:14px; font-weight:600; color:#111827; margin-bottom:4px;">Lancez une recherche</p>
            <p style="font-size:12px; color:#9ca3af;">Renseignez au moins un critère puis cliquez sur <strong>Rechercher</strong>.</p>
        </div>

    @else
        <div style="background:#fff; border:1px solid #e5e7eb; border-radius:10px; overflow:hidden;">

            <div style="padding:12px 16px; border-bottom:1px solid #e5e7eb; display:flex; align-items:center; justify-content:space-between;">
                <p style="font-size:13px; font-weight:600; color:#111827; margin:0;">Résultats</p>
                <span style="background:#E8ECF8; color:#1B2F6E; font-size:11px; font-weight:600; padding:3px 10px; border-radius:20px;">
                    {{ $transactions->total() }} transaction(s)
                </span>
            </div>

            <div style="overflow-x:auto; overflow-y:auto; max-height:600px;">
                <table style="width:100%; border-collapse:collapse; font-size:12px;">
                    <thead>
                        <tr style="background:#F7F8FC;">
                            <th style="padding:10px 12px; text-align:left; color:#6b7280; font-weight:500; border-bottom:1px solid #e5e7eb; white-space:nowrap; position:sticky; top:0; background:#F7F8FC; z-index:1;">#</th>
                            <th style="padding:10px 12px; text-align:left; color:#6b7280; font-weight:500; border-bottom:1px solid #e5e7eb; white-space:nowrap; position:sticky; top:0; background:#F7F8FC; z-index:1;">Date</th>
                            <th style="padding:10px 12px; text-align:left; color:#6b7280; font-weight:500; border-bottom:1px solid #e5e7eb; white-space:nowrap; position:sticky; top:0; background:#F7F8FC; z-index:1;">TXN ID</th>
                            <th style="padding:10px 12px; text-align:left; color:#6b7280; font-weight:500; border-bottom:1px solid #e5e7eb; white-space:nowrap; position:sticky; top:0; background:#F7F8FC; z-index:1;">Statut</th>
                            <th style="padding:10px 12px; text-align:left; color:#6b7280; font-weight:500; border-bottom:1px solid #e5e7eb; white-space:nowrap; position:sticky; top:0; background:#F7F8FC; z-index:1;">Détail</th>
                            <th style="padding:10px 12px; text-align:left; color:#6b7280; font-weight:500; border-bottom:1px solid #e5e7eb; white-space:nowrap; position:sticky; top:0; background:#F7F8FC; z-index:1;">Type</th>
                            <th style="padding:10px 12px; text-align:left; color:#6b7280; font-weight:500; border-bottom:1px solid #e5e7eb; white-space:nowrap; position:sticky; top:0; background:#F7F8FC; z-index:1;">Canal</th>
                            <th style="padding:10px 12px; text-align:left; color:#6b7280; font-weight:500; border-bottom:1px solid #e5e7eb; white-space:nowrap; position:sticky; top:0; background:#F7F8FC; z-index:1;">Debit MSISDN</th>
                            <th style="padding:10px 12px; text-align:left; color:#6b7280; font-weight:500; border-bottom:1px solid #e5e7eb; white-space:nowrap; position:sticky; top:0; background:#F7F8FC; z-index:1;">Debit Profile</th>
                            <th style="padding:10px 12px; text-align:left; color:#6b7280; font-weight:500; border-bottom:1px solid #e5e7eb; white-space:nowrap; position:sticky; top:0; background:#F7F8FC; z-index:1;">Credit MSISDN</th>
                            <th style="padding:10px 12px; text-align:left; color:#6b7280; font-weight:500; border-bottom:1px solid #e5e7eb; white-space:nowrap; position:sticky; top:0; background:#F7F8FC; z-index:1;">Credit Profile</th>
                            <th style="padding:10px 12px; text-align:right; color:#6b7280; font-weight:500; border-bottom:1px solid #e5e7eb; white-space:nowrap; position:sticky; top:0; background:#F7F8FC; z-index:1;">Montant</th>
                            <th style="padding:10px 12px; text-align:right; color:#6b7280; font-weight:500; border-bottom:1px solid #e5e7eb; white-space:nowrap; position:sticky; top:0; background:#F7F8FC; z-index:1;">Frais</th>
                            <th style="padding:10px 12px; text-align:right; color:#6b7280; font-weight:500; border-bottom:1px solid #e5e7eb; white-space:nowrap; position:sticky; top:0; background:#F7F8FC; z-index:1;">Comm</th>
                            <th style="padding:10px 12px; text-align:right; color:#6b7280; font-weight:500; border-bottom:1px solid #e5e7eb; white-space:nowrap; position:sticky; top:0; background:#F7F8FC; z-index:1;">Taxe</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($transactions as $t)
                            <tr style="border-bottom:1px solid #f3f4f6;"
                                onmouseover="this.style.background='#F7F8FC'"
                                onmouseout="this.style.background='transparent'">

                                <td style="padding:9px 12px; color:#9ca3af;">{{ $transactions->firstItem() + $loop->index }}</td>

                                <td style="padding:9px 12px; color:#6b7280; white-space:nowrap;">
                                    {{ \Carbon\Carbon::parse($t->transactionCreationDate)->format('d/m/Y H:i') }}
                                </td>

                                <td style="padding:9px 12px; font-family:monospace; font-size:11px; color:#374151;">
                                    {{ $t->transactionId }}
                                </td>

                                <td style="padding:9px 12px;">
                                    @if($t->transactionState === 'SUCCESS')
                                        <span style="background:#E5F5ED; color:#005C2B; font-size:10px; font-weight:600; padding:2px 8px; border-radius:12px;">SUCCESS</span>
                                    @elseif($t->transactionState === 'FAILED')
                                        <span style="background:#FDECEA; color:#7F1D1D; font-size:10px; font-weight:600; padding:2px 8px; border-radius:12px;">FAILED</span>
                                    @elseif($t->transactionState === 'PENDING')
                                        <span style="background:#FFF3D0; color:#7A4F00; font-size:10px; font-weight:600; padding:2px 8px; border-radius:12px;">PENDING</span>
                                    @else
                                        <span style="background:#f3f4f6; color:#374151; font-size:10px; font-weight:600; padding:2px 8px; border-radius:12px;">{{ $t->transactionState }}</span>
                                    @endif
                                </td>

                                <td style="padding:9px 12px; color:#6b7280; font-size:11px;">
                                    {{ $t->detailedStatus ?? '—' }}
                                </td>

                                <td style="padding:9px 12px;">
                                    <span style="background:#E8ECF8; color:#1B2F6E; font-size:10px; font-weight:600; padding:2px 8px; border-radius:12px; white-space:nowrap;">
                                        {{ $t->transactionType }}
                                    </span>
                                </td>

                                <td style="padding:9px 12px;">
                                    <span style="background:#f3f4f6; color:#374151; font-size:10px; font-weight:600; padding:2px 8px; border-radius:12px;">
                                        {{ $t->transactionMediumCode }}
                                    </span>
                                </td>

                                <td style="padding:9px 12px; color:#374151;">{{ $t->debitedMSISDN }}</td>
                                <td style="padding:9px 12px; color:#6b7280; font-size:11px;">{{ $t->debitedProfileCode }}</td>
                                <td style="padding:9px 12px; color:#374151;">
                                    {{ $t->creditedMSISDN }} {{$t->deliveredSubscriber}}
                                </td>
                                <td style="padding:9px 12px; color:#6b7280; font-size:11px;">{{ $t->creditedProfileCode }}</td>

                                <td style="padding:9px 12px; text-align:right; font-weight:600; color:#111827;">
                                    {{ number_format($t->transactionAmount/100000, 0, ',', ' ') }}
                                </td>
                                <td style="padding:9px 12px; text-align:right; color:#374151;">
                                    {{ $t->transactionFeeAmount/100000 ?? '—' }}
                                </td>
                                <td style="padding:9px 12px; text-align:right; color:#374151;">
                                    {{ $t->transactionCommisionAmount/100000 ?? '—' }}
                                </td>
                                <td style="padding:9px 12px; text-align:right; color:#374151;">
                                    {{ $t->transactionTaxAmount/100000 ?? '—' }}
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="15" style="padding:40px; text-align:center; color:#9ca3af; font-size:13px;">
                                    Aucune transaction trouvée pour ces critères.
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            @if($transactions->hasPages())
                <div style="padding:12px 16px; border-top:1px solid #e5e7eb;">
                    {{ $transactions->links() }}
                </div>
            @endif
        </div>
    @endif

    <script>
        const EXCEL_LIMIT = 100000;

        function lancerExport(total) {
            if (total <= EXCEL_LIMIT) {
                Swal.fire({
                    title: 'Export Excel',
                    html: `<span style="font-size:13px;color:#6b7280;"><strong style="color:#111827;">${total.toLocaleString('fr-FR')}</strong> lignes seront exportées.</span>`,
                    icon: 'question',
                    showCancelButton: true,
                    confirmButtonText: 'Exporter Excel',
                    cancelButtonText: 'Annuler',
                    confirmButtonColor: '#1B2F6E',
                    cancelButtonColor: '#6b7280',
                    reverseButtons: true,
                }).then(r => { if (r.isConfirmed) lancerTelechargement('excel'); });
            } else {
                Swal.fire({
                    title: 'Fichier trop volumineux pour Excel',
                    html: `<div style="font-size:13px;color:#6b7280;line-height:1.6;"><strong style="color:#111827;">${total.toLocaleString('fr-FR')}</strong> lignes détectées.<br>Excel est limité à <strong>100 000 lignes</strong>.<br><br>Voulez-vous exporter en <strong style="color:#1B2F6E;">CSV</strong> ?</div>`,
                    icon: 'warning',
                    showCancelButton: true,
                    confirmButtonText: 'Exporter CSV',
                    cancelButtonText: 'Annuler',
                    confirmButtonColor: '#1B2F6E',
                    cancelButtonColor: '#6b7280',
                    reverseButtons: true,
                }).then(r => { if (r.isConfirmed) lancerTelechargement('csv'); });
            }
        }

        function lancerTelechargement(format) {
            Swal.fire({
                title: 'Génération en cours...',
                text: `Préparation du fichier ${format.toUpperCase()}.`,
                allowOutsideClick: false,
                showConfirmButton: false,
                didOpen: () => {
                    Swal.showLoading();
                    const method = format === 'excel' ? 'exportExcel' : 'exportCsv';
                    const component = Livewire.find(document.querySelector('[wire\\:id]').getAttribute('wire:id'));
                    component.call(method)
                        .then(() => Swal.fire({ title: 'Téléchargement lancé !', icon: 'success', confirmButtonColor: '#1B2F6E', timer: 2500, timerProgressBar: true }))
                        .catch(() => Swal.fire({ title: 'Erreur', text: "Une erreur est survenue.", icon: 'error', confirmButtonColor: '#E24B4A' }));
                }
            });
        }
    </script>

    <style>
        @keyframes spin { from { transform:rotate(0deg); } to { transform:rotate(360deg); } }
    </style>
</div>
</div>