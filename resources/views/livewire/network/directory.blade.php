<?php

use App\Exports\SimpleArrayExport;
use App\Support\Network;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Url;
use Livewire\Volt\Component;
use Livewire\WithPagination;
use Maatwebsite\Excel\Facades\Excel;

new class extends Component {

    use WithPagination;

    // Filtres (dans l'URL : liens depuis le tableau de bord et les autres pages)
    #[Url] public string $search          = '';
    #[Url] public string $segment         = '';
    #[Url] public string $product         = '';
    #[Url] public string $charge_profile  = '';
    #[Url] public string $identity_status = '';
    #[Url] public string $activity        = '';
    #[Url] public string $top_org         = '';
    #[Url] public string $level           = '';
    #[Url] public string $year            = '';

    /** Organisation affichée dans la fiche (unique_system_id) */
    #[Url] public string $org = '';

    private const FILTERS = ['search', 'segment', 'product', 'charge_profile', 'identity_status', 'activity', 'top_org', 'level', 'year'];

    public function updated($property): void
    {
        if (in_array($property, self::FILTERS)) {
            $this->resetPage();
        }
    }

    public function resetFilters(): void
    {
        $this->reset(self::FILTERS);
        $this->resetPage();
    }

    public function openOrg(string $id): void
    {
        $this->org = $id;
    }

    public function closeOrg(): void
    {
        $this->org = '';
    }

    private function filters(): array
    {
        return collect(self::FILTERS)->mapWithKeys(fn ($f) => [$f => $this->{$f}])->all();
    }

    public function export()
    {
        abort_unless(auth()->user()->can('network.export'), 403);

        $ref  = Network::referenceDate();
        $from = $ref->copy()->subDays(29)->toDateString();

        // Activité des 30 derniers jours chargés, par organisation
        $activity30 = DB::table('org_daily_activity')
            ->whereBetween('activity_date', [$from, $ref->toDateString()])
            ->groupBy('org_id')
            ->selectRaw('org_id, SUM(txn_count) AS nb, SUM(txn_amount) AS val, SUM(cashin_amount) AS ci, SUM(cashout_amount) AS co, SUM(merchant_amount) AS mp, SUM(commission_amount) AS com');

        $rows = Network::applyFilters(Network::organizations(), $this->filters())
            ->leftJoinSub($activity30, 'a30', 'a30.org_id', '=', 'm.short_code')
            ->addSelect('a30.nb', 'a30.val', 'a30.ci', 'a30.co', 'a30.mp', 'a30.com')
            ->orderBy('m.org_name')
            ->get()
            ->map(fn ($o) => [
                (string) $o->short_code, (string) $o->org_name, (string) $o->segment, (string) $o->products, (string) $o->charge_profile,
                (string) $o->identity_status, Network::ACTIVITY[$o->activity][0] ?? $o->activity,
                $o->last_txn_at ? Carbon::parse($o->last_txn_at)->format('d/m/Y H:i') : '',
                $o->is_top_org ? '(organisation de tête)' : (string) $o->top_org_name,
                $o->is_top_org ? '' : (string) $o->parent_name,
                (int) $o->hierarchy_level,
                $o->registered_at ? Carbon::parse($o->registered_at)->format('d/m/Y') : '',
                (int) ($o->nb ?? 0), (float) ($o->val ?? 0), (float) ($o->ci ?? 0), (float) ($o->co ?? 0), (float) ($o->mp ?? 0), (float) ($o->com ?? 0),
            ])
            ->all();

        return Excel::download(new SimpleArrayExport([
            'Shortcode', 'Nom', 'Segment', 'Produit', 'Profil de frais', 'Statut', 'Activité', 'Dernière transaction', 'Réseau (organisation de tête)', 'Parent',
            'Niveau', 'Inscrite le', 'Nb transactions 30 j', 'Valeur 30 j (FDJ)', 'Cash-in 30 j (FDJ)', 'Cash-out 30 j (FDJ)', 'Paiements marchands 30 j (FDJ)', 'Commission 30 j (FDJ)',
        ], $rows, 'Réseau'), 'reseau_distribution_' . now()->format('Ymd_His') . '.xlsx');
    }

    /** Fiche complète d'une organisation */
    private function orgDetail(string $id): ?array
    {
        $m = DB::table('kyc.org_master')->where('unique_system_id', $id)->first();
        if (!$m) {
            return null;
        }

        $ref  = Network::referenceDate();
        $from = $ref->copy()->subDays(29)->toDateString();

        $children = Network::organizations()
            ->where('m.parent_id', $m->unique_system_id)
            ->where('m.unique_system_id', '<>', $m->unique_system_id)
            ->orderBy('m.org_name')
            ->get();

        // Statut courant des comptes : dernier changement connu (org_account_current) sinon org_accounts
        $current  = DB::table('kyc.org_account_current')->where('unique_system_id', $id)->get()->keyBy('account_type');
        $accounts = DB::table('kyc.org_accounts')->where('unique_system_id', $id)->orderBy('seq')->get()
            ->map(fn ($a) => [
                'type'    => $a->account_type,
                'status'  => $current[$a->account_type]->account_status ?? $a->account_status,
                'changed' => isset($current[$a->account_type]) ? Carbon::parse($current[$a->account_type]->changed_at)->format('d/m/Y H:i') . ' par ' . $current[$a->account_type]->changed_by_username : null,
                'balance' => DB::table('pca_balances')->where('party_id', $m->short_code)->where('party_type', 'Organization')->where('account_type', $a->account_type)->first(['balance', 'as_of']),
            ]);

        $history = DB::table('kyc.org_status_history')->where('unique_system_id', $id)
            ->selectRaw("changed_at, 'Organisation' AS objet, status, changed_by_username")
            ->unionAll(DB::table('kyc.org_account_status_history')->where('unique_system_id', $id)->selectRaw('changed_at, account_type AS objet, status, changed_by_username'))
            ->orderByDesc('changed_at')->limit(20)->get();

        return [
            'm'        => $m,
            'last'     => DB::table('org_last_activity')->where('org_id', $m->short_code)->first(),
            // first() et non value() : value() remplacerait la sélection et perdrait le statut calculé
            'activity' => Network::organizations()->where('m.unique_system_id', $id)->first()?->activity,
            'parent'   => $m->parent_id && $m->parent_id !== $m->unique_system_id ? DB::table('kyc.org_master')->where('unique_system_id', $m->parent_id)->first(['unique_system_id', 'org_name', 'short_code']) : null,
            'top'      => !$m->is_top_org && $m->top_org_id ? DB::table('kyc.org_master')->where('unique_system_id', $m->top_org_id)->first(['unique_system_id', 'org_name', 'short_code']) : null,
            'children' => $children,
            'accounts' => $accounts,
            'history'  => $history,
            'perf'     => DB::table('org_daily_activity')->where('org_id', $m->short_code)->whereBetween('activity_date', [$from, $ref->toDateString()])
                ->selectRaw('COUNT(*) AS days, COALESCE(SUM(txn_count), 0) AS nb, COALESCE(SUM(txn_amount), 0) AS val,
                    COALESCE(SUM(cashin_count), 0) AS ci_n, COALESCE(SUM(cashin_amount), 0) AS ci, COALESCE(SUM(cashout_count), 0) AS co_n, COALESCE(SUM(cashout_amount), 0) AS co,
                    COALESCE(SUM(merchant_count), 0) AS mp_n, COALESCE(SUM(merchant_amount), 0) AS mp, COALESCE(SUM(commission_amount), 0) AS com')->first(),
            'from'     => $from,
        ];
    }

    public function with(): array
    {
        return [
            'orgs'    => Network::applyFilters(Network::organizations(), $this->filters())->orderBy('m.org_name')->paginate(50),
            'options' => Network::options(),
            'detail'  => $this->org !== '' ? $this->orgDetail($this->org) : null,
        ];
    }
};
?>
<div style="padding:24px;">

@php
    $input = 'border:1px solid #d1d5db; border-radius:7px; padding:7px 10px; font-size:12px; color:#111827; outline:none; background:#fff;';
    $label = 'font-size:11px; color:#6b7280; display:block; margin-bottom:4px;';
    $fdj   = fn ($v) => number_format((float) $v, 0, ',', ' ');
    $badge = function ($activity) {
        [$l, $bg, $c] = \App\Support\Network::ACTIVITY[$activity] ?? [$activity, '#F3F4F6', '#374151'];
        return "<span style=\"background:{$bg}; color:{$c}; font-size:10px; font-weight:600; padding:2px 8px; border-radius:12px; white-space:nowrap;\">{$l}</span>";
    };
    $status = function ($s) {
        [$bg, $c] = \App\Support\Network::IDENTITY_STATUS[$s] ?? ['#F3F4F6', '#374151'];
        return "<span style=\"background:{$bg}; color:{$c}; font-size:10px; font-weight:600; padding:2px 8px; border-radius:12px;\">" . e($s) . '</span>';
    };
@endphp

    <x-network-tabs active="network.directory" />

    {{-- FILTRES --}}
    <div style="background:#fff; border:1px solid #e5e7eb; border-radius:10px; padding:14px 16px; margin-bottom:14px; display:flex; gap:10px; flex-wrap:wrap; align-items:flex-end;">
        <div>
            <label style="{{ $label }}">Nom ou shortcode</label>
            <input type="text" wire:model.live.debounce.400ms="search" style="{{ $input }} width:190px;">
        </div>
        @foreach([
            ['segment', 'Segment', $options['segments']],
            ['product', 'Produit', $options['products']],
            ['charge_profile', 'Profil de frais', $options['charge_profiles']],
            ['identity_status', 'Statut', $options['statuses']],
            ['year', 'Inscrite en', $options['years']],
        ] as [$field, $text, $values])
            <div>
                <label style="{{ $label }}">{{ $text }}</label>
                <select wire:model.live="{{ $field }}" style="{{ $input }} max-width:200px;">
                    <option value="">Tous</option>
                    @foreach($values as $v) <option value="{{ $v }}">{{ $v }}</option> @endforeach
                </select>
            </div>
        @endforeach
        <div>
            <label style="{{ $label }}">Activité</label>
            <select wire:model.live="activity" style="{{ $input }}">
                <option value="">Toutes</option>
                @foreach(\App\Support\Network::ACTIVITY as $k => [$l]) <option value="{{ $k }}">{{ $l }}</option> @endforeach
            </select>
        </div>
        <div>
            <label style="{{ $label }}">Réseau</label>
            <select wire:model.live="top_org" style="{{ $input }} max-width:220px;">
                <option value="">Tous</option>
                @foreach($options['networks'] as $n) <option value="{{ $n['unique_system_id'] }}">{{ $n['org_name'] }}</option> @endforeach
            </select>
        </div>
        <div>
            <label style="{{ $label }}">Niveau</label>
            <select wire:model.live="level" style="{{ $input }}">
                <option value="">Tous</option>
                <option value="0">0 — tête</option>
                <option value="1">1 — caisse</option>
                <option value="2">2 — sous-caisse</option>
            </select>
        </div>
        <button wire:click="resetFilters" style="background:#f3f4f6; color:#374151; font-size:12px; font-weight:600; padding:8px 14px; border-radius:7px; border:1px solid #d1d5db; cursor:pointer;">Réinitialiser</button>
        @can('network.export')
            <button wire:click="export" wire:loading.attr="disabled" wire:target="export" style="background:#fff; color:#1B2F6E; font-size:12px; font-weight:600; padding:8px 14px; border-radius:7px; border:1px solid #1B2F6E; cursor:pointer; margin-left:auto;">
                <span wire:loading.remove wire:target="export">⬇ Exporter (Excel)</span>
                <span wire:loading wire:target="export">Export…</span>
            </button>
        @endcan
    </div>

    {{-- LISTE --}}
    <div style="background:#fff; border:1px solid #e5e7eb; border-radius:10px; overflow:hidden;">
        <div style="padding:10px 16px; border-bottom:1px solid #e5e7eb; display:flex; justify-content:space-between; align-items:center;">
            <p style="font-size:13px; font-weight:600; color:#111827; margin:0;">Organisations</p>
            <span style="background:#E8ECF8; color:#1B2F6E; font-size:11px; font-weight:600; padding:3px 10px; border-radius:20px;">{{ number_format($orgs->total(), 0, ',', ' ') }}</span>
        </div>
        <div style="overflow-x:auto;">
            <table style="width:100%; border-collapse:collapse; font-size:12px;">
                <thead>
                    <tr style="background:#F7F8FC;">
                        @foreach(['Shortcode', 'Nom', 'Segment', 'Statut', 'Activité', 'Dernière transaction', 'Réseau', 'Niveau', 'Inscrite le'] as $th)
                            <th style="padding:9px 12px; text-align:left; color:#6b7280; font-weight:500; border-bottom:1px solid #e5e7eb; white-space:nowrap;">{{ $th }}</th>
                        @endforeach
                    </tr>
                </thead>
                <tbody>
                    @forelse($orgs as $o)
                        <tr wire:key="org-{{ $o->unique_system_id }}" wire:click="openOrg('{{ $o->unique_system_id }}')" style="border-bottom:1px solid #f3f4f6; cursor:pointer;"
                            onmouseover="this.style.background='#F7F8FC'" onmouseout="this.style.background='transparent'">
                            <td style="padding:8px 12px; font-family:monospace; font-weight:600; color:#1B2F6E;">{{ $o->short_code }}</td>
                            <td style="padding:8px 12px; color:#111827; font-weight:500;">{{ $o->org_name }}</td>
                            <td style="padding:8px 12px; color:#6b7280;">{{ $o->segment ?? '—' }}</td>
                            <td style="padding:8px 12px;">{!! $status($o->identity_status) !!}</td>
                            <td style="padding:8px 12px;">{!! $badge($o->activity) !!}</td>
                            <td style="padding:8px 12px; color:#6b7280; white-space:nowrap;">{{ $o->last_txn_at ? \Carbon\Carbon::parse($o->last_txn_at)->format('d/m/Y H:i') : '—' }}</td>
                            <td style="padding:8px 12px; color:#6b7280; max-width:200px; overflow:hidden; text-overflow:ellipsis; white-space:nowrap;">{{ $o->is_top_org ? '—' : $o->top_org_name }}</td>
                            <td style="padding:8px 12px; color:#6b7280; text-align:center;">{{ $o->hierarchy_level }}</td>
                            <td style="padding:8px 12px; color:#6b7280; white-space:nowrap;">{{ $o->registered_at ? \Carbon\Carbon::parse($o->registered_at)->format('d/m/Y') : '—' }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="9" style="padding:24px; text-align:center; color:#9ca3af;">Aucune organisation pour ces critères.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <div style="padding:10px 16px; border-top:1px solid #e5e7eb;">{{ $orgs->links() }}</div>
    </div>

    {{-- FICHE ORGANISATION --}}
    @if($detail)
        @php $m = $detail['m']; $p = $detail['perf']; @endphp
        <div style="position:fixed; inset:0; background:rgba(17,24,39,0.45); z-index:200; display:flex; justify-content:flex-end;" wire:click.self="closeOrg">
            <div style="width:min(860px, 100%); height:100%; background:#F7F8FC; overflow-y:auto; box-shadow:-8px 0 24px rgba(0,0,0,0.15);">
                <div style="background:#fff; padding:16px 22px; border-bottom:1px solid #e5e7eb; display:flex; justify-content:space-between; gap:12px; position:sticky; top:0; z-index:1;">
                    <div>
                        <p style="font-size:16px; font-weight:700; color:#111827; margin:0;">{{ $m->org_name }}</p>
                        <p style="font-size:12px; color:#6b7280; margin:3px 0 0;">
                            <span style="font-family:monospace; font-weight:600;">{{ $m->short_code }}</span> · {{ $m->segment ?? '—' }} ·
                            {!! $status($m->identity_status) !!} {!! $badge($detail['activity']) !!}
                        </p>
                    </div>
                    <button wire:click="closeOrg" style="background:none; border:none; font-size:22px; color:#9ca3af; cursor:pointer; line-height:1;">×</button>
                </div>

                <div style="padding:18px 22px; display:flex; flex-direction:column; gap:14px;">
                    {{-- Identité --}}
                    <div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(180px, 1fr)); gap:8px;">
                        @foreach([
                            ['Produit', $m->products],
                            ['Profil de frais', $m->charge_profile],
                            ['Niveau', $m->hierarchy_level . ($m->is_top_org ? ' (organisation de tête)' : '')],
                            ['Inscrite le', $m->registered_at ? \Carbon\Carbon::parse($m->registered_at)->format('d/m/Y') : '—'],
                            ['Première transaction', $detail['last'] ? \Carbon\Carbon::parse($detail['last']->first_activity_date)->format('d/m/Y') : 'aucune'],
                            ['Dernière transaction', $detail['last'] ? \Carbon\Carbon::parse($detail['last']->last_txn_at)->format('d/m/Y H:i') : 'aucune'],
                        ] as [$l, $v])
                            <div style="background:#fff; border:1px solid #e5e7eb; border-radius:8px; padding:9px 11px;">
                                <p style="font-size:10px; color:#9ca3af; margin:0 0 2px; text-transform:uppercase;">{{ $l }}</p>
                                <p style="font-size:12px; color:#111827; font-weight:600; margin:0;">{{ $v ?? '—' }}</p>
                            </div>
                        @endforeach
                    </div>

                    {{-- Activité 30 jours --}}
                    <div style="background:#fff; border:1px solid #e5e7eb; border-radius:10px; padding:14px;">
                        <p style="font-size:13px; font-weight:700; color:#111827; margin:0 0 10px;">Activité des 30 derniers jours <span style="font-weight:400; color:#9ca3af; font-size:11px;">(du {{ \Carbon\Carbon::parse($detail['from'])->format('d/m') }}, {{ $p->days }} jour(s) actif(s))</span></p>
                        <div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(150px, 1fr)); gap:8px;">
                            @foreach([
                                ['Transactions', number_format($p->nb, 0, ',', ' '), $fdj($p->val) . ' FDJ'],
                                ['Cash-in faits', number_format($p->ci_n, 0, ',', ' '), $fdj($p->ci) . ' FDJ'],
                                ['Cash-out reçus', number_format($p->co_n, 0, ',', ' '), $fdj($p->co) . ' FDJ'],
                                ['Paiements marchands', number_format($p->mp_n, 0, ',', ' '), $fdj($p->mp) . ' FDJ'],
                                ['Commission reçue', $fdj($p->com) . ' FDJ', ''],
                            ] as [$l, $v, $sub])
                                <div style="background:#F7F8FC; border-radius:8px; padding:9px 11px;">
                                    <p style="font-size:10px; color:#9ca3af; margin:0 0 2px; text-transform:uppercase;">{{ $l }}</p>
                                    <p style="font-size:14px; color:#111827; font-weight:700; margin:0;">{{ $v }}</p>
                                    @if($sub) <p style="font-size:11px; color:#6b7280; margin:2px 0 0;">{{ $sub }}</p> @endif
                                </div>
                            @endforeach
                        </div>
                    </div>

                    {{-- Hiérarchie --}}
                    <div style="background:#fff; border:1px solid #e5e7eb; border-radius:10px; padding:14px;">
                        <p style="font-size:13px; font-weight:700; color:#111827; margin:0 0 8px;">Hiérarchie</p>
                        <p style="font-size:12px; color:#374151; margin:0 0 4px;">
                            Réseau :
                            @if($detail['top']) <a wire:click="openOrg('{{ $detail['top']->unique_system_id }}')" style="color:#1B2F6E; font-weight:600; cursor:pointer;">{{ $detail['top']->org_name }} ({{ $detail['top']->short_code }})</a>
                            @else <strong>organisation de tête</strong> @endif
                        </p>
                        @if($detail['parent'] && (!$detail['top'] || $detail['parent']->unique_system_id !== $detail['top']->unique_system_id))
                            <p style="font-size:12px; color:#374151; margin:0 0 4px;">Parent : <a wire:click="openOrg('{{ $detail['parent']->unique_system_id }}')" style="color:#1B2F6E; font-weight:600; cursor:pointer;">{{ $detail['parent']->org_name }} ({{ $detail['parent']->short_code }})</a></p>
                        @endif
                        @if($m->parent_missing) <p style="font-size:12px; color:#B91C1C; margin:0 0 4px;">⚠ Parent introuvable dans le référentiel.</p> @endif

                        @if($detail['children']->isNotEmpty())
                            <p style="font-size:12px; font-weight:600; color:#111827; margin:10px 0 6px;">Caisses rattachées ({{ $detail['children']->count() }})</p>
                            <div style="max-height:260px; overflow-y:auto; border:1px solid #f3f4f6; border-radius:8px;">
                                <table style="width:100%; border-collapse:collapse; font-size:12px;">
                                    @foreach($detail['children'] as $c)
                                        <tr wire:click="openOrg('{{ $c->unique_system_id }}')" style="border-bottom:1px solid #f3f4f6; cursor:pointer;">
                                            <td style="padding:6px 10px; font-family:monospace; color:#1B2F6E; font-weight:600;">{{ $c->short_code }}</td>
                                            <td style="padding:6px 10px; color:#111827;">{{ $c->org_name }}</td>
                                            <td style="padding:6px 10px;">{!! $status($c->identity_status) !!}</td>
                                            <td style="padding:6px 10px;">{!! $badge($c->activity) !!}</td>
                                            <td style="padding:6px 10px; color:#6b7280; white-space:nowrap;">{{ $c->last_txn_at ? \Carbon\Carbon::parse($c->last_txn_at)->format('d/m/Y') : '—' }}</td>
                                        </tr>
                                    @endforeach
                                </table>
                            </div>
                        @endif
                    </div>

                    {{-- Comptes --}}
                    <div style="background:#fff; border:1px solid #e5e7eb; border-radius:10px; padding:14px;">
                        <p style="font-size:13px; font-weight:700; color:#111827; margin:0 0 8px;">Comptes</p>
                        <table style="width:100%; border-collapse:collapse; font-size:12px;">
                            @forelse($detail['accounts'] as $a)
                                <tr style="border-bottom:1px solid #f3f4f6;">
                                    <td style="padding:6px 0; color:#111827; font-weight:500;">{{ $a['type'] }}</td>
                                    <td style="padding:6px 8px;">{!! $status($a['status']) !!} @if($a['changed']) <span style="font-size:10px; color:#9ca3af;">depuis le {{ $a['changed'] }}</span> @endif</td>
                                    <td style="padding:6px 0; text-align:right; color:#111827; font-weight:600;">
                                        @if($a['balance']) {{ $fdj($a['balance']->balance) }} FDJ <span style="font-size:10px; color:#9ca3af; font-weight:400;">au {{ \Carbon\Carbon::parse($a['balance']->as_of)->format('d/m/Y') }}</span> @else <span style="color:#9ca3af; font-weight:400;">solde inconnu</span> @endif
                                    </td>
                                </tr>
                            @empty
                                <tr><td style="color:#9ca3af; padding:6px 0;">Aucun compte connu.</td></tr>
                            @endforelse
                        </table>
                    </div>

                    {{-- Historique des statuts --}}
                    @if($detail['history']->isNotEmpty())
                        <div style="background:#fff; border:1px solid #e5e7eb; border-radius:10px; padding:14px;">
                            <p style="font-size:13px; font-weight:700; color:#111827; margin:0 0 8px;">Changements de statut</p>
                            @foreach($detail['history'] as $h)
                                <p style="font-size:12px; color:#374151; margin:0 0 4px;">
                                    <span style="color:#9ca3af;">{{ \Carbon\Carbon::parse($h->changed_at)->format('d/m/Y H:i') }}</span> · {{ $h->objet }} → {!! $status($h->status) !!}
                                    <span style="color:#9ca3af;">par {{ $h->changed_by_username ?? '—' }}</span>
                                </p>
                            @endforeach
                        </div>
                    @endif
                </div>
            </div>
        </div>
    @endif

</div>
