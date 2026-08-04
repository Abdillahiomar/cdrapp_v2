<?php

use App\Models\RevenueAccount;
use Livewire\Volt\Component;
use Livewire\WithPagination;
use Carbon\Carbon;

new class extends Component {

    use WithPagination;

    public string $date       = '';
    public string $alias      = '';
    public string $account_no = '';
    public bool   $searched   = false;

    public function mount()
    {
        $this->date = Carbon::yesterday()->format('Y-m-d');
    }

    public function search()
    {
        $this->validate([
            'date' => 'required|date',
        ], [
            'date.required' => 'La date est obligatoire.',
        ]);

        $this->resetPage();
        $this->searched = true;
    }

    public function resetFilters()
    {
        $this->alias      = '';
        $this->account_no = '';
        $this->searched   = false;
        $this->resetPage();
    }

    public function with(): array
    {
        // Toujours chargé pour le select
        $aliases = RevenueAccount::select('alias')
            ->whereNotNull('alias')
            ->distinct()
            ->orderBy('alias')
            ->pluck('alias');

        if (!$this->searched) {
            return [
                'accounts' => null,
                'kpis'     => null,
                'aliases'  => $aliases,
            ];
        }
        if (!$this->searched) {
            return ['accounts' => null, 'kpis' => null];
        }

        $query = RevenueAccount::query()
            ->whereDate('data_date', $this->date);

        // Après
        if ($this->alias) {
            $query->where('alias', $this->alias);
        }

        if ($this->account_no) {
            $query->where('account_no', 'like', '%' . $this->account_no . '%');
        }

        // KPIs sur la même requête sans pagination
        $kpisQuery = clone $query;
        $kpis = [
            'total_comptes'    => $kpisQuery->count(),
            'total_balance'    => $kpisQuery->sum('balance'),
            'total_reserved'   => $kpisQuery->sum('reserved_balance'),
            'total_unclear'    => $kpisQuery->sum('unclear_balance'),
        ];

        return [
            'accounts' => $query->orderByDesc('balance')->paginate(50),
            'kpis'     => $kpis,
            'aliases'  => $aliases,
        ];
    }
};
?>

<div style="padding:24px;">

    {{-- FILTRES --}}
    <div style="background:#fff; border:1px solid #e5e7eb; border-radius:12px; padding:20px; margin-bottom:20px;">

        <p style="font-size:13px; font-weight:600; color:#111827; margin-bottom:14px;">
            Revenue Accounts
        </p>

        <div style="display:grid; grid-template-columns:repeat(3, minmax(0,1fr)); gap:12px; margin-bottom:12px;">

            <div>
                <label style="font-size:11px; color:#6b7280; display:block; margin-bottom:4px;">Date <span style="color:#E24B4A;">*</span></label>
                <input type="date"
                       wire:model="date"
                       style="width:100%; border:1px solid #d1d5db; border-radius:7px; padding:8px 10px; font-size:13px; color:#111827; outline:none;">
                @error('date')
                    <p style="font-size:10px; color:#E24B4A; margin:3px 0 0;">{{ $message }}</p>
                @enderror
            </div>

            <div>
                <label style="font-size:11px; color:#6b7280; display:block; margin-bottom:4px;">Alias</label>
                <select wire:model="alias"
                        style="width:100%; border:1px solid #d1d5db; border-radius:7px; padding:8px 10px; font-size:13px; color:#111827; outline:none; background:#fff;">
                    <option value="">Tous les alias</option>
                    @foreach($aliases as $al)
                        <option value="{{ $al }}">{{ $al }}</option>
                    @endforeach
                </select>
            </div>

            <div>
                <label style="font-size:11px; color:#6b7280; display:block; margin-bottom:4px;">Account No</label>
                <input type="text"
                       wire:model="account_no"
                       placeholder="Ex: ACC0001234"
                       style="width:100%; border:1px solid #d1d5db; border-radius:7px; padding:8px 10px; font-size:13px; color:#111827; outline:none;">
            </div>

        </div>

        {{-- Boutons --}}
        <div style="display:flex; align-items:center; gap:10px; margin-top:4px;">
            <button wire:click="search"
                    wire:loading.attr="disabled"
                    wire:target="search"
                    style="background:#1B2F6E; color:#fff; font-size:13px; font-weight:600; padding:9px 22px; border-radius:8px; border:none; cursor:pointer; display:flex; align-items:center; gap:7px;">

                <!-- état normal -->
                <span wire:loading.remove wire:target="search">
                    Rechercher
                </span>

                <!-- état loading -->
                <span wire:loading wire:target="search" >
                    Chargement...
                </span>

            </button>

            @if($searched)
                <button wire:click="resetFilters"
                        style="background:#f3f4f6; color:#374151; font-size:13px; font-weight:500; padding:9px 18px; border-radius:8px; border:1px solid #e5e7eb; cursor:pointer;">
                    Réinitialiser
                </button>
            @endif
        </div>
    </div>

    {{-- ÉTAT INITIAL --}}
    @if(!$searched)
        <div style="text-align:center; padding:60px 20px; background:#fff; border:1px solid #e5e7eb; border-radius:10px;">
            <div style="width:52px; height:52px; background:#E8ECF8; border-radius:14px; display:flex; align-items:center; justify-content:center; margin:0 auto 14px;">
                <svg width="26" height="26" viewBox="0 0 24 24" fill="none">
                    <rect x="3" y="5" width="18" height="14" rx="2" stroke="#1B2F6E" stroke-width="1.5"/>
                    <path d="M7 10h10M7 14h6" stroke="#1B2F6E" stroke-width="1.5" stroke-linecap="round"/>
                </svg>
            </div>
            <p style="font-size:14px; font-weight:600; color:#111827; margin-bottom:4px;">Sélectionnez une date</p>
            <p style="font-size:12px; color:#9ca3af;">Choisissez une date puis cliquez sur <strong>Rechercher</strong>.</p>
        </div>

    {{-- RÉSULTATS --}}
    @else

        {{-- KPIs --}}
        <div style="display:grid; grid-template-columns:repeat(4, minmax(0,1fr)); gap:12px; margin-bottom:16px;">
            <div style="background:#fff; border:1px solid #e5e7eb; border-radius:10px; padding:14px; border-top:3px solid #1B2F6E;">
                <p style="font-size:11px; color:#6b7280; margin:0 0 6px;">Total comptes</p>
                <p style="font-size:22px; font-weight:700; color:#111827; margin:0;">
                    {{ number_format($kpis['total_comptes'], 0, ',', ' ') }}
                </p>
                <p style="font-size:10px; color:#9ca3af; margin:3px 0 0;">{{ $date }}</p>
            </div>
            <div style="background:#fff; border:1px solid #e5e7eb; border-radius:10px; padding:14px; border-top:3px solid #FFC72C;">
                <p style="font-size:11px; color:#6b7280; margin:0 0 6px;">Balance totale</p>
                <p style="font-size:22px; font-weight:700; color:#111827; margin:0;">
                    {{ number_format($kpis['total_balance'], 0, ',', ' ') }}
                </p>
                <p style="font-size:10px; color:#9ca3af; margin:3px 0 0;">DJF</p>
            </div>
            <div style="background:#fff; border:1px solid #e5e7eb; border-radius:10px; padding:14px; border-top:3px solid #378ADD;">
                <p style="font-size:11px; color:#6b7280; margin:0 0 6px;">Reserved balance</p>
                <p style="font-size:22px; font-weight:700; color:#111827; margin:0;">
                    {{ number_format($kpis['total_reserved'], 0, ',', ' ') }}
                </p>
                <p style="font-size:10px; color:#9ca3af; margin:3px 0 0;">DJF</p>
            </div>
            <div style="background:#fff; border:1px solid #e5e7eb; border-radius:10px; padding:14px; border-top:3px solid #E24B4A;">
                <p style="font-size:11px; color:#6b7280; margin:0 0 6px;">Unclear balance</p>
                <p style="font-size:22px; font-weight:700; color:#111827; margin:0;">
                    {{ number_format($kpis['total_unclear'], 0, ',', ' ') }}
                </p>
                <p style="font-size:10px; color:#9ca3af; margin:3px 0 0;">DJF</p>
            </div>
        </div>

        {{-- TABLEAU --}}
        <div style="background:#fff; border:1px solid #e5e7eb; border-radius:10px; overflow:hidden;">

            <div style="padding:12px 16px; border-bottom:1px solid #e5e7eb; display:flex; align-items:center; justify-content:space-between;">
                <p style="font-size:13px; font-weight:600; color:#111827; margin:0;">Résultats</p>
                <span style="background:#E8ECF8; color:#1B2F6E; font-size:11px; font-weight:600; padding:3px 10px; border-radius:20px;">
                    {{ $accounts->total() }} compte(s)
                </span>
            </div>

            <div style="overflow-x:auto; overflow-y:auto; max-height:600px;">
                <table style="width:100%; border-collapse:collapse; font-size:12px;">
                    <thead>
                        <tr style="background:#F7F8FC;">
                            <th style="padding:10px 12px; text-align:left; color:#6b7280; font-weight:500; border-bottom:1px solid #e5e7eb; white-space:nowrap; position:sticky; top:0; background:#F7F8FC; z-index:1;">#</th>
                            <th style="padding:10px 12px; text-align:left; color:#6b7280; font-weight:500; border-bottom:1px solid #e5e7eb; white-space:nowrap; position:sticky; top:0; background:#F7F8FC; z-index:1;">Date</th>
                            <th style="padding:10px 12px; text-align:left; color:#6b7280; font-weight:500; border-bottom:1px solid #e5e7eb; white-space:nowrap; position:sticky; top:0; background:#F7F8FC; z-index:1;">Account No</th>
                            <th style="padding:10px 12px; text-align:left; color:#6b7280; font-weight:500; border-bottom:1px solid #e5e7eb; white-space:nowrap; position:sticky; top:0; background:#F7F8FC; z-index:1;">Alias</th>
                            <th style="padding:10px 12px; text-align:left; color:#6b7280; font-weight:500; border-bottom:1px solid #e5e7eb; white-space:nowrap; position:sticky; top:0; background:#F7F8FC; z-index:1;">Type</th>
                            <th style="padding:10px 12px; text-align:left; color:#6b7280; font-weight:500; border-bottom:1px solid #e5e7eb; white-space:nowrap; position:sticky; top:0; background:#F7F8FC; z-index:1;">Identity Type</th>
                            <th style="padding:10px 12px; text-align:left; color:#6b7280; font-weight:500; border-bottom:1px solid #e5e7eb; white-space:nowrap; position:sticky; top:0; background:#F7F8FC; z-index:1;">Value Type</th>
                            <th style="padding:10px 12px; text-align:left; color:#6b7280; font-weight:500; border-bottom:1px solid #e5e7eb; white-space:nowrap; position:sticky; top:0; background:#F7F8FC; z-index:1;">Currency</th>
                            <th style="padding:10px 12px; text-align:right; color:#6b7280; font-weight:500; border-bottom:1px solid #e5e7eb; white-space:nowrap; position:sticky; top:0; background:#F7F8FC; z-index:1;">Balance</th>
                            <th style="padding:10px 12px; text-align:right; color:#6b7280; font-weight:500; border-bottom:1px solid #e5e7eb; white-space:nowrap; position:sticky; top:0; background:#F7F8FC; z-index:1;">Reserved</th>
                            <th style="padding:10px 12px; text-align:right; color:#6b7280; font-weight:500; border-bottom:1px solid #e5e7eb; white-space:nowrap; position:sticky; top:0; background:#F7F8FC; z-index:1;">Unclear</th>
                            <th style="padding:10px 12px; text-align:left; color:#6b7280; font-weight:500; border-bottom:1px solid #e5e7eb; white-space:nowrap; position:sticky; top:0; background:#F7F8FC; z-index:1;">Statut</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($accounts as $acc)
                            <tr style="border-bottom:1px solid #f3f4f6;"
                                onmouseover="this.style.background='#F7F8FC'"
                                onmouseout="this.style.background='transparent'">

                                <td style="padding:10px 12px; color:#9ca3af;">
                                    {{ $accounts->firstItem() + $loop->index }}
                                </td>

                                <td style="padding:10px 12px; color:#6b7280; white-space:nowrap;">
                                    {{ $acc->data_date ? \Carbon\Carbon::parse($acc->data_date)->format('d/m/Y') : '—' }}
                                </td>

                                <td style="padding:10px 12px; font-family:monospace; font-size:11px; color:#374151; white-space:nowrap;">
                                    {{ $acc->account_no ?? '—' }}
                                </td>

                                <td style="padding:10px 12px; font-weight:600; color:#111827; white-space:nowrap;">
                                    {{ $acc->alias ?? '—' }}
                                </td>

                                <td style="padding:10px 12px;">
                                    @if($acc->account_type_id)
                                        <span style="background:#E8ECF8; color:#1B2F6E; font-size:10px; font-weight:600; padding:2px 8px; border-radius:12px; white-space:nowrap;">
                                            {{ $acc->account_type_id }}
                                        </span>
                                    @else
                                        <span style="color:#9ca3af;">—</span>
                                    @endif
                                </td>

                                <td style="padding:10px 12px; color:#374151; white-space:nowrap;">
                                    
                                    
                                    @php $at = $acc->identity_type; @endphp
                                    @if(in_array($at, ['8000', 'SP', 'SP']))
                                        <span style="background:#E5F5ED; color:#005C2B; font-size:10px; font-weight:600; padding:2px 8px; border-radius:12px;">SP</span>
                                    @elseif(in_array($st, ['9000', 'BANK', 'Bank']))
                                        <span style="background:#FFF3D0; color:#7A4F00; font-size:10px; font-weight:600; padding:2px 8px; border-radius:12px;">Bank</span>
                                    @elseif(in_array($st, ['5000', 'ORG', 'Org']))
                                        <span style="background:#FDECEA; color:#7F1D1D; font-size:10px; font-weight:600; padding:2px 8px; border-radius:12px;">Org</span>
                                    @else
                                        <span style="background:#f3f4f6; color:#6b7280; font-size:10px; font-weight:600; padding:2px 8px; border-radius:12px;">Customer</span>
                                    @endif
                                </td>

                                <td style="padding:10px 12px; color:#374151; white-space:nowrap;">
                                    {{ $acc->value_type ?? '—' }}
                                </td>

                                <td style="padding:10px 12px; text-align:center;">
                                    <span style="background:#f3f4f6; color:#374151; font-size:10px; font-weight:600; padding:2px 8px; border-radius:12px;">
                                        {{ $acc->currency ?? '—' }}
                                    </span>
                                </td>

                                <td style="padding:10px 12px; text-align:right; font-weight:600; color:#111827; white-space:nowrap;">
                                    {{ number_format($acc->balance, 2, ',', ' ') }}
                                </td>

                                <td style="padding:10px 12px; text-align:right; color:#378ADD; white-space:nowrap;">
                                    {{ number_format($acc->reserved_balance, 2, ',', ' ') }}
                                </td>

                                <td style="padding:10px 12px; text-align:right; color:#E24B4A; white-space:nowrap;">
                                    {{ number_format($acc->unclear_balance, 2, ',', ' ') }}
                                </td>

                                <td style="padding:10px 12px;">
                                    @php $st = $acc->account_status; @endphp
                                    @if(in_array($st, ['1002', 'ACTIVE', 'Active']))
                                        <span style="background:#E5F5ED; color:#005C2B; font-size:10px; font-weight:600; padding:2px 8px; border-radius:12px;">Active</span>
                                    @elseif(in_array($st, ['2', 'FROZEN', 'Frozen']))
                                        <span style="background:#FFF3D0; color:#7A4F00; font-size:10px; font-weight:600; padding:2px 8px; border-radius:12px;">Frozen</span>
                                    @elseif(in_array($st, ['3', 'CLOSED', 'Closed']))
                                        <span style="background:#FDECEA; color:#7F1D1D; font-size:10px; font-weight:600; padding:2px 8px; border-radius:12px;">Closed</span>
                                    @else
                                        <span style="background:#f3f4f6; color:#6b7280; font-size:10px; font-weight:600; padding:2px 8px; border-radius:12px;">{{ $st ?? '—' }}</span>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="12" style="padding:40px; text-align:center; color:#9ca3af; font-size:13px;">
                                    Aucun compte trouvé pour cette date.
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            {{-- PAGINATION --}}
            @if($accounts->hasPages())
                <div style="padding:12px 16px; border-top:1px solid #e5e7eb;">
                    {{ $accounts->links() }}
                </div>
            @endif
        </div>
    @endif

    <style>
        @keyframes spin { from { transform:rotate(0deg); } to { transform:rotate(360deg); } }
    </style>
</div>