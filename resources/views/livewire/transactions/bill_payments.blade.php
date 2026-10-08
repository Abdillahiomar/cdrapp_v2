<?php

use App\Jobs\GenerateBillPaymentsExport;
use App\Models\ExportRequest;
use App\Models\Transaction;
use App\Support\BillPayments;
use Carbon\Carbon;
use Illuminate\Support\Facades\Storage;
use Livewire\Volt\Component;
use Livewire\WithPagination;

/**
 * Paiements de factures (DT, EDD, ONEAD, forfaits DT, taxes, frais
 * universitaires) pour l'équipe finance.
 *
 * Les filtres ne sont appliqués qu'au clic sur « Rechercher » : la recherche
 * affichée, la synthèse et l'export utilisent tous le même instantané
 * ($applied), même si l'utilisateur modifie les champs entre-temps.
 */
new class extends Component {

    use WithPagination;

    public string $date_debut = '';
    public string $date_fin   = '';
    public array  $types      = [];
    public string $biller     = '';
    public string $status     = '';
    public string $search     = '';

    /** Filtres de la dernière recherche lancée */
    public array $applied = [];

    // Export
    public bool   $showExport    = false;
    public string $exportType    = 'excel';
    public array  $exportColumns = BillPayments::DEFAULT_COLUMNS;

    public function mount(): void
    {
        $this->date_debut = Carbon::now()->startOfMonth()->toDateString();
        $this->date_fin   = Carbon::now()->toDateString();
        $this->types      = array_map('strval', array_keys(BillPayments::types()));
    }

    public function search(): void
    {
        $this->validate([
            'date_debut' => 'required|date',
            'date_fin'   => 'required|date|after_or_equal:date_debut',
            'types'      => 'required|array|min:1',
        ], [
            'date_debut.required'     => 'Choisissez la date de début.',
            'date_fin.required'       => 'Choisissez la date de fin.',
            'date_fin.after_or_equal' => 'La date de fin doit être après la date de début.',
            'types.required'          => 'Sélectionnez au moins un type de facture.',
            'types.min'               => 'Sélectionnez au moins un type de facture.',
        ]);

        $days = (int) round(Carbon::parse($this->date_debut)->diffInDays(Carbon::parse($this->date_fin), absolute: true)) + 1;
        if ($days > BillPayments::MAX_DAYS) {
            $this->addError('date_fin', "La période fait {$days} jours : le maximum est " . BillPayments::MAX_DAYS . ' jours (environ 3 mois). Découpez la recherche.');
            return;
        }

        $this->applied = [
            'date_debut' => $this->date_debut,
            'date_fin'   => $this->date_fin,
            'types'      => array_map('intval', $this->types),
            'biller'     => $this->biller,
            'status'     => $this->status,
            'search'     => trim($this->search),
        ];

        $this->resetPage();
    }

    public function resetFilters(): void
    {
        $this->mount();
        $this->biller  = '';
        $this->status  = '';
        $this->search  = '';
        $this->applied = [];
        $this->resetErrorBag();
        $this->resetPage();
    }

    public function toggleAllTypes(): void
    {
        $all = array_map('strval', array_keys(BillPayments::types()));
        $this->types = count($this->types) === count($all) ? [] : $all;
        $this->updatedTypes();
    }

    /** Le facturier choisi doit rester cohérent avec les types cochés. */
    public function updatedTypes(): void
    {
        if ($this->biller !== '' && !collect($this->availableBillers())->contains('id', $this->biller)) {
            $this->biller = '';
        }
    }

    /** Facturiers qui reçoivent au moins un des types cochés. */
    private function availableBillers(): array
    {
        $types = array_map('intval', $this->types);

        return array_values(array_filter(BillPayments::billers(), fn ($b) => array_intersect($b['types'], $types)));
    }

    // ───────────────────────────── Export ─────────────────────────────

    public function openExport(): void
    {
        if (empty($this->applied)) {
            return;
        }
        $this->showExport = true;
        $this->resetErrorBag('exportColumns');
    }

    public function selectColumns(string $preset): void
    {
        $this->exportColumns = $preset === 'all'
            ? array_keys(BillPayments::COLUMNS)
            : BillPayments::DEFAULT_COLUMNS;
    }

    public function queueExport(): void
    {
        if (empty($this->applied)) {
            return;
        }

        $this->validate(
            ['exportColumns' => 'required|array|min:1', 'exportType' => 'required|in:excel,csv'],
            ['exportColumns.required' => 'Cochez au moins une colonne.', 'exportColumns.min' => 'Cochez au moins une colonne.']
        );

        $request = ExportRequest::create([
            'user_id' => auth()->id(),
            'type'    => $this->exportType,
            'source'  => 'bill_payments',
            'status'  => 'pending',
            'filters' => $this->applied,
            'columns' => BillPayments::sanitizeColumns($this->exportColumns),
        ]);

        GenerateBillPaymentsExport::dispatch($request);

        $this->showExport = false;
        session()->flash('export-message', 'Export ' . strtoupper($this->exportType) . ' lancé : il apparaîtra ci-dessous dès qu\'il sera prêt.');
    }

    public function downloadExport(int $exportId)
    {
        $export = ExportRequest::where('user_id', auth()->id())->findOrFail($exportId);

        if (!in_array($export->status, ['done', 'uploaded']) || !$export->file_path || !Storage::disk('local')->exists($export->file_path)) {
            session()->flash('export-error', "Ce fichier n'est plus disponible.");
            return null;
        }

        if ($export->status === 'done') {
            $export->update(['status' => 'uploaded', 'downloaded_at' => now()]);
        }

        return response()->download(Storage::disk('local')->path($export->file_path), $export->fileName());
    }

    public function with(): array
    {
        $typeLabels = BillPayments::types();

        $data = [
            'typeLabels' => $typeLabels,
            'billers'    => $this->availableBillers(),
            'payments'   => null,
            'summary'    => collect(),
            'labels'     => null,
            'myExports'  => ExportRequest::where('user_id', auth()->id())
                ->where('source', 'bill_payments')
                ->whereIn('status', ['pending', 'processing', 'done'])
                ->latest()
                ->limit(5)
                ->get(),
        ];

        if (empty($this->applied)) {
            return $data;
        }

        // Synthèse par type de facture et statut
        $data['summary'] = BillPayments::apply(Transaction::query(), $this->applied)
            ->toBase()
            ->selectRaw('txn_index, status, COUNT(*) AS nb, COALESCE(SUM(actual_amount), 0) * 100 AS montant')
            ->groupBy('txn_index', 'status')
            ->get()
            ->groupBy('txn_index');

        $data['payments'] = BillPayments::apply(Transaction::query(), $this->applied)
            ->select([
                'transaction_id', 'transaction_initiated_time', 'txn_index', 'reason_index', 'transaction_type',
                'status', 'channel', 'debit_party_identifier', 'debit_party_type', 'debit_party_name',
                'credit_party_identifier', 'credit_party_name', 'ref_value',
                'actual_amount', 'charge_amount', 'commission_amount',
            ])
            ->orderByDesc('transaction_initiated_time')
            ->paginate(50);

        $data['labels'] = BillPayments::labels();

        return $data;
    }
};
?>
<div style="padding:24px;">

@php
    $inputStyle = 'border:1px solid #d1d5db; border-radius:7px; padding:8px 10px; font-size:13px; color:#111827; outline:none; background:#fff;';
    $labelStyle = 'font-size:11px; color:#6b7280; display:block; margin-bottom:4px;';
    $card       = 'background:#fff; border:1px solid #e5e7eb; border-radius:10px;';
    $fdj        = fn ($v) => number_format((float) $v, 0, ',', ' ');
    $statusStyles = [
        'Completed' => ['#E5F5ED', '#005C2B'],
        'Cancelled' => ['#FDECEA', '#7F1D1D'],
        'Declined'  => ['#FDECEA', '#7F1D1D'],
        'Expired'   => ['#F3F4F6', '#374151'],
    ];
@endphp

    <div style="margin-bottom:16px;">
        <h2 style="font-size:16px; font-weight:700; color:#111827; margin:0 0 4px;">Paiements de factures</h2>
        <p style="font-size:12px; color:#9ca3af; margin:0;">Factures DT, EDD, ONEAD, forfaits DT, taxes et frais universitaires payés par les clients D-Money.</p>
    </div>

    {{-- ── FILTRES ── --}}
    <div style="{{ $card }} padding:16px; margin-bottom:16px;">
        <div style="display:flex; gap:12px; flex-wrap:wrap; align-items:flex-start; margin-bottom:14px;">
            <div>
                <label style="{{ $labelStyle }}">Date de début <span style="color:#B91C1C;">*</span></label>
                <input type="date" wire:model="date_debut" style="{{ $inputStyle }}">
                @error('date_debut') <p style="font-size:11px; color:#B91C1C; margin:4px 0 0;">{{ $message }}</p> @enderror
            </div>
            <div>
                <label style="{{ $labelStyle }}">Date de fin <span style="color:#B91C1C;">*</span></label>
                <input type="date" wire:model="date_fin" style="{{ $inputStyle }}">
                @error('date_fin') <p style="font-size:11px; color:#B91C1C; margin:4px 0 0; max-width:260px;">{{ $message }}</p> @enderror
            </div>
            <div>
                <label style="{{ $labelStyle }}">Facturier</label>
                <select wire:model="biller" style="{{ $inputStyle }} width:220px;">
                    <option value="">Tous les facturiers</option>
                    @foreach($billers as $b)
                        <option value="{{ $b['id'] }}">{{ $b['name'] }} ({{ $b['id'] }})</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label style="{{ $labelStyle }}">Statut</label>
                <select wire:model="status" style="{{ $inputStyle }} width:150px;">
                    <option value="">Tous les statuts</option>
                    @foreach(['Completed', 'Cancelled', 'Declined', 'Expired'] as $s)
                        <option value="{{ $s }}">{{ $s }}</option>
                    @endforeach
                </select>
            </div>
            <div style="flex:1; min-width:240px;">
                <label style="{{ $labelStyle }}">Client, shortcode, transaction ID ou référence</label>
                <input type="text" wire:model="search" wire:keydown.enter="search" placeholder="Ex. 25377XXXXXX, 1298, 000419…, référence facture"
                       style="{{ $inputStyle }} width:100%; box-sizing:border-box;">
            </div>
        </div>

        <div style="margin-bottom:14px;">
            <div style="display:flex; align-items:center; gap:10px; margin-bottom:6px;">
                <span style="{{ $labelStyle }} margin:0;">Types de facture</span>
                <button type="button" wire:click="toggleAllTypes" style="background:none; border:none; color:#1B2F6E; font-size:11px; font-weight:600; cursor:pointer; padding:0;">
                    {{ count($types) === count($typeLabels) ? 'Tout décocher' : 'Tout cocher' }}
                </button>
            </div>
            <div style="display:flex; gap:8px; flex-wrap:wrap;">
                @foreach($typeLabels as $idx => $label)
                    <label style="display:flex; align-items:center; gap:6px; font-size:12px; color:#374151; border:1px solid {{ in_array((string) $idx, $types, true) ? '#1B2F6E' : '#e5e7eb' }}; background:{{ in_array((string) $idx, $types, true) ? '#E8ECF8' : '#fff' }}; border-radius:20px; padding:5px 12px; cursor:pointer;">
                        <input type="checkbox" wire:model.live="types" value="{{ $idx }}" style="margin:0;"> {{ $label }}
                    </label>
                @endforeach
            </div>
            @error('types') <p style="font-size:11px; color:#B91C1C; margin:6px 0 0;">{{ $message }}</p> @enderror
        </div>

        <div style="display:flex; gap:8px; flex-wrap:wrap;">
            <button wire:click="search"
                    style="background:#00843D; color:#fff; font-size:13px; font-weight:600; padding:9px 20px; border-radius:8px; border:none; cursor:pointer;">
                <span wire:loading.remove wire:target="search">Rechercher</span>
                <span wire:loading wire:target="search">Recherche…</span>
            </button>
            <button wire:click="resetFilters"
                    style="background:#f3f4f6; color:#374151; font-size:13px; font-weight:600; padding:9px 16px; border-radius:8px; border:1px solid #d1d5db; cursor:pointer;">
                Réinitialiser
            </button>
            @if(!empty($applied))
                <button wire:click="openExport"
                        style="background:#fff; color:#1B2F6E; font-size:13px; font-weight:600; padding:9px 16px; border-radius:8px; border:1px solid #1B2F6E; cursor:pointer; margin-left:auto;">
                    ⬇ Exporter cette recherche
                </button>
            @endif
        </div>
    </div>

    {{-- ── MESSAGES ── --}}
    @if(session('export-message'))
        <div style="background:#E5F5ED; color:#005C2B; border:1px solid #A7E3C1; border-radius:8px; padding:10px 14px; font-size:12px; margin-bottom:16px;">{{ session('export-message') }}</div>
    @endif
    @if(session('export-error'))
        <div style="background:#FDE8E8; color:#7F1D1D; border:1px solid #F5B5B5; border-radius:8px; padding:10px 14px; font-size:12px; margin-bottom:16px;">{{ session('export-error') }}</div>
    @endif

    {{-- ── MES EXPORTS RÉCENTS ── --}}
    @if($myExports->isNotEmpty())
        <div style="{{ $card }} padding:16px; margin-bottom:16px;"
             @if($myExports->whereIn('status', ['pending', 'processing'])->isNotEmpty()) wire:poll.5s @endif>
            <p style="font-size:12px; font-weight:600; color:#111827; margin:0 0 10px;">Mes exports récents</p>
            <div style="display:flex; flex-direction:column; gap:6px;">
                @foreach($myExports as $export)
                    <div style="display:flex; align-items:center; justify-content:space-between; padding:8px 12px; background:#F7F8FC; border-radius:6px; font-size:12px;">
                        <div style="display:flex; align-items:center; gap:10px; flex-wrap:wrap;">
                            <span style="color:#111827; font-weight:500;">{{ strtoupper($export->type) }}</span>
                            <span style="color:#9ca3af;">{{ $export->created_at->format('d/m/Y H:i') }}</span>
                            <span style="color:#6b7280;">
                                {{ \Carbon\Carbon::parse($export->filters['date_debut'] ?? null)->format('d/m') }} → {{ \Carbon\Carbon::parse($export->filters['date_fin'] ?? null)->format('d/m/Y') }}
                                · {{ count($export->columns ?? []) }} colonne(s)
                            </span>
                            @if($export->status === 'pending')
                                <span style="background:#FEF3C7; color:#92400E; font-size:10px; font-weight:600; padding:2px 8px; border-radius:20px;">En attente</span>
                            @elseif($export->status === 'processing')
                                <span style="background:#DBEAFE; color:#1E40AF; font-size:10px; font-weight:600; padding:2px 8px; border-radius:20px;">En cours...</span>
                            @else
                                <span style="background:#E5F5ED; color:#005C2B; font-size:10px; font-weight:600; padding:2px 8px; border-radius:20px;">Prêt</span>
                            @endif
                        </div>
                        @if($export->status === 'done')
                            <button wire:click="downloadExport({{ $export->id }})"
                                    style="background:#1B2F6E; color:#fff; font-size:11px; font-weight:600; padding:5px 12px; border-radius:6px; border:none; cursor:pointer;">
                                Télécharger
                            </button>
                        @endif
                    </div>
                @endforeach
            </div>
        </div>
    @endif

    @if(empty($applied))
        <div style="{{ $card }} text-align:center; padding:60px 20px;">
            <p style="font-size:14px; font-weight:600; color:#111827; margin:0 0 6px;">Choisissez une période et cliquez sur « Rechercher »</p>
            <p style="font-size:12px; color:#9ca3af; margin:0;">Les dates de début et de fin sont obligatoires (période de {{ \App\Support\BillPayments::MAX_DAYS }} jours maximum).</p>
        </div>
    @else
        {{-- ── SYNTHÈSE PAR TYPE ── --}}
        <div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(200px, 1fr)); gap:12px; margin-bottom:16px;">
            @foreach($typeLabels as $idx => $label)
                @continue(!in_array($idx, $applied['types']))
                @php
                    $rows      = $summary->get($idx, collect());
                    $nb        = $rows->sum('nb');
                    $completed = $rows->firstWhere('status', 'Completed');
                    $failed    = $rows->where('status', '!=', 'Completed')->sum('nb');
                @endphp
                <div style="{{ $card }} padding:14px; border-top:3px solid #1B2F6E;">
                    <p style="font-size:11px; color:#6b7280; margin:0 0 6px;">{{ $label }}</p>
                    <p style="font-size:18px; font-weight:700; color:#111827; margin:0;">{{ number_format($nb, 0, ',', ' ') }} <span style="font-size:11px; font-weight:500; color:#9ca3af;">paiement(s)</span></p>
                    <p style="font-size:12px; color:#005C2B; margin:4px 0 0;">{{ $fdj($completed->montant ?? 0) }} FDJ payés</p>
                    <p style="font-size:11px; color:{{ $failed > 0 ? '#B91C1C' : '#9ca3af' }}; margin:2px 0 0;">{{ number_format($failed, 0, ',', ' ') }} non abouti(s)</p>
                </div>
            @endforeach
        </div>

        {{-- ── TABLEAU ── --}}
        <div style="{{ $card }} overflow:hidden;">
            <div style="padding:12px 16px; border-bottom:1px solid #e5e7eb; display:flex; align-items:center; justify-content:space-between; gap:10px; flex-wrap:wrap;">
                <p style="font-size:13px; font-weight:600; color:#111827; margin:0;">
                    Du {{ \Carbon\Carbon::parse($applied['date_debut'])->format('d/m/Y') }} au {{ \Carbon\Carbon::parse($applied['date_fin'])->format('d/m/Y') }}
                </p>
                <span style="background:#E8ECF8; color:#1B2F6E; font-size:11px; font-weight:600; padding:3px 10px; border-radius:20px;">
                    {{ number_format($payments->total(), 0, ',', ' ') }} paiement(s)
                </span>
            </div>
            <div style="overflow-x:auto;">
                <table style="width:100%; border-collapse:collapse; font-size:12px;">
                    <thead>
                        <tr style="background:#F7F8FC;">
                            @foreach(['Date', 'Transaction ID', 'Type', 'Client', 'Facturier', 'Référence facture', 'Montant (FDJ)', 'Frais', 'Statut'] as $i => $th)
                                <th style="padding:10px 12px; text-align:{{ in_array($i, [6, 7]) ? 'right' : 'left' }}; color:#6b7280; font-weight:500; border-bottom:1px solid #e5e7eb; white-space:nowrap;">{{ $th }}</th>
                            @endforeach
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($payments as $p)
                            @php [$sBg, $sColor] = $statusStyles[$p->status] ?? ['#F3F4F6', '#374151']; @endphp
                            <tr style="border-bottom:1px solid #f3f4f6;"
                                onmouseover="this.style.background='#F7F8FC'"
                                onmouseout="this.style.background='transparent'">
                                <td style="padding:9px 12px; color:#6b7280; white-space:nowrap;">{{ \Carbon\Carbon::parse($p->transaction_initiated_time)->format('d/m/Y H:i') }}</td>
                                <td style="padding:9px 12px; color:#374151; font-family:monospace; font-size:11px;">{{ $p->transaction_id }}</td>
                                <td style="padding:9px 12px; white-space:nowrap;">
                                    <span style="background:#E8ECF8; color:#1B2F6E; font-size:10px; font-weight:600; padding:2px 8px; border-radius:12px;">{{ $labels['types'][$p->txn_index] ?? $p->transaction_type }}</span>
                                    @if($labels['reasons'][$p->reason_index] ?? null)
                                        <br><span style="color:#9ca3af; font-size:10px;">{{ \Illuminate\Support\Str::limit($labels['reasons'][$p->reason_index], 32) }}</span>
                                    @endif
                                </td>
                                <td style="padding:9px 12px;">
                                    <span style="color:#111827; font-weight:600; font-family:monospace;">{{ $p->debit_party_identifier }}</span>
                                    <span style="font-size:9px; font-weight:600; padding:1px 6px; border-radius:10px; margin-left:4px; {{ $p->debit_party_type === 'Organization' ? 'background:#FEF3C7; color:#92400E;' : 'background:#F3F4F6; color:#6b7280;' }}">
                                        {{ $p->debit_party_type === 'Organization' ? 'Shortcode' : 'MSISDN' }}
                                    </span>
                                    @if($p->debit_party_name)
                                        <br><span style="color:#9ca3af; font-size:11px;">{{ $p->debit_party_name }}</span>
                                    @endif
                                </td>
                                <td style="padding:9px 12px; color:#374151;">
                                    {{ $p->credit_party_name ?: $p->credit_party_identifier }}
                                    @if($p->credit_party_name)
                                        <br><span style="color:#9ca3af; font-size:11px;">{{ $p->credit_party_identifier }}</span>
                                    @endif
                                </td>
                                <td style="padding:9px 12px; color:#111827; font-family:monospace; font-size:11px;">{{ $p->ref_value ?: '—' }}</td>
                                <td style="padding:9px 12px; text-align:right; color:#111827; font-weight:600; white-space:nowrap;">{{ $fdj($p->actual_amount * 100) }}</td>
                                <td style="padding:9px 12px; text-align:right; color:#6b7280; white-space:nowrap;">{{ $fdj($p->charge_amount * 100) }}</td>
                                <td style="padding:9px 12px;">
                                    <span style="background:{{ $sBg }}; color:{{ $sColor }}; font-size:10px; font-weight:600; padding:2px 8px; border-radius:12px;">{{ $p->status }}</span>
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="9" style="padding:24px; text-align:center; color:#9ca3af;">Aucun paiement de facture pour ces critères.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            <div style="padding:12px 16px; border-top:1px solid #e5e7eb;">
                {{ $payments->links() }}
            </div>
        </div>
    @endif

    {{-- ── FENÊTRE D'EXPORT ── --}}
    @if($showExport)
        <div style="position:fixed; inset:0; background:rgba(17,24,39,0.45); z-index:200; display:flex; align-items:center; justify-content:center; padding:16px;"
             wire:click.self="$set('showExport', false)">
            <div style="width:min(560px, 100%); max-height:90vh; overflow-y:auto; background:#fff; border-radius:12px; box-shadow:0 12px 32px rgba(0,0,0,0.2);">
                <div style="padding:16px 20px; border-bottom:1px solid #e5e7eb; display:flex; justify-content:space-between; align-items:center;">
                    <div>
                        <p style="font-size:15px; font-weight:700; color:#111827; margin:0;">Exporter les paiements</p>
                        <p style="font-size:11px; color:#9ca3af; margin:3px 0 0;">
                            Du {{ \Carbon\Carbon::parse($applied['date_debut'])->format('d/m/Y') }} au {{ \Carbon\Carbon::parse($applied['date_fin'])->format('d/m/Y') }}, avec les filtres de la recherche affichée.
                        </p>
                    </div>
                    <button wire:click="$set('showExport', false)" style="background:none; border:none; font-size:22px; color:#9ca3af; cursor:pointer; line-height:1;">×</button>
                </div>

                <div style="padding:16px 20px;">
                    <p style="{{ $labelStyle }}">Format</p>
                    <div style="display:flex; gap:8px; margin-bottom:16px;">
                        @foreach(['excel' => 'Excel (.xlsx)', 'csv' => 'CSV (;)'] as $key => $label)
                            <label style="display:flex; align-items:center; gap:6px; font-size:13px; color:#374151; border:1px solid {{ $exportType === $key ? '#1B2F6E' : '#e5e7eb' }}; background:{{ $exportType === $key ? '#E8ECF8' : '#fff' }}; border-radius:8px; padding:8px 14px; cursor:pointer;">
                                <input type="radio" wire:model.live="exportType" value="{{ $key }}" style="margin:0;"> {{ $label }}
                            </label>
                        @endforeach
                    </div>

                    <div style="display:flex; align-items:center; justify-content:space-between; margin-bottom:6px;">
                        <p style="{{ $labelStyle }} margin:0;">Colonnes à exporter ({{ count($exportColumns) }})</p>
                        <div style="display:flex; gap:10px;">
                            <button type="button" wire:click="selectColumns('default')" style="background:none; border:none; color:#1B2F6E; font-size:11px; font-weight:600; cursor:pointer; padding:0;">Par défaut</button>
                            <button type="button" wire:click="selectColumns('all')" style="background:none; border:none; color:#1B2F6E; font-size:11px; font-weight:600; cursor:pointer; padding:0;">Tout cocher</button>
                            <button type="button" wire:click="$set('exportColumns', [])" style="background:none; border:none; color:#6b7280; font-size:11px; font-weight:600; cursor:pointer; padding:0;">Tout décocher</button>
                        </div>
                    </div>
                    <div style="display:grid; grid-template-columns:repeat(2, minmax(0,1fr)); gap:4px 12px; border:1px solid #e5e7eb; border-radius:8px; padding:10px 12px;">
                        @foreach(\App\Support\BillPayments::COLUMNS as $key => $label)
                            <label style="display:flex; align-items:center; gap:8px; font-size:12px; color:#374151; padding:3px 0; cursor:pointer;">
                                <input type="checkbox" wire:model.live="exportColumns" value="{{ $key }}"> {{ $label }}
                            </label>
                        @endforeach
                    </div>
                    @error('exportColumns') <p style="font-size:11px; color:#B91C1C; margin:6px 0 0;">{{ $message }}</p> @enderror
                    <p style="font-size:11px; color:#9ca3af; margin:10px 0 0;">
                        Les colonnes gardent toujours l'ordre ci-dessus. L'export est préparé en arrière-plan : vous le retrouverez dans « Mes exports récents » sur cette page.
                    </p>
                </div>

                <div style="padding:14px 20px; border-top:1px solid #e5e7eb; display:flex; gap:8px; justify-content:flex-end;">
                    <button wire:click="$set('showExport', false)"
                            style="background:#f3f4f6; color:#374151; font-size:13px; font-weight:600; padding:9px 16px; border-radius:8px; border:1px solid #d1d5db; cursor:pointer;">
                        Annuler
                    </button>
                    <button wire:click="queueExport"
                            style="background:#1B2F6E; color:#fff; font-size:13px; font-weight:600; padding:9px 16px; border-radius:8px; border:none; cursor:pointer;">
                        Lancer l'export
                    </button>
                </div>
            </div>
        </div>
    @endif

</div>
