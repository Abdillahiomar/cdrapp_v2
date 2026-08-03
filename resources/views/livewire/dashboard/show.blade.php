<?php

use Livewire\Volt\Component;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

new class extends Component {

    public string $date_debut = '';
    public string $date_fin   = '';
    public bool   $searched   = false;

    // KPIs
    public float $volume_total    = 0;
    public int   $nb_transactions = 0;
    public float $revenus         = 0;
    public float $ticket_moyen    = 0;
    public float $taux_reussite   = 0;

    // Réseau
    public int $agents_actifs    = 0;
    public int $marchands_actifs = 0;
    public int $clients_actifs = 0;

    // Graphiques (données brutes passées au JS)
    public array $serie_jours     = [];   // [{jour, volume, nb}]
    public array $repartition_type = [];  // [{type, volume, nb, revenus}]
    public array $repartition_canal = []; // [{canal, nb}]

    // Comparaison période précédente
    public ?float $volume_precedent = null;
    public ?float $variation_volume = null;

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

    public function search()
    {
        set_time_limit(120);

        $debut = $this->date_debut . ' 00:00:00';
        $fin   = Carbon::parse($this->date_fin)->addDay()->format('Y-m-d') . ' 00:00:00';

        // reason_index pour identifier agents (cash in) et marchands (merchant payment)
        $idxCashin   = $this->reasonIndexesFor('customer cash in');
        $idxMerchant = $this->reasonIndexesFor('merchant payment');


        // ── KPIs globaux (une seule passe) ──
        $kpi = DB::selectOne("
            SELECT
                COALESCE(SUM(actual_amount), 0)                                   AS volume_total,
                COUNT(*)                                                          AS nb_transactions,
                COALESCE(SUM(charge_amount -commission_amount), 0)              AS revenus,
                COALESCE(AVG(actual_amount), 0)                                  AS ticket_moyen,
                COALESCE(
                    100.0 * COUNT(*) FILTER (WHERE status = 'Completed') / NULLIF(COUNT(*), 0),
                    0
                )                                                                 AS taux_reussite
            FROM fact_txn_v2
            WHERE transaction_initiated_time >= ? AND transaction_initiated_time < ?
            AND status = 'Completed'

        ", [$debut, $fin]);

        $this->volume_total    = (float) $kpi->volume_total;
        $this->nb_transactions = (int)   $kpi->nb_transactions;
        $this->revenus         = (float) $kpi->revenus;
        $this->ticket_moyen    = (float) $kpi->ticket_moyen;
        $this->taux_reussite   = (float) $kpi->taux_reussite;

        // ── Comparaison période précédente (même durée, juste avant) ──
        $jours = Carbon::parse($this->date_debut)->diffInDays(Carbon::parse($this->date_fin)) + 1;
        $debutPrec = Carbon::parse($this->date_debut)->subDays($jours)->format('Y-m-d') . ' 00:00:00';
        $finPrec   = $debut;

        $prec = DB::selectOne("
            SELECT COALESCE(SUM(actual_amount), 0) AS volume
            FROM fact_txn_v2
            WHERE transaction_initiated_time >= ? AND transaction_initiated_time < ?
            AND status = 'Completed'
        ", [$debutPrec, $finPrec]);

        $this->volume_precedent = (float) $prec->volume;
        $this->variation_volume = $this->volume_precedent > 0
            ? (($this->volume_total - $this->volume_precedent) / $this->volume_precedent) * 100
            : null;

        // ── Série temporelle par jour ──
        $this->serie_jours = array_map(fn($r) => (array) $r, DB::select("
            SELECT
                transaction_initiated_time::date AS jour,
                COALESCE(SUM(actual_amount), 0)  AS volume,
                COUNT(*)                         AS nb
            FROM fact_txn_v2
            WHERE transaction_initiated_time >= ? AND transaction_initiated_time < ?
            AND status = 'Completed'
            GROUP BY transaction_initiated_time::date
            ORDER BY jour
        ", [$debut, $fin]));

        // ── Répartition par type d'opération (via reason_types) ──
        

        // Types à exclure du graphique de répartition
        $typesExclus = ['E-Money Creation', 'E-Money Destroy', 'Journal Transaction' ,'Org Withdrawal of Funds', 'Org Inter-Transfer'];
        $placeholders = implode(',', array_fill(0, count($typesExclus), '?'));

        $this->repartition_type = array_map(fn($r) => (array) $r, DB::select("
            SELECT
                tt.txn_type_name                              AS type,
                COALESCE(SUM(f.actual_amount), 0)           AS volume,
                COUNT(*)                                    AS nb,
                COALESCE(SUM(f.charge_amount - f.commission_amount ), 0) AS revenus
            FROM fact_txn_v2 f
            JOIN transaction_types tt ON tt.txn_index = f.txn_index
            WHERE f.transaction_initiated_time >= ? AND f.transaction_initiated_time < ?
            AND tt.txn_type_name NOT IN ($placeholders)
            AND status = 'Completed'
            GROUP BY tt.txn_type_name
            ORDER BY volume DESC
            LIMIT 15
        ", array_merge([$debut, $fin], $typesExclus)));

        // ── Répartition par canal ──
        $this->repartition_canal = array_map(fn($r) => (array) $r, DB::select("
            SELECT
                COALESCE(channel, 'Inconnu') AS canal,
                COUNT(*)                     AS nb
            FROM fact_txn_v2
            WHERE transaction_initiated_time >= ? AND transaction_initiated_time < ?
            GROUP BY channel
            ORDER BY nb DESC
        ", [$debut, $fin]));

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

        // Envoi des données aux graphiques JS
        $this->dispatch('dashboard-updated',
            serie:  $this->serie_jours,
            types:  $this->repartition_type,
            canaux: $this->repartition_canal,
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

        {{-- KPIs PRINCIPAUX --}}
        <div style="display:grid; grid-template-columns:repeat(5, minmax(0,1fr)); gap:12px; margin-bottom:16px;">

            <div style="background:#fff; border:1px solid #e5e7eb; border-radius:10px; padding:16px; border-top:3px solid #00843D;">
                <p style="font-size:11px; color:#6b7280; margin:0 0 6px;">Valeur total</p>
                <p style="font-size:22px; font-weight:700; color:#111827; margin:0;">{{ number_format($volume_total * 100, 0, ',', ' ') }}</p>
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
                <p style="font-size:22px; font-weight:700; color:#111827; margin:0;">{{ number_format($revenus * 100, 0, ',', ' ') }}</p>
                <p style="font-size:10px; color:#9ca3af; margin:4px 0 0;">FDJ (frais - commission)</p>
            </div>

            <div style="background:#fff; border:1px solid #e5e7eb; border-radius:10px; padding:16px; border-top:3px solid #9333ea;">
                <p style="font-size:11px; color:#6b7280; margin:0 0 6px;">Ticket moyen</p>
                <p style="font-size:22px; font-weight:700; color:#111827; margin:0;">{{ number_format($ticket_moyen * 100, 0, ',', ' ') }}</p>
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

        {{-- COURBE ÉVOLUTION --}}
        <div wire:ignore style="background:#fff; border:1px solid #e5e7eb; border-radius:10px; padding:20px; margin-bottom:16px;">
            <p style="font-size:13px; font-weight:600; color:#111827; margin:0 0 16px;">Évolution du volume et du nombre de transactions</p>
            <div style="position:relative; height:300px;">
                <canvas id="chartEvolution"></canvas>
            </div>
        </div>

        {{-- CAMEMBERT TYPE + BARRES REVENUS --}}
        <div style="display:grid; grid-template-columns:repeat(2, minmax(0,1fr)); gap:16px; margin-bottom:16px;">
            <div wire:ignore style="background:#fff; border:1px solid #e5e7eb; border-radius:10px; padding:20px;">
                <p style="font-size:13px; font-weight:600; color:#111827; margin:0 0 16px;">Répartition du valeur par Type de transaction</p>
                <div style="position:relative; height:280px;">
                    <canvas id="chartTypes"></canvas>
                </div>
            </div>
            <div wire:ignore style="background:#fff; border:1px solid #e5e7eb; border-radius:10px; padding:20px;">
                <p style="font-size:13px; font-weight:600; color:#111827; margin:0 0 16px;">Revenus par type</p>
                <div style="position:relative; height:280px;">
                    <canvas id="chartRevenus"></canvas>
                </div>
            </div>
        </div>

        {{-- RÉPARTITION CANAL --}}
        <div wire:ignore style="background:#fff; border:1px solid #e5e7eb; border-radius:10px; padding:20px; margin-bottom:16px;">
            <p style="font-size:13px; font-weight:600; color:#111827; margin:0 0 16px;">Répartition par canal</p>
            <div style="position:relative; height:220px;">
                <canvas id="chartCanaux"></canvas>
            </div>
        </div>

    @endif

</div>

@assets
<script src="https://cdnjs.cloudflare.com/ajax/libs/Chart.js/4.4.1/chart.umd.min.js"></script>
@endassets

@script
<script>
    let charts = {};

    const palette = ['#00843D','#1B2F6E','#F5A800','#9333ea','#E24B4A','#0891b2','#7A4F00','#6B21A8','#005C2B','#92400E','#0369a1','#be123c'];

    function destroyCharts() {
        Object.values(charts).forEach(c => { if (c) c.destroy(); });
        charts = {};
    }

    function renderDashboard(serie, types, canaux) {
        if (typeof Chart === 'undefined') {
            // Chart.js pas encore chargé : on réessaie
            setTimeout(() => renderDashboard(serie, types, canaux), 100);
            return;
        }

        destroyCharts();

        const ctxE = document.getElementById('chartEvolution');
        if (ctxE) {
            charts.evolution = new Chart(ctxE, {
                type: 'bar',
                data: {
                    labels: serie.map(r => r.jour),
                    datasets: [
                        {
                            label: 'Valeur (FDJ)',
                            data: serie.map(r => Number(r.volume) * 100),
                            backgroundColor: 'rgba(0,132,61,0.6)',
                            yAxisID: 'y',
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

        const ctxT = document.getElementById('chartTypes');
        if (ctxT) {
            charts.types = new Chart(ctxT, {
                type: 'doughnut',
                data: {
                    labels: types.map(r => r.type),
                    datasets: [{
                        data: types.map(r => Number(r.volume) * 100),
                        backgroundColor: palette,
                    }]
                },
                options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { position: 'right', labels: { font: { size: 10 } } } } }
            });
        }

        const ctxR = document.getElementById('chartRevenus');
        if (ctxR) {
            charts.revenus = new Chart(ctxR, {
                type: 'bar',
                data: {
                    labels: types.map(r => r.type),
                    datasets: [{
                        label: 'Revenus (FDJ)',
                        data: types.map(r => Number(r.revenus) * 100),
                        backgroundColor: '#F5A800',
                    }]
                },
                options: { indexAxis: 'y', responsive: true, maintainAspectRatio: false, plugins: { legend: { display: false } } }
            });
        }

        const ctxC = document.getElementById('chartCanaux');
        if (ctxC) {
            charts.canaux = new Chart(ctxC, {
                type: 'pie',
                data: {
                    labels: canaux.map(r => r.canal),
                    datasets: [{
                        data: canaux.map(r => Number(r.nb)),
                        backgroundColor: palette,
                    }]
                },
                options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { position: 'right', labels: { font: { size: 11 } } } } }
            });
        }
    }

    // Écoute l'événement émis après la recherche
    $wire.on('dashboard-updated', (data) => {
        const payload = Array.isArray(data) ? data[0] : data;
        // requestAnimationFrame garantit que le DOM (canvas) est bien inséré
        requestAnimationFrame(() => {
            renderDashboard(payload.serie || [], payload.types || [], payload.canaux || []);
        });
    });
</script>
@endscript
</div>