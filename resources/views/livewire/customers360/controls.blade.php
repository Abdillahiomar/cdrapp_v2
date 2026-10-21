<?php

use App\Livewire\Concerns\HandlesCustomersExports;
use App\Support\Customers;
use Illuminate\Support\Facades\Cache;
use Livewire\Attributes\Url;
use Livewire\Volt\Component;
use Livewire\WithPagination;

new class extends Component {

    use WithPagination, HandlesCustomersExports;

    #[Url] public string $check = 'reactivate';
    public string $segment = '';

    public function updatedSegment(): void
    {
        $this->resetPage();
    }

    public function setCheck(string $check): void
    {
        $this->check = array_key_exists($check, Customers::CHECKS) ? $check : 'reactivate';
        $this->resetPage();
    }

    protected function exportFilters(): array
    {
        return ['check' => $this->check, 'segment' => $this->segment];
    }

    public function with(): array
    {
        // Compteurs mis en cache 10 min (8 requêtes sur toute la base)
        $counts = Cache::remember('customers_check_counts_' . md5($this->segment . json_encode(Customers::settings())), 600, fn () =>
            collect(array_keys(Customers::CHECKS))->mapWithKeys(fn ($c) => [
                $c => Customers::applyFilters(Customers::customers(), ['check' => $c, 'segment' => $this->segment])->reorder()->count(),
            ])->all()
        );

        $order = match ($this->check) {
            'reactivate', 'blocked_txn' => 'la.last_txn_at',
            default                     => 'c.registered_at',
        };

        return [
            'counts'    => $counts,
            'customers' => Customers::applyFilters(Customers::customers(true), $this->exportFilters())->orderByDesc($order)->paginate(50),
            'options'   => Customers::options(),
            'exports'   => $this->myCustomersExports(),
        ];
    }
};
?>
<div style="padding:24px;">

@php
    $input = 'border:1px solid #d1d5db; border-radius:7px; padding:7px 10px; font-size:12px; color:#111827; outline:none; background:#fff;';
    $badge = function ($a) { [$l, $bg, $c] = \App\Support\Customers::ACTIVITY[$a] ?? [$a, '#F3F4F6', '#374151']; return "<span style=\"background:{$bg}; color:{$c}; font-size:10px; font-weight:600; padding:2px 8px; border-radius:12px; white-space:nowrap;\">{$l}</span>"; };
    $status = function ($s) { [$bg, $c] = \App\Support\Customers::STATUS[$s] ?? ['#F3F4F6', '#374151']; return "<span style=\"background:{$bg}; color:{$c}; font-size:10px; font-weight:600; padding:2px 8px; border-radius:12px; white-space:nowrap;\">" . e($s ?? 'Absent') . '</span>'; };
    $alarm = ['blocked_txn', 'no_main', 'minor', 'removed'];
@endphp

    <x-customers-tabs active="customer360.controls" />

    @include('livewire.customers360.partials.exports', ['exports' => $exports])

    <div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(160px, 1fr)); gap:10px; margin-bottom:14px;">
        @foreach(\App\Support\Customers::CHECKS as $key => [$label])
            <button wire:click="setCheck('{{ $key }}')" style="text-align:left; cursor:pointer; background:{{ $check === $key ? '#E8ECF8' : '#fff' }}; border:1px solid {{ $check === $key ? '#1B2F6E' : '#e5e7eb' }}; border-radius:10px; padding:12px 14px;">
                <p style="font-size:11px; color:#6b7280; margin:0 0 4px;">{{ $label }}</p>
                <p style="font-size:20px; font-weight:700; color:{{ $counts[$key] > 0 && in_array($key, $alarm) ? '#B91C1C' : '#111827' }}; margin:0;">{{ number_format($counts[$key], 0, ',', ' ') }}</p>
            </button>
        @endforeach
    </div>

    <div style="background:#fff; border:1px solid #e5e7eb; border-radius:10px; padding:12px 16px; margin-bottom:14px; display:flex; gap:12px; flex-wrap:wrap; align-items:flex-end;">
        <p style="font-size:12px; color:#374151; margin:0; flex:1; min-width:260px;"><strong>{{ \App\Support\Customers::CHECKS[$check][0] }}</strong> — {{ \App\Support\Customers::CHECKS[$check][1] }}</p>
        <div>
            <label style="font-size:11px; color:#6b7280; display:block; margin-bottom:4px;">Segment</label>
            <select wire:model.live="segment" style="{{ $input }}">
                <option value="">Tous</option>
                @foreach($options['segments'] as $s) <option value="{{ $s }}">{{ $s }}</option> @endforeach
            </select>
        </div>
        @can('customer360.export')
            <select wire:model="exportType" style="{{ $input }}"><option value="excel">Excel</option><option value="csv">CSV</option></select>
            <button wire:click="queueCustomersExport" style="background:#fff; color:#1B2F6E; font-size:12px; font-weight:600; padding:8px 14px; border-radius:7px; border:1px solid #1B2F6E; cursor:pointer;">⬇ Exporter la liste</button>
        @endcan
    </div>

    <div style="background:#fff; border:1px solid #e5e7eb; border-radius:10px; overflow:hidden;">
        <div style="overflow-x:auto;">
            <table style="width:100%; border-collapse:collapse; font-size:12px;">
                <thead><tr style="background:#F7F8FC;">
                    @foreach(['MSISDN', 'Segment', 'Statut', 'Compte principal', 'Activité', 'Dernière transaction', 'Inscrit le', 'Activé le', 'Pièce', 'Naissance'] as $th)
                        <th style="padding:9px 12px; text-align:left; color:#6b7280; font-weight:500; border-bottom:1px solid #e5e7eb; white-space:nowrap;">{{ $th }}</th>
                    @endforeach
                </tr></thead>
                <tbody>
                    @forelse($customers as $c)
                        <tr style="border-bottom:1px solid #f3f4f6;">
                            <td style="padding:8px 12px;"><a href="{{ route('customer360.directory', ['client' => $c->msisdn]) }}" wire:navigate style="font-family:monospace; font-weight:600; color:#1B2F6E; text-decoration:none;">{{ $c->msisdn }}</a></td>
                            <td style="padding:8px 12px; color:#6b7280;">{{ $c->segment }}</td>
                            <td style="padding:8px 12px;">{!! $status($c->identity_status) !!}</td>
                            <td style="padding:8px 12px;">{!! $status($c->main_account_status) !!}</td>
                            <td style="padding:8px 12px;">{!! $badge($c->activity) !!}</td>
                            <td style="padding:8px 12px; color:#6b7280; white-space:nowrap;">{{ $c->last_txn_at ? \Carbon\Carbon::parse($c->last_txn_at)->format('d/m/Y H:i') : '—' }}</td>
                            <td style="padding:8px 12px; color:#6b7280; white-space:nowrap;">{{ \Carbon\Carbon::parse($c->registered_at)->format('d/m/Y') }}</td>
                            <td style="padding:8px 12px; color:#6b7280; white-space:nowrap;">{{ $c->activated_at ? \Carbon\Carbon::parse($c->activated_at)->format('d/m/Y') : '—' }}</td>
                            <td style="padding:8px 12px; color:#6b7280;">{{ $c->id_type ?? '—' }}</td>
                            <td style="padding:8px 12px; color:#6b7280; white-space:nowrap;">{{ $c->date_of_birth ? \Carbon\Carbon::parse($c->date_of_birth)->format('d/m/Y') : '—' }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="10" style="padding:24px; text-align:center; color:#9ca3af;">Aucun client : rien à traiter.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <div style="padding:10px 16px; border-top:1px solid #e5e7eb;">{{ $customers->links() }}</div>
    </div>

</div>
