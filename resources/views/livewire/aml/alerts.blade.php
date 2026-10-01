<?php

use App\Models\AmlAlert;
use App\Models\AmlAlertEvent;
use App\Models\AmlRule;
use App\Support\AmlRuleEngine;
use Livewire\Volt\Component;
use Livewire\WithPagination;
use Carbon\Carbon;

new class extends Component {

    use WithPagination;

    public string $filterStatus   = 'open';   // open = nouvelles + en cours
    public string $filterRule     = '';
    public string $filterSeverity = '';
    public string $filterPeriod   = '';
    public string $search         = '';
    public string $date_debut     = '';
    public string $date_fin       = '';

    // Détail d'une alerte
    public ?int   $selectedId = null;
    public string $newStatus  = '';
    public string $comment    = '';

    public function mount(): void
    {
        $this->date_debut = Carbon::now()->subDays(30)->format('Y-m-d');
        $this->date_fin   = Carbon::now()->format('Y-m-d');
    }

    public function updated($property): void
    {
        if (in_array($property, ['filterStatus', 'filterRule', 'filterSeverity', 'filterPeriod', 'search', 'date_debut', 'date_fin'])) {
            $this->resetPage();
        }
    }

    public function resetFilters(): void
    {
        $this->filterStatus   = 'open';
        $this->filterRule     = '';
        $this->filterSeverity = '';
        $this->filterPeriod   = '';
        $this->search         = '';
        $this->date_debut     = Carbon::now()->subDays(30)->format('Y-m-d');
        $this->date_fin       = Carbon::now()->format('Y-m-d');
        $this->resetPage();
    }

    public function openAlert(int $id): void
    {
        $alert = \App\Models\AmlAlert::findOrFail($id);

        $this->selectedId = $alert->id;
        $this->newStatus  = $alert->status;
        $this->comment    = '';
        $this->resetErrorBag();
    }

    public function closeAlert(): void
    {
        $this->selectedId = null;
        $this->comment    = '';
        $this->resetErrorBag();
    }

    public function assignToMe(): void
    {
        abort_unless(auth()->user()->can('aml.alerts.manage'), 403);

        $alert = \App\Models\AmlAlert::findOrFail($this->selectedId);
        $from  = $alert->status;

        // Prendre une alerte en charge la passe en analyse
        $alert->update([
            'assigned_to' => auth()->id(),
            'status'      => $from === 'new' ? 'in_review' : $from,
        ]);

        $this->logEvent($alert, 'assign', $from, $alert->status, null);
        $this->newStatus = $alert->status;

        \App\Models\AmlAlert::forgetNewCount();
    }

    public function updateStatus(): void
    {
        abort_unless(auth()->user()->can('aml.alerts.manage'), 403);

        $this->validate([
            'newStatus' => 'required|in:' . implode(',', array_keys(\App\Models\AmlAlert::STATUSES)),
            'comment'   => in_array($this->newStatus, \App\Models\AmlAlert::STATUSES_REQUIRING_COMMENT) ? 'required|string|min:5' : 'nullable|string',
        ], [
            'comment.required' => 'Un commentaire est obligatoire pour clôturer ou déclarer une alerte.',
            'comment.min'      => 'Le commentaire doit contenir au moins 5 caractères.',
        ]);

        $alert = \App\Models\AmlAlert::findOrFail($this->selectedId);
        $from  = $alert->status;

        if ($from === $this->newStatus) {
            $this->addError('newStatus', 'Le statut est déjà « ' . \App\Models\AmlAlert::STATUSES[$from][0] . ' ».');
            return;
        }

        $alert->update([
            'status'      => $this->newStatus,
            'closed_at'   => in_array($this->newStatus, ['closed', 'reported']) ? now() : null,
            'assigned_to' => $alert->assigned_to ?? auth()->id(),
        ]);

        $this->logEvent($alert, 'status', $from, $this->newStatus, $this->comment ?: null);
        $this->comment = '';

        \App\Models\AmlAlert::forgetNewCount();
    }

    public function addComment(): void
    {
        abort_unless(auth()->user()->can('aml.alerts.manage'), 403);

        $this->validate(['comment' => 'required|string|min:2'], ['comment.required' => 'Le commentaire est vide.']);

        $alert = \App\Models\AmlAlert::findOrFail($this->selectedId);

        $this->logEvent($alert, 'comment', null, null, $this->comment);
        $this->comment = '';
    }

    private function logEvent(AmlAlert $alert, string $action, ?string $from, ?string $to, ?string $comment): void
    {
        AmlAlertEvent::create([
            'alert_id'    => $alert->id,
            'user_id'     => auth()->id(),
            'action'      => $action,
            'from_status' => $from,
            'to_status'   => $to,
            'comment'     => $comment,
        ]);
    }

    public function with(AmlRuleEngine $engine): array
    {
        $query = \App\Models\AmlAlert::query()->with(['rule:id,code,name', 'assignee:id,name']);

        if ($this->filterRule) {
            $query->where('rule_id', $this->filterRule);
        }
        if ($this->filterSeverity) {
            $query->where('severity', $this->filterSeverity);
        }
        if ($this->filterPeriod) {
            $query->where('period', $this->filterPeriod);
        }
        if ($this->search) {
            $query->where(function ($q) {
                $q->where('party_id', 'like', '%' . $this->search . '%')
                  ->orWhere('party_name', 'ilike', '%' . $this->search . '%');
            });
        }
        if ($this->date_debut) {
            $query->where('last_detected_at', '>=', $this->date_debut . ' 00:00:00');
        }
        if ($this->date_fin) {
            $query->where('last_detected_at', '<', Carbon::parse($this->date_fin)->addDay()->format('Y-m-d') . ' 00:00:00');
        }

        // Compteurs avant le filtre statut, pour garder la répartition complète
        $countsByStatus = (clone $query)->reorder()
            ->selectRaw('status, COUNT(*) AS total')
            ->groupBy('status')
            ->pluck('total', 'status');

        if ($this->filterStatus === 'open') {
            $query->whereIn('status', ['new', 'in_review']);
        } elseif ($this->filterStatus) {
            $query->where('status', $this->filterStatus);
        }

        $selected     = null;
        $transactions = collect();

        if ($this->selectedId) {
            $selected     = \App\Models\AmlAlert::with(['rule', 'assignee:id,name', 'events.user:id,name'])->find($this->selectedId);
            $transactions = $selected ? $engine->transactionsForAlert($selected) : collect();
        }

        return [
            'alerts'         => $query->orderByRaw("CASE severity WHEN 'high' THEN 0 WHEN 'medium' THEN 1 ELSE 2 END")
                                      ->orderByDesc('last_detected_at')
                                      ->paginate(25),
            'countsByStatus' => $countsByStatus,
            'rules'          => \App\Models\AmlRule::orderBy('id')->get(['id', 'code', 'name']),
            'selected'       => $selected,
            'transactions'   => $transactions,
        ];
    }
};
?>
<div style="padding:24px;">

@php
    $severityStyles = [
        'high'   => ['Élevée',  '#FDE8E8', '#7F1D1D'],
        'medium' => ['Moyenne', '#FEF3C7', '#92400E'],
        'low'    => ['Faible',  '#E5F5ED', '#005C2B'],
    ];
    $fdj = fn ($v) => number_format((float) $v, 0, ',', ' ');
    $inputStyle = 'border:1px solid #d1d5db; border-radius:7px; padding:8px 10px; font-size:13px; color:#111827; outline:none; background:#fff;';
@endphp

    <div style="margin-bottom:16px;">
        <h2 style="font-size:16px; font-weight:700; color:#111827; margin:0 0 4px;">AML — Alertes</h2>
        <p style="font-size:12px; color:#9ca3af; margin:0;">Comptes ayant dépassé un seuil des règles AML. Détection quotidienne sur les transactions de la veille.</p>
    </div>

    <x-aml-tabs active="aml.alerts" />

    {{-- KPIs --}}
    <div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(150px, 1fr)); gap:12px; margin-bottom:16px;">
        @foreach(\App\Models\AmlAlert::STATUSES as $key => [$label, $bg, $color])
            <button wire:click="$set('filterStatus', '{{ $key }}')"
                    style="text-align:left; cursor:pointer; background:#fff; border:1px solid {{ $filterStatus === $key ? $color : '#e5e7eb' }}; border-radius:10px; padding:16px; border-top:3px solid {{ $color }};">
                <p style="font-size:11px; color:#6b7280; margin:0 0 6px;">{{ $label }}</p>
                <p style="font-size:20px; font-weight:700; color:#111827; margin:0;">{{ number_format($countsByStatus[$key] ?? 0, 0, ',', ' ') }}</p>
            </button>
        @endforeach
    </div>

    {{-- FILTRES --}}
    <div style="background:#fff; border:1px solid #e5e7eb; border-radius:10px; padding:16px; margin-bottom:16px; display:flex; gap:12px; flex-wrap:wrap; align-items:flex-end;">
        <div>
            <label style="font-size:11px; color:#6b7280; display:block; margin-bottom:4px;">Compte</label>
            <input type="text" wire:model.live.debounce.400ms="search" placeholder="MSISDN, short code ou nom..."
                   style="{{ $inputStyle }} width:200px;">
        </div>
        <div>
            <label style="font-size:11px; color:#6b7280; display:block; margin-bottom:4px;">Statut</label>
            <select wire:model.live="filterStatus" style="{{ $inputStyle }} width:190px;">
                <option value="open">À traiter (nouvelles + en cours)</option>
                <option value="">Tous</option>
                @foreach(\App\Models\AmlAlert::STATUSES as $key => [$label])
                    <option value="{{ $key }}">{{ $label }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label style="font-size:11px; color:#6b7280; display:block; margin-bottom:4px;">Règle</label>
            <select wire:model.live="filterRule" style="{{ $inputStyle }} width:220px;">
                <option value="">Toutes</option>
                @foreach($rules as $r)
                    <option value="{{ $r->id }}">{{ $r->code }} — {{ \Illuminate\Support\Str::limit($r->name, 30) }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label style="font-size:11px; color:#6b7280; display:block; margin-bottom:4px;">Sévérité</label>
            <select wire:model.live="filterSeverity" style="{{ $inputStyle }} width:120px;">
                <option value="">Toutes</option>
                @foreach(\App\Models\AmlRule::SEVERITIES as $key => $label)
                    <option value="{{ $key }}">{{ $label }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label style="font-size:11px; color:#6b7280; display:block; margin-bottom:4px;">Période</label>
            <select wire:model.live="filterPeriod" style="{{ $inputStyle }} width:120px;">
                <option value="">Toutes</option>
                <option value="day">Jour</option>
                <option value="week">Semaine</option>
                <option value="month">Mois</option>
            </select>
        </div>
        <div>
            <label style="font-size:11px; color:#6b7280; display:block; margin-bottom:4px;">Détectée du</label>
            <input type="date" wire:model.live="date_debut" style="{{ $inputStyle }}">
        </div>
        <div>
            <label style="font-size:11px; color:#6b7280; display:block; margin-bottom:4px;">au</label>
            <input type="date" wire:model.live="date_fin" style="{{ $inputStyle }}">
        </div>
        <button wire:click="resetFilters"
                style="background:#f3f4f6; color:#374151; font-size:13px; font-weight:600; padding:9px 16px; border-radius:8px; border:1px solid #d1d5db; cursor:pointer;">
            Réinitialiser
        </button>
    </div>

    {{-- TABLEAU --}}
    <div style="background:#fff; border:1px solid #e5e7eb; border-radius:10px; overflow:hidden;">
        <div style="padding:12px 16px; border-bottom:1px solid #e5e7eb; display:flex; align-items:center; justify-content:space-between;">
            <p style="font-size:13px; font-weight:600; color:#111827; margin:0;">Alertes</p>
            <span style="background:#E8ECF8; color:#1B2F6E; font-size:11px; font-weight:600; padding:3px 10px; border-radius:20px;">
                {{ $alerts->total() }} alerte(s)
            </span>
        </div>

        <div style="overflow-x:auto;">
            <table style="width:100%; border-collapse:collapse; font-size:12px;">
                <thead>
                    <tr style="background:#F7F8FC;">
                        @foreach(['Sévérité', 'Règle', 'Compte', 'Période', 'Nb', 'Montant (FDJ)', 'Seuil', 'Statut', 'Assignée à', 'Détectée le', ''] as $th)
                            <th style="padding:10px 12px; text-align:left; color:#6b7280; font-weight:500; border-bottom:1px solid #e5e7eb; white-space:nowrap;">{{ $th }}</th>
                        @endforeach
                    </tr>
                </thead>
                <tbody>
                    @forelse($alerts as $alert)
                        @php
                            [$sevLabel, $sevBg, $sevColor] = $severityStyles[$alert->severity] ?? [$alert->severity, '#F3F4F6', '#374151'];
                            [$stLabel, $stBg, $stColor]    = \App\Models\AmlAlert::STATUSES[$alert->status] ?? [$alert->status, '#F3F4F6', '#374151'];
                        @endphp
                        <tr wire:key="alert-{{ $alert->id }}" style="border-bottom:1px solid #f3f4f6; cursor:pointer;"
                            wire:click="openAlert({{ $alert->id }})"
                            onmouseover="this.style.background='#F7F8FC'"
                            onmouseout="this.style.background='transparent'">
                            <td style="padding:10px 12px;">
                                <span style="background:{{ $sevBg }}; color:{{ $sevColor }}; font-size:11px; font-weight:600; padding:3px 10px; border-radius:20px;">{{ $sevLabel }}</span>
                            </td>
                            <td style="padding:10px 12px;">
                                <span style="color:#1B2F6E; font-weight:700;">{{ $alert->rule->code ?? '—' }}</span><br>
                                <span style="color:#6b7280; font-size:11px;">{{ \Illuminate\Support\Str::limit($alert->rule->name ?? '', 40) }}</span>
                            </td>
                            <td style="padding:10px 12px;">
                                <span style="color:#111827; font-weight:500; font-family:monospace;">{{ $alert->party_id }}</span><br>
                                <span style="color:#9ca3af; font-size:11px;">{{ $alert->party_name ?: '—' }} · {{ $alert->party_type === 'Organization' ? 'Corporate' : 'RDS' }}</span>
                            </td>
                            <td style="padding:10px 12px; color:#6b7280; white-space:nowrap;">
                                @if($alert->period === 'day')
                                    {{ $alert->period_start->format('d/m/Y') }}
                                @else
                                    {{ $alert->period_start->format('d/m') }} → {{ $alert->period_end->format('d/m/Y') }}
                                @endif
                            </td>
                            <td style="padding:10px 12px; color:#111827; font-weight:600;">{{ $alert->txn_count }}</td>
                            <td style="padding:10px 12px; color:#111827; font-weight:600; white-space:nowrap;">{{ $fdj($alert->total_amount) }}</td>
                            <td style="padding:10px 12px; color:#6b7280; font-size:11px;">{{ $alert->thresholdLabel() }}</td>
                            <td style="padding:10px 12px;">
                                <span style="background:{{ $stBg }}; color:{{ $stColor }}; font-size:11px; font-weight:600; padding:3px 10px; border-radius:20px; white-space:nowrap;">{{ $stLabel }}</span>
                            </td>
                            <td style="padding:10px 12px; color:#6b7280;">{{ $alert->assignee->name ?? '—' }}</td>
                            <td style="padding:10px 12px; color:#6b7280; white-space:nowrap;">{{ $alert->first_detected_at->format('d/m/Y H:i') }}</td>
                            <td style="padding:10px 12px; color:#1B2F6E; font-weight:600;">Voir →</td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="11" style="padding:24px; text-align:center; color:#9ca3af;">Aucune alerte pour ces critères.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        <div style="padding:12px 16px; border-top:1px solid #e5e7eb;">
            {{ $alerts->links() }}
        </div>
    </div>

    {{-- DÉTAIL D'UNE ALERTE --}}
    @if($selected)
        @php
            [$sevLabel, $sevBg, $sevColor] = $severityStyles[$selected->severity] ?? [$selected->severity, '#F3F4F6', '#374151'];
            [$stLabel, $stBg, $stColor]    = \App\Models\AmlAlert::STATUSES[$selected->status] ?? [$selected->status, '#F3F4F6', '#374151'];
        @endphp
        <div style="position:fixed; inset:0; background:rgba(17,24,39,0.45); z-index:200; display:flex; justify-content:flex-end;"
             wire:click.self="closeAlert">
            <div style="width:min(860px, 100%); height:100%; background:#F7F8FC; overflow-y:auto; box-shadow:-8px 0 24px rgba(0,0,0,0.15);">

                {{-- En-tête --}}
                <div style="background:#fff; padding:18px 24px; border-bottom:1px solid #e5e7eb; display:flex; align-items:flex-start; justify-content:space-between; gap:12px; position:sticky; top:0; z-index:1;">
                    <div>
                        <div style="display:flex; align-items:center; gap:8px; margin-bottom:6px; flex-wrap:wrap;">
                            <span style="color:#1B2F6E; font-weight:700; font-size:15px;">{{ $selected->rule->code ?? '—' }}</span>
                            <span style="background:{{ $sevBg }}; color:{{ $sevColor }}; font-size:11px; font-weight:600; padding:3px 10px; border-radius:20px;">{{ $sevLabel }}</span>
                            <span style="background:{{ $stBg }}; color:{{ $stColor }}; font-size:11px; font-weight:600; padding:3px 10px; border-radius:20px;">{{ $stLabel }}</span>
                        </div>
                        <p style="font-size:13px; color:#111827; margin:0;">{{ $selected->rule->name ?? '' }}</p>
                    </div>
                    <button wire:click="closeAlert" style="background:none; border:none; font-size:22px; color:#9ca3af; cursor:pointer; line-height:1;">×</button>
                </div>

                <div style="padding:20px 24px;">

                    {{-- Résumé --}}
                    <div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(150px, 1fr)); gap:10px; margin-bottom:16px;">
                        @foreach([
                            ['Compte', $selected->party_id . ($selected->party_name ? ' — ' . $selected->party_name : '')],
                            ['Type', $selected->party_type === 'Organization' ? 'Corporate' : 'RDS'],
                            ['Période', $selected->period === 'day' ? $selected->period_start->format('d/m/Y') : $selected->period_start->format('d/m/Y') . ' → ' . $selected->period_end->format('d/m/Y')],
                            ['Nb transactions', $selected->txn_count],
                            ['Montant cumulé', $fdj($selected->total_amount) . ' FDJ'],
                            ['Seuil de la règle', $selected->thresholdLabel()],
                            ['Détectée le', $selected->first_detected_at->format('d/m/Y H:i')],
                            ['Assignée à', $selected->assignee->name ?? '—'],
                        ] as [$label, $value])
                            <div style="background:#fff; border:1px solid #e5e7eb; border-radius:8px; padding:10px 12px;">
                                <p style="font-size:10px; color:#9ca3af; margin:0 0 3px; text-transform:uppercase; letter-spacing:.5px;">{{ $label }}</p>
                                <p style="font-size:12px; color:#111827; font-weight:600; margin:0; word-break:break-word;">{{ $value }}</p>
                            </div>
                        @endforeach
                    </div>

                    {{-- Traitement --}}
                    @can('aml.alerts.manage')
                        <div style="background:#fff; border:1px solid #e5e7eb; border-radius:10px; padding:16px; margin-bottom:16px;">
                            <div style="display:flex; align-items:center; justify-content:space-between; margin-bottom:12px;">
                                <p style="font-size:13px; font-weight:600; color:#111827; margin:0;">Traitement</p>
                                @if($selected->assigned_to !== auth()->id())
                                    <button wire:click="assignToMe"
                                            style="background:#E8ECF8; color:#1B2F6E; font-size:12px; font-weight:600; padding:6px 12px; border-radius:7px; border:none; cursor:pointer;">
                                        Me l'assigner
                                    </button>
                                @endif
                            </div>

                            <div style="display:flex; gap:10px; flex-wrap:wrap; align-items:flex-start; margin-bottom:10px;">
                                <div>
                                    <label style="font-size:11px; color:#6b7280; display:block; margin-bottom:4px;">Statut</label>
                                    <select wire:model="newStatus" style="{{ $inputStyle }} width:220px;">
                                        @foreach(\App\Models\AmlAlert::STATUSES as $key => [$label])
                                            <option value="{{ $key }}">{{ $label }}</option>
                                        @endforeach
                                    </select>
                                    @error('newStatus') <p style="font-size:11px; color:#B91C1C; margin:4px 0 0;">{{ $message }}</p> @enderror
                                </div>
                                <div style="flex:1; min-width:220px;">
                                    <label style="font-size:11px; color:#6b7280; display:block; margin-bottom:4px;">Commentaire (obligatoire pour clôturer ou déclarer)</label>
                                    <textarea wire:model="comment" rows="3"
                                              style="{{ $inputStyle }} width:100%; resize:vertical; box-sizing:border-box;"></textarea>
                                    @error('comment') <p style="font-size:11px; color:#B91C1C; margin:4px 0 0;">{{ $message }}</p> @enderror
                                </div>
                            </div>
                            <div style="display:flex; gap:8px;">
                                <button wire:click="updateStatus"
                                        style="background:#1B2F6E; color:#fff; font-size:12px; font-weight:600; padding:8px 14px; border-radius:7px; border:none; cursor:pointer;">
                                    Changer le statut
                                </button>
                                <button wire:click="addComment"
                                        style="background:#fff; color:#374151; font-size:12px; font-weight:600; padding:8px 14px; border-radius:7px; border:1px solid #d1d5db; cursor:pointer;">
                                    Ajouter le commentaire seul
                                </button>
                            </div>
                        </div>
                    @endcan

                    {{-- Transactions --}}
                    <div style="background:#fff; border:1px solid #e5e7eb; border-radius:10px; overflow:hidden; margin-bottom:16px;">
                        <div style="padding:12px 16px; border-bottom:1px solid #e5e7eb; display:flex; justify-content:space-between; align-items:center;">
                            <p style="font-size:13px; font-weight:600; color:#111827; margin:0;">Transactions concernées</p>
                            <span style="font-size:11px; color:#9ca3af;">{{ $transactions->count() >= 200 ? '200 plus récentes' : $transactions->count() . ' transaction(s)' }}</span>
                        </div>
                        <div style="overflow:auto; max-height:360px;">
                            <table style="width:100%; border-collapse:collapse; font-size:11px;">
                                <thead>
                                    <tr style="background:#F7F8FC;">
                                        @foreach(['Date', 'ID', 'Type', 'Débit', 'Crédit', 'Montant (FDJ)'] as $th)
                                            <th style="padding:8px 12px; text-align:left; color:#6b7280; font-weight:500; border-bottom:1px solid #e5e7eb; white-space:nowrap; position:sticky; top:0; background:#F7F8FC;">{{ $th }}</th>
                                        @endforeach
                                    </tr>
                                </thead>
                                <tbody>
                                    @forelse($transactions as $t)
                                        @php $isDebit = $t->debit_party_identifier === $selected->party_id; @endphp
                                        <tr style="border-bottom:1px solid #f3f4f6;">
                                            <td style="padding:7px 12px; color:#6b7280; white-space:nowrap;">{{ \Carbon\Carbon::parse($t->transaction_initiated_time)->format('d/m/Y H:i') }}</td>
                                            <td style="padding:7px 12px; color:#6b7280; font-family:monospace;">{{ $t->transaction_id }}</td>
                                            <td style="padding:7px 12px; color:#111827;">{{ $t->transaction_type }}</td>
                                            <td style="padding:7px 12px; color:{{ $isDebit ? '#1B2F6E' : '#6b7280' }}; font-weight:{{ $isDebit ? '600' : '400' }};">{{ $t->debit_party_identifier }}<br><span style="color:#9ca3af; font-weight:400;">{{ $t->debit_party_name }}</span></td>
                                            <td style="padding:7px 12px; color:{{ !$isDebit ? '#1B2F6E' : '#6b7280' }}; font-weight:{{ !$isDebit ? '600' : '400' }};">{{ $t->credit_party_identifier }}<br><span style="color:#9ca3af; font-weight:400;">{{ $t->credit_party_name }}</span></td>
                                            <td style="padding:7px 12px; font-weight:600; white-space:nowrap; color:{{ $isDebit ? '#B91C1C' : '#005C2B' }};">{{ $isDebit ? '−' : '+' }} {{ $fdj($t->amount_fdj) }}</td>
                                        </tr>
                                    @empty
                                        <tr><td colspan="6" style="padding:18px; text-align:center; color:#9ca3af;">Aucune transaction trouvée.</td></tr>
                                    @endforelse
                                </tbody>
                            </table>
                        </div>
                    </div>

                    {{-- Historique --}}
                    <div style="background:#fff; border:1px solid #e5e7eb; border-radius:10px; padding:16px;">
                        <p style="font-size:13px; font-weight:600; color:#111827; margin:0 0 12px;">Historique</p>
                        @forelse($selected->events as $event)
                            <div style="border-left:2px solid #E8ECF8; padding:2px 0 10px 12px;">
                                <p style="font-size:11px; color:#9ca3af; margin:0 0 2px;">
                                    {{ $event->created_at->format('d/m/Y H:i') }} · {{ $event->user->name ?? 'Système' }}
                                </p>
                                <p style="font-size:12px; color:#111827; margin:0;">
                                    @if($event->action === 'assign')
                                        A pris l'alerte en charge
                                    @elseif($event->action === 'status')
                                        Statut : {{ \App\Models\AmlAlert::STATUSES[$event->from_status][0] ?? $event->from_status }} → <strong>{{ \App\Models\AmlAlert::STATUSES[$event->to_status][0] ?? $event->to_status }}</strong>
                                    @else
                                        Commentaire
                                    @endif
                                </p>
                                @if($event->comment)
                                    <p style="font-size:12px; color:#374151; margin:4px 0 0; white-space:pre-line; background:#F7F8FC; padding:8px 10px; border-radius:6px;">{{ $event->comment }}</p>
                                @endif
                            </div>
                        @empty
                            <p style="font-size:12px; color:#9ca3af; margin:0;">Aucune action pour l'instant.</p>
                        @endforelse
                        <p style="font-size:11px; color:#9ca3af; margin:6px 0 0;">Alerte détectée le {{ $selected->first_detected_at->format('d/m/Y H:i') }}, dernière mise à jour le {{ $selected->last_detected_at->format('d/m/Y H:i') }}.</p>
                    </div>

                </div>
            </div>
        </div>
    @endif

</div>
