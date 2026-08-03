<?php

use Livewire\Volt\Component;
use App\Models\Transaction;
use Livewire\WithPagination;

new class extends Component {

    use WithPagination;

    public $ORDERID        = '';
    public $TRANS_STATUS   = '';
    public $CHANNEL        = '';
    public $INITATE_DATE   = '';
    public $REASON_TYPE    = '';
    public array $REASON_NAMES = [];
    public array $TXN_INDEXES  = [];
    public $DEBIT_MSISDN   = '';
    public $DEBIT_PROFILE  = '';
    public $CREDIT_MSISDN  = '';
    public $CREDIT_PROFILE = '';
    public $ACTUAL_AMOUNT  = '';
    public $FEE            = '';
    public $COMMISSION     = '';
    public $AVAILABLE_BALANCE = '';

    public string $DEBIT_SEGMENT  = '';
    public string $CREDIT_SEGMENT = '';

    public $date_debut = '';
    public $date_fin   = '';

    public $searched = false;


    public function search()
    {
        $this->resetPage();
        $this->searched = true;
    }

    public function resetFilters()
    {
        $this->TRANS_STATUS   = '';
        $this->CHANNEL        = '';
        $this->ORDERID        = '';
        $this->DEBIT_MSISDN   = '';
        $this->CREDIT_MSISDN  = '';
        $this->REASON_TYPE    = '';
        $this->TXN_INDEXES    = [];
        $this->date_debut     = '';
        $this->date_fin       = '';
        $this->searched       = false;
        $this->REASON_NAMES   = [];
        $this->DEBIT_SEGMENT  = '';
        $this->CREDIT_SEGMENT = '';
        $this->resetPage();
    }

    public function updatedTXNIndexes(): void
    {
        $this->REASON_NAMES = [];
    }

    public function getreasonTypes()
    {
        if (empty($this->TXN_INDEXES)) return collect();

        return \App\Models\reasonType::whereIn('txn_index', $this->TXN_INDEXES)
            ->orderBy('reason_name')
            ->get();
    }

    /**
     * Applique tous les filtres actifs à une requête Transaction.
     * Centralisé pour éviter la duplication entre with(), exportExcel(), etc.
     */
    private function applyFilters($query)
    {
        // Transaction ID
        if ($this->ORDERID) {
            $query->where('transaction_id', 'like', '%' . $this->ORDERID . '%');
        }

        // Debit Party
        if ($this->DEBIT_MSISDN) {
            $query->where('debit_party_identifier', 'like', '%' . $this->DEBIT_MSISDN . '%');
            
        }

        // Credit Party
        if ($this->CREDIT_MSISDN) {
            $query->where('credit_party_identifier', 'like', '%' . $this->CREDIT_MSISDN . '%');
        }

        // Transaction types
        if (!empty($this->TXN_INDEXES)) {
            $query->whereIn('txn_index', $this->TXN_INDEXES);
        }

        // Reason types (filtre sur reason_type = index)
        if (!empty($this->REASON_NAMES)) {
            $query->whereIn('reason_index', $this->REASON_NAMES);
        }

        // Statut
        if ($this->TRANS_STATUS) {
            $query->where('status', $this->TRANS_STATUS);
        }

        // Canal
        if ($this->CHANNEL) {
            $query->where('channel', $this->CHANNEL);
        }

        // Date début — comparaison directe pour utiliser l'index (pas de cast ::date)
        if ($this->date_debut) {
            $query->where('transaction_initiated_time', '>=', $this->date_debut . ' 00:00:00');
        }

        // Date fin — borne exclusive au lendemain, pour rester "sargable"
        if ($this->date_fin) {
            $query->where(
                'transaction_initiated_time',
                '<',
                \Carbon\Carbon::parse($this->date_fin)->addDay()->format('Y-m-d') . ' 00:00:00'
            );
        }

        // Debit segment
        if ($this->DEBIT_SEGMENT) {
            $query->where('debit_party_type', $this->DEBIT_SEGMENT);
        }

        // Credit segment
        if ($this->CREDIT_SEGMENT) {
            $query->where('credit_party_type', $this->CREDIT_SEGMENT);
        }

        return $query;
    }

    public function with()
    {
        $transaction_types = \App\Models\TransactionType::all();
        $segments          = \App\Models\Segment::all();

        if (!$this->searched) {
            return [
                'transactions'      => null,
                'transaction_types' => $transaction_types,
                'segments'          => $segments,
                'reason_types'      => $this->getreasonTypes(),
            ];
        }

        $query = $this->applyFilters(Transaction::query());

        return [
            // Eager loading de transactionType ET reasonType pour éviter le N+1
            'transactions'      => $query
                                        ->with(['transactionType', 'reasonType'])
                                        ->orderBy('transaction_initiated_time', 'desc')
                                        ->paginate(100),
            'transaction_types' => $transaction_types,
            'segments'          => $segments,
            'reason_types'      => $this->getreasonTypes(),
        ];
    }

    public function exportCsv()
    {
        set_time_limit(600);

        $filename = 'transactions_' . now()->format('Ymd_His') . '.csv';

        // 1) Pré-charger les libellés UNE fois (petites tables) -> pas de N+1
        $typeNames = \DB::table('transaction_types')
            ->select('txn_index', 'txn_type_name')
            ->get()
            ->keyBy('txn_index')
            ->map(fn($r) => $r->txn_type_name);

        $reasonNames = \DB::table('reason_types')
            ->select('reason_index', 'reason_name')
            ->get()
            ->keyBy('reason_index')
            ->map(fn($r) => $r->reason_name);

        // 2) Query filtrée, SANS relations Eloquent (on résout en mémoire)
        $query = $this->applyFilters(\App\Models\Transaction::query());

        return response()->streamDownload(function () use ($query, $typeNames, $reasonNames) {

            if (ob_get_level()) ob_end_clean();

            $handle = fopen('php://output', 'w');
            fprintf($handle, chr(0xEF) . chr(0xBB) . chr(0xBF));

            fputcsv($handle, [
                '#', 'Date', 'Transaction ID', 'Statut', 'Canal', 'Reason',
                'Transaction Type', 'Debit Party', 'Credit Party',
                'Debit Balance Avant', 'Debit Balance Apres',
                'Credit Balance Avant', 'Credit Balance Apres',
                'Amount', 'Fee', 'Commission',
            ], ';');

            $index = 1;

            foreach ($query->cursor() as $t) {
                fputcsv($handle, [
                    $index++,
                    $t->transaction_initiated_time
                        ? \Carbon\Carbon::parse($t->transaction_initiated_time)->format('d/m/Y H:i')
                        : '',
                    $t->transaction_id,
                    $t->status,
                    $t->channel ?? '',
                    // Résolution en mémoire (pas de requête SQL)
                    $reasonNames[$t->reason_index] ?? '',
                    $typeNames[$t->txn_index] ?? $t->transaction_type ?? $t->txn_index,
                    $t->debit_party_identifier,
                    $t->credit_party_identifier,
                    $t->balance_before_debit  * 100,
                    $t->balance_after_debit   * 100,
                    $t->balance_before_credit * 100,
                    $t->balance_after_credit  * 100,
                    $t->actual_amount     * 100,
                    $t->charge_amount     * 100,
                    $t->commission_amount * 100,
                ], ';');

                if ($index % 1000 === 0) flush();
            }

            fclose($handle);

        }, $filename, [
            'Content-Type'        => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="' . $filename . '"',
            'X-Accel-Buffering'   => 'no',
        ]);
    }

    public function exportExcel()
    {
        ini_set('memory_limit', '1024M'); 
        set_time_limit(600);

        return \Maatwebsite\Excel\Facades\Excel::download(
            new \App\Exports\TransactionsExport($this->buildQuery()),
            'transactions_' . now()->format('Ymd_His') . '.xlsx'
        );
    }

    private function buildQuery()
    {
        // Réutilise applyFilters pour rester cohérent avec l'affichage
        return $this->applyFilters(Transaction::query())
                    ->orderBy('transaction_initiated_time', 'desc');
    }

}; ?>

<div>
<div style="padding:24px;">

    {{-- FILTRES --}}
    <div style="background:#fff; border:1px solid #e5e7eb; border-radius:10px; padding:20px; margin-bottom:20px;">

        <p style="font-size:13px; font-weight:600; color:#111827; margin-bottom:14px;">
            Transactions : Filtres de recherche
        </p>

        <div style="display:grid; grid-template-columns:repeat(3, 1fr); gap:12px; margin-bottom:12px;">

            <div>
                <label style="font-size:11px; color:#6b7280; display:block; margin-bottom:4px;">Date début</label>
                <input type="date"
                       wire:model="date_debut"
                       style="width:100%; border:1px solid #d1d5db; border-radius:7px; padding:8px 10px; font-size:13px; color:#111827; outline:none;">
            </div>

            <div>
                <label style="font-size:11px; color:#6b7280; display:block; margin-bottom:4px;">Date fin</label>
                <input type="date"
                       wire:model="date_fin"
                       style="width:100%; border:1px solid #d1d5db; border-radius:7px; padding:8px 10px; font-size:13px; color:#111827; outline:none;">
            </div>

            {{-- Transaction Type — multi-select --}}
            <div x-data="{ open: false }" style="position:relative;">
                <label style="font-size:11px; color:#6b7280; display:block; margin-bottom:4px;">Transaction Type</label>
                <button type="button"
                        x-on:click="open = !open"
                        style="width:100%; border:1px solid #d1d5db; border-radius:7px; padding:8px 10px; font-size:13px; color:#111827; background:#fff; text-align:left; cursor:pointer; display:flex; align-items:center; justify-content:space-between;">
                    <span style="overflow:hidden; text-overflow:ellipsis; white-space:nowrap; max-width:200px;">
                        @if(empty($TXN_INDEXES))
                            Tous les types
                        @else
                            {{ count($TXN_INDEXES) }} type(s) sélectionné(s)
                        @endif
                    </span>
                    <svg width="12" height="12" viewBox="0 0 12 12" fill="none" x-bind:style="open ? 'transform:rotate(180deg)' : ''">
                        <path d="M2 4l4 4 4-4" stroke="#6b7280" stroke-width="1.5" stroke-linecap="round"/>
                    </svg>
                </button>

                <div x-show="open"
                    x-on:click.outside="open = false"
                    x-transition
                    style="position:absolute; top:calc(100% + 4px); left:0; right:0; background:#fff; border:1px solid #e5e7eb; border-radius:8px; box-shadow:0 8px 24px rgba(0,0,0,0.12); z-index:50; max-height:220px; overflow-y:auto;">

                    <div style="padding:8px 12px; border-bottom:1px solid #f3f4f6; display:flex; align-items:center; gap:8px;">
                        <button type="button"
                                wire:click="$set('TXN_INDEXES', [])"
                                style="font-size:10px; color:#E24B4A; background:none; border:none; cursor:pointer; padding:0;">
                            Tout désélectionner
                        </button>
                    </div>

                   @foreach($transaction_types as $type)
                        <label wire:key="txn-type-{{ $type->txn_index }}"
                            style="display:flex; align-items:center; gap:8px; padding:8px 12px; cursor:pointer; font-size:12px; color:#111827;"
                            onmouseover="this.style.background='#F7F8FC'"
                            onmouseout="this.style.background='transparent'">
                            <input type="checkbox"
                                wire:model.live="TXN_INDEXES"
                                value="{{ $type->txn_index }}"
                                style="accent-color:#1B2F6E; width:14px; height:14px; flex-shrink:0;">
                            {{ $type->txn_type_name }}
                        </label>
                    @endforeach
                </div>
            </div>

            {{-- reason Type — multi-select dépendant --}}
            <div x-data="{ open: false }" style="position:relative;">
                <label style="font-size:11px; color:#6b7280; display:block; margin-bottom:4px;">
                    reason Type
                    @if(empty($TXN_INDEXES))
                        <span style="color:#d1d5db; font-size:10px;">— choisir un type d'abord</span>
                    @endif
                </label>
                <button type="button"
                        x-on:click="open = !open"
                        {{ empty($TXN_INDEXES) ? 'disabled' : '' }}
                        style="width:100%; border:1px solid #d1d5db; border-radius:7px; padding:8px 10px; font-size:13px; color:#111827; background:{{ empty($TXN_INDEXES) ? '#f9fafb' : '#fff' }}; text-align:left; cursor:{{ empty($TXN_INDEXES) ? 'not-allowed' : 'pointer' }}; display:flex; align-items:center; justify-content:space-between; opacity:{{ empty($TXN_INDEXES) ? '0.6' : '1' }};">
                    <span style="overflow:hidden; text-overflow:ellipsis; white-space:nowrap; max-width:200px;">
                        @if(empty($REASON_NAMES))
                            Tous les reason types
                        @else
                            {{ count($REASON_NAMES) }} reason(s) sélectionné(s)
                        @endif
                    </span>
                    <svg width="12" height="12" viewBox="0 0 12 12" fill="none" x-bind:style="open ? 'transform:rotate(180deg)' : ''">
                        <path d="M2 4l4 4 4-4" stroke="#6b7280" stroke-width="1.5" stroke-linecap="round"/>
                    </svg>
                </button>

                @if(!empty($TXN_INDEXES))
                <div x-show="open"
                    x-on:click.outside="open = false"
                    x-transition
                    style="position:absolute; top:calc(100% + 4px); left:0; right:0; background:#fff; border:1px solid #e5e7eb; border-radius:8px; box-shadow:0 8px 24px rgba(0,0,0,0.12); z-index:50; max-height:220px; overflow-y:auto;">

                    <div style="padding:8px 12px; border-bottom:1px solid #f3f4f6;">
                        <button type="button"
                                wire:click="$set('REASON_NAMES', [])"
                                style="font-size:10px; color:#E24B4A; background:none; border:none; cursor:pointer; padding:0;">
                            Tout désélectionner
                        </button>
                    </div>

                    @forelse($reason_types as $reason)
                        <label wire:key="reason-{{ $reason->reason_index }}"
                            style="display:flex; align-items:center; gap:8px; padding:8px 12px; cursor:pointer; font-size:12px; color:#111827;"
                            onmouseover="this.style.background='#F7F8FC'"
                            onmouseout="this.style.background='transparent'">
                            <input type="checkbox"
                                wire:model="REASON_NAMES"
                                value="{{ $reason->reason_index }}"
                                style="accent-color:#1B2F6E; width:14px; height:14px; flex-shrink:0;">
                            {{ $reason->reason_name }}
                        </label>
                    @empty
                        <p style="padding:12px; font-size:12px; color:#9ca3af; text-align:center;">Aucun reason type disponible.</p>
                    @endforelse
                </div>
                @endif
            </div>

            <div>
                <label style="font-size:11px; color:#6b7280; display:block; margin-bottom:4px;">Debit Party (MSISDN)</label>
                <input type="text"
                       wire:model="DEBIT_MSISDN"
                       placeholder="Ex: 25377000000"
                       style="width:100%; border:1px solid #d1d5db; border-radius:7px; padding:8px 10px; font-size:13px; color:#111827; outline:none;">
            </div>

            <div>
                <label style="font-size:11px; color:#6b7280; display:block; margin-bottom:4px;">Debit Segment</label>
                <select wire:model="DEBIT_SEGMENT"
                        style="width:100%; border:1px solid #d1d5db; border-radius:7px; padding:8px 10px; font-size:13px; color:#111827; outline:none; background:#fff;">
                    <option value="">Tous les segments</option>
                    @foreach($segments as $segment)
                        <option value="{{ $segment->SEGMENT_ID }}">{{ $segment->SEGMENT_NAME }}</option>
                    @endforeach
                </select>
            </div>

            <div>
                <label style="font-size:11px; color:#6b7280; display:block; margin-bottom:4px;">Credit Party (MSISDN)</label>
                <input type="text"
                       wire:model="CREDIT_MSISDN"
                       placeholder="Ex: 25377000000"
                       style="width:100%; border:1px solid #d1d5db; border-radius:7px; padding:8px 10px; font-size:13px; color:#111827; outline:none;">
            </div>

            <div>
                <label style="font-size:11px; color:#6b7280; display:block; margin-bottom:4px;">Credit Segment</label>
                <select wire:model="CREDIT_SEGMENT"
                        style="width:100%; border:1px solid #d1d5db; border-radius:7px; padding:8px 10px; font-size:13px; color:#111827; outline:none; background:#fff;">
                    <option value="">Tous les segments</option>
                    @foreach($segments as $segment)
                        <option value="{{ $segment->SEGMENT_ID }}">{{ $segment->SEGMENT_NAME }}</option>
                    @endforeach
                </select>
            </div>

            <div>
                <label style="font-size:11px; color:#6b7280; display:block; margin-bottom:4px;">Transaction ID</label>
                <input type="text"
                    wire:model="ORDERID"
                    placeholder="Ex: 000123456789"
                    style="width:100%; border:1px solid #d1d5db; border-radius:7px; padding:8px 10px; font-size:13px; color:#111827; outline:none;">
            </div>

            <div>
                <label style="font-size:11px; color:#6b7280; display:block; margin-bottom:4px;">Canal</label>
                <select wire:model="CHANNEL"
                        style="width:100%; border:1px solid #d1d5db; border-radius:7px; padding:8px 10px; font-size:13px; color:#111827; outline:none; background:#fff;">
                    <option value="">Tous les canaux</option>
                    <option value="APP">MOBILE APP</option>
                    <option value="USSD">USSD</option>
                    <option value="API">API</option>
                    <option value="SYSTEM">SYSTEM</option>
                </select>
            </div>

            <div>
                <label style="font-size:11px; color:#6b7280; display:block; margin-bottom:4px;">Statut</label>
                <select wire:model="TRANS_STATUS"
                        style="width:100%; border:1px solid #d1d5db; border-radius:7px; padding:8px 10px; font-size:13px; color:#111827; outline:none; background:#fff;">
                    <option value="">Tous les statuts</option>
                    <option value="Completed">Completed</option>
                    <option value="Cancelled">Cancelled</option>
                    <option value="Authorized">Pending</option>
                    <option value="Declined">Declined</option>
                </select>
            </div>

        </div>

        {{-- Boutons --}}
        <div style="display:flex; align-items:center; gap:10px; margin-top:4px;">
            <button wire:click="search"
                    wire:loading.attr="disabled"
                    wire:target="search"
                    style="background:#00843D; color:#fff; font-size:13px; font-weight:600; padding:9px 22px; border-radius:8px; border:none; cursor:pointer; display:flex; align-items:center; gap:7px;">

                <span wire:loading.remove wire:target="search" style="display:flex; align-items:center; gap:7px;">
                    <svg width="14" height="14" viewBox="0 0 16 16" fill="white">
                        <circle cx="6.5" cy="6.5" r="4.5" stroke="white" stroke-width="1.5" fill="none"/>
                        <path d="M10.5 10.5L14 14" stroke="white" stroke-width="1.5" stroke-linecap="round"/>
                    </svg>
                    Rechercher
                </span>

                <span wire:loading.inline-flex wire:target="search" style="align-items:center; gap:7px;">
                    <svg width="14" height="14" viewBox="0 0 40 40" fill="none" style="animation:spin 0.8s linear infinite;">
                        <circle cx="20" cy="20" r="16" stroke="rgba(255,255,255,0.3)" stroke-width="4"/>
                        <path d="M20 4a16 16 0 0116 16" stroke="white" stroke-width="4" stroke-linecap="round"/>
                    </svg>
                    Chargement...
                </span>
            </button>

            <style>
            @keyframes spin {
                from { transform: rotate(0deg); }
                to   { transform: rotate(360deg); }
            }
            </style>

            @if($searched)
                <button wire:click="resetFilters"
                        style="background:#f3f4f6; color:#374151; font-size:13px; font-weight:500; padding:9px 18px; border-radius:8px; border:1px solid #e5e7eb; cursor:pointer;">
                    Réinitialiser
                </button>
            @endif

            @if($searched && $transactions->total() > 0)
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
            <div style="width:52px; height:52px; background:#E5F5ED; border-radius:14px; display:flex; align-items:center; justify-content:center; margin:0 auto 14px;">
                <svg width="26" height="26" viewBox="0 0 24 24" fill="none">
                    <circle cx="10" cy="10" r="7" stroke="#00843D" stroke-width="1.5"/>
                    <path d="M15.5 15.5L20 20" stroke="#00843D" stroke-width="1.5" stroke-linecap="round"/>
                </svg>
            </div>
            <p style="font-size:14px; font-weight:600; color:#111827; margin-bottom:4px;">Lancez une recherche</p>
            <p style="font-size:12px; color:#9ca3af;">Renseignez au moins un critère puis cliquez sur <strong>Rechercher</strong>.</p>
        </div>

    {{-- RÉSULTATS --}}
    @else
        <div style="background:#fff; border:1px solid #e5e7eb; border-radius:10px; overflow:hidden;">

            <div style="padding:12px 16px; border-bottom:1px solid #e5e7eb; display:flex; align-items:center; justify-content:space-between;">
                <p style="font-size:13px; font-weight:600; color:#111827;">Résultats</p>
                <span style="background:#E5F5ED; color:#005C2B; font-size:11px; font-weight:600; padding:3px 10px; border-radius:20px;">
                    {{ $transactions->total() }} transaction(s) trouvée(s)
                </span>
            </div>

            <div style="overflow-x:auto;">
                <table style="width:100%; border-collapse:collapse; font-size:12px;">
                    <thead>
                        <tr style="background:#F9FAF9;">
                            <th style="padding:10px 14px; text-align:left; color:#6b7280; font-weight:500; border-bottom:1px solid #e5e7eb; white-space:nowrap;">#</th>
                            <th style="padding:10px 14px; text-align:left; color:#6b7280; font-weight:500; border-bottom:1px solid #e5e7eb; white-space:nowrap;">Date</th>
                            <th style="padding:10px 14px; text-align:left; color:#6b7280; font-weight:500; border-bottom:1px solid #e5e7eb; white-space:nowrap;">TRANSACTION_ID</th>
                            <th style="padding:10px 14px; text-align:left; color:#6b7280; font-weight:500; border-bottom:1px solid #e5e7eb; white-space:nowrap;">Statut</th>
                            <th style="padding:10px 14px; text-align:left; color:#6b7280; font-weight:500; border-bottom:1px solid #e5e7eb; white-space:nowrap;">Canal</th>
                            <th style="padding:10px 14px; text-align:left; color:#6b7280; font-weight:500; border-bottom:1px solid #e5e7eb; white-space:nowrap;">reason</th>
                            <th style="padding:10px 14px; text-align:left; color:#6b7280; font-weight:500; border-bottom:1px solid #e5e7eb; white-space:nowrap;">Transaction Type</th>
                            <th style="padding:10px 14px; text-align:left; color:#6b7280; font-weight:500; border-bottom:1px solid #e5e7eb; white-space:nowrap;">Debit Party</th>
                            <th style="padding:10px 14px; text-align:left; color:#6b7280; font-weight:500; border-bottom:1px solid #e5e7eb; white-space:nowrap;">Credit Party</th>
                            <th style="padding:10px 14px; text-align:left; color:#6b7280; font-weight:500; border-bottom:1px solid #e5e7eb; white-space:nowrap;">Debit Balance</th>
                            <th style="padding:10px 14px; text-align:left; color:#6b7280; font-weight:500; border-bottom:1px solid #e5e7eb; white-space:nowrap;">Credit Balance</th>
                            <th style="padding:10px 14px; text-align:left; color:#6b7280; font-weight:500; border-bottom:1px solid #e5e7eb; white-space:nowrap;">Amount</th>
                            <th style="padding:10px 14px; text-align:left; color:#6b7280; font-weight:500; border-bottom:1px solid #e5e7eb; white-space:nowrap;">Fee</th>
                            <th style="padding:10px 14px; text-align:left; color:#6b7280; font-weight:500; border-bottom:1px solid #e5e7eb; white-space:nowrap;">Com</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($transactions as $transaction)
                            <tr style="border-bottom:1px solid #f3f4f6;"
                                onmouseover="this.style.background='#F9FCF9'"
                                onmouseout="this.style.background='transparent'">

                                <td style="padding:10px 14px; color:#6b7280;">{{ $transactions->firstItem() + $loop->index }}</td>

                                <td style="padding:10px 14px; color:#6b7280;">
                                    {{ \Carbon\Carbon::parse($transaction->transaction_initiated_time)->format('d/m/Y H:i') }}
                                </td>

                                <td style="padding:10px 14px; color:#6b7280; font-family:monospace; font-size:11px;">
                                    {{ $transaction->transaction_id }}
                                </td>

                                <td style="padding:10px 14px;">
                                    @if($transaction->status == 'Completed')
                                        <span style="background:#E5F5ED; color:#005C2B; font-size:10px; font-weight:600; padding:2px 8px; border-radius:12px;">Completed</span>
                                    @elseif($transaction->status == 'Cancelled')
                                        <span style="background:#FDECEA; color:#7F1D1D; font-size:10px; font-weight:600; padding:2px 8px; border-radius:12px;">Cancelled</span>
                                    @elseif($transaction->status == 'Authorized')
                                        <span style="background:#FEF3C7; color:#92400E; font-size:10px; font-weight:600; padding:2px 8px; border-radius:12px;">Pending</span>
                                    @else
                                        <span style="background:#FDECEA; color:#7F1D1D; font-size:10px; font-weight:600; padding:2px 8px; border-radius:12px;">Declined</span>
                                    @endif
                                </td>

                                <td style="padding:10px 14px; color:#6b7280;">
                                    {{ $transaction->channel ?? '—' }}
                                </td>

                                <td style="padding:10px 14px; color:#111827; font-weight:500;">
                                    {{ $transaction->reasonType?->reason_name ?? '—' }}
                                </td>

                                <td style="padding:10px 14px;">
                                    @if($transaction->transactionType)
                                        <span style="background:#E8ECF8; color:#1B2F6E; font-size:10px; font-weight:600; padding:2px 8px; border-radius:12px; white-space:nowrap;">
                                            {{ $transaction->transactionType->txn_type_name }}
                                        </span>
                                    @else
                                        <span style="color:#9ca3af;">{{ $transaction->txn_type_name }}</span>
                                    @endif
                                </td>

                                <td style="padding:10px 14px; color:#374151;">{{ $transaction->debit_party_identifier }}</td>
                                <td style="padding:10px 14px; color:#374151;">{{ $transaction->credit_party_identifier }}</td>
                                <td style="padding:10px 14px; color:#374151;">{{ number_format($transaction->balance_before_debit * 100, 0, ',', ' ') }} / {{number_format($transaction->balance_after_debit * 100, 0, ',', ' ')  }}</td>
                                <td style="padding:10px 14px; color:#374151;">{{ number_format($transaction->balance_before_credit * 100, 0, ',', ' ') }} / {{number_format($transaction->balance_after_credit * 100, 0, ',', ' ') }}</td>
                                <td style="padding:10px 14px; color:#374151;">{{ number_format($transaction->actual_amount * 100, 0, ',', ' ') }}</td>
                                <td style="padding:10px 14px; color:#374151;">{{ number_format($transaction->charge_amount * 100, 0, ',', ' ') }}</td>
                                <td style="padding:10px 14px; color:#374151;">{{ number_format($transaction->commission_amount * 100, 0, ',', ' ')  }}</td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="14" style="padding:40px; text-align:center; color:#9ca3af; font-size:13px;">
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
        const EXCEL_LIMIT = 10000;

        function lancerExport(total) {
            if (total <= EXCEL_LIMIT) {
                Swal.fire({
                    title: 'Export Excel',
                    html: `<span style="font-size:13px;color:#6b7280;">
                                <strong style="color:#111827;">${total.toLocaleString('fr-FR')}</strong> lignes seront exportées.
                           </span>`,
                    icon: 'question',
                    showCancelButton: true,
                    confirmButtonText: 'Exporter Excel',
                    cancelButtonText: 'Annuler',
                    confirmButtonColor: '#1B2F6E',
                    cancelButtonColor: '#6b7280',
                    reverseButtons: true,
                }).then(result => {
                    if (!result.isConfirmed) return;
                    lancerTelechargement('excel');
                });
            } else {
                Swal.fire({
                    title: 'Fichier trop volumineux pour Excel',
                    html: `
                        <div style="font-size:13px; color:#6b7280; line-height:1.6;">
                            <strong style="color:#111827;">${total.toLocaleString('fr-FR')}</strong> lignes détectées.<br>
                            Excel est limité à <strong>10 000 lignes</strong>.<br><br>
                            Voulez-vous exporter en <strong style="color:#1B2F6E;">CSV</strong> à la place ?
                        </div>
                    `,
                    icon: 'warning',
                    showCancelButton: true,
                    confirmButtonText: 'Exporter CSV',
                    cancelButtonText: 'Annuler',
                    confirmButtonColor: '#1B2F6E',
                    cancelButtonColor: '#6b7280',
                    reverseButtons: true,
                }).then(result => {
                    if (!result.isConfirmed) return;
                    lancerTelechargement('csv');
                });
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

                    const method    = format === 'excel' ? 'exportExcel' : 'exportCsv';
                    const component = Livewire.find(
                        document.querySelector('[wire\\:id]').getAttribute('wire:id')
                    );

                    component.call(method)
                        .then(() => {
                            Swal.fire({
                                title: 'Téléchargement lancé !',
                                text: `Votre fichier ${format.toUpperCase()} a été généré.`,
                                icon: 'success',
                                confirmButtonColor: '#1B2F6E',
                                timer: 2500,
                                timerProgressBar: true,
                            });
                        })
                        .catch(() => {
                            Swal.fire({
                                title: 'Erreur',
                                text: "Une erreur est survenue lors de l'export.",
                                icon: 'error',
                                confirmButtonColor: '#E24B4A',
                            });
                        });
                }
            });
        }
    </script>
</div>
</div>