<?php

use App\Exports\SimpleArrayExport;
use App\Support\Network;
use Illuminate\Support\Facades\DB;
use Livewire\Volt\Component;
use Livewire\WithPagination;
use Maatwebsite\Excel\Facades\Excel;

/**
 * Réseaux : organisations de tête qui ont des caisses, avec l'activité et la
 * performance cumulées de tout le réseau (tête + caisses) sur la période.
 */
new class extends Component {

    use WithPagination;

    public int    $days     = 30;
    public string $search   = '';
    public string $sort     = 'value';    // value | caisses | inactive
    public ?string $openId  = null;

    public function updated($property): void
    {
        if (in_array($property, ['days', 'search', 'sort'])) {
            $this->resetPage();
        }
    }

    public function toggle(string $id): void
    {
        $this->openId = $this->openId === $id ? null : $id;
    }

    private function period(): array
    {
        $ref = Network::referenceDate();
        return [$ref->copy()->subDays(max(1, $this->days) - 1)->toDateString(), $ref->toDateString()];
    }

    /** Performance par organisation sur la période */
    private function perfSub()
    {
        [$from, $to] = $this->period();

        return DB::table('org_daily_activity')->whereBetween('activity_date', [$from, $to])
            ->groupBy('org_id')
            ->selectRaw('org_id, SUM(txn_count) AS nb, SUM(txn_amount) AS val, SUM(cashin_amount) AS ci, SUM(cashout_amount) AS co, SUM(merchant_amount) AS mp, SUM(commission_amount) AS com');
    }

    private function networksQuery()
    {
        $activity = Network::activitySql();

        $q = DB::table('kyc.org_master as m')
            ->join('kyc.org_master as t', 't.unique_system_id', '=', 'm.top_org_id')
            ->leftJoin('org_last_activity as la', 'la.org_id', '=', 'm.short_code')
            ->leftJoinSub($this->perfSub(), 'p', 'p.org_id', '=', 'm.short_code')
            ->whereNotNull('m.top_org_id')
            ->groupBy('t.unique_system_id', 't.org_name', 't.short_code', 't.segment')
            ->havingRaw('COUNT(*) FILTER (WHERE m.is_top_org = false) > 0')
            ->selectRaw("t.unique_system_id, t.org_name, t.short_code, t.segment,
                COUNT(*) FILTER (WHERE m.is_top_org = false) AS caisses,
                COUNT(*) FILTER (WHERE m.is_top_org = false AND {$activity} = 'active') AS active,
                COUNT(*) FILTER (WHERE m.is_top_org = false AND {$activity} = 'dormant') AS dormant,
                COUNT(*) FILTER (WHERE m.is_top_org = false AND {$activity} IN ('inactive', 'never')) AS inactive,
                COALESCE(SUM(p.nb), 0) AS nb, COALESCE(SUM(p.val), 0) AS val, COALESCE(SUM(p.ci), 0) AS ci,
                COALESCE(SUM(p.co), 0) AS co, COALESCE(SUM(p.mp), 0) AS mp, COALESCE(SUM(p.com), 0) AS com")
            ->when($this->search !== '', fn ($q) => $q->where(fn ($w) => $w->where('t.org_name', 'ilike', "%{$this->search}%")->orWhere('t.short_code', 'like', "{$this->search}%")));

        return match ($this->sort) {
            'caisses'  => $q->orderByDesc('caisses'),
            'inactive' => $q->orderByDesc('inactive'),
            default    => $q->orderByDesc('val'),
        };
    }

    public function export()
    {
        abort_unless(auth()->user()->can('network.export'), 403);

        $rows = $this->networksQuery()->get()->map(fn ($n) => [
            (string) $n->short_code, (string) $n->org_name, (string) $n->segment, (int) $n->caisses, (int) $n->active, (int) $n->dormant, (int) $n->inactive,
            (int) $n->nb, (float) $n->val, (float) $n->ci, (float) $n->co, (float) $n->mp, (float) $n->com,
        ])->all();

        [$from, $to] = $this->period();

        return Excel::download(new SimpleArrayExport([
            'Shortcode', 'Réseau', 'Segment', 'Caisses', 'Caisses actives', 'Caisses dormantes', 'Caisses inactives / jamais',
            "Nb transactions", 'Valeur (FDJ)', 'Cash-in (FDJ)', 'Cash-out (FDJ)', 'Paiements marchands (FDJ)', 'Commission (FDJ)',
        ], $rows, "Réseaux {$from} {$to}"), 'reseaux_' . now()->format('Ymd_His') . '.xlsx');
    }

    public function with(): array
    {
        [$from, $to] = $this->period();

        $caisses = collect();
        if ($this->openId) {
            $caisses = Network::organizations()
                ->leftJoinSub($this->perfSub(), 'p', 'p.org_id', '=', 'm.short_code')
                ->addSelect('p.nb', 'p.val', 'p.ci', 'p.co', 'p.mp', 'p.com')
                ->where('m.top_org_id', $this->openId)
                ->where('m.is_top_org', false)
                ->orderBy('m.hierarchy_level')->orderByRaw('COALESCE(p.val, 0) DESC')
                ->get();
        }

        return [
            'networks' => $this->networksQuery()->paginate(25),
            'caisses'  => $caisses,
            'from'     => $from,
            'to'       => $to,
        ];
    }
};
?>
<div style="padding:24px;">

@php
    $input = 'border:1px solid #d1d5db; border-radius:7px; padding:7px 10px; font-size:12px; color:#111827; outline:none; background:#fff;';
    $fdj   = fn ($v) => number_format((float) $v, 0, ',', ' ');
    $int   = fn ($v) => number_format((int) $v, 0, ',', ' ');
    $badge = function ($activity) {
        [$l, $bg, $c] = \App\Support\Network::ACTIVITY[$activity] ?? [$activity, '#F3F4F6', '#374151'];
        return "<span style=\"background:{$bg}; color:{$c}; font-size:10px; font-weight:600; padding:2px 8px; border-radius:12px; white-space:nowrap;\">{$l}</span>";
    };
@endphp

    <x-network-tabs active="network.networks" />

    <div style="background:#fff; border:1px solid #e5e7eb; border-radius:10px; padding:14px 16px; margin-bottom:14px; display:flex; gap:12px; flex-wrap:wrap; align-items:flex-end;">
        <div>
            <label style="font-size:11px; color:#6b7280; display:block; margin-bottom:4px;">Réseau</label>
            <input type="text" wire:model.live.debounce.400ms="search" placeholder="Nom ou shortcode" style="{{ $input }} width:200px;">
        </div>
        <div>
            <label style="font-size:11px; color:#6b7280; display:block; margin-bottom:4px;">Période</label>
            <select wire:model.live="days" style="{{ $input }}">
                <option value="7">7 derniers jours</option>
                <option value="30">30 derniers jours</option>
                <option value="90">90 derniers jours</option>
            </select>
        </div>
        <div>
            <label style="font-size:11px; color:#6b7280; display:block; margin-bottom:4px;">Trier par</label>
            <select wire:model.live="sort" style="{{ $input }}">
                <option value="value">Valeur des transactions</option>
                <option value="caisses">Nombre de caisses</option>
                <option value="inactive">Caisses inactives</option>
            </select>
        </div>
        <p style="font-size:11px; color:#9ca3af; margin:0 0 8px;">Du {{ \Carbon\Carbon::parse($from)->format('d/m/Y') }} au {{ \Carbon\Carbon::parse($to)->format('d/m/Y') }} · performance cumulée de l'organisation de tête et de ses caisses</p>
        @can('network.export')
            <button wire:click="export" style="background:#fff; color:#1B2F6E; font-size:12px; font-weight:600; padding:8px 14px; border-radius:7px; border:1px solid #1B2F6E; cursor:pointer; margin-left:auto;">⬇ Exporter (Excel)</button>
        @endcan
    </div>

    <div style="background:#fff; border:1px solid #e5e7eb; border-radius:10px; overflow:hidden;">
        <div style="overflow-x:auto;">
            <table style="width:100%; border-collapse:collapse; font-size:12px;">
                <thead>
                    <tr style="background:#F7F8FC;">
                        @foreach(['', 'Réseau', 'Segment', 'Caisses', 'Actives', 'Dormantes', 'Inactives', 'Transactions', 'Valeur (FDJ)', 'Cash-in', 'Cash-out', 'Commission'] as $i => $th)
                            <th style="padding:9px 12px; text-align:{{ $i > 2 ? 'right' : 'left' }}; color:#6b7280; font-weight:500; border-bottom:1px solid #e5e7eb; white-space:nowrap;">{{ $th }}</th>
                        @endforeach
                    </tr>
                </thead>
                <tbody>
                    @forelse($networks as $n)
                        <tr wire:key="net-{{ $n->unique_system_id }}" wire:click="toggle('{{ $n->unique_system_id }}')" style="border-bottom:1px solid #f3f4f6; cursor:pointer; {{ $openId === $n->unique_system_id ? 'background:#F0F4FF;' : '' }}">
                            <td style="padding:8px 12px; color:#9ca3af;">{{ $openId === $n->unique_system_id ? '▾' : '▸' }}</td>
                            <td style="padding:8px 12px;"><strong style="color:#111827;">{{ $n->org_name }}</strong> <span style="font-family:monospace; color:#9ca3af; font-size:11px;">{{ $n->short_code }}</span></td>
                            <td style="padding:8px 12px; color:#6b7280;">{{ $n->segment ?? '—' }}</td>
                            <td style="padding:8px 12px; text-align:right; color:#111827; font-weight:600;">{{ $int($n->caisses) }}</td>
                            <td style="padding:8px 12px; text-align:right; color:#005C2B;">{{ $int($n->active) }}</td>
                            <td style="padding:8px 12px; text-align:right; color:#92400E;">{{ $int($n->dormant) }}</td>
                            <td style="padding:8px 12px; text-align:right; color:#7F1D1D;">{{ $int($n->inactive) }}</td>
                            <td style="padding:8px 12px; text-align:right; color:#374151;">{{ $int($n->nb) }}</td>
                            <td style="padding:8px 12px; text-align:right; color:#111827; font-weight:600;">{{ $fdj($n->val) }}</td>
                            <td style="padding:8px 12px; text-align:right; color:#374151;">{{ $fdj($n->ci) }}</td>
                            <td style="padding:8px 12px; text-align:right; color:#374151;">{{ $fdj($n->co) }}</td>
                            <td style="padding:8px 12px; text-align:right; color:#374151;">{{ $fdj($n->com) }}</td>
                        </tr>

                        @if($openId === $n->unique_system_id)
                            <tr>
                                <td colspan="12" style="background:#FAFBFC; padding:10px 16px 14px 40px;">
                                    <table style="width:100%; border-collapse:collapse; font-size:12px;">
                                        <thead>
                                            <tr>
                                                @foreach(['Caisse', 'Niveau', 'Parent', 'Activité', 'Dernière transaction', 'Transactions', 'Valeur (FDJ)', 'Cash-in', 'Cash-out'] as $i => $th)
                                                    <th style="padding:6px 8px; text-align:{{ $i > 4 ? 'right' : 'left' }}; color:#6b7280; font-weight:500; border-bottom:1px solid #e5e7eb;">{{ $th }}</th>
                                                @endforeach
                                            </tr>
                                        </thead>
                                        <tbody>
                                            @foreach($caisses as $c)
                                                <tr style="border-bottom:1px solid #f3f4f6;">
                                                    <td style="padding:6px 8px; padding-left:{{ 8 + ($c->hierarchy_level - 1) * 18 }}px;">
                                                        <a href="{{ route('network.directory', ['org' => $c->unique_system_id]) }}" wire:navigate style="color:#1B2F6E; font-weight:600; text-decoration:none;">{{ $c->org_name }}</a>
                                                        <span style="font-family:monospace; color:#9ca3af; font-size:11px;">{{ $c->short_code }}</span>
                                                    </td>
                                                    <td style="padding:6px 8px; color:#6b7280;">{{ $c->hierarchy_level }}</td>
                                                    <td style="padding:6px 8px; color:#6b7280;">{{ $c->hierarchy_level > 1 ? $c->parent_name : '—' }}</td>
                                                    <td style="padding:6px 8px;">{!! $badge($c->activity) !!}</td>
                                                    <td style="padding:6px 8px; color:#6b7280; white-space:nowrap;">{{ $c->last_txn_at ? \Carbon\Carbon::parse($c->last_txn_at)->format('d/m/Y') : '—' }}</td>
                                                    <td style="padding:6px 8px; text-align:right; color:#374151;">{{ $int($c->nb ?? 0) }}</td>
                                                    <td style="padding:6px 8px; text-align:right; color:#111827; font-weight:600;">{{ $fdj($c->val ?? 0) }}</td>
                                                    <td style="padding:6px 8px; text-align:right; color:#374151;">{{ $fdj($c->ci ?? 0) }}</td>
                                                    <td style="padding:6px 8px; text-align:right; color:#374151;">{{ $fdj($c->co ?? 0) }}</td>
                                                </tr>
                                            @endforeach
                                        </tbody>
                                    </table>
                                </td>
                            </tr>
                        @endif
                    @empty
                        <tr><td colspan="12" style="padding:24px; text-align:center; color:#9ca3af;">Aucun réseau trouvé.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <div style="padding:10px 16px; border-top:1px solid #e5e7eb;">{{ $networks->links() }}</div>
    </div>

</div>
