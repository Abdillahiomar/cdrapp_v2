<?php

use Livewire\Volt\Component;
use App\Models\Transaction;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;

new class extends Component {

    public string $date_debut = '';
    public string $date_fin   = '';
    public bool   $analyse    = false;
    public bool   $loading    = false;

    // Paramètres cycling
    public int   $min_cycles        = 2;
    public int   $amount_tolerance  = 1;
    public int   $max_depth         = 10;

    // Résultats
    public array $repeat_mp        = [];
    public array $repeat_cashin    = [];
    public array $repeat_w2b       = [];
    public array $circulaires      = [];
    public array $cycling          = [];

    // Index pré-calculés
    private array $idxMerchant       = [];
    private array $idxCashin         = [];
    private array $idxW2b            = [];
    private array $idxSend           = [];
    private array $idxCashoutGeneral = [];
    private array $idxBizCashout     = [];

    // Messages d'erreur
    public string $error_message = '';

    public function mount()
    {
        $this->date_debut = Carbon::now()->subDays(7)->format('Y-m-d');
        $this->date_fin   = Carbon::now()->format('Y-m-d');
    }

    /**
     * Résout les reason_index correspondant à un motif de libellé (LIKE),
     * en interrogeant reason_types une seule fois.
     */
    private function reasonIndexesFor(string $needle): array
    {
        // reason_types change rarement : on évite de refaire les 6 lookups
        // à chaque clic sur "Lancer l'analyse".
        return Cache::remember('reason_idx_' . md5($needle), 86400, function () use ($needle) {
            return \App\Models\ReasonType::query()
                ->whereRaw('LOWER(reason_name) LIKE ?', ['%' . strtolower($needle) . '%'])
                ->pluck('reason_index')
                ->map(fn($v) => (int) $v)
                ->all();
        });
    }

    /**
     * Transforme une liste d'index en fragment SQL "IN (...)" sécurisé.
     * Liste vide => "IN (NULL)" (ne matche aucune ligne).
     */
    private function inClause(array $indexes): string
    {
        if (empty($indexes)) {
            return 'IN (NULL)';
        }
        return 'IN (' . implode(',', $indexes) . ')';
    }

    /**
     * Vérifie si la période est valide
     */
    private function validatePeriod(): bool
    {
        $debut = Carbon::parse($this->date_debut);
        $fin = Carbon::parse($this->date_fin);

        if ($debut->gt($fin)) {
            $this->error_message = 'La date de début doit être antérieure à la date de fin.';
            return false;
        }

        if ($debut->diffInDays($fin) > 30) {
            $this->error_message = 'La période ne peut pas dépasser 30 jours.';
            return false;
        }

        return true;
    }

    public function lancer()
{
    // Générer une clé de cache unique basée sur les paramètres
    $cacheKey = 'fraude_analysis_' . md5(
        $this->date_debut . 
        $this->date_fin . 
        $this->min_cycles . 
        $this->amount_tolerance . 
        $this->max_depth
    );
    // Vérifier si les résultats sont en cache
    if (Cache::has($cacheKey)) {
        $cached = Cache::get($cacheKey);
        $this->repeat_mp = $cached['repeat_mp'];
        $this->repeat_cashin = $cached['repeat_cashin'];
        $this->repeat_w2b = $cached['repeat_w2b'];
        $this->circulaires = $cached['circulaires'];
        $this->cycling = $cached['cycling'];
        $this->analyse = true;
        session()->flash('info', 'Résultats chargés depuis le cache.');
        return;
    }
    try {
        set_time_limit(300);
        ini_set('memory_limit', '512M');

        if (!$this->validatePeriod()) {
            $this->analyse = false;
            session()->flash('error', $this->error_message);
            return;
        }

        $debut = $this->date_debut . ' 00:00:00';
        $fin   = $this->date_fin   . ' 23:59:59';
        $isSingleDay = $this->date_debut === $this->date_fin;

        // Résolution des indexes
        $this->idxMerchant       = $this->reasonIndexesFor('merchant payment');
        $this->idxCashin         = $this->reasonIndexesFor('customer cash in');
        $this->idxW2b            = $this->reasonIndexesFor('w2b');
        $this->idxSend           = $this->reasonIndexesFor('send money');
        $this->idxCashoutGeneral = $this->reasonIndexesFor('cash out');
        $this->idxBizCashout     = $this->reasonIndexesFor('business cash out');

        $idxMerchant       = $this->inClause($this->idxMerchant);
        $idxCashin         = $this->inClause($this->idxCashin);
        $idxW2b            = $this->inClause($this->idxW2b);
        $idxSend           = $this->inClause($this->idxSend);
        $idxCashoutGeneral = $this->inClause($this->idxCashoutGeneral);
        $idxBizCashout     = $this->inClause($this->idxBizCashout);

        // ── OPTIMISATION 1 : Utiliser des CTE pour réduire les scans ──
        
        // 1. MP répétitifs - optimisé
        $this->repeat_mp = DB::select("
            WITH filtered_transactions AS (
                SELECT debit_party_identifier, credit_party_identifier
                FROM fact_txn_v2
                WHERE transaction_initiated_time BETWEEN ? AND ?
                  AND reason_index {$idxMerchant}
            )
            SELECT 
                debit_party_identifier,
                credit_party_identifier,
                COUNT(*) as nb_paiements
            FROM filtered_transactions
            GROUP BY debit_party_identifier, credit_party_identifier
            HAVING COUNT(*) > 2
            ORDER BY nb_paiements DESC
            LIMIT 100
        ", [$debut, $fin]);

        // 2. Cash In répétitifs - optimisé
        $this->repeat_cashin = DB::select("
            WITH filtered_transactions AS (
                SELECT debit_party_identifier, credit_party_identifier
                FROM fact_txn_v2
                WHERE transaction_initiated_time BETWEEN ? AND ?
                  AND reason_index {$idxCashin}
            )
            SELECT 
                debit_party_identifier,
                credit_party_identifier,
                COUNT(*) as nb_cashin
            FROM filtered_transactions
            GROUP BY debit_party_identifier, credit_party_identifier
            HAVING COUNT(*) >= 2
            ORDER BY nb_cashin DESC
            LIMIT 100
        ", [$debut, $fin]);

        // 3. W2B répétitifs - optimisé
        $this->repeat_w2b = DB::select("
            WITH filtered_transactions AS (
                SELECT debit_party_identifier, credit_party_identifier
                FROM fact_txn_v2
                WHERE transaction_initiated_time BETWEEN ? AND ?
                  AND reason_index {$idxW2b}
            )
            SELECT 
                debit_party_identifier,
                credit_party_identifier,
                COUNT(*) as nb_w2b
            FROM filtered_transactions
            GROUP BY debit_party_identifier, credit_party_identifier
            HAVING COUNT(*) >= 2
            ORDER BY nb_w2b DESC
            LIMIT 100
        ", [$debut, $fin]);

        // 4. Scénarios circulaires : Customer Cash In → Merchant Payment → Business Cash Out
        // (le client reçoit un cash-in, paie un marchand avec, et le marchand
        // ressort le même montant en Business Cash Out peu après)
        $timeWindowCircMinutes = $isSingleDay ? 60 : 360;
        $timeWindowCirc = "INTERVAL '{$timeWindowCircMinutes} minutes'";
        $debutCashin = Carbon::parse($debut)->subMinutes($timeWindowCircMinutes)->format('Y-m-d H:i:s');
        $finCashout  = Carbon::parse($fin)->addMinutes($timeWindowCircMinutes)->format('Y-m-d H:i:s');

        $this->circulaires = DB::select("
            WITH mp_transactions AS (
                SELECT
                    transaction_initiated_time,
                    debit_party_identifier,
                    credit_party_identifier,
                    actual_amount
                FROM fact_txn_v2
                WHERE transaction_initiated_time BETWEEN ? AND ?
                  AND reason_index {$idxMerchant}
                LIMIT 300
            ),
            cashin_transactions AS (
                SELECT
                    transaction_initiated_time,
                    debit_party_identifier,
                    credit_party_identifier
                FROM fact_txn_v2
                WHERE transaction_initiated_time BETWEEN ? AND ?
                  AND reason_index {$idxCashin}
            ),
            cashout_transactions AS (
                SELECT
                    transaction_initiated_time,
                    debit_party_identifier,
                    credit_party_identifier,
                    actual_amount
                FROM fact_txn_v2
                WHERE transaction_initiated_time BETWEEN ? AND ?
                  AND reason_index {$idxBizCashout}
            )
            SELECT
                mp.transaction_initiated_time::date AS date,
                ci.debit_party_identifier AS cashin_from,
                ci.transaction_initiated_time AS ci_time,
                mp.debit_party_identifier AS client,
                mp.credit_party_identifier AS merchant,
                mp.transaction_initiated_time AS mp_time,
                bco.transaction_initiated_time AS bco_time,
                mp.actual_amount AS amount,
                bco.credit_party_identifier AS cashout_to,
                EXTRACT(EPOCH FROM (bco.transaction_initiated_time - mp.transaction_initiated_time)) / 60 AS delay_minutes,
                CASE
                    WHEN EXTRACT(EPOCH FROM (bco.transaction_initiated_time - mp.transaction_initiated_time)) / 60 < 10
                        THEN 'Cashout rapide'
                    WHEN mp.actual_amount >= 20000
                        THEN 'Montant élevé'
                    ELSE 'Activité inhabituelle'
                END AS flags
            FROM mp_transactions mp
            JOIN cashin_transactions ci
                ON ci.credit_party_identifier = mp.debit_party_identifier
                AND ci.transaction_initiated_time < mp.transaction_initiated_time
                AND ci.transaction_initiated_time > mp.transaction_initiated_time - {$timeWindowCirc}
            JOIN cashout_transactions bco
                ON bco.debit_party_identifier = mp.credit_party_identifier
                AND bco.actual_amount = mp.actual_amount
                AND bco.transaction_initiated_time > mp.transaction_initiated_time
                AND bco.transaction_initiated_time < mp.transaction_initiated_time + {$timeWindowCirc}
            ORDER BY delay_minutes ASC
            LIMIT 200
        ", [$debut, $fin, $debutCashin, $fin, $debut, $finCashout]);

        // 5. Cycling de commission : Cash In → Send Money (n fois, n <= max_depth) → W2B ou Cash Out.
        // CTE récursive : à chaque étape on suit le "porteur" courant de l'argent
        // via un Send Money dont le montant reste proche du cash-in d'origine
        // (tolérance %), jusqu'à ce qu'il sorte du système (W2B ou Cash Out) ou
        // que la profondeur max soit atteinte. Les tables send/exit sont
        // pré-filtrées une seule fois (CTE non récursives) pour que chaque étape
        // de la récursion fasse un Hash Join, pas un scan corrélé.
        $timeWindowCycleMinutes = $isSingleDay ? 120 : 720;
        $timeWindowCycle = "INTERVAL '{$timeWindowCycleMinutes} minutes'";
        $maxDepth = max(1, (int) $this->max_depth);
        $finSendCycle = Carbon::parse($fin)->addMinutes($timeWindowCycleMinutes * $maxDepth)->format('Y-m-d H:i:s');
        $finExitCycle = Carbon::parse($fin)->addMinutes($timeWindowCycleMinutes * ($maxDepth + 1))->format('Y-m-d H:i:s');

        $this->cycling = DB::select("
            WITH RECURSIVE cashin_base AS (
                SELECT
                    transaction_id,
                    transaction_initiated_time::date AS txn_date,
                    debit_party_identifier  AS agent,
                    credit_party_identifier AS holder,
                    actual_amount           AS origin_amount,
                    transaction_initiated_time AS origin_time
                FROM fact_txn_v2
                WHERE transaction_initiated_time BETWEEN ? AND ?
                  AND reason_index {$idxCashin}
                  AND actual_amount > 0
                LIMIT 500
            ),
            send_pool AS (
                SELECT
                    transaction_initiated_time,
                    debit_party_identifier,
                    credit_party_identifier,
                    actual_amount
                FROM fact_txn_v2
                WHERE transaction_initiated_time BETWEEN ? AND ?
                  AND reason_index {$idxSend}
            ),
            exit_pool AS (
                SELECT
                    transaction_initiated_time,
                    debit_party_identifier,
                    actual_amount
                FROM fact_txn_v2
                WHERE transaction_initiated_time BETWEEN ? AND ?
                  AND (reason_index {$idxW2b} OR reason_index {$idxCashoutGeneral})
            ),
            chain AS (
                SELECT
                    cb.transaction_id            AS origin_id,
                    cb.txn_date,
                    cb.agent,
                    cb.origin_amount,
                    cb.origin_time,
                    cb.holder AS current_holder,
                    cb.origin_time AS current_time,
                    0 AS depth
                FROM cashin_base cb

                UNION ALL

                SELECT
                    c.origin_id,
                    c.txn_date,
                    c.agent,
                    c.origin_amount,
                    c.origin_time,
                    sp.credit_party_identifier AS current_holder,
                    sp.transaction_initiated_time AS current_time,
                    c.depth + 1
                FROM chain c
                JOIN send_pool sp
                    ON sp.debit_party_identifier = c.current_holder
                    AND sp.transaction_initiated_time > c.current_time
                    AND sp.transaction_initiated_time < c.current_time + {$timeWindowCycle}
                    AND ABS(sp.actual_amount - c.origin_amount) / c.origin_amount <= ?
                WHERE c.depth < ?
            ),
            successful_exits AS (
                SELECT DISTINCT ON (chain.origin_id)
                    chain.origin_id,
                    chain.txn_date,
                    chain.agent,
                    chain.origin_amount,
                    chain.origin_time,
                    chain.depth AS nb_hops,
                    ep.transaction_initiated_time AS exit_time,
                    ep.actual_amount AS exit_amount
                FROM chain
                JOIN exit_pool ep
                    ON ep.debit_party_identifier = chain.current_holder
                    AND ep.transaction_initiated_time > chain.current_time
                    AND ep.transaction_initiated_time < chain.current_time + {$timeWindowCycle}
                WHERE chain.depth >= 1
                ORDER BY chain.origin_id, chain.depth ASC, ep.transaction_initiated_time ASC
            )
            SELECT
                se.txn_date AS date,
                se.agent,
                COUNT(*) AS nb_cycles,
                AVG(se.nb_hops) AS profondeur_moy,
                MAX(se.nb_hops) AS profondeur_max,
                AVG(se.origin_amount) AS montant_moyen,
                SUM(se.origin_amount) AS total_cashin_fdj,
                SUM(se.exit_amount) AS total_exit_fdj,
                SUM(se.origin_amount) * 0.0256 AS commission_gagnee,
                AVG(EXTRACT(EPOCH FROM (se.exit_time - se.origin_time)) / 60) AS avg_delay_min
            FROM successful_exits se
            GROUP BY se.txn_date, se.agent
            HAVING COUNT(*) >= ?
            ORDER BY nb_cycles DESC, commission_gagnee DESC
            LIMIT 100
        ", [
            $debut,
            $fin,
            $debut,
            $finSendCycle,
            $debut,
            $finExitCycle,
            $this->amount_tolerance / 100,
            $maxDepth,
            $this->min_cycles,
        ]);

        // Conversion en minuscules
        $toLower = fn($rows) => array_map(
            fn($r) => array_change_key_case((array)$r, CASE_LOWER),
            $rows
        );

        $this->repeat_mp     = $toLower($this->repeat_mp);
        $this->repeat_cashin = $toLower($this->repeat_cashin);
        $this->repeat_w2b    = $toLower($this->repeat_w2b);
        $this->circulaires   = $toLower($this->circulaires);
        $this->cycling       = $toLower($this->cycling);

        $this->analyse = true;
        $this->error_message = '';

    } catch (\Exception $e) {
        $this->analyse = false;
        $this->loading = false;
        $this->error_message = 'Erreur: ' . $e->getMessage();
        \Log::error('Erreur analyse: ' . $e->getMessage() . "\n" . $e->getTraceAsString());
        session()->flash('error', 'Une erreur est survenue: ' . $e->getMessage());
    }

    // Mettre en cache pour 1 heure
    Cache::put($cacheKey, [
        'repeat_mp' => $this->repeat_mp,
        'repeat_cashin' => $this->repeat_cashin,
        'repeat_w2b' => $this->repeat_w2b,
        'circulaires' => $this->circulaires,
        'cycling' => $this->cycling,
    ], 3600);


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
            Paramètres d'analyse
        </p>

        @if(session()->has('error'))
            <div style="background:#FDECEA; border-left:3px solid #E24B4A; padding:12px 16px; border-radius:6px; margin-bottom:16px;">
                <p style="font-size:12px; color:#7F1D1D; margin:0;">{{ session('error') }}</p>
            </div>
        @endif

        @if(session()->has('warning'))
            <div style="background:#FFF3D0; border-left:3px solid #F5A800; padding:12px 16px; border-radius:6px; margin-bottom:16px;">
                <p style="font-size:12px; color:#7A4F00; margin:0;">{{ session('warning') }}</p>
            </div>
        @endif

        <div style="display:grid; grid-template-columns:repeat(3, minmax(0,1fr)); gap:12px; margin-bottom:16px;">
            <div>
                <label style="font-size:11px; color:#6b7280; display:block; margin-bottom:4px;">Date début</label>
                <input type="date" wire:model="date_debut"
                       min="{{ Carbon::now()->subDays(30)->format('Y-m-d') }}"
                       max="{{ Carbon::now()->format('Y-m-d') }}"
                       style="width:100%; border:1px solid #d1d5db; border-radius:7px; padding:8px 10px; font-size:13px; color:#111827; outline:none;">
            </div>
            <div>
                <label style="font-size:11px; color:#6b7280; display:block; margin-bottom:4px;">Date fin</label>
                <input type="date" wire:model="date_fin"
                       min="{{ Carbon::now()->subDays(30)->format('Y-m-d') }}"
                       max="{{ Carbon::now()->format('Y-m-d') }}"
                       style="width:100%; border:1px solid #d1d5db; border-radius:7px; padding:8px 10px; font-size:13px; color:#111827; outline:none;">
            </div>
            <div>
                <label style="font-size:11px; color:#6b7280; display:block; margin-bottom:4px;">Cycles min (cycling)</label>
                <input type="number" wire:model="min_cycles" min="2" max="20"
                       style="width:100%; border:1px solid #d1d5db; border-radius:7px; padding:8px 10px; font-size:13px; color:#111827; outline:none;">
            </div>
            <div>
                <label style="font-size:11px; color:#6b7280; display:block; margin-bottom:4px;">Tolérance montant (%)</label>
                <input type="number" wire:model="amount_tolerance" min="0" max="10"
                       style="width:100%; border:1px solid #d1d5db; border-radius:7px; padding:8px 10px; font-size:13px; color:#111827; outline:none;">
            </div>
            <div>
                <label style="font-size:11px; color:#6b7280; display:block; margin-bottom:4px;">Profondeur max Send Money</label>
                <input type="number" wire:model="max_depth" min="1" max="20"
                       style="width:100%; border:1px solid #d1d5db; border-radius:7px; padding:8px 10px; font-size:13px; color:#111827; outline:none;">
            </div>
        </div>

        <div style="display:flex; gap:12px; align-items:center;">
            <button onclick="lancerAnalyse()"
                    style="background:#1B2F6E; color:#fff; font-size:13px; font-weight:600; padding:10px 24px; border-radius:8px; border:none; cursor:pointer; display:flex; align-items:center; gap:8px;">
                <svg width="14" height="14" viewBox="0 0 16 16" fill="white">
                    <path d="M8 2L1 14h14L8 2zm0 5v4"/><circle cx="8" cy="12" r="0.8"/>
                </svg>
                Lancer l'analyse
            </button>

            @if(session()->has('error'))
                <span style="font-size:12px; color:#E24B4A;">{{ session('error') }}</span>
            @endif
        </div>
    </div>

    @if($analyse)

        {{-- KPIs GLOBAUX --}}
        <div style="display:grid; grid-template-columns:repeat(4, minmax(0,1fr)); gap:10px; margin-bottom:20px;">
            @php
                $kpis = [
                    ['label' => 'MP répétitifs',     'val' => count($repeat_mp),     'color' => '#F5A800'],
                    ['label' => 'Cash In répétitifs','val' => count($repeat_cashin), 'color' => '#1B2F6E'],
                    ['label' => 'Circuits Cash In→MP→Cash Out', 'val' => count($circulaires), 'color' => '#9333ea'],
                    ['label' => 'Cycling agents',    'val' => count($cycling),       'color' => '#E24B4A'],
                ];
            @endphp
            @foreach($kpis as $kpi)
                <div style="background:#fff; border:1px solid #e5e7eb; border-radius:10px; padding:14px; border-top:3px solid {{ $kpi['color'] }};">
                    <p style="font-size:11px; color:#6b7280; margin:0 0 6px;">{{ $kpi['label'] }}</p>
                    <p style="font-size:24px; font-weight:700; color:#111827; margin:0;">{{ $kpi['val'] }}</p>
                </div>
            @endforeach
        </div>

        {{-- 1 & 2. MP RÉPÉTITIFS + CASH IN RÉPÉTITIFS --}}
        <div style="display:grid; grid-template-columns:repeat(2, minmax(0,1fr)); gap:16px; margin-bottom:16px;">

            {{-- MP RÉPÉTITIFS --}}
            <div style="background:#fff; border:1px solid #e5e7eb; border-radius:10px; overflow:hidden;">
                <div style="padding:12px 16px; border-bottom:1px solid #e5e7eb; display:flex; align-items:center; gap:8px;">
                    <span style="width:8px; height:8px; border-radius:50%; background:#F5A800; display:inline-block;"></span>
                    <p style="font-size:13px; font-weight:600; color:#111827; margin:0;">Paiements marchands répétitifs</p>
                    <span style="margin-left:auto; background:#FFF3D0; color:#7A4F00; font-size:10px; font-weight:600; padding:2px 8px; border-radius:12px;">{{ count($repeat_mp) }} cas</span>
                </div>

                @if(empty($repeat_mp))
                    <p style="padding:20px; font-size:12px; color:#9ca3af;">Aucun résultat.</p>
                @else
                    <div style="overflow-x:auto; overflow-y:auto; max-height:220px;">
                        <table style="width:100%; border-collapse:collapse; font-size:11px;">
                            <thead>
                                <tr style="background:#F7F8FC;">
                                    <th style="padding:8px 12px; text-align:left; color:#6b7280; font-weight:500; border-bottom:1px solid #e5e7eb; white-space:nowrap; position:sticky; top:0; background:#F7F8FC; z-index:1;">#</th>
                                    <th style="padding:8px 12px; text-align:left; color:#6b7280; font-weight:500; border-bottom:1px solid #e5e7eb; white-space:nowrap; position:sticky; top:0; background:#F7F8FC; z-index:1;">Débit MSISDN</th>
                                    <th style="padding:8px 12px; text-align:left; color:#6b7280; font-weight:500; border-bottom:1px solid #e5e7eb; white-space:nowrap; position:sticky; top:0; background:#F7F8FC; z-index:1;">Crédit MSISDN</th>
                                    <th style="padding:8px 12px; text-align:left; color:#6b7280; font-weight:500; border-bottom:1px solid #e5e7eb; white-space:nowrap; position:sticky; top:0; background:#F7F8FC; z-index:1;">Nb</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach($repeat_mp as $i => $row)
                                    <tr style="border-bottom:1px solid #f3f4f6;"
                                        onmouseover="this.style.background='#F7F8FC'"
                                        onmouseout="this.style.background='transparent'">
                                        <td style="padding:8px 12px; color:#9ca3af;">{{ $i + 1 }}</td>
                                        <td style="padding:8px 12px; color:#374151; white-space:nowrap;">{{ $row['debit_party_identifier'] }}</td>
                                        <td style="padding:8px 12px; color:#374151; white-space:nowrap;">{{ $row['credit_party_identifier'] }}</td>
                                        <td style="padding:8px 12px;">
                                            <span style="background:#FFF3D0; color:#7A4F00; font-size:10px; font-weight:600; padding:2px 8px; border-radius:12px;">
                                                {{ $row['nb_paiements'] }}
                                            </span>
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>

                    @if(count($repeat_mp) > 5)
                        <div style="padding:8px 16px; border-top:1px solid #e5e7eb; background:#FAFAFA;">
                            <p style="font-size:10px; color:#9ca3af; margin:0;">
                                Affichage de {{ count($repeat_mp) }} résultats — faites défiler pour voir tout
                            </p>
                        </div>
                    @endif
                @endif
            </div>

            {{-- CASH IN RÉPÉTITIFS --}}
            <div style="background:#fff; border:1px solid #e5e7eb; border-radius:10px; overflow:hidden;">
                <div style="padding:12px 16px; border-bottom:1px solid #e5e7eb; display:flex; align-items:center; gap:8px;">
                    <span style="width:8px; height:8px; border-radius:50%; background:#1B2F6E; display:inline-block;"></span>
                    <p style="font-size:13px; font-weight:600; color:#111827; margin:0;">Cash In répétitifs</p>
                    <span style="margin-left:auto; background:#E8ECF8; color:#1B2F6E; font-size:10px; font-weight:600; padding:2px 8px; border-radius:12px;">{{ count($repeat_cashin) }} cas</span>
                </div>

                @if(empty($repeat_cashin))
                    <p style="padding:20px; font-size:12px; color:#9ca3af;">Aucun résultat.</p>
                @else
                    <div style="overflow-x:auto; overflow-y:auto; max-height:220px;">
                        <table style="width:100%; border-collapse:collapse; font-size:11px;">
                            <thead>
                                <tr style="background:#F7F8FC;">
                                    <th style="padding:8px 12px; text-align:left; color:#6b7280; font-weight:500; border-bottom:1px solid #e5e7eb; white-space:nowrap; position:sticky; top:0; background:#F7F8FC; z-index:1;">#</th>
                                    <th style="padding:8px 12px; text-align:left; color:#6b7280; font-weight:500; border-bottom:1px solid #e5e7eb; white-space:nowrap; position:sticky; top:0; background:#F7F8FC; z-index:1;">Débit MSISDN</th>
                                    <th style="padding:8px 12px; text-align:left; color:#6b7280; font-weight:500; border-bottom:1px solid #e5e7eb; white-space:nowrap; position:sticky; top:0; background:#F7F8FC; z-index:1;">Crédit MSISDN</th>
                                    <th style="padding:8px 12px; text-align:left; color:#6b7280; font-weight:500; border-bottom:1px solid #e5e7eb; white-space:nowrap; position:sticky; top:0; background:#F7F8FC; z-index:1;">Nb</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach($repeat_cashin as $i => $row)
                                    <tr style="border-bottom:1px solid #f3f4f6;"
                                        onmouseover="this.style.background='#F7F8FC'"
                                        onmouseout="this.style.background='transparent'">
                                        <td style="padding:8px 12px; color:#9ca3af;">{{ $i + 1 }}</td>
                                        <td style="padding:8px 12px; color:#374151; white-space:nowrap;">{{ $row['debit_party_identifier'] }}</td>
                                        <td style="padding:8px 12px; color:#374151; white-space:nowrap;">{{ $row['credit_party_identifier'] }}</td>
                                        <td style="padding:8px 12px;">
                                            <span style="background:#E8ECF8; color:#1B2F6E; font-size:10px; font-weight:600; padding:2px 8px; border-radius:12px;">
                                                {{ $row['nb_cashin'] }}
                                            </span>
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>

                    @if(count($repeat_cashin) > 5)
                        <div style="padding:8px 16px; border-top:1px solid #e5e7eb; background:#FAFAFA;">
                            <p style="font-size:10px; color:#9ca3af; margin:0;">
                                Affichage de {{ count($repeat_cashin) }} résultats — faites défiler pour voir tout
                            </p>
                        </div>
                    @endif
                @endif
            </div>

        </div>

        {{-- 3. CIRCUITS : Customer Cash In → Merchant Payment → Business Cash Out --}}
        <div style="background:#fff; border:1px solid #e5e7eb; border-radius:10px; overflow:hidden; margin-bottom:16px;">
            <div style="padding:12px 16px; border-bottom:1px solid #e5e7eb; display:flex; align-items:center; gap:8px;">
                <span style="width:8px; height:8px; border-radius:50%; background:#9333ea; display:inline-block;"></span>
                <p style="font-size:13px; font-weight:600; color:#111827; margin:0;">Cash In → Merchant Payment → Business Cash Out</p>
                <span style="margin-left:auto; background:#F3E8FF; color:#6B21A8; font-size:10px; font-weight:600; padding:2px 8px; border-radius:12px;">{{ count($circulaires) }} cas</span>
            </div>
            @if(empty($circulaires))
                <p style="padding:20px; font-size:12px; color:#9ca3af;">Aucun circuit Cash In → Merchant Payment → Business Cash Out détecté.</p>
            @else
                <div style="overflow-x:auto;">
                    <table style="width:100%; border-collapse:collapse; font-size:11px;">
                        <thead><tr style="background:#F7F8FC;">
                            <th style="padding:8px 12px; text-align:left; color:#6b7280; font-weight:500; border-bottom:1px solid #e5e7eb;">Date</th>
                            <th style="padding:8px 12px; text-align:left; color:#6b7280; font-weight:500; border-bottom:1px solid #e5e7eb;">Cash In (de)</th>
                            <th style="padding:8px 12px; text-align:left; color:#6b7280; font-weight:500; border-bottom:1px solid #e5e7eb;">Client</th>
                            <th style="padding:8px 12px; text-align:left; color:#6b7280; font-weight:500; border-bottom:1px solid #e5e7eb;">Marchand</th>
                            <th style="padding:8px 12px; text-align:left; color:#6b7280; font-weight:500; border-bottom:1px solid #e5e7eb;">Montant</th>
                            <th style="padding:8px 12px; text-align:left; color:#6b7280; font-weight:500; border-bottom:1px solid #e5e7eb;">Business Cash Out (vers)</th>
                            <th style="padding:8px 12px; text-align:left; color:#6b7280; font-weight:500; border-bottom:1px solid #e5e7eb;">Délai</th>
                            <th style="padding:8px 12px; text-align:left; color:#6b7280; font-weight:500; border-bottom:1px solid #e5e7eb;">Flags</th>
                        </tr></thead>
                        <tbody>
                            @foreach($circulaires as $row)
                                <tr style="border-bottom:1px solid #f3f4f6;"
                                    onmouseover="this.style.background='#F7F8FC'"
                                    onmouseout="this.style.background='transparent'">
                                    <td style="padding:8px 12px; color:#6b7280;">{{ $row['date'] }}</td>
                                    <td style="padding:8px 12px; color:#374151;">{{ $row['cashin_from'] }}</td>
                                    <td style="padding:8px 12px; font-weight:600; color:#111827;">{{ $row['client'] }}</td>
                                    <td style="padding:8px 12px; color:#374151;">{{ $row['merchant'] }}</td>
                                    <td style="padding:8px 12px; color:#374151;">{{ number_format($row['amount'], 0, ',', ' ') }} FDJ</td>
                                    <td style="padding:8px 12px; color:#374151;">{{ $row['cashout_to'] }}</td>
                                    <td style="padding:8px 12px;">
                                        @php $delay = round($row['delay_minutes']); @endphp
                                        <span style="background:{{ $delay < 10 ? '#FDECEA' : ($delay < 30 ? '#FFF3D0' : '#E5F5ED') }};
                                                    color:{{ $delay < 10 ? '#7F1D1D' : ($delay < 30 ? '#7A4F00' : '#005C2B') }};
                                                    font-size:10px; font-weight:700; padding:2px 8px; border-radius:12px;">
                                            {{ $delay }} min
                                        </span>
                                    </td>
                                    <td style="padding:8px 12px; color:#6b7280; font-size:10px;">{{ $row['flags'] }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </div>

        {{-- 4. CYCLING DE COMMISSION : Cash In → Send Money (n fois) → W2B ou Cash Out --}}
        <div style="background:#fff; border:1px solid #e5e7eb; border-radius:10px; overflow:hidden; margin-bottom:16px;">
            <div style="padding:12px 16px; border-bottom:1px solid #e5e7eb; display:flex; align-items:center; gap:8px;">
                <span style="width:8px; height:8px; border-radius:50%; background:#E24B4A; display:inline-block;"></span>
                <p style="font-size:13px; font-weight:600; color:#111827; margin:0;">Cash In → Send Money (n fois) → W2B / Cash Out</p>
                <span style="margin-left:auto; background:#FDECEA; color:#7F1D1D; font-size:10px; font-weight:600; padding:2px 8px; border-radius:12px;">{{ count($cycling) }} agents</span>
            </div>

            @if(empty($cycling))
                <p style="padding:20px; font-size:12px; color:#9ca3af;">Aucun agent suspect détecté.</p>
            @else
                @php
                    $totalCycles     = array_sum(array_column($cycling, 'nb_cycles'));
                    $totalCommission = array_sum(array_column($cycling, 'commission_gagnee'));
                    $totalVolume     = array_sum(array_column($cycling, 'total_cashin_fdj'));
                @endphp

                <div style="display:grid; grid-template-columns:repeat(4, minmax(0,1fr)); gap:0; border-bottom:1px solid #e5e7eb;">
                    @foreach([
                        ['label' => 'Agents alertés',    'val' => count($cycling)],
                        ['label' => 'Total cycles',       'val' => $totalCycles],
                        ['label' => 'Volume Cash In total', 'val' => number_format($totalVolume, 0, ',', ' ') . ' FDJ'],
                        ['label' => 'Commission totale',  'val' => number_format($totalCommission, 0, ',', ' ') . ' FDJ'],
                    ] as $kpi)
                        <div style="padding:14px 16px; border-right:1px solid #e5e7eb;">
                            <p style="font-size:10px; color:#6b7280; margin:0 0 4px;">{{ $kpi['label'] }}</p>
                            <p style="font-size:18px; font-weight:700; color:#111827; margin:0;">{{ $kpi['val'] }}</p>
                        </div>
                    @endforeach
                </div>

                <div style="overflow-x:auto;">
                    <table style="width:100%; border-collapse:collapse; font-size:11px;">
                        <thead>
                            <tr style="background:#F7F8FC;">
                                <th style="padding:8px 12px; text-align:left; color:#6b7280; font-weight:500; border-bottom:1px solid #e5e7eb;">Date</th>
                                <th style="padding:8px 12px; text-align:left; color:#6b7280; font-weight:500; border-bottom:1px solid #e5e7eb;">Agent</th>
                                <th style="padding:8px 12px; text-align:left; color:#6b7280; font-weight:500; border-bottom:1px solid #e5e7eb;">Cycles</th>
                                <th style="padding:8px 12px; text-align:left; color:#6b7280; font-weight:500; border-bottom:1px solid #e5e7eb;">Profondeur (moy/max)</th>
                                <th style="padding:8px 12px; text-align:left; color:#6b7280; font-weight:500; border-bottom:1px solid #e5e7eb;">Montant moyen</th>
                                <th style="padding:8px 12px; text-align:left; color:#6b7280; font-weight:500; border-bottom:1px solid #e5e7eb;">Total Cash In</th>
                                <th style="padding:8px 12px; text-align:left; color:#6b7280; font-weight:500; border-bottom:1px solid #e5e7eb;">Total sorti (W2B/Cash Out)</th>
                                <th style="padding:8px 12px; text-align:left; color:#6b7280; font-weight:500; border-bottom:1px solid #e5e7eb;">Commission</th>
                                <th style="padding:8px 12px; text-align:left; color:#6b7280; font-weight:500; border-bottom:1px solid #e5e7eb;">Délai moy.</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($cycling as $row)
                                <tr style="border-bottom:1px solid #f3f4f6;"
                                    onmouseover="this.style.background='#F7F8FC'"
                                    onmouseout="this.style.background='transparent'">
                                    <td style="padding:8px 12px; color:#6b7280;">{{ $row['date'] }}</td>
                                    <td style="padding:8px 12px; font-weight:600; color:#111827;">{{ $row['agent'] }}</td>
                                    <td style="padding:8px 12px;">
                                        <span style="background:#FDECEA; color:#7F1D1D; font-size:10px; font-weight:700; padding:2px 8px; border-radius:12px;">
                                            {{ $row['nb_cycles'] }}
                                        </span>
                                    </td>
                                    <td style="padding:8px 12px; color:#374151;">
                                        {{ round($row['profondeur_moy'], 1) }} / {{ $row['profondeur_max'] }} hops
                                    </td>
                                    <td style="padding:8px 12px; color:#374151;">
                                        {{ number_format($row['montant_moyen'], 0, ',', ' ') }} FDJ
                                    </td>
                                    <td style="padding:8px 12px; color:#374151;">
                                        {{ number_format($row['total_cashin_fdj'], 0, ',', ' ') }} FDJ
                                    </td>
                                    <td style="padding:8px 12px; color:#374151;">
                                        {{ number_format($row['total_exit_fdj'], 0, ',', ' ') }} FDJ
                                    </td>
                                    <td style="padding:8px 12px; color:#374151;">
                                        {{ number_format($row['commission_gagnee'], 0, ',', ' ') }} FDJ
                                    </td>
                                    <td style="padding:8px 12px; color:#374151;">
                                        {{ round($row['avg_delay_min'], 1) }} min
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </div>

    @endif

    <script>
        function lancerAnalyse() {
            if (typeof Swal === 'undefined') {
                alert('SweetAlert2 non chargé — vérifie le layout.');
                return;
            }

            // Récupérer les dates
            const dateDebut = document.querySelector('[wire\\:model="date_debut"]')?.value || '';
            const dateFin = document.querySelector('[wire\\:model="date_fin"]')?.value || '';
            
            // Vérifier si la période est d'un seul jour
            if (dateDebut && dateFin && dateDebut === dateFin) {
                Swal.fire({
                    title: '⚠️ Période courte',
                    text: 'L\'analyse sur une seule journée peut donner des résultats limités. Les scénarios de fraude sont souvent détectés sur plusieurs jours.',
                    icon: 'warning',
                    showCancelButton: true,
                    confirmButtonColor: '#1B2F6E',
                    cancelButtonColor: '#E24B4A',
                    confirmButtonText: 'Continuer quand même',
                    cancelButtonText: 'Annuler',
                }).then((result) => {
                    if (result.isConfirmed) {
                        lancerAnalyseReelle();
                    }
                });
            } else {
                lancerAnalyseReelle();
            }
        }

        function lancerAnalyseReelle() {
            Swal.fire({
                title: 'Analyse en cours...',
                html: `
                    <div style="font-size:13px; color:#6b7280; margin-bottom:16px;">
                        Détection des scénarios de fraude sur la période sélectionnée.
                    </div>
                    <div style="width:100%; height:6px; background:#e5e7eb; border-radius:10px; overflow:hidden; margin-bottom:8px;">
                        <div id="fraude-fill" style="height:100%; width:0%; background:#1B2F6E; border-radius:10px; transition:width 0.4s ease;"></div>
                    </div>
                    <div id="fraude-msg" style="font-size:11px; color:#9ca3af;">Initialisation...</div>
                `,
                allowOutsideClick: false,
                showConfirmButton: false,
                didOpen: () => {
                    const messages = [
                        'Chargement des transactions...',
                        'Classification par type...',
                        'Détection MP répétitifs...',
                        'Analyse Cash In → W2B...',
                        'Analyse B2W → Send → W2B...',
                        'Scénarios circulaires...',
                        'Cycling de commission...',
                        'Calcul des scores de risque...',
                        'Finalisation...',
                    ];

                    let progress = 0;
                    let msgIndex = 0;

                    const getFill = () => document.getElementById('fraude-fill');
                    const getMsg  = () => document.getElementById('fraude-msg');

                    const interval = setInterval(() => {
                        progress += (92 - progress) * 0.06;
                        msgIndex = Math.min(
                            Math.floor((progress / 92) * messages.length),
                            messages.length - 1
                        );
                        if (getFill()) getFill().style.width = progress.toFixed(1) + '%';
                        if (getMsg())  getMsg().textContent  = messages[msgIndex];
                    }, 300);

                    const component = Livewire.find(
                        document.querySelector('[wire\\:id]').getAttribute('wire:id')
                    );

                    component.call('lancer')
                        .then(() => {
                            clearInterval(interval);

                            if (getFill()) {
                                getFill().style.width = '100%';
                                getFill().style.background = '#16a34a';
                            }
                            if (getMsg()) getMsg().textContent = 'Analyse terminée !';

                            setTimeout(() => {
                                Swal.fire({
                                    title:             'Analyse terminée !',
                                    icon:              'success',
                                    confirmButtonText: 'Voir les résultats',
                                    confirmButtonColor: '#1B2F6E',
                                    timer:             3000,
                                    timerProgressBar:  true,
                                });
                            }, 400);
                        })
                        .catch((err) => {
                            clearInterval(interval);
                            console.error(err);
                            Swal.fire({
                                title: 'Erreur',
                                text: 'Une erreur est survenue lors de l\'analyse. Vérifie les logs pour plus de détails.',
                                icon: 'error',
                                confirmButtonColor: '#E24B4A',
                            });
                        });
                }
            });
        }
    </script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/sweetalert2/11.10.5/sweetalert2.all.min.js"></script>

    <style>
        @keyframes spin { from { transform:rotate(0deg); } to { transform:rotate(360deg); } }
    </style>
</div>

</div>