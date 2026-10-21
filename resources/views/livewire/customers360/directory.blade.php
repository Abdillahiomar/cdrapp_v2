<?php

use App\Livewire\Concerns\HandlesCustomersExports;
use App\Support\Customers;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Url;
use Livewire\Volt\Component;
use Livewire\WithPagination;

new class extends Component {

    use WithPagination, HandlesCustomersExports;

    #[Url] public string $search          = '';
    #[Url] public string $segment         = '';
    #[Url] public string $product         = '';
    #[Url] public string $identity_status = '';
    #[Url] public string $activity        = '';
    #[Url] public string $year            = '';
    #[Url] public string $id_type         = '';
    #[Url] public string $main_account    = '';

    /** Client affiché dans la fiche (MSISDN) */
    #[Url] public string $client = '';

    private const FILTERS = ['search', 'segment', 'product', 'identity_status', 'activity', 'year', 'id_type', 'main_account'];

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

    public function openClient(string $msisdn): void
    {
        $this->client = $msisdn;
    }

    public function closeClient(): void
    {
        $this->client = '';
    }

    protected function exportFilters(): array
    {
        return collect(self::FILTERS)->mapWithKeys(fn ($f) => [$f => $this->{$f}])->all();
    }

    private function clientDetail(string $msisdn): ?array
    {
        $c = Customers::customers(true)->where('c.msisdn', $msisdn)->first();
        if (!$c) {
            return null;
        }

        $current  = DB::table('kyc.customer_account_current')->where('unique_system_id', $c->unique_system_id)->get()->keyBy('account_type');
        $accounts = DB::table('kyc.customer_accounts')->where('unique_system_id', $c->unique_system_id)->orderBy('seq')->get()->map(fn ($a) => [
            'type'    => $a->account_type,
            'status'  => $current[$a->account_type]->account_status ?? $a->account_status,
            'changed' => isset($current[$a->account_type]) ? Carbon::parse($current[$a->account_type]->changed_at)->format('d/m/Y H:i') . ' par ' . ($current[$a->account_type]->changed_by_username ?: '—') : null,
            'balance' => DB::table('pca_balances')->where('party_id', $msisdn)->where('party_type', 'Customer')->where('account_type', $a->account_type)->first(['balance', 'as_of']),
        ]);

        $history = DB::table('kyc.customer_status_history')->where('unique_system_id', $c->unique_system_id)
            ->selectRaw("changed_at, 'Client' AS objet, status, changed_by_username")
            ->unionAll(DB::table('kyc.customer_account_status_history')->where('unique_system_id', $c->unique_system_id)->selectRaw('changed_at, account_type AS objet, status, changed_by_username'))
            ->orderByDesc('changed_at')->limit(30)->get();

        $ref = Customers::referenceDate();

        return [
            'c'         => $c,
            'master'    => DB::table('kyc.customer_master')->where('unique_system_id', $c->unique_system_id)->first(),
            'identity'  => Customers::identity($msisdn),
            'accounts'  => $accounts,
            'history'   => $history,
            'months'    => DB::table('customer_monthly_activity')->where('msisdn', $msisdn)
                ->where('month', '>=', $ref->copy()->startOfMonth()->subMonths(11)->toDateString())->orderByDesc('month')->get(),
            'watchlist' => DB::table('aml_watchlist')->where('msisdn', $msisdn)->where('actif', true)->get(),
            'alerts'    => rescue(fn () => DB::table('aml_alerts as a')->join('aml_rules as r', 'r.id', '=', 'a.rule_id')->where('a.party_id', $msisdn)
                ->orderByDesc('a.last_detected_at')->limit(10)->get(['r.code', 'r.name', 'a.status', 'a.period_start', 'a.total_amount']), collect(), false),
        ];
    }

    public function with(): array
    {
        return [
            'customers' => Customers::applyFilters(Customers::customers(true), $this->exportFilters())->orderByDesc('c.registered_at')->paginate(50),
            'options'   => Customers::options(),
            'detail'    => $this->client !== '' ? $this->clientDetail($this->client) : null,
            'exports'   => $this->myCustomersExports(),
        ];
    }
};
?>
<div style="padding:24px;">

@php
    $input = 'border:1px solid #d1d5db; border-radius:7px; padding:7px 10px; font-size:12px; color:#111827; outline:none; background:#fff;';
    $label = 'font-size:11px; color:#6b7280; display:block; margin-bottom:4px;';
    $fdj   = fn ($v) => number_format((float) $v, 0, ',', ' ');
    $badge = function ($a) { [$l, $bg, $c] = \App\Support\Customers::ACTIVITY[$a] ?? [$a, '#F3F4F6', '#374151']; return "<span style=\"background:{$bg}; color:{$c}; font-size:10px; font-weight:600; padding:2px 8px; border-radius:12px; white-space:nowrap;\">{$l}</span>"; };
    $status = function ($s) { [$bg, $c] = \App\Support\Customers::STATUS[$s] ?? ['#F3F4F6', '#374151']; return "<span style=\"background:{$bg}; color:{$c}; font-size:10px; font-weight:600; padding:2px 8px; border-radius:12px; white-space:nowrap;\">" . e($s ?? 'Absent') . '</span>'; };
@endphp

    <x-customers-tabs active="customer360.directory" />

    @include('livewire.customers360.partials.exports', ['exports' => $exports])

    <div style="background:#fff; border:1px solid #e5e7eb; border-radius:10px; padding:14px 16px; margin-bottom:14px; display:flex; gap:10px; flex-wrap:wrap; align-items:flex-end;">
        <div>
            <label style="{{ $label }}">MSISDN</label>
            <input type="text" wire:model.live.debounce.400ms="search" placeholder="253…" style="{{ $input }} width:150px;">
        </div>
        @foreach([['segment', 'Segment', $options['segments']], ['product', 'Produit', $options['products']], ['identity_status', 'Statut', $options['statuses']], ['year', 'Inscrit en', $options['years']]] as [$field, $text, $values])
            <div>
                <label style="{{ $label }}">{{ $text }}</label>
                <select wire:model.live="{{ $field }}" style="{{ $input }} max-width:200px;">
                    <option value="">Tous</option>
                    @foreach($values as $v) <option value="{{ $v }}">{{ $v }}</option> @endforeach
                </select>
            </div>
        @endforeach
        <div>
            <label style="{{ $label }}">Pièce</label>
            <select wire:model.live="id_type" style="{{ $input }}">
                <option value="">Toutes</option>
                <option value="__none">Non renseignée</option>
                @foreach($options['id_types'] as $v) <option value="{{ $v }}">{{ $v }}</option> @endforeach
            </select>
        </div>
        <div>
            <label style="{{ $label }}">Activité</label>
            <select wire:model.live="activity" style="{{ $input }}">
                <option value="">Toutes</option>
                @foreach(\App\Support\Customers::ACTIVITY as $k => [$l]) <option value="{{ $k }}">{{ $l }}</option> @endforeach
            </select>
        </div>
        <div>
            <label style="{{ $label }}">Compte principal</label>
            <select wire:model.live="main_account" style="{{ $input }}">
                <option value="">Tous</option>
                @foreach(['Active', 'Frozen', 'Suspended', 'New', 'Closed', 'Absent'] as $v) <option value="{{ $v }}">{{ $v }}</option> @endforeach
            </select>
        </div>
        <button wire:click="resetFilters" style="background:#f3f4f6; color:#374151; font-size:12px; font-weight:600; padding:8px 14px; border-radius:7px; border:1px solid #d1d5db; cursor:pointer;">Réinitialiser</button>
        @can('customer360.export')
            <div style="display:flex; gap:6px; margin-left:auto; align-items:flex-end;">
                <select wire:model="exportType" style="{{ $input }}"><option value="excel">Excel</option><option value="csv">CSV</option></select>
                <button wire:click="queueCustomersExport" style="background:#fff; color:#1B2F6E; font-size:12px; font-weight:600; padding:8px 14px; border-radius:7px; border:1px solid #1B2F6E; cursor:pointer;">⬇ Exporter</button>
            </div>
        @endcan
    </div>

    <div style="background:#fff; border:1px solid #e5e7eb; border-radius:10px; overflow:hidden;">
        <div style="padding:10px 16px; border-bottom:1px solid #e5e7eb; display:flex; justify-content:space-between; align-items:center;">
            <p style="font-size:13px; font-weight:600; color:#111827; margin:0;">Clients</p>
            <span style="background:#E8ECF8; color:#1B2F6E; font-size:11px; font-weight:600; padding:3px 10px; border-radius:20px;">{{ number_format($customers->total(), 0, ',', ' ') }}</span>
        </div>
        <div style="overflow-x:auto;">
            <table style="width:100%; border-collapse:collapse; font-size:12px;">
                <thead><tr style="background:#F7F8FC;">
                    @foreach(['MSISDN', 'Segment', 'Produit', 'Statut', 'Compte principal', 'Activité', 'Dernière transaction', 'Inscrit le', 'Pièce'] as $th)
                        <th style="padding:9px 12px; text-align:left; color:#6b7280; font-weight:500; border-bottom:1px solid #e5e7eb; white-space:nowrap;">{{ $th }}</th>
                    @endforeach
                </tr></thead>
                <tbody>
                    @forelse($customers as $c)
                        <tr wire:key="c-{{ $c->unique_system_id }}" wire:click="openClient('{{ $c->msisdn }}')" style="border-bottom:1px solid #f3f4f6; cursor:pointer;"
                            onmouseover="this.style.background='#F7F8FC'" onmouseout="this.style.background='transparent'">
                            <td style="padding:8px 12px; font-family:monospace; font-weight:600; color:#1B2F6E;">{{ $c->msisdn }}</td>
                            <td style="padding:8px 12px; color:#6b7280;">{{ $c->segment }}</td>
                            <td style="padding:8px 12px; color:#6b7280;">{{ $c->products }}</td>
                            <td style="padding:8px 12px;">{!! $status($c->identity_status) !!}</td>
                            <td style="padding:8px 12px;">{!! $status($c->main_account_status) !!}</td>
                            <td style="padding:8px 12px;">{!! $badge($c->activity) !!}</td>
                            <td style="padding:8px 12px; color:#6b7280; white-space:nowrap;">{{ $c->last_txn_at ? \Carbon\Carbon::parse($c->last_txn_at)->format('d/m/Y H:i') : '—' }}</td>
                            <td style="padding:8px 12px; color:#6b7280; white-space:nowrap;">{{ \Carbon\Carbon::parse($c->registered_at)->format('d/m/Y') }}</td>
                            <td style="padding:8px 12px; color:#6b7280;">{{ $c->id_type ?? '—' }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="9" style="padding:24px; text-align:center; color:#9ca3af;">Aucun client pour ces critères.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <div style="padding:10px 16px; border-top:1px solid #e5e7eb;">{{ $customers->links() }}</div>
    </div>

    {{-- FICHE CLIENT --}}
    @if($detail)
        @php $c = $detail['c']; $m = $detail['master']; @endphp
        <div style="position:fixed; inset:0; background:rgba(17,24,39,0.45); z-index:200; display:flex; justify-content:flex-end;" wire:click.self="closeClient">
            <div style="width:min(880px, 100%); height:100%; background:#F7F8FC; overflow-y:auto; box-shadow:-8px 0 24px rgba(0,0,0,0.15);">
                <div style="background:#fff; padding:16px 22px; border-bottom:1px solid #e5e7eb; display:flex; justify-content:space-between; gap:12px; position:sticky; top:0; z-index:1;">
                    <div>
                        <p style="font-size:16px; font-weight:700; color:#111827; margin:0; font-family:monospace;">{{ $c->msisdn }}</p>
                        <p style="font-size:12px; color:#6b7280; margin:3px 0 0;">{{ $detail['identity']['fields']['Nom complet'] ?? 'Nom inconnu' }} · {{ $c->segment }} · {!! $status($c->identity_status) !!} {!! $badge($c->activity) !!}</p>
                    </div>
                    <button wire:click="closeClient" style="background:none; border:none; font-size:22px; color:#9ca3af; cursor:pointer; line-height:1;">×</button>
                </div>

                <div style="padding:18px 22px; display:flex; flex-direction:column; gap:14px;">
                    @foreach($detail['watchlist'] as $w)
                        <div style="background:#FDE8E8; border-left:3px solid #B91C1C; border-radius:6px; padding:10px 12px; font-size:12px; color:#7F1D1D;">
                            <strong>⚠ {{ ['ppe' => 'Liste PPE', 'black_list' => 'BLACK LIST', 'grey_list' => 'Liste grise'][$w->list_type] ?? $w->list_type }}</strong> @if($w->motif) — {{ $w->motif }} @endif
                        </div>
                    @endforeach

                    {{-- Référentiel --}}
                    <div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(170px, 1fr)); gap:8px;">
                        @foreach([
                            ['Produit', $m->products], ['Profil de frais', $m->charge_profile], ['Inscrit le', \Carbon\Carbon::parse($m->registered_at)->format('d/m/Y H:i')],
                            ['Activé le', $m->activated_at ? \Carbon\Carbon::parse($m->activated_at)->format('d/m/Y H:i') : 'Non activé'],
                            ['Inscrit par', $m->registered_by_operator_name ?: $m->registered_by_operator_id],
                            ['Première transaction', $c->first_activity_date ? \Carbon\Carbon::parse($c->first_activity_date)->format('d/m/Y') : 'aucune'],
                            ['Dernière transaction', $c->last_txn_at ? \Carbon\Carbon::parse($c->last_txn_at)->format('d/m/Y H:i') : 'aucune'],
                            ['Pièce (référentiel)', $m->id_type ?? 'Non renseignée'],
                        ] as [$l, $v])
                            <div style="background:#fff; border:1px solid #e5e7eb; border-radius:8px; padding:9px 11px;">
                                <p style="font-size:10px; color:#9ca3af; margin:0 0 2px; text-transform:uppercase;">{{ $l }}</p>
                                <p style="font-size:12px; color:#111827; font-weight:600; margin:0; word-break:break-word;">{{ $v ?? '—' }}</p>
                            </div>
                        @endforeach
                    </div>

                    {{-- Identité KYC --}}
                    <div style="background:#fff; border:1px solid #e5e7eb; border-radius:10px; padding:14px;">
                        <p style="font-size:13px; font-weight:700; color:#111827; margin:0 0 2px;">Identité (KYC)</p>
                        <p style="font-size:11px; color:#9ca3af; margin:0 0 10px;">Source : {{ $detail['identity']['source'] ?? 'aucun dossier KYC' }}</p>
                        @if($detail['identity']['fields'])
                            <div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(180px, 1fr)); gap:8px;">
                                @foreach($detail['identity']['fields'] as $l => $v)
                                    <div style="background:#F7F8FC; border-radius:6px; padding:7px 10px;"><p style="font-size:10px; color:#9ca3af; margin:0;">{{ $l }}</p><p style="font-size:12px; font-weight:600; color:#111827; margin:0;">{{ $v }}</p></div>
                                @endforeach
                            </div>
                            <p style="font-size:12px; color:#374151; margin:10px 0 0;">Photos :
                                @foreach($detail['identity']['photos'] as $l => $has) <span style="margin-right:10px; color:{{ $has ? '#005C2B' : '#B91C1C' }};">{{ $has ? '✓' : '✗' }} {{ $l }}</span> @endforeach
                            </p>
                        @endif
                    </div>

                    {{-- Comptes --}}
                    <div style="background:#fff; border:1px solid #e5e7eb; border-radius:10px; padding:14px;">
                        <p style="font-size:13px; font-weight:700; color:#111827; margin:0 0 8px;">Comptes</p>
                        @foreach($detail['accounts'] as $a)
                            <div style="display:flex; justify-content:space-between; gap:10px; padding:6px 0; border-bottom:1px solid #f3f4f6; font-size:12px;">
                                <span style="color:#111827; font-weight:500;">{{ $a['type'] }} {!! $status($a['status']) !!} @if($a['changed']) <span style="font-size:10px; color:#9ca3af;">depuis le {{ $a['changed'] }}</span> @endif</span>
                                <span style="font-weight:600;">@if($a['balance']) {{ $fdj($a['balance']->balance) }} FDJ <span style="font-size:10px; color:#9ca3af; font-weight:400;">au {{ \Carbon\Carbon::parse($a['balance']->as_of)->format('d/m/Y') }}</span> @else <span style="color:#9ca3af; font-weight:400;">solde inconnu</span> @endif</span>
                            </div>
                        @endforeach
                    </div>

                    {{-- Activité 12 mois --}}
                    <div style="background:#fff; border:1px solid #e5e7eb; border-radius:10px; overflow:hidden;">
                        <p style="font-size:13px; font-weight:700; color:#111827; margin:0; padding:12px 14px; border-bottom:1px solid #e5e7eb;">Activité des 12 derniers mois</p>
                        @if($detail['months']->isEmpty())
                            <p style="font-size:12px; color:#9ca3af; margin:0; padding:12px 14px;">Aucune transaction sur la période.</p>
                        @else
                            <table style="width:100%; border-collapse:collapse; font-size:12px;">
                                <thead><tr style="background:#F7F8FC;">
                                    @foreach(['Mois', 'Transactions', 'Valeur', 'Cash-in reçus', 'Cash-out', 'Transferts envoyés', 'Transferts reçus', 'Paiements'] as $i => $th)
                                        <th style="padding:7px 10px; text-align:{{ $i ? 'right' : 'left' }}; color:#6b7280; font-weight:500;">{{ $th }}</th>
                                    @endforeach
                                </tr></thead>
                                <tbody>
                                    @foreach($detail['months'] as $mo)
                                        <tr style="border-top:1px solid #f3f4f6;">
                                            <td style="padding:6px 10px; font-weight:600;">{{ \Carbon\Carbon::parse($mo->month)->locale('fr')->translatedFormat('M Y') }}</td>
                                            <td style="padding:6px 10px; text-align:right;">{{ $mo->txn_count }}</td>
                                            <td style="padding:6px 10px; text-align:right; font-weight:600;">{{ $fdj($mo->txn_amount) }}</td>
                                            <td style="padding:6px 10px; text-align:right;">{{ $fdj($mo->cashin_amount) }} <span style="color:#9ca3af;">({{ $mo->cashin_count }})</span></td>
                                            <td style="padding:6px 10px; text-align:right;">{{ $fdj($mo->cashout_amount) }} <span style="color:#9ca3af;">({{ $mo->cashout_count }})</span></td>
                                            <td style="padding:6px 10px; text-align:right;">{{ $fdj($mo->p2p_sent_amount) }} <span style="color:#9ca3af;">({{ $mo->p2p_sent_count }})</span></td>
                                            <td style="padding:6px 10px; text-align:right;">{{ $fdj($mo->p2p_received_amount) }} <span style="color:#9ca3af;">({{ $mo->p2p_received_count }})</span></td>
                                            <td style="padding:6px 10px; text-align:right;">{{ $fdj($mo->payment_amount) }} <span style="color:#9ca3af;">({{ $mo->payment_count }})</span></td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        @endif
                    </div>

                    {{-- Alertes AML --}}
                    @if($detail['alerts']->isNotEmpty())
                        <div style="background:#fff; border:1px solid #e5e7eb; border-radius:10px; padding:14px;">
                            <p style="font-size:13px; font-weight:700; color:#111827; margin:0 0 8px;">Alertes AML</p>
                            @foreach($detail['alerts'] as $al)
                                <p style="font-size:12px; color:#374151; margin:0 0 4px;"><strong style="color:#1B2F6E;">{{ $al->code }}</strong> {{ $al->name }} · {{ \Carbon\Carbon::parse($al->period_start)->format('d/m/Y') }} · {{ $fdj($al->total_amount) }} FDJ · <span style="color:#6b7280;">{{ $al->status }}</span></p>
                            @endforeach
                        </div>
                    @endif

                    {{-- Historique des statuts --}}
                    <div style="background:#fff; border:1px solid #e5e7eb; border-radius:10px; padding:14px;">
                        <p style="font-size:13px; font-weight:700; color:#111827; margin:0 0 8px;">Changements de statut</p>
                        @forelse($detail['history'] as $h)
                            <p style="font-size:12px; color:#374151; margin:0 0 4px;">
                                <span style="color:#9ca3af;">{{ \Carbon\Carbon::parse($h->changed_at)->format('d/m/Y H:i') }}</span> · {{ $h->objet }} → {!! $status($h->status) !!}
                                <span style="color:#9ca3af;">par {{ $h->changed_by_username ?: '—' }}</span>
                            </p>
                        @empty
                            <p style="font-size:12px; color:#9ca3af; margin:0;">Aucun changement de statut enregistré (historique alimenté depuis peu).</p>
                        @endforelse
                    </div>
                </div>
            </div>
        </div>
    @endif

</div>
