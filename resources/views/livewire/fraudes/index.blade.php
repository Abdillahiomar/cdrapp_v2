<?php

use Livewire\Volt\Component;
use App\Models\Transaction;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

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
    public array $cashin_w2b       = [];
    public array $b2w_send_w2b     = [];
    public array $circulaires      = [];
    public array $cycling          = [];

    // Index pré-calculés
    private array $idxMerchant = [];
    private array $idxCashin   = [];
    private array $idxW2b      = [];
    private array $idxSend     = [];
    private array $idxB2w      = [];
    private array $idxCashout  = [];

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
        return \App\Models\reasonType::query()
            ->whereRaw('LOWER(reason_name) LIKE ?', ['%' . strtolower($needle) . '%'])
            ->pluck('reason_index')
            ->map(fn($v) => (int) $v)
            ->all();
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
        try {
            set_time_limit(300);
            ini_set('memory_limit', '512M');

            // Validation
            if (!$this->validatePeriod()) {
                $this->analyse = false;
                session()->flash('error', $this->error_message);
                return;
            }

            $debut = $this->date_debut . ' 00:00:00';
            $fin   = $this->date_fin   . ' 23:59:59';

            // Vérifier si c'est une période d'un seul jour
            $isSingleDay = $this->date_debut === $this->date_fin;
            
            if ($isSingleDay) {
                session()->flash('warning', '⚠️ L\'analyse sur une seule journée peut donner des résultats limités. Les scénarios de fraude sont souvent détectés sur plusieurs jours.');
            }

            // ── Résolution des reason_index par motif (une seule fois) ────
            $this->idxMerchant = $this->reasonIndexesFor('merchant payment');
            $this->idxCashin   = $this->reasonIndexesFor('customer cash in');
            $this->idxW2b      = $this->reasonIndexesFor('w2b');
            $this->idxSend     = $this->reasonIndexesFor('send money');
            $this->idxB2w      = $this->reasonIndexesFor('b2w');
            $this->idxCashout  = $this->reasonIndexesFor('cash out');

            $idxMerchant = $this->inClause($this->idxMerchant);
            $idxCashin   = $this->inClause($this->idxCashin);
            $idxW2b      = $this->inClause($this->idxW2b);
            $idxSend     = $this->inClause($this->idxSend);
            $idxB2w      = $this->inClause($this->idxB2w);
            $idxCashout  = $this->inClause($this->idxCashout);

            // ── 1. MP répétitifs ─────────────────────────────────────────
            $this->repeat_mp = DB::select("
                SELECT
                    debit_party_identifier,
                    credit_party_identifier,
                    COUNT(*) as nb_paiements
                FROM fact_txn_v2
                WHERE transaction_initiated_time BETWEEN ? AND ?
                  AND reason_index {$idxMerchant}
                GROUP BY debit_party_identifier, credit_party_identifier
                HAVING COUNT(*) > 2
                ORDER BY nb_paiements DESC
            ", [$debut, $fin]);

            // ── 2. Cash In répétitifs ────────────────────────────────────
            $this->repeat_cashin = DB::select("
                SELECT
                    debit_party_identifier,
                    credit_party_identifier,
                    COUNT(*) as nb_cashin
                FROM fact_txn_v2
                WHERE transaction_initiated_time BETWEEN ? AND ?
                  AND reason_index {$idxCashin}
                GROUP BY debit_party_identifier, credit_party_identifier
                HAVING COUNT(*) >= 2
                ORDER BY nb_cashin DESC
            ", [$debut, $fin]);

            // ── 3. W2B répétitifs ────────────────────────────────────────
            $this->repeat_w2b = DB::select("
                SELECT
                    debit_party_identifier,
                    credit_party_identifier,
                    COUNT(*) as nb_w2b
                FROM fact_txn_v2
                WHERE transaction_initiated_time BETWEEN ? AND ?
                  AND reason_index {$idxW2b}
                GROUP BY debit_party_identifier, credit_party_identifier
                HAVING COUNT(*) >= 2
                ORDER BY nb_w2b DESC
            ", [$debut, $fin]);

            // ── 4. Cash In → W2B — jointure SQL ──────────────────────────
            // Adapter la requête selon la période
            $timeWindow = $isSingleDay ? "INTERVAL '2 hours'" : "INTERVAL '24 hours'";
            
            $this->cashin_w2b = DB::select("
                SELECT
                    ci.transaction_initiated_time::date        AS date,
                    ci.debit_party_identifier                  AS distributeur,
                    ci.credit_party_identifier                 AS client,
                    ci.actual_amount                           AS cashin_amount,
                    ci.transaction_initiated_time              AS cashin_time,
                    w.actual_amount                            AS w2b_amount,
                    w.transaction_initiated_time               AS w2b_time,
                    w.credit_party_identifier                  AS banque,
                    EXTRACT(EPOCH FROM (w.transaction_initiated_time - ci.transaction_initiated_time)) / 60 AS delay_minutes
                FROM fact_txn_v2 ci
                JOIN fact_txn_v2 w
                    ON  w.credit_party_identifier    = ci.credit_party_identifier
                    AND w.transaction_initiated_time > ci.transaction_initiated_time
                    AND w.transaction_initiated_time < ci.transaction_initiated_time + {$timeWindow}
                    AND w.reason_index {$idxW2b}
                WHERE ci.transaction_initiated_time BETWEEN ? AND ?
                  AND ci.reason_index {$idxCashin}
                ORDER BY ci.transaction_initiated_time
                LIMIT 1000
            ", [$debut, $fin]);

            // ── 5. B2W → Send → W2B — double jointure SQL ────────────────
            $timeWindow1 = $isSingleDay ? "INTERVAL '1 hour'" : "INTERVAL '6 hours'";
            $timeWindow2 = $isSingleDay ? "INTERVAL '1 hour'" : "INTERVAL '6 hours'";

            $this->b2w_send_w2b = DB::select("
                SELECT
                    b.transaction_initiated_time::date         AS date,
                    b.credit_party_identifier                  AS source_bank,
                    b.credit_party_identifier                  AS client_a,
                    b.actual_amount                            AS b2w_amount,
                    b.transaction_initiated_time               AS b2w_time,
                    s.credit_party_identifier                  AS client_b,
                    s.actual_amount                            AS send_amount,
                    s.transaction_initiated_time               AS send_time,
                    w.actual_amount                            AS w2b_amount,
                    w.transaction_initiated_time               AS w2b_time,
                    w.credit_party_identifier                  AS destination_bank,
                    EXTRACT(EPOCH FROM (s.transaction_initiated_time - b.transaction_initiated_time)) / 60 AS delay_b2w_send_min,
                    EXTRACT(EPOCH FROM (w.transaction_initiated_time - s.transaction_initiated_time)) / 60 AS delay_send_w2b_min
                FROM fact_txn_v2 b
                JOIN fact_txn_v2 s
                    ON  s.credit_party_identifier    = b.credit_party_identifier
                    AND s.transaction_initiated_time > b.transaction_initiated_time
                    AND s.transaction_initiated_time < b.transaction_initiated_time + {$timeWindow1}
                    AND s.reason_index {$idxSend}
                JOIN fact_txn_v2 w
                    ON  w.credit_party_identifier    = s.credit_party_identifier
                    AND w.transaction_initiated_time > s.transaction_initiated_time
                    AND w.transaction_initiated_time < s.transaction_initiated_time + {$timeWindow2}
                    AND w.reason_index {$idxW2b}
                WHERE b.transaction_initiated_time BETWEEN ? AND ?
                  AND b.reason_index {$idxB2w}
                ORDER BY b.transaction_initiated_time
                LIMIT 1000
            ", [$debut, $fin]);

            // ── 6. Scénarios circulaires — SQL ────────────────────────────
            $timeWindowCirc = $isSingleDay ? "INTERVAL '2 hours'" : "INTERVAL '12 hours'";

            $this->circulaires = DB::select("
                SELECT
                    mp.transaction_initiated_time::date        AS date,
                    ci.debit_party_identifier                  AS cashin_from,
                    ci.transaction_initiated_time              AS ci_time,
                    mp.debit_party_identifier                  AS client,
                    mp.credit_party_identifier                 AS merchant,
                    mp.transaction_initiated_time              AS mp_time,
                    bco.transaction_initiated_time             AS bco_time,
                    mp.actual_amount                           AS amount,
                    bco.credit_party_identifier                AS cashout_to,
                    EXTRACT(EPOCH FROM (bco.transaction_initiated_time - mp.transaction_initiated_time)) / 60 AS delay_minutes,
                    CASE
                        WHEN EXTRACT(EPOCH FROM (bco.transaction_initiated_time - mp.transaction_initiated_time)) / 60 < 10
                            THEN 'Cashout rapide'
                        WHEN mp.actual_amount >= 20000
                            THEN 'Montant élevé'
                        ELSE 'Activité inhabituelle'
                    END AS flags
                FROM fact_txn_v2 mp
                JOIN fact_txn_v2 ci
                    ON  ci.credit_party_identifier   = mp.debit_party_identifier
                    AND ci.transaction_initiated_time < mp.transaction_initiated_time
                    AND ci.transaction_initiated_time > mp.transaction_initiated_time - {$timeWindowCirc}
                    AND ci.reason_index {$idxCashin}
                JOIN fact_txn_v2 bco
                    ON  bco.debit_party_identifier   = mp.credit_party_identifier
                    AND bco.actual_amount            = mp.actual_amount
                    AND bco.transaction_initiated_time > mp.transaction_initiated_time
                    AND bco.transaction_initiated_time < mp.transaction_initiated_time + {$timeWindowCirc}
                    AND bco.reason_index {$idxCashout}
                WHERE mp.transaction_initiated_time BETWEEN ? AND ?
                  AND mp.reason_index {$idxMerchant}
                ORDER BY delay_minutes ASC
                LIMIT 500
            ", [$debut, $fin]);

            // ── 7. Cycling — SQL agrégé ───────────────────────────────────
            // Pour le cycling, on utilise une fenêtre temporelle plus large
            $timeWindowCycle = $isSingleDay ? "INTERVAL '3 hours'" : "INTERVAL '24 hours'";

            $this->cycling = DB::select("
                SELECT
                    ci.transaction_initiated_time::date        AS date,
                    ci.debit_party_identifier                  AS agent,
                    COUNT(*)                                    AS nb_cycles,
                    ci.actual_amount                           AS montant_par_cycle,
                    SUM(ci.actual_amount)                       AS total_cashin_fdj,
                    SUM(w.actual_amount)                        AS total_w2b_fdj,
                    SUM(ci.actual_amount) * 0.0256              AS commission_gagnee,
                    (SUM(ci.actual_amount) * 0.0256) - (ci.actual_amount * 0.0256) AS surplus_commission,
                    AVG(EXTRACT(EPOCH FROM (w.transaction_initiated_time - ci.transaction_initiated_time)) / 60) AS avg_delay_min
                FROM fact_txn_v2 ci
                JOIN fact_txn_v2 s
                    ON  s.debit_party_identifier     = ci.credit_party_identifier
                    AND s.transaction_initiated_time > ci.transaction_initiated_time
                    AND s.transaction_initiated_time < ci.transaction_initiated_time + {$timeWindowCycle}
                    AND s.reason_index {$idxSend}
                    AND ABS(s.actual_amount - ci.actual_amount) / ci.actual_amount <= ?
                JOIN fact_txn_v2 w
                    ON  w.debit_party_identifier     = s.credit_party_identifier
                    AND w.transaction_initiated_time > s.transaction_initiated_time
                    AND w.transaction_initiated_time < s.transaction_initiated_time + {$timeWindowCycle}
                    AND w.reason_index {$idxW2b}
                WHERE ci.transaction_initiated_time BETWEEN ? AND ?
                  AND ci.reason_index {$idxCashin}
                  AND ci.actual_amount > 0
                GROUP BY ci.transaction_initiated_time::date, ci.debit_party_identifier, ci.actual_amount
                HAVING COUNT(*) >= ?
                ORDER BY nb_cycles DESC, commission_gagnee DESC
                LIMIT 200
            ", [
                $this->amount_tolerance / 100,
                $debut,
                $fin,
                $this->min_cycles,
            ]);

            // Conversion en minuscules pour les clés
            $toLower = fn($rows) => array_map(
                fn($r) => array_change_key_case((array)$r, CASE_LOWER),
                $rows
            );

            $this->repeat_mp     = $toLower($this->repeat_mp);
            $this->repeat_cashin = $toLower($this->repeat_cashin);
            $this->repeat_w2b    = $toLower($this->repeat_w2b);
            $this->cashin_w2b    = $toLower($this->cashin_w2b);
            $this->b2w_send_w2b  = $toLower($this->b2w_send_w2b);
            $this->circulaires   = $toLower($this->circulaires);
            $this->cycling       = $toLower($this->cycling);

            $this->analyse = true;
            $this->error_message = '';

        } catch (\Exception $e) {
            $this->analyse = false;
            $this->loading = false;
            $this->error_message = 'Erreur SQL: ' . $e->getMessage();
            \Log::error('Erreur analyse: ' . $e->getMessage() . "\n" . $e->getTraceAsString());
            session()->flash('error', 'Une erreur est survenue lors de l\'analyse: ' . $e->getMessage());
        }
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
        <div style="display:grid; grid-template-columns:repeat(5, minmax(0,1fr)); gap:10px; margin-bottom:20px;">
            @php
                $kpis = [
                    ['label' => 'MP répétitifs',    'val' => count($repeat_mp),     'color' => '#F5A800'],
                    ['label' => 'Cash In répétitifs','val' => count($repeat_cashin), 'color' => '#1B2F6E'],
                    ['label' => 'Cash In → W2B',    'val' => count($cashin_w2b),    'color' => '#E24B4A'],
                    ['label' => 'B2W → Send → W2B', 'val' => count($b2w_send_w2b),  'color' => '#9333ea'],
                    ['label' => 'Cycling agents',   'val' => count($cycling),        'color' => '#E24B4A'],
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

        {{-- 3. CASH IN → W2B --}}
        <div style="background:#fff; border:1px solid #e5e7eb; border-radius:10px; overflow:hidden; margin-bottom:16px;">
            <div style="padding:12px 16px; border-bottom:1px solid #e5e7eb; display:flex; align-items:center; gap:8px;">
                <span style="width:8px; height:8px; border-radius:50%; background:#E24B4A; display:inline-block;"></span>
                <p style="font-size:13px; font-weight:600; color:#111827; margin:0;">Cash In → W2B</p>
                <span style="margin-left:auto; background:#FDECEA; color:#7F1D1D; font-size:10px; font-weight:600; padding:2px 8px; border-radius:12px;">{{ count($cashin_w2b) }} cas</span>
            </div>
            @if(empty($cashin_w2b))
                <p style="padding:20px; font-size:12px; color:#9ca3af;">Aucun résultat.</p>
            @else
                <div style="overflow-x:auto; overflow-y:auto; max-height:350px;">
                    <table style="width:100%; border-collapse:collapse; font-size:11px;">
                        <thead><tr style="background:#F7F8FC;">
                            <th style="padding:8px 12px; text-align:left; color:#6b7280; font-weight:500; border-bottom:1px solid #e5e7eb; white-space:nowrap; position:sticky; top:0; background:#F7F8FC; z-index:1;">Date</th>
                            <th style="padding:8px 12px; text-align:left; color:#6b7280; font-weight:500; border-bottom:1px solid #e5e7eb; white-space:nowrap; position:sticky; top:0; background:#F7F8FC; z-index:1;">Distributeur</th>
                            <th style="padding:8px 12px; text-align:left; color:#6b7280; font-weight:500; border-bottom:1px solid #e5e7eb; white-space:nowrap; position:sticky; top:0; background:#F7F8FC; z-index:1;">Client</th>
                            <th style="padding:8px 12px; text-align:left; color:#6b7280; font-weight:500; border-bottom:1px solid #e5e7eb; white-space:nowrap; position:sticky; top:0; background:#F7F8FC; z-index:1;">Cash In</th>
                            <th style="padding:8px 12px; text-align:left; color:#6b7280; font-weight:500; border-bottom:1px solid #e5e7eb; white-space:nowrap; position:sticky; top:0; background:#F7F8FC; z-index:1;">W2B</th>
                            <th style="padding:8px 12px; text-align:left; color:#6b7280; font-weight:500; border-bottom:1px solid #e5e7eb; white-space:nowrap; position:sticky; top:0; background:#F7F8FC; z-index:1;">Banque</th>
                            <th style="padding:8px 12px; text-align:left; color:#6b7280; font-weight:500; border-bottom:1px solid #e5e7eb; white-space:nowrap; position:sticky; top:0; background:#F7F8FC; z-index:1;">Délai (min)</th>
                        </tr></thead>
                        <tbody>
                            @foreach($cashin_w2b as $row)
                                <tr style="border-bottom:1px solid #f3f4f6;" onmouseover="this.style.background='#F7F8FC'" onmouseout="this.style.background='transparent'">
                                    <td style="padding:8px 12px; color:#6b7280;">{{ $row['date'] }}</td>
                                    <td style="padding:8px 12px; color:#374151;">{{ $row['distributeur'] }}</td>
                                    <td style="padding:8px 12px; font-weight:600; color:#111827;">{{ $row['client'] }}</td>
                                    <td style="padding:8px 12px; color:#374151;">{{ number_format($row['cashin_amount'], 0, ',', ' ') }} FDJ</td>
                                    <td style="padding:8px 12px; color:#374151;">{{ number_format($row['w2b_amount'], 0, ',', ' ') }} FDJ</td>
                                    <td style="padding:8px 12px; color:#374151;">{{ trim(strstr($row['banque'], '-') ?: $row['banque'], '- ') }}</td>
                                    <td style="padding:8px 12px;">
                                        <span style="background:{{ $row['delay_minutes'] < 30 ? '#FDECEA' : '#E5F5ED' }}; color:{{ $row['delay_minutes'] < 30 ? '#7F1D1D' : '#005C2B' }}; font-size:10px; font-weight:600; padding:2px 8px; border-radius:12px;">
                                            {{ round($row['delay_minutes']) }} min
                                        </span>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </div>

        {{-- 4. B2W → Send → W2B --}}
        <div style="background:#fff; border:1px solid #e5e7eb; border-radius:10px; overflow:hidden; margin-bottom:16px;">
            <div style="padding:12px 16px; border-bottom:1px solid #e5e7eb; display:flex; align-items:center; gap:8px;">
                <span style="width:8px; height:8px; border-radius:50%; background:#9333ea; display:inline-block;"></span>
                <p style="font-size:13px; font-weight:600; color:#111827; margin:0;">B2W → Send Money → W2B</p>
                <span style="margin-left:auto; background:#F3E8FF; color:#6B21A8; font-size:10px; font-weight:600; padding:2px 8px; border-radius:12px;">{{ count($b2w_send_w2b) }} cas</span>
            </div>

            @if(empty($b2w_send_w2b))
                <p style="padding:20px; font-size:12px; color:#9ca3af;">Aucun scénario B2W → Send → W2B détecté.</p>
            @else
                <div style="overflow-x:auto; overflow-y:auto; max-height:350px;">
                    <table style="width:100%; border-collapse:collapse; font-size:11px;">
                        <thead>
                            <tr style="background:#F7F8FC;">
                                <th style="padding:8px 12px; text-align:left; color:#6b7280; font-weight:500; border-bottom:1px solid #e5e7eb; white-space:nowrap; position:sticky; top:0; background:#F7F8FC; z-index:1;">#</th>
                                <th style="padding:8px 12px; text-align:left; color:#6b7280; font-weight:500; border-bottom:1px solid #e5e7eb; white-space:nowrap; position:sticky; top:0; background:#F7F8FC; z-index:1;">Date</th>
                                <th style="padding:8px 12px; text-align:left; color:#6b7280; font-weight:500; border-bottom:1px solid #e5e7eb; white-space:nowrap; position:sticky; top:0; background:#F7F8FC; z-index:1;">Banque source</th>
                                <th style="padding:8px 12px; text-align:left; color:#6b7280; font-weight:500; border-bottom:1px solid #e5e7eb; white-space:nowrap; position:sticky; top:0; background:#F7F8FC; z-index:1;">Client A</th>
                                <th style="padding:8px 12px; text-align:left; color:#6b7280; font-weight:500; border-bottom:1px solid #e5e7eb; white-space:nowrap; position:sticky; top:0; background:#F7F8FC; z-index:1;">Montant B2W</th>
                                <th style="padding:8px 12px; text-align:left; color:#6b7280; font-weight:500; border-bottom:1px solid #e5e7eb; white-space:nowrap; position:sticky; top:0; background:#F7F8FC; z-index:1;">Client B</th>
                                <th style="padding:8px 12px; text-align:left; color:#6b7280; font-weight:500; border-bottom:1px solid #e5e7eb; white-space:nowrap; position:sticky; top:0; background:#F7F8FC; z-index:1;">Montant Send</th>
                                <th style="padding:8px 12px; text-align:left; color:#6b7280; font-weight:500; border-bottom:1px solid #e5e7eb; white-space:nowrap; position:sticky; top:0; background:#F7F8FC; z-index:1;">Montant W2B</th>
                                <th style="padding:8px 12px; text-align:left; color:#6b7280; font-weight:500; border-bottom:1px solid #e5e7eb; white-space:nowrap; position:sticky; top:0; background:#F7F8FC; z-index:1;">Banque dest.</th>
                                <th style="padding:8px 12px; text-align:left; color:#6b7280; font-weight:500; border-bottom:1px solid #e5e7eb; white-space:nowrap; position:sticky; top:0; background:#F7F8FC; z-index:1;">Délai B2W→Send</th>
                                <th style="padding:8px 12px; text-align:left; color:#6b7280; font-weight:500; border-bottom:1px solid #e5e7eb; white-space:nowrap; position:sticky; top:0; background:#F7F8FC; z-index:1;">Délai Send→W2B</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($b2w_send_w2b as $i => $row)
                                <tr style="border-bottom:1px solid #f3f4f6;"
                                    onmouseover="this.style.background='#F7F8FC'"
                                    onmouseout="this.style.background='transparent'">
                                    <td style="padding:8px 12px; color:#9ca3af;">{{ $i + 1 }}</td>
                                    <td style="padding:8px 12px; color:#6b7280; white-space:nowrap;">{{ $row['date'] }}</td>
                                    <td style="padding:8px 12px; color:#374151; white-space:nowrap;">{{ trim(strstr($row['source_bank'], '-') ?: $row['source_bank'], '- ') }}</td>
                                    <td style="padding:8px 12px; font-weight:600; color:#111827; white-space:nowrap;">{{ $row['client_a'] }}</td>
                                    <td style="padding:8px 12px; color:#374151; white-space:nowrap;">{{ number_format($row['b2w_amount'], 0, ',', ' ') }} FDJ</td>
                                    <td style="padding:8px 12px; font-weight:600; color:#111827; white-space:nowrap;">{{ $row['client_b'] }}</td>
                                    <td style="padding:8px 12px; color:#374151; white-space:nowrap;">{{ number_format($row['send_amount'], 0, ',', ' ') }} FDJ</td>
                                    <td style="padding:8px 12px; color:#374151; white-space:nowrap;">{{ number_format($row['w2b_amount'], 0, ',', ' ') }} FDJ</td>
                                    <td style="padding:8px 12px; color:#374151; white-space:nowrap;">{{ trim(strstr($row['destination_bank'], '-') ?: $row['destination_bank'], '- ') }}</td>
                                    <td style="padding:8px 12px;">
                                        <span style="background:{{ $row['delay_b2w_send_min'] < 30 ? '#FDECEA' : '#E5F5ED' }};
                                                    color:{{ $row['delay_b2w_send_min'] < 30 ? '#7F1D1D' : '#005C2B' }};
                                                    font-size:10px; font-weight:600; padding:2px 8px; border-radius:12px;">
                                            {{ round($row['delay_b2w_send_min']) }} min
                                        </span>
                                    </td>
                                    <td style="padding:8px 12px;">
                                        <span style="background:{{ $row['delay_send_w2b_min'] < 30 ? '#FDECEA' : '#E5F5ED' }};
                                                    color:{{ $row['delay_send_w2b_min'] < 30 ? '#7F1D1D' : '#005C2B' }};
                                                    font-size:10px; font-weight:600; padding:2px 8px; border-radius:12px;">
                                            {{ round($row['delay_send_w2b_min']) }} min
                                        </span>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>

                @if(count($b2w_send_w2b) > 5)
                    <div style="padding:8px 16px; border-top:1px solid #e5e7eb; background:#FAFAFA;">
                        <p style="font-size:10px; color:#9ca3af; margin:0;">
                            {{ count($b2w_send_w2b) }} résultats — faites défiler pour voir tout
                        </p>
                    </div>
                @endif
            @endif
        </div>

        {{-- 5. CIRCULAIRES --}}
        <div style="background:#fff; border:1px solid #e5e7eb; border-radius:10px; overflow:hidden; margin-bottom:16px;">
            <div style="padding:12px 16px; border-bottom:1px solid #e5e7eb; display:flex; align-items:center; gap:8px;">
                <span style="width:8px; height:8px; border-radius:50%; background:#9333ea; display:inline-block;"></span>
                <p style="font-size:13px; font-weight:600; color:#111827; margin:0;">Scénarios circulaires</p>
                <span style="margin-left:auto; background:#F3E8FF; color:#6B21A8; font-size:10px; font-weight:600; padding:2px 8px; border-radius:12px;">{{ count($circulaires) }} cas</span>
            </div>
            @if(empty($circulaires))
                <p style="padding:20px; font-size:12px; color:#9ca3af;">Aucun scénario circulaire détecté.</p>
            @else
                <div style="overflow-x:auto;">
                    <table style="width:100%; border-collapse:collapse; font-size:11px;">
                        <thead><tr style="background:#F7F8FC;">
                            <th style="padding:8px 12px; text-align:left; color:#6b7280; font-weight:500; border-bottom:1px solid #e5e7eb;">Date</th>
                            <th style="padding:8px 12px; text-align:left; color:#6b7280; font-weight:500; border-bottom:1px solid #e5e7eb;">Client</th>
                            <th style="padding:8px 12px; text-align:left; color:#6b7280; font-weight:500; border-bottom:1px solid #e5e7eb;">Marchand</th>
                            <th style="padding:8px 12px; text-align:left; color:#6b7280; font-weight:500; border-bottom:1px solid #e5e7eb;">Montant</th>
                            <th style="padding:8px 12px; text-align:left; color:#6b7280; font-weight:500; border-bottom:1px solid #e5e7eb;">Délai</th>
                            <th style="padding:8px 12px; text-align:left; color:#6b7280; font-weight:500; border-bottom:1px solid #e5e7eb;">Flags</th>
                        </tr></thead>
                        <tbody>
                            @foreach($circulaires as $row)
                                <tr style="border-bottom:1px solid #f3f4f6;"
                                    onmouseover="this.style.background='#F7F8FC'"
                                    onmouseout="this.style.background='transparent'">
                                    <td style="padding:8px 12px; color:#6b7280;">{{ $row['date'] }}</td>
                                    <td style="padding:8px 12px; font-weight:600; color:#111827;">{{ $row['client'] }}</td>
                                    <td style="padding:8px 12px; color:#374151;">{{ $row['merchant'] }}</td>
                                    <td style="padding:8px 12px; color:#374151;">{{ number_format($row['amount'], 0, ',', ' ') }} FDJ</td>
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

        {{-- 6. CYCLING DE COMMISSION --}}
        <div style="background:#fff; border:1px solid #e5e7eb; border-radius:10px; overflow:hidden; margin-bottom:16px;">
            <div style="padding:12px 16px; border-bottom:1px solid #e5e7eb; display:flex; align-items:center; gap:8px;">
                <span style="width:8px; height:8px; border-radius:50%; background:#E24B4A; display:inline-block;"></span>
                <p style="font-size:13px; font-weight:600; color:#111827; margin:0;">Cycling de commission</p>
                <span style="margin-left:auto; background:#FDECEA; color:#7F1D1D; font-size:10px; font-weight:600; padding:2px 8px; border-radius:12px;">{{ count($cycling) }} agents</span>
            </div>

            @if(empty($cycling))
                <p style="padding:20px; font-size:12px; color:#9ca3af;">Aucun agent suspect détecté.</p>
            @else
                @php
                    $totalCycles     = array_sum(array_column($cycling, 'nb_cycles'));
                    $totalCommission = array_sum(array_column($cycling, 'commission_gagnee'));
                    $totalSurplus    = array_sum(array_column($cycling, 'surplus_commission'));
                    $totalVolume     = array_sum(array_column($cycling, 'total_cashin_fdj'));
                @endphp

                <div style="display:grid; grid-template-columns:repeat(4, minmax(0,1fr)); gap:0; border-bottom:1px solid #e5e7eb;">
                    @foreach([
                        ['label' => 'Agents alertés',    'val' => count($cycling)],
                        ['label' => 'Total cycles',       'val' => $totalCycles],
                        ['label' => 'Commission totale',  'val' => number_format($totalCommission, 0, ',', ' ') . ' FDJ'],
                        ['label' => 'Surplus frauduleux', 'val' => number_format($totalSurplus, 0, ',', ' ') . ' FDJ'],
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
                                <th style="padding:8px 12px; text-align:left; color:#6b7280; font-weight:500; border-bottom:1px solid #e5e7eb;">Montant/cycle</th>
                                <th style="padding:8px 12px; text-align:left; color:#6b7280; font-weight:500; border-bottom:1px solid #e5e7eb;">Total Cash In</th>
                                <th style="padding:8px 12px; text-align:left; color:#6b7280; font-weight:500; border-bottom:1px solid #e5e7eb;">Commission</th>
                                <th style="padding:8px 12px; text-align:left; color:#6b7280; font-weight:500; border-bottom:1px solid #e5e7eb;">Surplus</th>
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
                                        {{ number_format($row['montant_par_cycle'], 0, ',', ' ') }} FDJ
                                    </td>
                                    <td style="padding:8px 12px; color:#374151;">
                                        {{ number_format($row['total_cashin_fdj'], 0, ',', ' ') }} FDJ
                                    </td>
                                    <td style="padding:8px 12px; color:#374151;">
                                        {{ number_format($row['commission_gagnee'], 0, ',', ' ') }} FDJ
                                    </td>
                                    <td style="padding:8px 12px;">
                                        <span style="background:#FDECEA; color:#7F1D1D; font-size:10px; font-weight:700; padding:2px 8px; border-radius:12px;">
                                            {{ number_format($row['surplus_commission'], 0, ',', ' ') }} FDJ
                                        </span>
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