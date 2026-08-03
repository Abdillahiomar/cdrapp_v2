<?php

use Livewire\Volt\Component;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

new class extends Component {

    public string $date_debut = '';
    public string $date_fin   = '';
    public bool   $searched   = false;

    public array $top_mp_clients   = [];
    public array $top_mp_marchands = [];
    public array $top_agents_ci    = [];
    public array $top_masters_bci  = [];
    public array $top_send_clients = [];

    public function mount()
    {
        $this->date_debut = Carbon::now()->subDays(7)->format('Y-m-d');
        $this->date_fin   = Carbon::now()->format('Y-m-d');
    }

    private function reasonIndexesFor(string $needle): array
    {
        return \App\Models\reasonType::query()
            ->whereRaw('LOWER(reason_name) LIKE ?', ['%' . strtolower($needle) . '%'])
            ->pluck('reason_index')
            ->map(fn($v) => (int) $v)
            ->all();
    }

    private function inClause(array $indexes): string
    {
        return empty($indexes) ? 'IN (NULL)' : 'IN (' . implode(',', $indexes) . ')';
    }

    public function search()
    {
        set_time_limit(120);

        $debut = $this->date_debut . ' 00:00:00';
        $fin   = Carbon::parse($this->date_fin)->addDay()->format('Y-m-d') . ' 00:00:00';

        $idxMerchant = $this->inClause($this->reasonIndexesFor('merchant payment'));
        $idxCashin   = $this->inClause($this->reasonIndexesFor('customer cash in'));
        $idxBusiness = $this->inClause($this->reasonIndexesFor('business cash in'));
        $idxSend     = $this->inClause($this->reasonIndexesFor('send money'));

        // ── 1. TOP 10 clients Merchant Payment ──
        $this->top_mp_clients = DB::select("
            SELECT
                debit_party_identifier          AS client,
                COUNT(*)                        AS nb_operations,
                SUM(actual_amount)              AS volume_total
            FROM fact_txn_v2
            WHERE transaction_initiated_time >= ? AND transaction_initiated_time < ?
              AND reason_index {$idxMerchant}
              AND status = 'Completed'
            GROUP BY debit_party_identifier
            ORDER BY nb_operations DESC
            LIMIT 10
        ", [$debut, $fin]);

        // ── 2. TOP 10 marchands Merchant Payment reçus ──
        $this->top_mp_marchands = DB::select("
            SELECT
                credit_party_identifier         AS marchand,
                COUNT(*)                        AS nb_operations,
                SUM(actual_amount)              AS volume_total
            FROM fact_txn_v2
            WHERE transaction_initiated_time >= ? AND transaction_initiated_time < ?
              AND reason_index {$idxMerchant}
              AND status = 'Completed'
            GROUP BY credit_party_identifier
            ORDER BY volume_total DESC
            LIMIT 10
        ", [$debut, $fin]);

        // ── 3. TOP 10 agents commission Customer Cash In ──
        $this->top_agents_ci = DB::select("
            SELECT
                debit_party_identifier          AS agent,
                COUNT(*)                        AS nb_operations,
                SUM(commission_amount)          AS total_commission,
                SUM(actual_amount)              AS volume_total
            FROM fact_txn_v2
            WHERE transaction_initiated_time >= ? AND transaction_initiated_time < ?
              AND reason_index {$idxCashin}
              AND status = 'Completed'
            GROUP BY debit_party_identifier
            ORDER BY total_commission DESC
            LIMIT 10
        ", [$debut, $fin]);

        // ── 4. TOP 10 Masters Business Cash In ──
        $this->top_masters_bci = DB::select("
            SELECT
                debit_party_identifier          AS master,
                COUNT(*)                        AS nb_operations,
                SUM(actual_amount)              AS volume_total
            FROM fact_txn_v2
            WHERE transaction_initiated_time >= ? AND transaction_initiated_time < ?
              AND reason_index {$idxBusiness}
              AND status = 'Completed'
            GROUP BY debit_party_identifier
            ORDER BY nb_operations DESC
            LIMIT 10
        ", [$debut, $fin]);

        // ── 5. TOP 10 clients Send Money ──
        $this->top_send_clients = DB::select("
            SELECT
                debit_party_identifier          AS client,
                COUNT(*)                        AS nb_operations,
                SUM(actual_amount)              AS volume_total
            FROM fact_txn_v2
            WHERE transaction_initiated_time >= ? AND transaction_initiated_time < ?
              AND reason_index {$idxSend}
              AND status = 'Completed'
            GROUP BY debit_party_identifier
            ORDER BY nb_operations DESC
            LIMIT 10
        ", [$debut, $fin]);

        $toArray = fn($rows) => array_map(fn($r) => (array) $r, $rows);

        $this->top_mp_clients   = $toArray($this->top_mp_clients);
        $this->top_mp_marchands = $toArray($this->top_mp_marchands);
        $this->top_agents_ci    = $toArray($this->top_agents_ci);
        $this->top_masters_bci  = $toArray($this->top_masters_bci);
        $this->top_send_clients = $toArray($this->top_send_clients);

        $this->searched = true;
    }

    public function with(): array
    {
        return [];
    }
};
?>
<div>
<div style="padding:24px;">

    {{-- FILTRES --}}
    <div style="background:#fff; border:1px solid #e5e7eb; border-radius:12px; padding:20px; margin-bottom:20px;">

        <p style="font-size:14px; font-weight:700; color:#111827; margin-bottom:16px;">
            Classements TOP 10 — Sélectionnez une période
        </p>

        <div style="display:flex; align-items:flex-end; gap:12px; flex-wrap:wrap;">
            <div>
                <label style="font-size:11px; color:#6b7280; display:block; margin-bottom:4px;">Date début</label>
                <input type="date" wire:model="date_debut"
                       style="border:1px solid #d1d5db; border-radius:7px; padding:8px 10px; font-size:13px; color:#111827; outline:none;">
            </div>
            <div>
                <label style="font-size:11px; color:#6b7280; display:block; margin-bottom:4px;">Date fin</label>
                <input type="date" wire:model="date_fin"
                       style="border:1px solid #d1d5db; border-radius:7px; padding:8px 10px; font-size:13px; color:#111827; outline:none;">
            </div>

            <button wire:click="search"
                    wire:loading.attr="disabled"
                    wire:target="search"
                    style="background:#00843D; color:#fff; font-size:13px; font-weight:600; padding:9px 22px; border-radius:8px; border:none; cursor:pointer; display:flex; align-items:center; gap:7px;">
                <span wire:loading.remove wire:target="search" style="display:flex; align-items:center; gap:7px;">
                    <svg width="14" height="14" viewBox="0 0 16 16" fill="white">
                        <circle cx="6.5" cy="6.5" r="4.5" stroke="white" stroke-width="1.5" fill="none"/>
                        <path d="M10.5 10.5L14 14" stroke="white" stroke-width="1.5" stroke-linecap="round"/>
                    </svg>
                    Rechercher
                </span>
                <span wire:loading.inline-flex wire:target="search" style="align-items:center; gap:7px;">
                    <svg width="14" height="14" viewBox="0 0 40 40" fill="none" style="animation:spin 0.8s linear infinite;">
                        <circle cx="20" cy="20" r="16" stroke="rgba(255,255,255,0.3)" stroke-width="4"/>
                        <path d="M20 4a16 16 0 0116 16" stroke="white" stroke-width="4" stroke-linecap="round"/>
                    </svg>
                    Chargement...
                </span>
            </button>
        </div>

        <style>
            @keyframes spin { from { transform:rotate(0deg); } to { transform:rotate(360deg); } }
        </style>
    </div>

    @if(!$searched)
        <div style="text-align:center; padding:60px 20px; background:#fff; border:1px solid #e5e7eb; border-radius:10px;">
            <div style="width:52px; height:52px; background:#E5F5ED; border-radius:14px; display:flex; align-items:center; justify-content:center; margin:0 auto 14px;">
                <svg width="26" height="26" viewBox="0 0 24 24" fill="none">
                    <path d="M4 20V10M12 20V4M20 20v-7" stroke="#00843D" stroke-width="2" stroke-linecap="round"/>
                </svg>
            </div>
            <p style="font-size:14px; font-weight:600; color:#111827; margin-bottom:4px;">Aucune donnée à afficher</p>
            <p style="font-size:12px; color:#9ca3af;">Choisissez une période puis cliquez sur <strong>Rechercher</strong>.</p>
        </div>
    @else

        {{-- 1. TOP 10 clients Merchant Payment --}}
        @include('livewire.top10._top_table', [
            'titre'    => 'TOP 10 clients — Merchant Payment (par nombre)',
            'couleur'  => '#F5A800', 'bgBadge' => '#FFF3D0', 'colBadge' => '#7A4F00',
            'rows'     => $top_mp_clients,
            'cols'     => [
                'client'        => ['label' => 'Client (MSISDN)', 'type' => 'text',   'bold' => true],
                'nb_operations' => ['label' => 'Nb paiements',    'type' => 'int'],
                'volume_total'  => ['label' => 'Volume total',    'type' => 'amount'],
            ],
        ])

        {{-- 2. TOP 10 marchands Merchant Payment --}}
        @include('livewire.top10._top_table', [
            'titre'    => 'TOP 10 marchands — Merchant Payment reçus (par volume)',
            'couleur'  => '#1B2F6E', 'bgBadge' => '#E8ECF8', 'colBadge' => '#1B2F6E',
            'rows'     => $top_mp_marchands,
            'cols'     => [
                'marchand'      => ['label' => 'Marchand (MSISDN)', 'type' => 'text',   'bold' => true],
                'nb_operations' => ['label' => 'Nb paiements',      'type' => 'int'],
                'volume_total'  => ['label' => 'Volume reçu',       'type' => 'amount'],
            ],
        ])

        {{-- 3. TOP 10 agents commission Customer Cash In --}}
        @include('livewire.top10._top_table', [
            'titre'    => 'TOP 10 agents — Commission sur Customer Cash In',
            'couleur'  => '#00843D', 'bgBadge' => '#E5F5ED', 'colBadge' => '#005C2B',
            'rows'     => $top_agents_ci,
            'cols'     => [
                'agent'            => ['label' => 'Agent (MSISDN)',    'type' => 'text',   'bold' => true],
                'nb_operations'    => ['label' => 'Nb cash in',        'type' => 'int'],
                'total_commission' => ['label' => 'Commission totale', 'type' => 'amount'],
                'volume_total'     => ['label' => 'Volume cash in',    'type' => 'amount'],
            ],
        ])

        {{-- 4. TOP 10 Masters Business Cash In --}}
        @include('livewire.top10._top_table', [
            'titre'    => 'TOP 10 Masters — Business Cash In (par nombre)',
            'couleur'  => '#9333ea', 'bgBadge' => '#F3E8FF', 'colBadge' => '#6B21A8',
            'rows'     => $top_masters_bci,
            'cols'     => [
                'master'        => ['label' => 'Master (MSISDN)', 'type' => 'text',   'bold' => true],
                'nb_operations' => ['label' => 'Nb opérations',   'type' => 'int'],
                'volume_total'  => ['label' => 'Volume total',    'type' => 'amount'],
            ],
        ])

        {{-- 5. TOP 10 clients Send Money --}}
        @include('livewire.top10._top_table', [
            'titre'    => 'TOP 10 clients — Send Money (par nombre)',
            'couleur'  => '#E24B4A', 'bgBadge' => '#FDECEA', 'colBadge' => '#7F1D1D',
            'rows'     => $top_send_clients,
            'cols'     => [
                'client'        => ['label' => 'Client (MSISDN)', 'type' => 'text',   'bold' => true],
                'nb_operations' => ['label' => 'Nb envois',       'type' => 'int'],
                'volume_total'  => ['label' => 'Volume total',    'type' => 'amount'],
            ],
        ])

    @endif

</div>
</div>