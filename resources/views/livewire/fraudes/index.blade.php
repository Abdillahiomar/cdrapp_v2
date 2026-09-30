<?php

use Livewire\Volt\Component;
use Livewire\WithPagination;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

new class extends Component {

    use WithPagination;

    // ── Commission indue sur Cash In répétés (agent/client) ──
    public string $comm_date_debut = '';
    public string $comm_date_fin   = '';
    public int    $comm_min_cashin = 3;
    public bool   $comm_searched   = false;

    // ── Structuring (S1/S2) ──
    public string $struct_date_debut = '';
    public string $struct_date_fin   = '';
    public int    $struct_min_agents = 3;
    public bool   $struct_searched   = false;

    public ?string $struct_expanded_customer = null;
    public array   $struct_detail            = [];

    // ── Chaînes de transactions (S3) ──
    public string $chain_date_debut = '';
    public string $chain_date_fin   = '';
    public bool   $chain_searched   = false;

    public ?int  $chain_expanded_id = null;
    public array $chain_members     = [];

    public function mount(): void
    {
        $this->comm_date_debut = Carbon::now()->subDays(7)->format('Y-m-d');
        $this->comm_date_fin   = Carbon::now()->format('Y-m-d');

        $this->struct_date_debut = Carbon::now()->subDays(7)->format('Y-m-d');
        $this->struct_date_fin   = Carbon::now()->format('Y-m-d');

        $this->chain_date_debut = Carbon::now()->subDays(7)->format('Y-m-d');
        $this->chain_date_fin   = Carbon::now()->format('Y-m-d');
    }

    public function analyserCommission(): void
    {
        $this->comm_searched = true;
        $this->resetPage('commissionPage');
    }

    public function analyserStructuring(): void
    {
        $this->struct_searched            = true;
        $this->struct_expanded_customer   = null;
        $this->struct_detail              = [];
        $this->resetPage('structPage');
    }

    public function analyserChains(): void
    {
        $this->chain_searched      = true;
        $this->chain_expanded_id   = null;
        $this->chain_members       = [];
        $this->resetPage('chainPage');
    }

    /**
     * Détail des agents pour un client (S1), sur la période analysée.
     */
    public function toggleStructCustomer(string $customerId): void
    {
        if ($this->struct_expanded_customer === $customerId) {
            $this->struct_expanded_customer = null;
            $this->struct_detail            = [];
            return;
        }

        $this->struct_expanded_customer = $customerId;

        $this->struct_detail = DB::table('agent_customer_daily_activity')
            ->where('customer_id', $customerId)
            ->whereBetween('activity_date', [$this->struct_date_debut, $this->struct_date_fin])
            ->orderByDesc('cashin_amount')
            ->get()
            ->map(fn ($r) => (array) $r)
            ->all();
    }

    /**
     * Parcours complet d'une chaîne (S3) : ses transactions membres, dans l'ordre.
     */
    public function toggleChain(int $chainId): void
    {
        if ($this->chain_expanded_id === $chainId) {
            $this->chain_expanded_id = null;
            $this->chain_members     = [];
            return;
        }

        $this->chain_expanded_id = $chainId;

        $this->chain_members = DB::table('transaction_chain_members')
            ->where('chain_id', $chainId)
            ->orderBy('sequence_number')
            ->get()
            ->map(fn ($r) => (array) $r)
            ->all();
    }

    public function with(): array
    {
        return [
            'processingStatus' => $this->getProcessingStatus(),
            'commissionIndue'  => $this->getCommissionIndue(),
            'structuring'      => $this->getStructuring(),
            'chains'           => $this->getChains(),
            'agentSummary'     => $this->getAgentSummary(),
        ];
    }

    /**
     * Commission indue : couples agent/client avec des Cash In répétés sur la
     * période, et la commission totale perçue par l'agent sur ces opérations.
     * La commission étant un pourcentage du montant, répéter les cash-in sur
     * le même argent (au lieu d'un seul mouvement réel) permet de refacturer
     * la commission plusieurs fois — c'est ce signal qu'on cherche ici,
     * indépendamment de la taille du ticket.
     */
    private function getCommissionIndue()
    {
        if (!$this->comm_searched) {
            return null;
        }

        $debut = $this->comm_date_debut . ' 00:00:00';
        $fin   = $this->comm_date_fin   . ' 23:59:59';

        return DB::table('fraud_transactions')
            ->select('debited_msisdn as agent_id', 'credited_msisdn as customer_id')
            ->selectRaw('COUNT(*) as nb_cashin')
            ->selectRaw('COALESCE(SUM(amount), 0) as total_amount')
            ->selectRaw('COALESCE(SUM(commission), 0) as total_commission')
            ->where('transaction_type', 'Customer Cash In')
            ->whereBetween('transaction_time', [$debut, $fin])
            ->groupBy('debited_msisdn', 'credited_msisdn')
            ->havingRaw('COUNT(*) >= ?', [$this->comm_min_cashin])
            ->orderByDesc('total_commission')
            ->paginate(20, ['*'], 'commissionPage');
    }

    /**
     * État des derniers traitements batch (fraud:process-daily écrit dans
     * fraud_processing_runs ; fraud:chains-weekly n'y écrit rien, on déduit
     * donc son dernier passage à partir de transaction_chains).
     */
    private function getProcessingStatus(): array
    {
        $runs = DB::table('fraud_processing_runs')
            ->whereIn('process_name', ['agent_customer_daily_activity', 'customer_multi_agent_daily'])
            ->orderByDesc('activity_date')
            ->get()
            ->groupBy('process_name')
            ->map(fn ($group) => $group->first());

        $chainsInfo = DB::table('transaction_chains')
            ->selectRaw('MAX(activity_date) as last_activity_date, MAX(created_at) as last_run_at, COUNT(*) as total_chains')
            ->first();

        return [
            's1' => $runs->get('agent_customer_daily_activity'),
            's2' => $runs->get('customer_multi_agent_daily'),
            's3' => $chainsInfo,
        ];
    }

    private function getStructuring()
    {
        if (!$this->struct_searched) {
            return null;
        }

        return DB::table('customer_multi_agent_daily')
            ->whereBetween('activity_date', [$this->struct_date_debut, $this->struct_date_fin])
            ->where('unique_agents', '>=', $this->struct_min_agents)
            ->orderByDesc('unique_agents')
            ->orderByDesc('total_cashin_amount')
            ->paginate(20, ['*'], 'structPage');
    }

    private function getChains()
    {
        if (!$this->chain_searched) {
            return null;
        }

        return DB::table('transaction_chains')
            ->whereBetween('activity_date', [$this->chain_date_debut, $this->chain_date_fin])
            ->orderByDesc('amount_retention_ratio')
            ->orderByDesc('send_money_hops')
            ->paginate(20, ['*'], 'chainPage');
    }

    /**
     * Récapitulatif par agent d'origine : combien de fois cet agent apparaît
     * comme point de départ d'une chaîne sur la période, et la commission
     * totale qu'il a gagnée sur ces cash-in initiaux.
     * Calculé sur l'ensemble de la période filtrée (pas seulement la page affichée).
     */
    private function getAgentSummary()
    {
        if (!$this->chain_searched) {
            return null;
        }

        return DB::table('transaction_chains')
            ->select('origin_agent_id')
            ->selectRaw('COUNT(*) as nb_chains')
            ->selectRaw('COALESCE(SUM(initial_commission), 0) as total_commission')
            ->whereBetween('activity_date', [$this->chain_date_debut, $this->chain_date_fin])
            ->groupBy('origin_agent_id')
            ->orderByDesc('nb_chains')
            ->get();
    }

    public function formatDuration(?int $seconds): string
    {
        if ($seconds === null) {
            return '—';
        }

        $h = intdiv($seconds, 3600);
        $m = intdiv($seconds % 3600, 60);
        $s = $seconds % 60;

        if ($h > 0) return sprintf('%dh%02dm', $h, $m);
        if ($m > 0) return sprintf('%dm%02ds', $m, $s);
        return "{$s}s";
    }

    public function riskLevel($ratio): string
    {
        $ratio = (float) $ratio;

        if ($ratio >= 0.8) return 'high';
        if ($ratio >= 0.5) return 'medium';
        return 'low';
    }
}; ?>

<div style="padding:24px;">

    <div style="margin-bottom:20px;">
        <h2 style="font-size:16px; font-weight:700; color:#111827; margin:0 0 4px;">AML — Détection de blanchiment</h2>
        <p style="font-size:12px; color:#9ca3af; margin:0;">Structuring (cash-in multi-agents) et reconstitution des chaînes de transactions.</p>
    </div>

    
    {{-- ÉTAT DES TRAITEMENTS --}}
    <div style="display:grid; grid-template-columns:repeat(3, minmax(0,1fr)); gap:12px; margin-bottom:20px;">
        <div style="background:#fff; border:1px solid #e5e7eb; border-radius:10px; padding:14px 16px;">
            <p style="font-size:10px; color:#9ca3af; margin:0 0 4px; text-transform:uppercase; letter-spacing:0.5px;">S1 — Agent → Client (quotidien)</p>
            @if($processingStatus['s1'])
                <p style="font-size:12px; color:#111827; margin:0;">
                    {{ \Carbon\Carbon::parse($processingStatus['s1']->activity_date)->format('d/m/Y') }}
                    <span style="background:{{ $processingStatus['s1']->status === 'SUCCESS' ? '#E5F5ED' : ($processingStatus['s1']->status === 'FAILED' ? '#FDE8E8' : '#FEF3C7') }};
                                 color:{{ $processingStatus['s1']->status === 'SUCCESS' ? '#005C2B' : ($processingStatus['s1']->status === 'FAILED' ? '#7F1D1D' : '#92400E') }};
                                 font-size:10px; font-weight:600; padding:2px 8px; border-radius:20px; margin-left:6px;">
                        {{ $processingStatus['s1']->status }}
                    </span>
                </p>
                <p style="font-size:11px; color:#9ca3af; margin:4px 0 0;">{{ number_format($processingStatus['s1']->rows_processed, 0, ',', ' ') }} lignes</p>
            @else
                <p style="font-size:12px; color:#9ca3af; margin:0;">Aucune exécution enregistrée.</p>
            @endif
        </div>

        <div style="background:#fff; border:1px solid #e5e7eb; border-radius:10px; padding:14px 16px;">
            <p style="font-size:10px; color:#9ca3af; margin:0 0 4px; text-transform:uppercase; letter-spacing:0.5px;">S2 — Client multi-agents (quotidien)</p>
            @if($processingStatus['s2'])
                <p style="font-size:12px; color:#111827; margin:0;">
                    {{ \Carbon\Carbon::parse($processingStatus['s2']->activity_date)->format('d/m/Y') }}
                    <span style="background:{{ $processingStatus['s2']->status === 'SUCCESS' ? '#E5F5ED' : ($processingStatus['s2']->status === 'FAILED' ? '#FDE8E8' : '#FEF3C7') }};
                                 color:{{ $processingStatus['s2']->status === 'SUCCESS' ? '#005C2B' : ($processingStatus['s2']->status === 'FAILED' ? '#7F1D1D' : '#92400E') }};
                                 font-size:10px; font-weight:600; padding:2px 8px; border-radius:20px; margin-left:6px;">
                        {{ $processingStatus['s2']->status }}
                    </span>
                </p>
                <p style="font-size:11px; color:#9ca3af; margin:4px 0 0;">{{ number_format($processingStatus['s2']->rows_processed, 0, ',', ' ') }} lignes</p>
            @else
                <p style="font-size:12px; color:#9ca3af; margin:0;">Aucune exécution enregistrée.</p>
            @endif
        </div>

        <div style="background:#fff; border:1px solid #e5e7eb; border-radius:10px; padding:14px 16px;">
            <p style="font-size:10px; color:#9ca3af; margin:0 0 4px; text-transform:uppercase; letter-spacing:0.5px;">S3 — Chaînes (hebdomadaire)</p>
            @if($processingStatus['s3'] && $processingStatus['s3']->last_run_at)
                <p style="font-size:12px; color:#111827; margin:0;">
                    Semaine du {{ \Carbon\Carbon::parse($processingStatus['s3']->last_activity_date)->format('d/m/Y') }}
                </p>
                <p style="font-size:11px; color:#9ca3af; margin:4px 0 0;">
                    {{ number_format($processingStatus['s3']->total_chains, 0, ',', ' ') }} chaînes au total — calculées le {{ \Carbon\Carbon::parse($processingStatus['s3']->last_run_at)->format('d/m/Y H:i') }}
                </p>
            @else
                <p style="font-size:12px; color:#9ca3af; margin:0;">Aucune chaîne calculée.</p>
            @endif
        </div>
    </div>

    {{-- SECTION COMMISSION INDUE (CASH IN RÉPÉTÉS) --}}
    <div style="background:#fff; border:1px solid #e5e7eb; border-radius:10px; padding:20px; margin-bottom:20px;">
        <p style="font-size:13px; font-weight:700; color:#111827; margin:0 0 4px;">Commission indue — Cash In répétés entre un agent et un client</p>
        <p style="font-size:11px; color:#9ca3af; margin:0 0 16px;">La commission étant un pourcentage du montant, refaire circuler le même argent en plusieurs Cash In permet de la refacturer plusieurs fois.</p>

        <div style="display:flex; align-items:flex-end; gap:12px; flex-wrap:wrap; margin-bottom:16px;">
            <div>
                <label style="font-size:11px; color:#6b7280; display:block; margin-bottom:4px;">Date début</label>
                <input type="date" wire:model="comm_date_debut"
                       style="border:1px solid #d1d5db; border-radius:7px; padding:8px 10px; font-size:13px; color:#111827; outline:none;">
            </div>
            <div>
                <label style="font-size:11px; color:#6b7280; display:block; margin-bottom:4px;">Date fin</label>
                <input type="date" wire:model="comm_date_fin"
                       style="border:1px solid #d1d5db; border-radius:7px; padding:8px 10px; font-size:13px; color:#111827; outline:none;">
            </div>
            <div>
                <label style="font-size:11px; color:#6b7280; display:block; margin-bottom:4px;">Nb cash-in min.</label>
                <input type="number" min="2" wire:model="comm_min_cashin"
                       style="width:100px; border:1px solid #d1d5db; border-radius:7px; padding:8px 10px; font-size:13px; color:#111827; outline:none;">
            </div>
            <button wire:click="analyserCommission" wire:loading.attr="disabled" wire:target="analyserCommission"
                    style="background:#00843D; color:#fff; font-size:13px; font-weight:600; padding:9px 22px; border-radius:8px; border:none; cursor:pointer;">
                Analyser
            </button>
        </div>

        @if($comm_searched)
            @if($commissionIndue->isEmpty())
                <p style="font-size:12px; color:#9ca3af; text-align:center; padding:24px;">Aucun couple agent/client au-dessus du seuil sur cette période.</p>
            @else
                <div style="overflow-x:auto;">
                    <table style="width:100%; border-collapse:collapse; font-size:12px;">
                        <thead>
                            <tr style="background:#F7F8FC;">
                                <th style="padding:10px 16px; text-align:left; color:#6b7280; font-weight:500; border-bottom:1px solid #e5e7eb;">Agent</th>
                                <th style="padding:10px 16px; text-align:left; color:#6b7280; font-weight:500; border-bottom:1px solid #e5e7eb;">Client</th>
                                <th style="padding:10px 16px; text-align:center; color:#6b7280; font-weight:500; border-bottom:1px solid #e5e7eb;">Nb cash-in</th>
                                <th style="padding:10px 16px; text-align:right; color:#6b7280; font-weight:500; border-bottom:1px solid #e5e7eb;">Montant total</th>
                                <th style="padding:10px 16px; text-align:right; color:#6b7280; font-weight:500; border-bottom:1px solid #e5e7eb;">Commission totale</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($commissionIndue as $row)
                                <tr style="border-bottom:1px solid #f3f4f6;"
                                    onmouseover="this.style.background='#F7F8FC'"
                                    onmouseout="this.style.background='transparent'">
                                    <td style="padding:10px 16px; color:#111827; font-weight:500;">{{ $row->agent_id }}</td>
                                    <td style="padding:10px 16px; color:#6b7280;">{{ $row->customer_id }}</td>
                                    <td style="padding:10px 16px; text-align:center;">
                                        <span style="background:#FEF3C7; color:#92400E; font-size:11px; font-weight:700; padding:2px 10px; border-radius:20px;">
                                            {{ $row->nb_cashin }}
                                        </span>
                                    </td>
                                    <td style="padding:10px 16px; text-align:right; color:#6b7280;">{{ number_format($row->total_amount * 100, 0, ',', ' ') }}</td>
                                    <td style="padding:10px 16px; text-align:right; color:#111827; font-weight:600;">{{ number_format($row->total_commission * 100, 0, ',', ' ') }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
                <div style="padding:12px 0 0;">
                    {{ $commissionIndue->links() }}
                </div>
            @endif
        @endif
    </div>


    {{-- SECTION STRUCTURING --}}
    <div style="background:#fff; border:1px solid #e5e7eb; border-radius:10px; padding:20px; margin-bottom:20px;">
        <p style="font-size:13px; font-weight:700; color:#111827; margin:0 0 4px;">Structuring — clients alimentés par plusieurs agents</p>
        <p style="font-size:11px; color:#9ca3af; margin:0 0 16px;">Basé sur les cash-in quotidiens agrégés par client et par agent.</p>

        <div style="display:flex; align-items:flex-end; gap:12px; flex-wrap:wrap; margin-bottom:16px;">
            <div>
                <label style="font-size:11px; color:#6b7280; display:block; margin-bottom:4px;">Date début</label>
                <input type="date" wire:model="struct_date_debut"
                       style="border:1px solid #d1d5db; border-radius:7px; padding:8px 10px; font-size:13px; color:#111827; outline:none;">
            </div>
            <div>
                <label style="font-size:11px; color:#6b7280; display:block; margin-bottom:4px;">Date fin</label>
                <input type="date" wire:model="struct_date_fin"
                       style="border:1px solid #d1d5db; border-radius:7px; padding:8px 10px; font-size:13px; color:#111827; outline:none;">
            </div>
            <div>
                <label style="font-size:11px; color:#6b7280; display:block; margin-bottom:4px;">Nb agents min.</label>
                <input type="number" min="2" wire:model="struct_min_agents"
                       style="width:100px; border:1px solid #d1d5db; border-radius:7px; padding:8px 10px; font-size:13px; color:#111827; outline:none;">
            </div>
            <button wire:click="analyserStructuring" wire:loading.attr="disabled" wire:target="analyserStructuring"
                    style="background:#00843D; color:#fff; font-size:13px; font-weight:600; padding:9px 22px; border-radius:8px; border:none; cursor:pointer;">
                Analyser
            </button>
        </div>

        @if($struct_searched)
            @if($structuring->isEmpty())
                <p style="font-size:12px; color:#9ca3af; text-align:center; padding:24px;">Aucun client au-dessus du seuil sur cette période.</p>
            @else
                <div style="overflow-x:auto;">
                    <table style="width:100%; border-collapse:collapse; font-size:12px;">
                        <thead>
                            <tr style="background:#F7F8FC;">
                                <th style="padding:10px 16px; text-align:left; color:#6b7280; font-weight:500; border-bottom:1px solid #e5e7eb;"></th>
                                <th style="padding:10px 16px; text-align:left; color:#6b7280; font-weight:500; border-bottom:1px solid #e5e7eb;">Date</th>
                                <th style="padding:10px 16px; text-align:left; color:#6b7280; font-weight:500; border-bottom:1px solid #e5e7eb;">Client</th>
                                <th style="padding:10px 16px; text-align:center; color:#6b7280; font-weight:500; border-bottom:1px solid #e5e7eb;">Agents distincts</th>
                                <th style="padding:10px 16px; text-align:center; color:#6b7280; font-weight:500; border-bottom:1px solid #e5e7eb;">Nb cash-in</th>
                                <th style="padding:10px 16px; text-align:right; color:#6b7280; font-weight:500; border-bottom:1px solid #e5e7eb;">Montant total</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($structuring as $row)
                                <tr style="border-bottom:1px solid #f3f4f6; cursor:pointer;"
                                    wire:click="toggleStructCustomer('{{ $row->customer_id }}')"
                                    onmouseover="this.style.background='#F7F8FC'"
                                    onmouseout="this.style.background='transparent'">
                                    <td style="padding:10px 16px; color:#9ca3af; width:20px;">
                                        {{ $struct_expanded_customer === $row->customer_id ? '▾' : '▸' }}
                                    </td>
                                    <td style="padding:10px 16px; color:#6b7280;">{{ \Carbon\Carbon::parse($row->activity_date)->format('d/m/Y') }}</td>
                                    <td style="padding:10px 16px; color:#111827; font-weight:500;">{{ $row->customer_id }}</td>
                                    <td style="padding:10px 16px; text-align:center;">
                                        <span style="background:{{ $row->unique_agents >= 5 ? '#FDE8E8' : '#FEF3C7' }}; color:{{ $row->unique_agents >= 5 ? '#7F1D1D' : '#92400E' }}; font-size:11px; font-weight:700; padding:2px 10px; border-radius:20px;">
                                            {{ $row->unique_agents }}
                                        </span>
                                    </td>
                                    <td style="padding:10px 16px; text-align:center; color:#6b7280;">{{ number_format($row->total_cashin_count, 0, ',', ' ') }}</td>
                                    <td style="padding:10px 16px; text-align:right; color:#111827;">{{ number_format($row->total_cashin_amount*100, 0, ',', ' ') }}</td>
                                </tr>

                                @if($struct_expanded_customer === $row->customer_id)
                                    <tr>
                                        <td colspan="6" style="padding:0; background:#FAFBFC;">
                                            <div style="padding:14px 16px 14px 46px;">
                                                <p style="font-size:11px; font-weight:600; color:#6b7280; margin:0 0 8px;">Détail par agent</p>
                                                @forelse($struct_detail as $d)
                                                    <div style="display:flex; align-items:center; justify-content:space-between; padding:6px 0; font-size:12px; border-bottom:1px solid #eef0f3;">
                                                        <span style="color:#111827;">{{ $d['agent_id'] }}</span>
                                                        <span style="color:#6b7280;">{{ \Carbon\Carbon::parse($d['activity_date'])->format('d/m/Y') }}</span>
                                                        <span style="color:#6b7280;">{{ number_format($d['cashin_count'], 0, ',', ' ') }} opérations</span>
                                                        <span style="color:#111827; font-weight:500;">{{ number_format($d['cashin_amount']*100, 0, ',', ' ') }}</span>
                                                    </div>
                                                @empty
                                                    <p style="font-size:12px; color:#9ca3af;">Aucun détail.</p>
                                                @endforelse
                                            </div>
                                        </td>
                                    </tr>
                                @endif
                            @endforeach
                        </tbody>
                    </table>
                </div>
                <div style="padding:12px 0 0;">
                    {{ $structuring->links() }}
                </div>
            @endif
        @endif
    </div>

    {{-- SECTION CHAÎNES DE TRANSACTIONS --}}
    <div style="background:#fff; border:1px solid #e5e7eb; border-radius:10px; padding:20px;">
        <p style="font-size:13px; font-weight:700; color:#111827; margin:0 0 4px;">Chaînes de transactions — Cash In → Send Money → Cash Out/W2B</p>
        <p style="font-size:11px; color:#9ca3af; margin:0 0 16px;">Le ratio de rétention proche de 100% combiné à une durée courte est un signal de risque élevé.</p>

        <div style="display:flex; align-items:flex-end; gap:12px; flex-wrap:wrap; margin-bottom:16px;">
            <div>
                <label style="font-size:11px; color:#6b7280; display:block; margin-bottom:4px;">Date début</label>
                <input type="date" wire:model="chain_date_debut"
                       style="border:1px solid #d1d5db; border-radius:7px; padding:8px 10px; font-size:13px; color:#111827; outline:none;">
            </div>
            <div>
                <label style="font-size:11px; color:#6b7280; display:block; margin-bottom:4px;">Date fin</label>
                <input type="date" wire:model="chain_date_fin"
                       style="border:1px solid #d1d5db; border-radius:7px; padding:8px 10px; font-size:13px; color:#111827; outline:none;">
            </div>
            <button wire:click="analyserChains" wire:loading.attr="disabled" wire:target="analyserChains"
                    style="background:#00843D; color:#fff; font-size:13px; font-weight:600; padding:9px 22px; border-radius:8px; border:none; cursor:pointer;">
                Analyser
            </button>
        </div>

        @if($chain_searched)
            @if($chains->isEmpty())
                <p style="font-size:12px; color:#9ca3af; text-align:center; padding:24px;">Aucune chaîne détectée sur cette période.</p>
            @else
                

                <div style="overflow-x:auto;">
                    <table style="width:100%; border-collapse:collapse; font-size:12px;">
                        <thead>
                            <tr style="background:#F7F8FC;">
                                <th style="padding:10px 14px; text-align:left; color:#6b7280; font-weight:500; border-bottom:1px solid #e5e7eb;"></th>
                                <th style="padding:10px 14px; text-align:left; color:#6b7280; font-weight:500; border-bottom:1px solid #e5e7eb;">Date</th>
                                <th style="padding:10px 14px; text-align:left; color:#6b7280; font-weight:500; border-bottom:1px solid #e5e7eb;">Agent origine</th>
                                <th style="padding:10px 14px; text-align:left; color:#6b7280; font-weight:500; border-bottom:1px solid #e5e7eb;">Client origine</th>
                                <th style="padding:10px 14px; text-align:right; color:#6b7280; font-weight:500; border-bottom:1px solid #e5e7eb;">Montant initial</th>
                                <th style="padding:10px 14px; text-align:right; color:#6b7280; font-weight:500; border-bottom:1px solid #e5e7eb;">Montant final</th>
                                <th style="padding:10px 14px; text-align:center; color:#6b7280; font-weight:500; border-bottom:1px solid #e5e7eb;">Poids de la Chaine</th>
                                <th style="padding:10px 14px; text-align:center; color:#6b7280; font-weight:500; border-bottom:1px solid #e5e7eb;">Durée</th>
                                <th style="padding:10px 14px; text-align:center; color:#6b7280; font-weight:500; border-bottom:1px solid #e5e7eb;">Rétention</th>
                                <th style="padding:10px 14px; text-align:left; color:#6b7280; font-weight:500; border-bottom:1px solid #e5e7eb;">Sortie</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($chains as $chain)
                                @php $risk = $this->riskLevel($chain->amount_retention_ratio); @endphp
                                <tr style="border-bottom:1px solid #f3f4f6; cursor:pointer;
                                           {{ $risk === 'high' ? 'background:#FEF7F7;' : '' }}"
                                    wire:click="toggleChain({{ $chain->chain_id }})"
                                    onmouseover="this.style.background='#F7F8FC'"
                                    onmouseout="this.style.background='{{ $risk === 'high' ? '#FEF7F7' : 'transparent' }}'">
                                    <td style="padding:10px 14px; color:#9ca3af;">
                                        {{ $chain_expanded_id === $chain->chain_id ? '▾' : '▸' }}
                                    </td>
                                    <td style="padding:10px 14px; color:#6b7280;">{{ \Carbon\Carbon::parse($chain->activity_date)->format('d/m/Y') }}</td>
                                    <td style="padding:10px 14px; color:#111827;">{{ $chain->origin_agent_id }}</td>
                                    <td style="padding:10px 14px; color:#111827;">{{ $chain->origin_customer_id }}</td>
                                    <td style="padding:10px 14px; text-align:right; color:#6b7280;">{{ number_format($chain->initial_amount*100, 0, ',', ' ') }}</td>
                                    <td style="padding:10px 14px; text-align:right; color:#111827; font-weight:500;">{{ number_format($chain->final_amount*100, 0, ',', ' ') }}</td>
                                    <td style="padding:10px 14px; text-align:center; color:#6b7280;">{{ $chain->send_money_hops }}</td>
                                    <td style="padding:10px 14px; text-align:center; color:#6b7280;">{{ $this->formatDuration($chain->duration_seconds) }}</td>
                                    <td style="padding:10px 14px; text-align:center;">
                                        <span style="background:{{ $risk === 'high' ? '#FDE8E8' : ($risk === 'medium' ? '#FEF3C7' : '#E5F5ED') }};
                                                     color:{{ $risk === 'high' ? '#7F1D1D' : ($risk === 'medium' ? '#92400E' : '#005C2B') }};
                                                     font-size:11px; font-weight:700; padding:2px 10px; border-radius:20px;">
                                            {{ number_format(((float) $chain->amount_retention_ratio), 0) }}%
                                        </span>
                                    </td>
                                    <td style="padding:10px 14px; color:#6b7280;">{{ $chain->final_transaction_type }}</td>
                                </tr>

                                @if($chain_expanded_id === $chain->chain_id)
                                    <tr>
                                        <td colspan="9" style="padding:0; background:#FAFBFC;">
                                            <div style="padding:16px 16px 16px 46px;">
                                                <p style="font-size:11px; font-weight:600; color:#6b7280; margin:0 0 10px;">Parcours de la chaîne</p>
                                                <div style="display:flex; flex-direction:column; gap:0;">
                                                    @foreach($chain_members as $i => $m)
                                                        <div style="display:flex; gap:12px; padding-bottom:14px; position:relative;">
                                                            <div style="display:flex; flex-direction:column; align-items:center; flex-shrink:0;">
                                                                <div style="width:10px; height:10px; border-radius:50%; background:{{ $i === 0 ? '#00843D' : ($i === count($chain_members)-1 ? '#E24B4A' : '#1B2F6E') }};"></div>
                                                                @if($i < count($chain_members) - 1)
                                                                    <div style="width:1.5px; flex:1; background:#e5e7eb; margin-top:2px;"></div>
                                                                @endif
                                                            </div>
                                                            <div style="font-size:12px; padding-bottom:2px;">
                                                                <p style="margin:0; color:#111827; font-weight:500;">
                                                                    {{ $m['transaction_type'] }}
                                                                    <span style="color:#9ca3af; font-weight:400;">— {{ \Carbon\Carbon::parse($m['transaction_time'])->format('d/m/Y H:i:s') }}</span>
                                                                </p>
                                                                <p style="margin:2px 0 0; color:#6b7280;">
                                                                    {{ $m['from_msisdn'] }} → {{ $m['to_msisdn'] }}
                                                                    · <span style="color:#111827; font-weight:500;">{{ number_format($m['amount']*100, 0, ',', ' ') }}</span>
                                                                    @if((float) ($m['commission']*100 ?? 0) > 0)
                                                                        <span style="color:#9ca3af;">(commission {{ number_format($m['commission']*100, 0, ',', ' ') }})</span>
                                                                    @endif
                                                                </p>
                                                            </div>
                                                        </div>
                                                    @endforeach
                                                </div>
                                            </div>
                                        </td>
                                    </tr>
                                @endif
                            @endforeach
                        </tbody>
                    </table>
                </div>
                <div style="padding:12px 0 0;">
                    {{ $chains->links() }}
                </div>


                {{-- RÉCAPITULATIF PAR AGENT D'ORIGINE --}}
                <div style="margin-bottom:20px;">
                    <p style="font-size:12px; font-weight:600; color:#111827; margin:0 0 10px;">Récapitulatif par agent d'origine</p>
                    <div style="overflow-x:auto; border:1px solid #e5e7eb; border-radius:8px;">
                        <table style="width:100%; border-collapse:collapse; font-size:12px;">
                            <thead>
                                <tr style="background:#F7F8FC;">
                                    <th style="padding:8px 14px; text-align:left; color:#6b7280; font-weight:500; border-bottom:1px solid #e5e7eb;">Agent</th>
                                    <th style="padding:8px 14px; text-align:center; color:#6b7280; font-weight:500; border-bottom:1px solid #e5e7eb;">Nb chaînes</th>
                                    <th style="padding:8px 14px; text-align:right; color:#6b7280; font-weight:500; border-bottom:1px solid #e5e7eb;">Commission totale</th>
                                </tr>
                            </thead>
                            <tbody>
                                @forelse($agentSummary as $agent)
                                    <tr style="border-bottom:1px solid #f3f4f6;">
                                        <td style="padding:8px 14px; color:#111827; font-weight:500;">{{ $agent->origin_agent_id }}</td>
                                        <td style="padding:8px 14px; text-align:center; color:#6b7280;">{{ number_format($agent->nb_chains, 0, ',', ' ') }}</td>
                                        <td style="padding:8px 14px; text-align:right; color:#111827;">{{ number_format($agent->total_commission, 0, ',', ' ') }}</td>
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="3" style="padding:16px; text-align:center; color:#9ca3af;">Aucun agent sur cette période.</td>
                                    </tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </div>
            @endif
        @endif
    </div>

</div>
