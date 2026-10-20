<?php

use App\Exports\SimpleArrayExport;
use App\Support\Network;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Url;
use Livewire\Volt\Component;
use Livewire\WithPagination;
use Maatwebsite\Excel\Facades\Excel;

/**
 * Contrôles du réseau : listes d'organisations à traiter, chacune exportable.
 */
new class extends Component {

    use WithPagination;

    #[Url] public string $check   = 'reactivate';
    public string $top_org = '';
    public string $segment = '';

    public const CHECKS = [
        'reactivate'    => ['À réactiver', 'Organisations dormantes ou inactives (déjà actives par le passé), de la plus récemment active à la plus ancienne.'],
        'new_inactive'  => ['Nouvelles sans transaction', 'Inscrites récemment (seuil « nouvelle organisation ») et n\'ayant encore fait aucune transaction.'],
        'never'         => ['Jamais actives', 'Inscrites depuis plus longtemps, sans aucune transaction depuis le début de l\'historique (19/08/2024).'],
        'blocked_txn'   => ['Suspendues / gelées actives', 'Statut Suspended ou Frozen, mais avec des transactions sur la période « active » : anomalie à signaler à la conformité.'],
        'no_main'       => ['Compte principal non actif', 'Organisations actives dont le compte principal est gelé, suspendu ou absent.'],
        'parent_missing'=> ['Parent introuvable', 'Le parent indiqué n\'existe pas dans le référentiel.'],
    ];

    public function updated($property): void
    {
        if (in_array($property, ['top_org', 'segment'])) {
            $this->resetPage();
        }
    }

    public function setCheck(string $check): void
    {
        $this->check = array_key_exists($check, self::CHECKS) ? $check : 'reactivate';
        $this->resetPage();
    }

    private function query(string $check)
    {
        $t   = Network::thresholds();
        $ref = Network::referenceDate();
        $act = Network::activitySql();

        $q = Network::organizations()
            ->when($this->top_org !== '', fn ($q) => $q->where('m.top_org_id', $this->top_org))
            ->when($this->segment !== '', fn ($q) => $q->where('m.segment', $this->segment));

        $newSince = $ref->copy()->subDays($t['new_org_days'])->toDateString();

        return match ($check) {
            'reactivate'     => $q->whereRaw("{$act} IN ('dormant', 'inactive')")->orderByDesc('la.last_activity_date'),
            'new_inactive'   => $q->whereNull('la.org_id')->where('m.registered_at', '>=', $newSince)->orderByDesc('m.registered_at'),
            'never'          => $q->whereNull('la.org_id')->where('m.registered_at', '<', $newSince)->orderByDesc('m.registered_at'),
            'blocked_txn'    => $q->whereIn('m.identity_status', ['Suspended', 'Frozen'])
                ->where('la.last_activity_date', '>=', $ref->copy()->subDays($t['active_days'])->toDateString())
                ->orderByDesc('la.last_txn_at'),
            'no_main'        => $q->where('m.identity_status', 'Active')
                ->whereNotExists(function ($s) {
                    // Compte principal actif : statut le plus récent (org_account_current) sinon org_accounts
                    $s->from('kyc.org_accounts as oa')
                      ->leftJoin('kyc.org_account_current as cur', function ($j) {
                          $j->on('cur.unique_system_id', '=', 'oa.unique_system_id')->on('cur.account_type', '=', 'oa.account_type');
                      })
                      ->whereColumn('oa.unique_system_id', 'm.unique_system_id')
                      ->where('oa.account_type', 'Org Main Account')
                      ->whereRaw("COALESCE(cur.account_status, oa.account_status) = 'Active'");
                })
                ->orderBy('m.org_name'),
            'parent_missing' => $q->where('m.parent_missing', true)->orderBy('m.org_name'),
        };
    }

    public function export()
    {
        abort_unless(auth()->user()->can('network.export'), 403);

        $rows = $this->query($this->check)->get()->map(fn ($o) => [
            (string) $o->short_code, (string) $o->org_name, (string) $o->segment, (string) $o->identity_status,
            Network::ACTIVITY[$o->activity][0] ?? $o->activity,
            $o->last_txn_at ? Carbon::parse($o->last_txn_at)->format('d/m/Y H:i') : '',
            $o->is_top_org ? '(organisation de tête)' : (string) $o->top_org_name,
            $o->registered_at ? Carbon::parse($o->registered_at)->format('d/m/Y') : '',
        ])->all();

        return Excel::download(new SimpleArrayExport(
            ['Shortcode', 'Nom', 'Segment', 'Statut', 'Activité', 'Dernière transaction', 'Réseau', 'Inscrite le'],
            $rows,
            self::CHECKS[$this->check][0]
        ), 'controle_reseau_' . $this->check . '_' . now()->format('Ymd_His') . '.xlsx');
    }

    public function with(): array
    {
        return [
            'counts'  => collect(array_keys(self::CHECKS))->mapWithKeys(fn ($c) => [$c => $this->query($c)->reorder()->count()])->all(),
            'orgs'    => $this->query($this->check)->paginate(50),
            'options' => Network::options(),
            'checks'  => self::CHECKS,
        ];
    }
};
?>
<div style="padding:24px;">

@php
    $input = 'border:1px solid #d1d5db; border-radius:7px; padding:7px 10px; font-size:12px; color:#111827; outline:none; background:#fff;';
    $badge = function ($activity) {
        [$l, $bg, $c] = \App\Support\Network::ACTIVITY[$activity] ?? [$activity, '#F3F4F6', '#374151'];
        return "<span style=\"background:{$bg}; color:{$c}; font-size:10px; font-weight:600; padding:2px 8px; border-radius:12px; white-space:nowrap;\">{$l}</span>";
    };
    $status = function ($s) {
        [$bg, $c] = \App\Support\Network::IDENTITY_STATUS[$s] ?? ['#F3F4F6', '#374151'];
        return "<span style=\"background:{$bg}; color:{$c}; font-size:10px; font-weight:600; padding:2px 8px; border-radius:12px;\">" . e($s) . '</span>';
    };
@endphp

    <x-network-tabs active="network.controls" />

    {{-- Contrôles disponibles --}}
    <div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(170px, 1fr)); gap:10px; margin-bottom:14px;">
        @foreach($checks as $key => [$label])
            <button wire:click="setCheck('{{ $key }}')"
                    style="text-align:left; cursor:pointer; background:{{ $check === $key ? '#E8ECF8' : '#fff' }}; border:1px solid {{ $check === $key ? '#1B2F6E' : '#e5e7eb' }}; border-radius:10px; padding:12px 14px;">
                <p style="font-size:11px; color:#6b7280; margin:0 0 4px;">{{ $label }}</p>
                <p style="font-size:20px; font-weight:700; color:{{ $counts[$key] > 0 && in_array($key, ['blocked_txn', 'no_main', 'parent_missing']) ? '#B91C1C' : '#111827' }}; margin:0;">{{ number_format($counts[$key], 0, ',', ' ') }}</p>
            </button>
        @endforeach
    </div>

    <div style="background:#fff; border:1px solid #e5e7eb; border-radius:10px; padding:12px 16px; margin-bottom:14px; display:flex; gap:12px; flex-wrap:wrap; align-items:flex-end;">
        <p style="font-size:12px; color:#374151; margin:0; flex:1; min-width:260px;"><strong>{{ $checks[$check][0] }}</strong> — {{ $checks[$check][1] }}</p>
        <div>
            <label style="font-size:11px; color:#6b7280; display:block; margin-bottom:4px;">Réseau</label>
            <select wire:model.live="top_org" style="{{ $input }} max-width:220px;">
                <option value="">Tous</option>
                @foreach($options['networks'] as $n) <option value="{{ $n['unique_system_id'] }}">{{ $n['org_name'] }}</option> @endforeach
            </select>
        </div>
        <div>
            <label style="font-size:11px; color:#6b7280; display:block; margin-bottom:4px;">Segment</label>
            <select wire:model.live="segment" style="{{ $input }}">
                <option value="">Tous</option>
                @foreach($options['segments'] as $s) <option value="{{ $s }}">{{ $s }}</option> @endforeach
            </select>
        </div>
        @can('network.export')
            <button wire:click="export" style="background:#fff; color:#1B2F6E; font-size:12px; font-weight:600; padding:8px 14px; border-radius:7px; border:1px solid #1B2F6E; cursor:pointer;">⬇ Exporter (Excel)</button>
        @endcan
    </div>

    <div style="background:#fff; border:1px solid #e5e7eb; border-radius:10px; overflow:hidden;">
        <div style="overflow-x:auto;">
            <table style="width:100%; border-collapse:collapse; font-size:12px;">
                <thead>
                    <tr style="background:#F7F8FC;">
                        @foreach(['Shortcode', 'Nom', 'Segment', 'Statut', 'Activité', 'Dernière transaction', 'Réseau', 'Inscrite le'] as $th)
                            <th style="padding:9px 12px; text-align:left; color:#6b7280; font-weight:500; border-bottom:1px solid #e5e7eb; white-space:nowrap;">{{ $th }}</th>
                        @endforeach
                    </tr>
                </thead>
                <tbody>
                    @forelse($orgs as $o)
                        <tr style="border-bottom:1px solid #f3f4f6;">
                            <td style="padding:8px 12px;"><a href="{{ route('network.directory', ['org' => $o->unique_system_id]) }}" wire:navigate style="font-family:monospace; font-weight:600; color:#1B2F6E; text-decoration:none;">{{ $o->short_code }}</a></td>
                            <td style="padding:8px 12px; color:#111827; font-weight:500;">{{ $o->org_name }}</td>
                            <td style="padding:8px 12px; color:#6b7280;">{{ $o->segment ?? '—' }}</td>
                            <td style="padding:8px 12px;">{!! $status($o->identity_status) !!}</td>
                            <td style="padding:8px 12px;">{!! $badge($o->activity) !!}</td>
                            <td style="padding:8px 12px; color:#6b7280; white-space:nowrap;">{{ $o->last_txn_at ? \Carbon\Carbon::parse($o->last_txn_at)->format('d/m/Y H:i') : '—' }}</td>
                            <td style="padding:8px 12px; color:#6b7280;">{{ $o->is_top_org ? '—' : $o->top_org_name }}</td>
                            <td style="padding:8px 12px; color:#6b7280; white-space:nowrap;">{{ $o->registered_at ? \Carbon\Carbon::parse($o->registered_at)->format('d/m/Y') : '—' }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="8" style="padding:24px; text-align:center; color:#9ca3af;">Aucune organisation : rien à traiter.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <div style="padding:10px 16px; border-top:1px solid #e5e7eb;">{{ $orgs->links() }}</div>
    </div>

</div>
