<?php

use Livewire\Volt\Component;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

new class extends Component {

    public string $date_debut = '';
    public string $date_fin   = '';
    public bool   $searched   = false;

    // KPIs (FDJ, depuis manager_daily_stats)
    public float $volume_total    = 0;
    public int   $nb_transactions = 0;
    public float $revenus         = 0;
    public float $total_clawback  = 0;   // montant des Commission Claw Back (reversé au Service Provider)
    public float $ticket_moyen    = 0;
    public float $taux_reussite   = 0;

    // Réseau
    public int $agents_actifs    = 0;
    public int $marchands_actifs = 0;
    public int $clients_actifs = 0;

    // Graphiques : données envoyées au JS par l'événement dashboard-updated (RevenueDashboard)
    public ?array  $top_payers = null;    // tableau du Top payeurs ; null = table pas encore créée
    public int     $top_limit  = 10;      // nombre de payeurs affichés : 10 → 100, par 10
    public ?string $last_date  = null;    // dernier jour agrégé par manager:aggregate

    // Comparaison période précédente
    public ?float $volume_precedent = null;
    public ?float $variation_volume = null;

    // Suivi Airtime (filtre de dates indépendant)
    public string $date_debut_airtime = '';
    public string $date_fin_airtime   = '';
    public bool   $searched_airtime   = false;

    public array $airtime_serie           = []; // [{jour, volume, nb}]
    public array $airtime_repartition     = []; // [{type, volume, nb}]
    public float $airtime_volume_total    = 0;
    public int   $airtime_nb_transactions = 0;
    public float $airtime_ticket_moyen    = 0;

    public function mount()
    {
        $this->date_debut = Carbon::now()->subDays(7)->format('Y-m-d');
        $this->date_fin   = Carbon::now()->format('Y-m-d');

        $this->date_debut_airtime = Carbon::now()->subDays(7)->format('Y-m-d');
        $this->date_fin_airtime   = Carbon::now()->format('Y-m-d');
    }

    private function reasonIndexesFor(string $needle): array
    {
        return \App\Models\ReasonType::query()
            ->whereRaw('LOWER(reason_name) LIKE ?', ['%' . strtolower($needle) . '%'])
            ->pluck('reason_index')
            ->map(fn($v) => (int) $v)
            ->all();
    }

    public function search()
    {
        set_time_limit(120);

        $debut = $this->date_debut . ' 00:00:00';
        $fin   = Carbon::parse($this->date_fin)->addDay()->format('Y-m-d') . ' 00:00:00';

        // reason_index pour identifier agents (cash in) et marchands (merchant payment)
        $idxCashin   = $this->reasonIndexesFor('customer cash in');
        $idxMerchant = $this->reasonIndexesFor('merchant payment');

        // ── Cadres et graphiques : agrégats de manager:aggregate (montants en FDJ) ──
        $dash = (new \App\Support\RevenueDashboard($this->date_debut, $this->date_fin, $this->top_limit))->build();

        foreach (['volume_total', 'nb_transactions', 'revenus', 'total_clawback', 'ticket_moyen', 'taux_reussite', 'volume_precedent', 'variation_volume'] as $k) {
            $this->{$k} = $dash['kpi'][$k];
        }
        $this->last_date = $dash['last_date'];
        unset($dash['kpi']);

        // ── Agents actifs (distincts sur cash in) ──
        if (!empty($idxCashin)) {
            $in = implode(',', $idxCashin);
            $this->agents_actifs = (int) DB::selectOne("
                SELECT COUNT(DISTINCT debit_party_identifier) AS n
                FROM fact_txn_v2
                WHERE transaction_initiated_time >= ? AND transaction_initiated_time < ?
                  AND reason_index IN ($in)
            ", [$debut, $fin])->n;
        }

        // ── Marchands actifs (distincts sur merchant payment) ──
if (!empty($idxMerchant)) {
    $in = implode(',', $idxMerchant);
    $this->marchands_actifs = (int) DB::selectOne("
        SELECT COUNT(DISTINCT credit_party_identifier) AS n
        FROM fact_txn_v2
        WHERE transaction_initiated_time >= ? AND transaction_initiated_time < ?
          AND reason_index IN ($in)
    ", [$debut, $fin])->n;
}

        $this->clients_actifs = (int) DB::selectOne("
                SELECT COUNT(DISTINCT debit_party_identifier) AS n
                FROM fact_txn_v2
                WHERE transaction_initiated_time >= ? AND transaction_initiated_time < ?
                AND debit_party_type = 'Customer'
                AND status = 'Completed'
                AND debit_party_identifier IS NOT NULL
                AND debit_party_identifier <> ''
            ", [$debut, $fin])->n;

        $this->searched = true;

        $this->top_payers = $dash['top_payers'];

        // Envoi des données aux graphiques JS
        $this->dispatch('dashboard-updated', dash: $dash);
    }

    /** Changement du nombre de payeurs : seul le Top payeurs est recalculé */
    public function updatedTopLimit(): void
    {
        $this->top_limit = max(10, min(100, (int) round($this->top_limit / 10) * 10));

        if (!$this->searched) {
            return;
        }

        $this->top_payers = (new \App\Support\RevenueDashboard($this->date_debut, $this->date_fin, $this->top_limit))->topPayers();
        $this->dispatch('top-payers-updated', top: $this->top_payers ?? []);
    }

    public function searchAirtime()
    {
        set_time_limit(120);

        $debut = $this->date_debut_airtime . ' 00:00:00';
        $fin   = Carbon::parse($this->date_fin_airtime)->addDay()->format('Y-m-d') . ' 00:00:00';

        // Sous-types Airtime (mêmes libellés que le regroupement du récapitulatif)
        $airtimeTypes = ['self top up', 'third top up', 'bulk buy airtime', 'purchase airtime package'];
        $likeClauses  = implode(' OR ', array_fill(0, count($airtimeTypes), 'LOWER(tt.alias) LIKE ?'));
        $likeParams   = array_map(fn($t) => '%' . strtolower($t) . '%', $airtimeTypes);

        // ── KPIs Airtime ──
        $kpi = DB::selectOne("
            SELECT
                COALESCE(SUM(f.actual_amount), 0) AS volume_total,
                COUNT(*)                          AS nb_transactions,
                COALESCE(AVG(f.actual_amount), 0) AS ticket_moyen
            FROM fact_txn_v2 f
            JOIN transaction_types tt ON tt.txn_index = f.txn_index
            WHERE f.transaction_initiated_time >= ? AND f.transaction_initiated_time < ?
            AND ($likeClauses)
            AND f.status = 'Completed'
        ", array_merge([$debut, $fin], $likeParams));

        $this->airtime_volume_total    = (float) $kpi->volume_total;
        $this->airtime_nb_transactions = (int)   $kpi->nb_transactions;
        $this->airtime_ticket_moyen    = (float) $kpi->ticket_moyen;

        // ── Série temporelle par jour ──
        $this->airtime_serie = array_map(fn($r) => (array) $r, DB::select("
            SELECT
                f.transaction_initiated_time::date AS jour,
                COALESCE(SUM(f.actual_amount), 0)  AS volume,
                COUNT(*)                           AS nb
            FROM fact_txn_v2 f
            JOIN transaction_types tt ON tt.txn_index = f.txn_index
            WHERE f.transaction_initiated_time >= ? AND f.transaction_initiated_time < ?
            AND ($likeClauses)
            AND f.status = 'Completed'
            GROUP BY f.transaction_initiated_time::date
            ORDER BY jour
        ", array_merge([$debut, $fin], $likeParams)));

        // ── Répartition par sous-type Airtime ──
        $this->airtime_repartition = array_map(fn($r) => (array) $r, DB::select("
            SELECT
                COALESCE(tt.alias, tt.txn_type_name) AS type,
                COALESCE(SUM(f.actual_amount), 0)    AS volume,
                COUNT(*)                             AS nb
            FROM fact_txn_v2 f
            JOIN transaction_types tt ON tt.txn_index = f.txn_index
            WHERE f.transaction_initiated_time >= ? AND f.transaction_initiated_time < ?
            AND ($likeClauses)
            AND f.status = 'Completed'
            GROUP BY COALESCE(tt.alias, tt.txn_type_name)
            ORDER BY volume DESC
        ", array_merge([$debut, $fin], $likeParams)));

        $this->searched_airtime = true;

        $this->dispatch('airtime-updated',
            serie:        $this->airtime_serie,
            repartition:  $this->airtime_repartition,
        );
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
            Tableau de bord des transactions
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
            <button wire:click="search" wire:loading.attr="disabled" wire:target="search"
                    style="background:#00843D; color:#fff; font-size:13px; font-weight:600; padding:9px 22px; border-radius:8px; border:none; cursor:pointer; display:flex; align-items:center; gap:7px;">
                <span wire:loading.remove wire:target="search">Rechercher</span>
                <span wire:loading.inline-flex wire:target="search" style="align-items:center; gap:7px;">
                    <svg width="14" height="14" viewBox="0 0 40 40" fill="none" style="animation:spin 0.8s linear infinite;">
                        <circle cx="20" cy="20" r="16" stroke="rgba(255,255,255,0.3)" stroke-width="4"/>
                        <path d="M20 4a16 16 0 0116 16" stroke="white" stroke-width="4" stroke-linecap="round"/>
                    </svg>
                    Chargement...
                </span>
            </button>
        </div>
        <style>@keyframes spin { from{transform:rotate(0)} to{transform:rotate(360deg)} }</style>
    </div>

    @if(!$searched)
        <div style="text-align:center; padding:60px 20px; background:#fff; border:1px solid #e5e7eb; border-radius:10px;">
            <p style="font-size:14px; font-weight:600; color:#111827; margin-bottom:4px;">Aucune donnée à afficher</p>
            <p style="font-size:12px; color:#9ca3af;">Choisissez une période puis cliquez sur <strong>Rechercher</strong>.</p>
        </div>
    @else

        @if($last_date && $last_date < $date_fin)
            <div style="background:#FEF3C7; color:#92400E; border:1px solid #FCD34D; border-radius:8px; padding:10px 14px; font-size:12px; margin-bottom:16px;">
                Les chiffres viennent des agrégats calculés chaque nuit : ils s'arrêtent au <strong>{{ \Carbon\Carbon::parse($last_date)->format('d/m/Y') }}</strong> (dernier jour agrégé).
            </div>
        @endif

        {{-- KPIs PRINCIPAUX --}}
        <div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(160px, 1fr)); gap:12px; margin-bottom:16px;">

            <div style="background:#fff; border:1px solid #e5e7eb; border-radius:10px; padding:16px; border-top:3px solid #00843D;">
                <p style="font-size:11px; color:#6b7280; margin:0 0 6px;">Valeur total</p>
                <p style="font-size:22px; font-weight:700; color:#111827; margin:0;">{{ number_format($volume_total, 0, ',', ' ') }}</p>
                <p style="font-size:10px; color:#9ca3af; margin:4px 0 0;">FDJ</p>
                @if($variation_volume !== null)
                    <p style="font-size:11px; font-weight:600; margin:6px 0 0; color:{{ $variation_volume >= 0 ? '#005C2B' : '#7F1D1D' }};">
                        {{ $variation_volume >= 0 ? '▲' : '▼' }} {{ number_format(abs($variation_volume), 1, ',', ' ') }} % vs période préc.
                    </p>
                @endif
            </div>

            <div style="background:#fff; border:1px solid #e5e7eb; border-radius:10px; padding:16px; border-top:3px solid #1B2F6E;">
                <p style="font-size:11px; color:#6b7280; margin:0 0 6px;">Nb transactions</p>
                <p style="font-size:22px; font-weight:700; color:#111827; margin:0;">{{ number_format($nb_transactions, 0, ',', ' ') }}</p>
                <p style="font-size:10px; color:#9ca3af; margin:4px 0 0;">opérations</p>
            </div>

            <div style="background:#fff; border:1px solid #e5e7eb; border-radius:10px; padding:16px; border-top:3px solid #F5A800;">
                <p style="font-size:11px; color:#6b7280; margin:0 0 6px;">Revenus</p>
                <p style="font-size:22px; font-weight:700; color:#111827; margin:0;">{{ number_format($revenus, 0, ',', ' ') }}</p>
                <p style="font-size:10px; color:#9ca3af; margin:4px 0 0;">FDJ (frais - commission)</p>
            </div>

            <div style="background:#fff; border:1px solid #e5e7eb; border-radius:10px; padding:16px; border-top:3px solid #0EA5E9;">
                <p style="font-size:11px; color:#6b7280; margin:0 0 6px;">Total Claw Back</p>
                <p style="font-size:22px; font-weight:700; color:#111827; margin:0;">{{ number_format($total_clawback, 0, ',', ' ') }}</p>
                <p style="font-size:10px; color:#9ca3af; margin:4px 0 0;">FDJ (Commission Claw Back)</p>
            </div>

            <div style="background:#fff; border:1px solid #e5e7eb; border-radius:10px; padding:16px; border-top:3px solid #005C2B;">
                <p style="font-size:11px; color:#6b7280; margin:0 0 6px;">Revenu total</p>
                <p style="font-size:22px; font-weight:700; color:#111827; margin:0;">{{ number_format($revenus + $total_clawback, 0, ',', ' ') }}</p>
                <p style="font-size:10px; color:#9ca3af; margin:4px 0 0;">FDJ (revenus + claw back)</p>
            </div>

            <div style="background:#fff; border:1px solid #e5e7eb; border-radius:10px; padding:16px; border-top:3px solid #9333ea;">
                <p style="font-size:11px; color:#6b7280; margin:0 0 6px;">Ticket moyen</p>
                <p style="font-size:22px; font-weight:700; color:#111827; margin:0;">{{ number_format($ticket_moyen, 0, ',', ' ') }}</p>
                <p style="font-size:10px; color:#9ca3af; margin:4px 0 0;">FDJ / transaction</p>
            </div>

            <div style="background:#fff; border:1px solid #e5e7eb; border-radius:10px; padding:16px; border-top:3px solid #E24B4A;">
                <p style="font-size:11px; color:#6b7280; margin:0 0 6px;">Taux de réussite</p>
                <p style="font-size:22px; font-weight:700; color:#111827; margin:0;">{{ number_format($taux_reussite, 1, ',', ' ') }} %</p>
                <p style="font-size:10px; color:#9ca3af; margin:4px 0 0;">transactions complétées</p>
            </div>
        </div>

        {{-- RÉSEAU --}}
        <div style="display:grid; grid-template-columns:repeat(3, minmax(0,1fr)); gap:12px; margin-bottom:16px;">
            <div style="background:#fff; border:1px solid #e5e7eb; border-radius:10px; padding:16px;">
                <p style="font-size:11px; color:#6b7280; margin:0 0 6px;">Agents actifs (Cash In)</p>
                <p style="font-size:20px; font-weight:700; color:#111827; margin:0;">{{ number_format($agents_actifs, 0, ',', ' ') }}</p>
            </div>
            <div style="background:#fff; border:1px solid #e5e7eb; border-radius:10px; padding:16px;">
                <p style="font-size:11px; color:#6b7280; margin:0 0 6px;">Marchands actifs (Merchant Payment)</p>
                <p style="font-size:20px; font-weight:700; color:#111827; margin:0;">{{ number_format($marchands_actifs, 0, ',', ' ') }}</p>
            </div>
            <div style="background:#fff; border:1px solid #e5e7eb; border-radius:10px; padding:16px;">
                <p style="font-size:11px; color:#6b7280; margin:0 0 6px;">Clients actifs (Une operation minimume)</p>
                <p style="font-size:20px; font-weight:700; color:#111827; margin:0;">{{ number_format($clients_actifs, 0, ',', ' ') }}</p>
            </div>
        </div>

        @php
            $card  = 'background:#fff; border:1px solid #e5e7eb; border-radius:10px; padding:20px; margin-bottom:16px; min-width:0;';
            $title = 'font-size:13px; font-weight:600; color:#111827; margin:0 0 4px;';
            $sub   = 'font-size:11px; color:#9ca3af; margin:0 0 14px;';
            $grid2 = 'display:grid; grid-template-columns:repeat(auto-fit, minmax(420px, 1fr)); gap:16px;';
        @endphp

        {{-- COURBE ÉVOLUTION --}}
        <div wire:ignore style="{{ $card }}">
            <p style="{{ $title }}">Évolution du volume et du nombre de transactions</p>
            <p style="{{ $sub }}">Transactions terminées, par jour</p>
            <div style="position:relative; height:300px;"><canvas id="chartEvolution"></canvas></div>
        </div>

        {{-- 1. ÉVOLUTION DU REVENU --}}
        <div wire:ignore style="{{ $card }}">
            <p style="{{ $title }}">Évolution du revenu par jour</p>
            <p style="{{ $sub }}">Frais encaissés, commissions versées, claw back et revenu net (frais − commissions + claw back)</p>
            <div style="position:relative; height:300px;"><canvas id="chartRevenueDaily"></canvas></div>
        </div>

        {{-- 5. CUMUL DU MOIS + 3. COMPOSITION --}}
        <div style="{{ $grid2 }}">
            <div wire:ignore style="{{ $card }}">
                <p style="{{ $title }}">Revenu cumulé du mois et projection</p>
                <p style="{{ $sub }}" id="cumulSubtitle">Mois de la date de fin</p>
                <div style="position:relative; height:280px;"><canvas id="chartCumul"></canvas></div>
            </div>
            <div wire:ignore style="{{ $card }}">
                <p style="{{ $title }}">Composition du revenu par famille</p>
                <p style="{{ $sub }}">Revenu net de chaque famille de types (les familles en perte sont en rouge)</p>
                <div style="position:relative; height:280px;"><canvas id="chartFamilies"></canvas></div>
            </div>
        </div>

        {{-- REVENUS PAR TYPE --}}
        <div wire:ignore style="{{ $card }}">
            <p style="{{ $title }}">Revenus par type</p>
            <p style="{{ $sub }}">Types au revenu non nul, du plus grand au plus petit</p>
            <div style="position:relative; height:280px;"><canvas id="chartRevenus"></canvas></div>
        </div>

        {{-- 2. FRAIS VS COMMISSIONS --}}
        <div wire:ignore style="{{ $card }}">
            <p style="{{ $title }}">Frais encaissés vs commissions versées, par type</p>
            <p style="{{ $sub }}">Un type est en perte quand ses commissions dépassent ses frais</p>
            <div style="position:relative; height:280px;"><canvas id="chartFeeCommission"></canvas></div>
        </div>

        {{-- 4. COMPARAISON PÉRIODE PRÉCÉDENTE --}}
        <div wire:ignore style="{{ $card }}">
            <p style="{{ $title }}">Revenu par type : comparaison avec la période précédente</p>
            <p style="{{ $sub }}" id="comparisonSubtitle">Période précédente de même durée</p>
            <div style="position:relative; height:280px;"><canvas id="chartComparison"></canvas></div>
        </div>

        {{-- 6. REVENU MOYEN + 7. PAR HEURE --}}
        <div style="{{ $grid2 }}">
            <div wire:ignore style="{{ $card }}">
                <p style="{{ $title }}">Revenu moyen par transaction, par type</p>
                <p style="{{ $sub }}">Revenu du type ÷ nombre de transactions terminées (FDJ)</p>
                <div style="position:relative; height:280px;"><canvas id="chartAvgTxn"></canvas></div>
            </div>
            <div wire:ignore style="{{ $card }}">
                <p style="{{ $title }}">Revenu par heure de la journée</p>
                <p style="{{ $sub }}">Revenu net cumulé sur la période, par heure</p>
                <div style="position:relative; height:280px;"><canvas id="chartHourly"></canvas></div>
            </div>
        </div>

        {{-- 8. TOP DES PAYEURS DE FRAIS --}}
        <div style="{{ $card }}">
            <div style="display:flex; justify-content:space-between; align-items:flex-start; gap:12px; flex-wrap:wrap;">
                <div>
                    <p style="{{ $title }}">Top {{ $top_limit }} des payeurs de frais</p>
                    <p style="{{ $sub }}">Comptes (débiteurs) qui ont payé le plus de frais sur la période</p>
                </div>
                <div style="display:flex; align-items:center; gap:8px;">
                    <label style="font-size:11px; color:#6b7280;">Afficher</label>
                    <select wire:model.live="top_limit"
                            style="border:1px solid #d1d5db; border-radius:7px; padding:6px 10px; font-size:12px; color:#111827; outline:none; background:#fff;">
                        @foreach(range(10, 100, 10) as $n)
                            <option value="{{ $n }}">Top {{ $n }}</option>
                        @endforeach
                    </select>
                    <span wire:loading wire:target="top_limit" style="font-size:11px; color:#9ca3af;">Chargement…</span>
                </div>
            </div>
            @if($top_payers === null)
                <p style="font-size:12px; color:#92400E; background:#FEF3C7; border-radius:8px; padding:10px 14px; margin:0;">
                    La table <code>manager_fee_payers_daily</code> n'existe pas encore : lancez <code>php artisan migrate</code> puis <code>php artisan manager:aggregate --from=AAAA-MM-JJ</code>.
                </p>
            @elseif(empty($top_payers))
                <p style="font-size:12px; color:#9ca3af; margin:0;">Aucun frais payé sur la période (ou période pas encore agrégée).</p>
            @else
                <div style="{{ $grid2 }} align-items:start;">
                    <div wire:ignore style="position:relative; height:300px;"><canvas id="chartTopPayers"></canvas></div>
                    <div style="overflow:auto; max-height:{{ max(300, count($top_payers) * 28) }}px;">
                        <table style="width:100%; border-collapse:collapse; font-size:12px;">
                            <thead>
                                <tr style="background:#F7F8FC;">
                                    @foreach(['#', 'Compte', 'Nom', 'Type', 'Txn', 'Frais (FDJ)'] as $th)
                                        <th style="padding:8px 10px; text-align:{{ in_array($th, ['Txn', 'Frais (FDJ)']) ? 'right' : 'left' }}; color:#6b7280; font-weight:500; border-bottom:1px solid #e5e7eb; white-space:nowrap;">{{ $th }}</th>
                                    @endforeach
                                </tr>
                            </thead>
                            <tbody>
                                @foreach($top_payers as $i => $p)
                                    <tr style="border-bottom:1px solid #f3f4f6;">
                                        <td style="padding:8px 10px; color:#9ca3af;">{{ $i + 1 }}</td>
                                        <td style="padding:8px 10px; font-family:monospace; color:#111827;">{{ $p['party_id'] }}</td>
                                        <td style="padding:8px 10px; color:#111827;">{{ $p['party_name'] ?: '—' }}</td>
                                        <td style="padding:8px 10px; color:#6b7280;">{{ $p['party_type'] === 'Organization' ? 'Organisation' : 'Client' }}</td>
                                        <td style="padding:8px 10px; text-align:right; color:#6b7280;">{{ number_format($p['nb'], 0, ',', ' ') }}</td>
                                        <td style="padding:8px 10px; text-align:right; font-weight:700; color:#00843D;">{{ number_format($p['fee'], 0, ',', ' ') }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </div>
            @endif
        </div>

        {{-- CAMEMBERT TYPE --}}
        <div wire:ignore style="{{ $card }}">
            <p style="{{ $title }}">Répartition du valeur par Type de transaction</p>
            <p style="{{ $sub }}">15 premiers types en valeur (hors mouvements internes de monnaie électronique)</p>
            <div style="position:relative; height:280px;"><canvas id="chartTypes"></canvas></div>
        </div>

    @endif

    {{-- SUIVI AIRTIME --}}
    <div style="background:#fff; border:1px solid #e5e7eb; border-radius:12px; padding:20px; margin-bottom:20px;">
        <p style="font-size:14px; font-weight:700; color:#111827; margin-bottom:16px;">
            Suivi Airtime (Self Top Up, Third Top Up, Bulk Buy Airtime, Purchase Airtime Package)
        </p>
        <div style="display:flex; align-items:flex-end; gap:12px; flex-wrap:wrap;">
            <div>
                <label style="font-size:11px; color:#6b7280; display:block; margin-bottom:4px;">Date début</label>
                <input type="date" wire:model="date_debut_airtime"
                       style="border:1px solid #d1d5db; border-radius:7px; padding:8px 10px; font-size:13px; color:#111827; outline:none;">
            </div>
            <div>
                <label style="font-size:11px; color:#6b7280; display:block; margin-bottom:4px;">Date fin</label>
                <input type="date" wire:model="date_fin_airtime"
                       style="border:1px solid #d1d5db; border-radius:7px; padding:8px 10px; font-size:13px; color:#111827; outline:none;">
            </div>
            <button wire:click="searchAirtime" wire:loading.attr="disabled" wire:target="searchAirtime"
                    style="background:#00843D; color:#fff; font-size:13px; font-weight:600; padding:9px 22px; border-radius:8px; border:none; cursor:pointer; display:flex; align-items:center; gap:7px;">
                <span wire:loading.remove wire:target="searchAirtime">Rechercher</span>
                <span wire:loading.inline-flex wire:target="searchAirtime" style="align-items:center; gap:7px;">
                    <svg width="14" height="14" viewBox="0 0 40 40" fill="none" style="animation:spin 0.8s linear infinite;">
                        <circle cx="20" cy="20" r="16" stroke="rgba(255,255,255,0.3)" stroke-width="4"/>
                        <path d="M20 4a16 16 0 0116 16" stroke="white" stroke-width="4" stroke-linecap="round"/>
                    </svg>
                    Chargement...
                </span>
            </button>
        </div>

        @if(!$searched_airtime)
            <div style="text-align:center; padding:40px 20px;">
                <p style="font-size:14px; font-weight:600; color:#111827; margin-bottom:4px;">Aucune donnée à afficher</p>
                <p style="font-size:12px; color:#9ca3af;">Choisissez une période puis cliquez sur <strong>Rechercher</strong>.</p>
            </div>
        @else
            <div style="display:grid; grid-template-columns:repeat(3, minmax(0,1fr)); gap:12px; margin:20px 0 16px;">
                <div style="background:#f9fafb; border:1px solid #e5e7eb; border-radius:10px; padding:16px; border-top:3px solid #00843D;">
                    <p style="font-size:11px; color:#6b7280; margin:0 0 6px;">Valeur total Airtime</p>
                    <p style="font-size:20px; font-weight:700; color:#111827; margin:0;">{{ number_format($airtime_volume_total * 100, 0, ',', ' ') }}</p>
                    <p style="font-size:10px; color:#9ca3af; margin:4px 0 0;">FDJ</p>
                </div>
                <div style="background:#f9fafb; border:1px solid #e5e7eb; border-radius:10px; padding:16px; border-top:3px solid #1B2F6E;">
                    <p style="font-size:11px; color:#6b7280; margin:0 0 6px;">Nb transactions</p>
                    <p style="font-size:20px; font-weight:700; color:#111827; margin:0;">{{ number_format($airtime_nb_transactions, 0, ',', ' ') }}</p>
                    <p style="font-size:10px; color:#9ca3af; margin:4px 0 0;">opérations</p>
                </div>
                <div style="background:#f9fafb; border:1px solid #e5e7eb; border-radius:10px; padding:16px; border-top:3px solid #F5A800;">
                    <p style="font-size:11px; color:#6b7280; margin:0 0 6px;">Ticket moyen</p>
                    <p style="font-size:20px; font-weight:700; color:#111827; margin:0;">{{ number_format($airtime_ticket_moyen * 100, 0, ',', ' ') }}</p>
                    <p style="font-size:10px; color:#9ca3af; margin:4px 0 0;">FDJ / transaction</p>
                </div>
            </div>

            <div style="display:grid; grid-template-columns:2fr 1fr; gap:16px;">
                <div wire:ignore style="background:#fff; border:1px solid #e5e7eb; border-radius:10px; padding:20px;">
                    <p style="font-size:13px; font-weight:600; color:#111827; margin:0 0 16px;">Évolution du volume et du nombre de transactions Airtime</p>
                    <div style="position:relative; height:280px;">
                        <canvas id="chartAirtimeEvolution"></canvas>
                    </div>
                </div>
                <div wire:ignore style="background:#fff; border:1px solid #e5e7eb; border-radius:10px; padding:20px;">
                    <p style="font-size:13px; font-weight:600; color:#111827; margin:0 0 16px;">Répartition par sous-type</p>
                    <div style="position:relative; height:280px;">
                        <canvas id="chartAirtimeRepartition"></canvas>
                    </div>
                </div>
            </div>
        @endif
    </div>

</div>

@assets
<script src="https://cdnjs.cloudflare.com/ajax/libs/Chart.js/4.4.1/chart.umd.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/chartjs-plugin-datalabels/2.2.0/chartjs-plugin-datalabels.min.js"></script>
@endassets

@script
<script>
    let charts = {};
    let datalabelsRegistered = false;

    const palette = ['#00843D','#1B2F6E','#F5A800','#9333ea','#E24B4A','#0891b2','#7A4F00','#6B21A8','#005C2B','#92400E','#0369a1','#be123c'];

    function formatFdj(value) {
        return new Intl.NumberFormat('fr-FR').format(Math.round(value));
    }

    function ensureDatalabelsRegistered() {
        if (!datalabelsRegistered && typeof ChartDataLabels !== 'undefined') {
            Chart.register(ChartDataLabels);
            Chart.defaults.set('plugins.datalabels', { display: false });
            datalabelsRegistered = true;
        }
    }

    function destroyCharts(keys) {
        keys.forEach(k => { if (charts[k]) { charts[k].destroy(); delete charts[k]; } });
    }

    const GREEN = '#00843D', RED = '#E24B4A', NAVY = '#1B2F6E', AMBER = '#F5A800', SKY = '#0EA5E9';
    const signColor = (values) => values.map(v => v < 0 ? RED : GREEN);
    const fdjTooltip = (axis) => ({ callbacks: { label: (c) => ' ' + (c.dataset.label ? c.dataset.label + ' : ' : '') + formatFdj(c.parsed[axis]) + ' FDJ' } });
    const fdjTicks = { callback: (v) => new Intl.NumberFormat('fr-FR', { notation: 'compact', maximumFractionDigits: 1 }).format(v) };

    // Barres horizontales par type : hauteur selon le nombre de lignes
    function typeBars(key, id, labels, datasets, legend = false) {
        const el = document.getElementById(id);
        if (!el) return;
        el.parentElement.style.height = Math.max(280, labels.length * 28 * (datasets.length > 1 ? 1.4 : 1)) + 'px';
        charts[key] = new Chart(el, {
            type: 'bar',
            data: { labels, datasets },
            options: {
                indexAxis: 'y', responsive: true, maintainAspectRatio: false,
                plugins: { legend: { display: legend, position: 'top' }, tooltip: fdjTooltip('x') },
                scales: { x: { ticks: fdjTicks }, y: { ticks: { font: { size: 10 } } } }
            }
        });
    }

    // Top payeurs : redessiné seul quand l'utilisateur change le nombre affiché
    function renderTopPayers(top) {
        destroyCharts(['topPayers']);
        const el = document.getElementById('chartTopPayers');
        if (!el || !top.length) return;
        el.parentElement.style.height = Math.max(300, top.length * 26) + 'px';
        charts.topPayers = new Chart(el, {
            type: 'bar',
            data: { labels: top.map(r => r.party_name || r.party_id), datasets: [{ label: 'Frais', data: top.map(r => Number(r.fee)), backgroundColor: GREEN }] },
            options: {
                indexAxis: 'y', responsive: true, maintainAspectRatio: false,
                plugins: { legend: { display: false }, tooltip: fdjTooltip('x') },
                scales: { x: { ticks: fdjTicks }, y: { ticks: { font: { size: 10 }, autoSkip: false } } }
            }
        });
    }

    function renderDashboard(dash) {
        if (typeof Chart === 'undefined') {
            // Chart.js pas encore chargé : on réessaie
            setTimeout(() => renderDashboard(dash), 100);
            return;
        }

        ensureDatalabelsRegistered();
        destroyCharts(['evolution', 'types', 'revenus', 'revenueDaily', 'cumul', 'families', 'feeCommission', 'comparison', 'avgTxn', 'hourly', 'topPayers']);

        const serie = dash.serie || [];

        const ctxE = document.getElementById('chartEvolution');
        if (ctxE) {
            charts.evolution = new Chart(ctxE, {
                type: 'bar',
                data: {
                    labels: serie.map(r => r.jour),
                    datasets: [
                        {
                            label: 'Valeur (FDJ)',
                            data: serie.map(r => Number(r.volume)),
                            backgroundColor: 'rgba(0,132,61,0.6)',
                            yAxisID: 'y',
                            datalabels: {
                                display: true,
                                anchor: 'center',
                                align: 'center',
                                rotation: -90,

                                color: '#ffffff',

                                font: {
                                    size: 11,
                                    weight: 'bold',
                                },

                                textStrokeColor: 'rgba(0,0,0,0.5)',
                                textStrokeWidth: 2,

                                formatter: formatFdj,
                            },
                        },
                        {
                            label: 'Volume ',
                            data: serie.map(r => Number(r.nb)),
                            type: 'line',
                            borderColor: '#1B2F6E',
                            backgroundColor: '#1B2F6E',
                            yAxisID: 'y1',
                            tension: 0.3,
                        }
                    ]
                },
                options: {
                    responsive: true, maintainAspectRatio: false,
                    scales: {
                        y:  { position: 'left',  title: { display: true, text: 'Valeur (FDJ)' } },
                        y1: { position: 'right', title: { display: true, text: 'Volume' }, grid: { drawOnChartArea: false } }
                    }
                }
            });
        }

        // 1. Évolution du revenu par jour
        const ctxRD = document.getElementById('chartRevenueDaily');
        if (ctxRD) {
            charts.revenueDaily = new Chart(ctxRD, {
                type: 'bar',
                data: {
                    labels: serie.map(r => r.jour),
                    datasets: [
                        { label: 'Frais', data: serie.map(r => Number(r.fee)), backgroundColor: 'rgba(0,132,61,0.55)', order: 2 },
                        { label: 'Commissions', data: serie.map(r => -Number(r.commission)), backgroundColor: 'rgba(226,75,74,0.55)', order: 2 },
                        { label: 'Claw back', data: serie.map(r => Number(r.clawback)), backgroundColor: SKY, order: 2 },
                        { label: 'Revenu net', data: serie.map(r => Number(r.net)), type: 'line', borderColor: NAVY, backgroundColor: NAVY, tension: 0.3, order: 1 },
                    ]
                },
                options: {
                    responsive: true, maintainAspectRatio: false, interaction: { mode: 'index', intersect: false },
                    plugins: { tooltip: fdjTooltip('y') },
                    scales: { x: { stacked: true }, y: { stacked: true, ticks: fdjTicks } }
                }
            });
        }

        // 5. Revenu cumulé du mois + projection
        const cumul = dash.cumul;
        const ctxCu = document.getElementById('chartCumul');
        if (ctxCu && cumul) {
            const sub = document.getElementById('cumulSubtitle');
            if (sub) sub.textContent = cumul.month + ' : ' + formatFdj(cumul.total) + ' FDJ sur ' + cumul.elapsed + ' jour(s), projection fin de mois ' + formatFdj(cumul.projected) + ' FDJ';
            charts.cumul = new Chart(ctxCu, {
                type: 'line',
                data: {
                    labels: cumul.labels,
                    datasets: [
                        { label: 'Revenu cumulé', data: cumul.actual, borderColor: GREEN, backgroundColor: 'rgba(0,132,61,0.12)', fill: true, tension: 0.2, pointRadius: 2 },
                        { label: 'Projection', data: cumul.projection, borderColor: AMBER, borderDash: [6, 4], pointRadius: 0, tension: 0 },
                    ]
                },
                options: {
                    responsive: true, maintainAspectRatio: false, interaction: { mode: 'index', intersect: false },
                    plugins: { tooltip: fdjTooltip('y') },
                    scales: { x: { title: { display: true, text: 'Jour du mois' } }, y: { ticks: fdjTicks } }
                }
            });
        }

        // 3. Composition par famille (barres : une famille peut être en perte)
        const fam = dash.families || [];
        const ctxF = document.getElementById('chartFamilies');
        if (ctxF) {
            const values = fam.map(r => Number(r.revenue));
            charts.families = new Chart(ctxF, {
                type: 'bar',
                data: { labels: fam.map(r => r.family), datasets: [{ label: 'Revenu net', data: values, backgroundColor: signColor(values) }] },
                options: {
                    indexAxis: 'y', responsive: true, maintainAspectRatio: false,
                    plugins: { legend: { display: false }, tooltip: fdjTooltip('x') },
                    scales: { x: { ticks: fdjTicks } }
                }
            });
        }

        // Revenus par type
        const rev = dash.revenus_type || [];
        const revValues = rev.map(r => Number(r.revenus));
        typeBars('revenus', 'chartRevenus', rev.map(r => r.type), [{ label: 'Revenu', data: revValues, backgroundColor: signColor(revValues) }]);

        // 2. Frais vs commissions
        const fc = dash.fee_commission || [];
        typeBars('feeCommission', 'chartFeeCommission', fc.map(r => r.type), [
            { label: 'Frais encaissés', data: fc.map(r => Number(r.fee)), backgroundColor: GREEN },
            { label: 'Commissions versées', data: fc.map(r => Number(r.commission)), backgroundColor: RED },
        ], true);

        // 4. Comparaison avec la période précédente
        const cmp = dash.comparison || { rows: [] };
        const cmpSub = document.getElementById('comparisonSubtitle');
        if (cmpSub && cmp.previous_label) cmpSub.textContent = 'Période choisie vs période précédente de même durée (' + cmp.previous_label + ')';
        typeBars('comparison', 'chartComparison', cmp.rows.map(r => r.type), [
            { label: 'Période choisie', data: cmp.rows.map(r => Number(r.current)), backgroundColor: GREEN },
            { label: 'Période précédente', data: cmp.rows.map(r => Number(r.previous)), backgroundColor: '#9CA3AF' },
        ], true);

        // 6. Revenu moyen par transaction
        const avg = dash.avg_per_txn || [];
        const avgValues = avg.map(r => Number(r.avg));
        typeBars('avgTxn', 'chartAvgTxn', avg.map(r => r.type), [{ label: 'Revenu moyen', data: avgValues, backgroundColor: signColor(avgValues) }]);

        // 7. Revenu par heure
        const hrs = dash.hourly || [];
        const ctxH = document.getElementById('chartHourly');
        if (ctxH) {
            const values = hrs.map(r => Number(r.net));
            charts.hourly = new Chart(ctxH, {
                type: 'bar',
                data: { labels: hrs.map(r => String(r.hour).padStart(2, '0') + 'h'), datasets: [{ label: 'Revenu net', data: values, backgroundColor: signColor(values) }] },
                options: {
                    responsive: true, maintainAspectRatio: false,
                    plugins: { legend: { display: false }, tooltip: fdjTooltip('y') },
                    scales: { y: { ticks: fdjTicks } }
                }
            });
        }

        // 8. Top des payeurs de frais
        renderTopPayers(dash.top_payers || []);

        // Répartition des volumes (camembert)
        const types = dash.types || [];
        const ctxT = document.getElementById('chartTypes');
        if (ctxT) {
            charts.types = new Chart(ctxT, {
                type: 'doughnut',
                data: {
                    labels: types.map(r => r.type),
                    datasets: [{
                        data: types.map(r => Number(r.volume)),
                        backgroundColor: palette,
                    }]
                },
                options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { position: 'right', labels: { font: { size: 10 } } } } }
            });
        }
    }

    function renderAirtime(serie, repartition) {
        if (typeof Chart === 'undefined') {
            setTimeout(() => renderAirtime(serie, repartition), 100);
            return;
        }

        ensureDatalabelsRegistered();
        destroyCharts(['airtimeEvolution', 'airtimeRepartition']);

        const ctxAE = document.getElementById('chartAirtimeEvolution');
        if (ctxAE) {
            charts.airtimeEvolution = new Chart(ctxAE, {
                type: 'bar',
                data: {
                    labels: serie.map(r => r.jour),
                    datasets: [
                        {
                            label: 'Valeur (FDJ)',
                            data: serie.map(r => Number(r.volume) * 100),
                            backgroundColor: 'rgba(0,132,61,0.6)',
                            yAxisID: 'y',
                            datalabels: {
                                display: true,
                                anchor: 'center',
                                align: 'center',
                                rotation: -90,

                                color: '#ffffff',

                                font: {
                                    size: 11,
                                    weight: 'bold',
                                },

                                textStrokeColor: 'rgba(0,0,0,0.5)',
                                textStrokeWidth: 2,

                                formatter: formatFdj,
                            },
                        },
                        {
                            label: 'Volume',
                            data: serie.map(r => Number(r.nb)),
                            type: 'line',
                            borderColor: '#1B2F6E',
                            backgroundColor: '#1B2F6E',
                            yAxisID: 'y1',
                            tension: 0.3,
                        }
                    ]
                },
                options: {
                    responsive: true, maintainAspectRatio: false,
                    scales: {
                        y:  { position: 'left',  title: { display: true, text: 'Valeur (FDJ)' } },
                        y1: { position: 'right', title: { display: true, text: 'Nb transactions' }, grid: { drawOnChartArea: false } }
                    }
                }
            });
        }

        const ctxAR = document.getElementById('chartAirtimeRepartition');
        if (ctxAR) {
            charts.airtimeRepartition = new Chart(ctxAR, {
                type: 'doughnut',
                data: {
                    labels: repartition.map(r => r.type),
                    datasets: [{
                        data: repartition.map(r => Number(r.volume) * 100),
                        backgroundColor: palette,
                    }]
                },
                options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { position: 'right', labels: { font: { size: 10 } } } } }
            });
        }
    }

    // Écoute l'événement émis après la recherche
    $wire.on('top-payers-updated', (data) => {
        const payload = Array.isArray(data) ? data[0] : data;
        requestAnimationFrame(() => {
            if (typeof Chart !== 'undefined') renderTopPayers(payload.top || []);
        });
    });

    $wire.on('dashboard-updated', (data) => {
        const payload = Array.isArray(data) ? data[0] : data;
        // requestAnimationFrame garantit que le DOM (canvas) est bien inséré
        requestAnimationFrame(() => {
            renderDashboard(payload.dash || {});
        });
    });

    $wire.on('airtime-updated', (data) => {
        const payload = Array.isArray(data) ? data[0] : data;
        requestAnimationFrame(() => {
            renderAirtime(payload.serie || [], payload.repartition || []);
        });
    });
</script>
@endscript
</div>