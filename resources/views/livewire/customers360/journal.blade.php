<?php

use App\Exports\SimpleArrayExport;
use App\Support\Customers;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Livewire\Volt\Component;
use Livewire\WithPagination;
use Maatwebsite\Excel\Facades\Excel;

/**
 * Journal des changements de statut des clients et de leurs comptes, avec
 * l'opérateur qui les a faits (audit : qui a gelé, suspendu, réactivé…).
 */
new class extends Component {

    use WithPagination;

    public string $operator   = '';
    public string $status     = '';
    public string $object     = '';     // client | account
    public string $msisdn     = '';
    public string $date_debut = '';
    public string $date_fin   = '';

    public function mount(): void
    {
        $this->date_debut = now()->subDays(30)->toDateString();
        $this->date_fin   = now()->toDateString();
    }

    public function updated(): void
    {
        $this->resetPage();
    }

    private function query()
    {
        $client  = DB::table('kyc.customer_status_history')->selectRaw("changed_at, msisdn, 'Client' AS objet, status, changed_by_username AS operator");
        $account = DB::table('kyc.customer_account_status_history')->selectRaw('changed_at, msisdn, account_type AS objet, status, changed_by_username AS operator');

        $union = match ($this->object) {
            'client'  => $client,
            'account' => $account,
            default   => $client->unionAll($account),
        };

        return DB::query()->fromSub($union, 'h')
            ->when($this->operator !== '', fn ($q) => $this->operator === '__none' ? $q->whereNull('operator') : $q->where('operator', $this->operator))
            ->when($this->status !== '', fn ($q) => $q->where('status', $this->status))
            ->when($this->msisdn !== '', fn ($q) => $q->where('msisdn', 'like', preg_replace('/\D/', '', $this->msisdn) . '%'))
            ->when($this->date_debut, fn ($q) => $q->where('changed_at', '>=', $this->date_debut . ' 00:00:00'))
            ->when($this->date_fin, fn ($q) => $q->where('changed_at', '<', Carbon::parse($this->date_fin)->addDay()->toDateString() . ' 00:00:00'))
            ->orderByDesc('changed_at');
    }

    public function export()
    {
        abort_unless(auth()->user()->can('customer360.export'), 403);

        $rows = $this->query()->get()->map(fn ($h) => [
            Carbon::parse($h->changed_at)->format('d/m/Y H:i:s'), (string) $h->msisdn, (string) $h->objet, (string) $h->status, (string) ($h->operator ?? ''),
        ])->all();

        return Excel::download(new SimpleArrayExport(['Date', 'MSISDN', 'Objet', 'Nouveau statut', 'Opérateur'], $rows, 'Journal des statuts'), 'journal_statuts_clients_' . now()->format('Ymd_His') . '.xlsx');
    }

    public function with(): array
    {
        $byOperator = DB::query()->fromSub($this->query()->reorder(), 'q')
            ->groupBy('operator', 'status')->selectRaw("COALESCE(operator, '—') AS operator, status, COUNT(*) AS n")
            ->orderByDesc('n')->limit(10)->get();

        return [
            'events'     => $this->query()->paginate(50),
            'byOperator' => $byOperator,
            'operators'  => DB::table('kyc.customer_status_history')->whereNotNull('changed_by_username')->distinct()->pluck('changed_by_username')
                ->merge(DB::table('kyc.customer_account_status_history')->whereNotNull('changed_by_username')->distinct()->pluck('changed_by_username'))
                ->unique()->sort()->values(),
            'statuses'   => DB::table('kyc.customer_status_history')->distinct()->pluck('status')
                ->merge(DB::table('kyc.customer_account_status_history')->distinct()->pluck('status'))->unique()->sort()->values(),
            'firstEvent' => DB::table('kyc.customer_status_history')->min('changed_at'),
        ];
    }
};
?>
<div style="padding:24px;">

@php
    $input  = 'border:1px solid #d1d5db; border-radius:7px; padding:7px 10px; font-size:12px; color:#111827; outline:none; background:#fff;';
    $label  = 'font-size:11px; color:#6b7280; display:block; margin-bottom:4px;';
    $status = function ($s) { [$bg, $c] = \App\Support\Customers::STATUS[$s] ?? ['#F3F4F6', '#374151']; return "<span style=\"background:{$bg}; color:{$c}; font-size:10px; font-weight:600; padding:2px 8px; border-radius:12px;\">" . e($s) . '</span>'; };
@endphp

    <x-customers-tabs active="customer360.journal" />

    <p style="font-size:11px; color:#9ca3af; margin:-8px 0 12px;">Historique disponible depuis le {{ $firstEvent ? \Carbon\Carbon::parse($firstEvent)->format('d/m/Y') : '—' }} (fichiers de changements de statut de l'ETL).</p>

    <div style="background:#fff; border:1px solid #e5e7eb; border-radius:10px; padding:14px 16px; margin-bottom:14px; display:flex; gap:10px; flex-wrap:wrap; align-items:flex-end;">
        <div><label style="{{ $label }}">Du</label><input type="date" wire:model.live="date_debut" style="{{ $input }}"></div>
        <div><label style="{{ $label }}">Au</label><input type="date" wire:model.live="date_fin" style="{{ $input }}"></div>
        <div><label style="{{ $label }}">MSISDN</label><input type="text" wire:model.live.debounce.400ms="msisdn" style="{{ $input }} width:140px;"></div>
        <div>
            <label style="{{ $label }}">Objet</label>
            <select wire:model.live="object" style="{{ $input }}"><option value="">Client et comptes</option><option value="client">Client</option><option value="account">Comptes</option></select>
        </div>
        <div>
            <label style="{{ $label }}">Nouveau statut</label>
            <select wire:model.live="status" style="{{ $input }}"><option value="">Tous</option>@foreach($statuses as $s)<option value="{{ $s }}">{{ $s }}</option>@endforeach</select>
        </div>
        <div>
            <label style="{{ $label }}">Opérateur</label>
            <select wire:model.live="operator" style="{{ $input }}"><option value="">Tous</option><option value="__none">Non renseigné</option>@foreach($operators as $o)<option value="{{ $o }}">{{ $o }}</option>@endforeach</select>
        </div>
        @can('customer360.export')
            <button wire:click="export" style="background:#fff; color:#1B2F6E; font-size:12px; font-weight:600; padding:8px 14px; border-radius:7px; border:1px solid #1B2F6E; cursor:pointer; margin-left:auto;">⬇ Exporter (Excel)</button>
        @endcan
    </div>

    @if($byOperator->isNotEmpty())
        <div style="background:#fff; border:1px solid #e5e7eb; border-radius:10px; padding:12px 16px; margin-bottom:14px;">
            <p style="font-size:12px; font-weight:600; color:#111827; margin:0 0 8px;">Principaux opérateurs sur la sélection</p>
            <div style="display:flex; gap:8px; flex-wrap:wrap;">
                @foreach($byOperator as $b)
                    <span style="background:#F7F8FC; border-radius:6px; padding:5px 10px; font-size:12px;"><strong>{{ $b->operator }}</strong> → {!! $status($b->status) !!} × {{ number_format($b->n, 0, ',', ' ') }}</span>
                @endforeach
            </div>
        </div>
    @endif

    <div style="background:#fff; border:1px solid #e5e7eb; border-radius:10px; overflow:hidden;">
        <table style="width:100%; border-collapse:collapse; font-size:12px;">
            <thead><tr style="background:#F7F8FC;">
                @foreach(['Date', 'MSISDN', 'Objet', 'Nouveau statut', 'Opérateur'] as $th)
                    <th style="padding:9px 12px; text-align:left; color:#6b7280; font-weight:500; border-bottom:1px solid #e5e7eb;">{{ $th }}</th>
                @endforeach
            </tr></thead>
            <tbody>
                @forelse($events as $e)
                    <tr style="border-bottom:1px solid #f3f4f6;">
                        <td style="padding:8px 12px; color:#6b7280; white-space:nowrap;">{{ \Carbon\Carbon::parse($e->changed_at)->format('d/m/Y H:i:s') }}</td>
                        <td style="padding:8px 12px;"><a href="{{ route('customer360.directory', ['client' => $e->msisdn]) }}" wire:navigate style="font-family:monospace; font-weight:600; color:#1B2F6E; text-decoration:none;">{{ $e->msisdn }}</a></td>
                        <td style="padding:8px 12px; color:#374151;">{{ $e->objet }}</td>
                        <td style="padding:8px 12px;">{!! $status($e->status) !!}</td>
                        <td style="padding:8px 12px; color:#374151;">{{ $e->operator ?? '—' }}</td>
                    </tr>
                @empty
                    <tr><td colspan="5" style="padding:24px; text-align:center; color:#9ca3af;">Aucun changement de statut sur cette sélection.</td></tr>
                @endforelse
            </tbody>
        </table>
        <div style="padding:10px 16px; border-top:1px solid #e5e7eb;">{{ $events->links() }}</div>
    </div>

</div>
