<?php

use App\Models\Customer;
use App\Models\Organization;
use App\Models\Transaction;
use App\Models\TransactionType;
use Livewire\Volt\Component;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

new class extends Component {

    public string $date       = '';
    public bool   $searched   = false;

    // User stats
    public array $userStats = [];

    // Org stats
    public array $orgStats = [];

    // Business table
    public array $business = [];

    public function mount()
    {
        $this->date = Carbon::yesterday()->format('Y-m-d');
    }

    public function afficher()
    {
        $this->validate([
            'date' => 'required|date',
        ]);

        $date      = $this->date;
        $moisDebut = Carbon::parse($date)->startOfMonth()->toDateString();
        $moisFin   = Carbon::parse($date)->toDateString();

        // ── USER STATISTICS ────────────────────────────────────────

        // Total users inscrits jusqu'à cette date
        $totalUser = Customer::whereDate('CREATE_TIME', '<=', $date)->count();

        // Daily new register
        $dailyNewUser = Customer::whereDate('CREATE_TIME', $date)->count();

        // Active TXN User Daily — MSISDN distincts ayant fait/reçu une txn ce jour
        $activeDailyRaw = DB::select("
            SELECT COUNT(DISTINCT msisdn) as nb FROM (
                SELECT DEBIT_MSISDN  as msisdn FROM transactions WHERE DATE(INITATE_DATE) = ?
                UNION
                SELECT CREDIT_MSISDN as msisdn FROM transactions WHERE DATE(INITATE_DATE) = ?
            ) t WHERE msisdn IS NOT NULL AND msisdn != ''
        ", [$date, $date]);
        $activeTxnDaily = $activeDailyRaw[0]->nb ?? 0;

        // Active TXN User Monthly — MSISDN distincts sur le mois
        $activeMonthlyRaw = DB::select("
            SELECT COUNT(DISTINCT msisdn) as nb FROM (
                SELECT DEBIT_MSISDN  as msisdn FROM transactions
                    WHERE DATE(INITATE_DATE) BETWEEN ? AND ?
                UNION
                SELECT CREDIT_MSISDN as msisdn FROM transactions
                    WHERE DATE(INITATE_DATE) BETWEEN ? AND ?
            ) t WHERE msisdn IS NOT NULL AND msisdn != ''
        ", [$moisDebut, $moisFin, $moisDebut, $moisFin]);
        $activeTxnMonthly = $activeMonthlyRaw[0]->nb ?? 0;

        $this->userStats = [
            'total_user'           => $totalUser,
            'daily_new_register'   => $dailyNewUser,
            'active_txn_daily'     => $activeTxnDaily,
            'active_txn_monthly'   => $activeTxnMonthly,
        ];

        // ── ORG STATISTICS ─────────────────────────────────────────

        $totalOrg    = Organization::whereDate('CREATE_TIME', '<=', $date)->count();
        $closedOrg   = Organization::whereDate('CREATE_TIME', '<=', $date)
                            ->where('STATUS', '6')->count();
        $frozenOrg   = Organization::whereDate('CREATE_TIME', '<=', $date)
                            ->where('STATUS', '5')->count();
        $dailyNewOrg = Organization::whereDate('CREATE_TIME', $date)->count();

        $this->orgStats = [
            'total_org'          => $totalOrg,
            'closed_org'         => $closedOrg,
            'frozen_org'         => $frozenOrg,
            'daily_new_register' => $dailyNewOrg,
        ];

        // ── BUSINESS TABLE ─────────────────────────────────────────

        $rows = DB::select("
            SELECT
                tt.TXN_TYPE_NAME                                        AS transaction_type,
                COUNT(*)                                                AS total,
                SUM(t.ACTUAL_AMOUNT)                                    AS amount,
                SUM(CASE WHEN t.TRANS_STATUS = 'Completed'   THEN 1 ELSE 0 END) AS completed,
                SUM(CASE WHEN t.TRANS_STATUS = 'Authorized'  THEN 1 ELSE 0 END) AS authorized,
                SUM(CASE WHEN t.TRANS_STATUS = 'Reversed'    THEN 1 ELSE 0 END) AS reversed,
                SUM(CASE WHEN t.TRANS_STATUS = 'Expired'     THEN 1 ELSE 0 END) AS expired,
                SUM(CASE WHEN t.TRANS_STATUS = 'Cancelled'   THEN 1 ELSE 0 END) AS cancelled,
                SUM(CASE WHEN t.TRANS_STATUS = 'Declined'    THEN 1 ELSE 0 END) AS declined,
                ROUND(
                    SUM(CASE WHEN t.TRANS_STATUS = 'Completed' THEN 1 ELSE 0 END)
                    / COUNT(*) * 100, 2
                )                                                       AS success_rate
            FROM transactions t
            LEFT JOIN transaction_types tt ON tt.TXN_INDEX = t.TXN_INDEX
            WHERE DATE(t.INITATE_DATE) = ?
            GROUP BY tt.TXN_TYPE_NAME
            ORDER BY total DESC
        ", [$date]);

        $this->business = array_map(
            fn($r) => array_change_key_case((array)$r, CASE_LOWER),
            $rows
        );

        $this->searched = true;
    }

    public function with(): array
    {
        return [];
    }
};
?>

<div style="padding:24px;">

    {{-- FILTRE DATE --}}
    <div style="background:#fff; border:1px solid #e5e7eb; border-radius:12px; padding:20px; margin-bottom:20px;">
        <p style="font-size:14px; font-weight:700; color:#111827; margin-bottom:14px;">
            D-Money Daily Report
        </p>
        <div style="display:flex; align-items:flex-end; gap:12px; flex-wrap:wrap;">
            <div>
                <label style="font-size:11px; color:#6b7280; display:block; margin-bottom:4px;">Date du rapport</label>
                <input type="date" wire:model="date"
                       style="border:1px solid #d1d5db; border-radius:7px; padding:8px 12px; font-size:13px; color:#111827; outline:none;">
                @error('date')
                    <p style="font-size:10px; color:#E24B4A; margin:3px 0 0;">{{ $message }}</p>
                @enderror
            </div>

            <button wire:click="afficher"
                    wire:loading.attr="disabled"
                    wire:target="afficher"
                    style="background:#1B2F6E; color:#fff; font-size:13px; font-weight:600; padding:9px 22px; border-radius:8px; border:none; cursor:pointer; display:flex; align-items:center; gap:7px;">
                <span wire:loading.remove wire:target="afficher" style="display:flex; align-items:center; gap:7px;">
                    <svg width="14" height="14" viewBox="0 0 16 16" fill="white">
                        <rect x="1" y="1" width="6" height="6" rx="1.5"/>
                        <rect x="9" y="1" width="6" height="6" rx="1.5"/>
                        <rect x="1" y="9" width="6" height="6" rx="1.5"/>
                        <rect x="9" y="9" width="6" height="6" rx="1.5"/>
                    </svg>
                    Générer le rapport
                </span>
                <span wire:loading.delay wire:target="afficher" style="display:flex; align-items:center; gap:7px;">
                    <svg width="14" height="14" viewBox="0 0 40 40" fill="none" style="animation:spin 0.8s linear infinite;">
                        <circle cx="20" cy="20" r="16" stroke="rgba(255,255,255,0.3)" stroke-width="4"/>
                        <path d="M20 4a16 16 0 0116 16" stroke="white" stroke-width="4" stroke-linecap="round"/>
                    </svg>
                    Génération...
                </span>
            </button>
        </div>
    </div>

    @if(!$searched)
        <div style="text-align:center; padding:60px 20px; background:#fff; border:1px solid #e5e7eb; border-radius:10px;">
            <div style="width:52px; height:52px; background:#E8ECF8; border-radius:14px; display:flex; align-items:center; justify-content:center; margin:0 auto 14px;">
                <svg width="26" height="26" viewBox="0 0 24 24" fill="none">
                    <rect x="3" y="4" width="18" height="18" rx="2" stroke="#1B2F6E" stroke-width="1.5"/>
                    <path d="M8 2v4M16 2v4M3 10h18" stroke="#1B2F6E" stroke-width="1.5" stroke-linecap="round"/>
                </svg>
            </div>
            <p style="font-size:14px; font-weight:600; color:#111827; margin-bottom:4px;">Sélectionnez une date</p>
            <p style="font-size:12px; color:#9ca3af;">Choisissez une date puis cliquez sur <strong>Générer le rapport</strong>.</p>
        </div>

    @else

        {{-- DATE DU RAPPORT --}}
        <div style="display:flex; align-items:center; gap:8px; margin-bottom:20px;">
            <span style="width:8px; height:8px; border-radius:50%; background:#1B2F6E; display:inline-block;"></span>
            <p style="font-size:13px; font-weight:600; color:#111827; margin:0;">
                Rapport du {{ \Carbon\Carbon::parse($date)->translatedFormat('l d F Y') }}
            </p>
        </div>

        {{-- ── USER STATISTICS ── --}}
        <div style="margin-bottom:20px;">
            <p style="font-size:12px; font-weight:700; color:#1B2F6E; text-transform:uppercase; letter-spacing:1px; margin-bottom:12px;">
                 User Statistics
            </p>
            <div style="display:grid; grid-template-columns:repeat(4, minmax(0,1fr)); gap:12px;">

                <div style="background:#fff; border:1px solid #e5e7eb; border-radius:10px; padding:16px; border-top:3px solid #1B2F6E;">
                    <p style="font-size:11px; color:#6b7280; margin:0 0 8px;">Total User</p>
                    <p style="font-size:26px; font-weight:700; color:#111827; margin:0; line-height:1;">
                        {{ number_format($userStats['total_user'], 0, ',', ' ') }}
                    </p>
                    <p style="font-size:10px; color:#9ca3af; margin:5px 0 0;">{{ $date }}</p>
                </div>

                <div style="background:#fff; border:1px solid #e5e7eb; border-radius:10px; padding:16px; border-top:3px solid #FFC72C;">
                    <p style="font-size:11px; color:#6b7280; margin:0 0 8px;">Daily New Register</p>
                    <p style="font-size:26px; font-weight:700; color:#111827; margin:0; line-height:1;">
                        {{ number_format($userStats['daily_new_register'], 0, ',', ' ') }}
                    </p>
                    <p style="font-size:10px; color:#9ca3af; margin:5px 0 0;">{{ $date }}</p>
                </div>

                <div style="background:#fff; border:1px solid #e5e7eb; border-radius:10px; padding:16px; border-top:3px solid #16a34a;">
                    <p style="font-size:11px; color:#6b7280; margin:0 0 8px;">Active TXN User Daily</p>
                    <p style="font-size:26px; font-weight:700; color:#111827; margin:0; line-height:1;">
                        {{ number_format($userStats['active_txn_daily'], 0, ',', ' ') }}
                    </p>
                    <p style="font-size:10px; color:#9ca3af; margin:5px 0 0;">{{ $date }}</p>
                </div>

                <div style="background:#fff; border:1px solid #e5e7eb; border-radius:10px; padding:16px; border-top:3px solid #378ADD;">
                    <p style="font-size:11px; color:#6b7280; margin:0 0 8px;">Active TXN User Monthly</p>
                    <p style="font-size:26px; font-weight:700; color:#111827; margin:0; line-height:1;">
                        {{ number_format($userStats['active_txn_monthly'], 0, ',', ' ') }}
                    </p>
                    <p style="font-size:10px; color:#9ca3af; margin:5px 0 0;">
                        {{ \Carbon\Carbon::parse($date)->format('m/Y') }}
                    </p>
                </div>

            </div>
        </div>

        {{-- ── ORG STATISTICS ── --}}
        <div style="margin-bottom:20px;">
            <p style="font-size:12px; font-weight:700; color:#1B2F6E; text-transform:uppercase; letter-spacing:1px; margin-bottom:12px;">
                Org Statistics
            </p>
            <div style="display:grid; grid-template-columns:repeat(4, minmax(0,1fr)); gap:12px;">

                <div style="background:#fff; border:1px solid #e5e7eb; border-radius:10px; padding:16px; border-top:3px solid #1B2F6E;">
                    <p style="font-size:11px; color:#6b7280; margin:0 0 8px;">Total Organization</p>
                    <p style="font-size:26px; font-weight:700; color:#111827; margin:0; line-height:1;">
                        {{ number_format($orgStats['total_org'], 0, ',', ' ') }}
                    </p>
                    <p style="font-size:10px; color:#9ca3af; margin:5px 0 0;">{{ $date }}</p>
                </div>

                <div style="background:#fff; border:1px solid #e5e7eb; border-radius:10px; padding:16px; border-top:3px solid #E24B4A;">
                    <p style="font-size:11px; color:#6b7280; margin:0 0 8px;">Closed Organization</p>
                    <p style="font-size:26px; font-weight:700; color:#E24B4A; margin:0; line-height:1;">
                        {{ number_format($orgStats['closed_org'], 0, ',', ' ') }}
                    </p>
                    <p style="font-size:10px; color:#9ca3af; margin:5px 0 0;">{{ $date }}</p>
                </div>

                <div style="background:#fff; border:1px solid #e5e7eb; border-radius:10px; padding:16px; border-top:3px solid #FFC72C;">
                    <p style="font-size:11px; color:#6b7280; margin:0 0 8px;">Frozen Organization</p>
                    <p style="font-size:26px; font-weight:700; color:#7A4F00; margin:0; line-height:1;">
                        {{ number_format($orgStats['frozen_org'], 0, ',', ' ') }}
                    </p>
                    <p style="font-size:10px; color:#9ca3af; margin:5px 0 0;">{{ $date }}</p>
                </div>

                <div style="background:#fff; border:1px solid #e5e7eb; border-radius:10px; padding:16px; border-top:3px solid #16a34a;">
                    <p style="font-size:11px; color:#6b7280; margin:0 0 8px;">Daily New Register</p>
                    <p style="font-size:26px; font-weight:700; color:#111827; margin:0; line-height:1;">
                        {{ number_format($orgStats['daily_new_register'], 0, ',', ' ') }}
                    </p>
                    <p style="font-size:10px; color:#9ca3af; margin:5px 0 0;">{{ $date }}</p>
                </div>

            </div>
        </div>

        {{-- ── BUSINESS TABLE ── --}}
        <div style="background:#fff; border:1px solid #e5e7eb; border-radius:10px; overflow:hidden;">
            <div style="padding:12px 16px; border-bottom:1px solid #e5e7eb; display:flex; align-items:center; justify-content:space-between;">
                <p style="font-size:12px; font-weight:700; color:#1B2F6E; text-transform:uppercase; letter-spacing:1px; margin:0;">
                    Transaction Statistics — Business
                </p>
                <span style="background:#E8ECF8; color:#1B2F6E; font-size:11px; font-weight:600; padding:3px 10px; border-radius:20px;">
                    {{ count($business) }} types
                </span>
            </div>

            @if(empty($business))
                <p style="padding:24px; font-size:12px; color:#9ca3af; text-align:center;">
                    Aucune transaction pour cette date.
                </p>
            @else
                <div style="overflow-x:auto; overflow-y:auto; max-height:560px;">
                    <table style="width:100%; border-collapse:collapse; font-size:11px;">
                        <thead>
                            <tr style="background:#F7F8FC;">
                                <th style="padding:9px 12px; text-align:left; color:#6b7280; font-weight:600; border-bottom:1px solid #e5e7eb; white-space:nowrap; position:sticky; top:0; background:#F7F8FC; z-index:1;">TRANSACTION TYPE</th>
                                <th style="padding:9px 12px; text-align:right; color:#6b7280; font-weight:600; border-bottom:1px solid #e5e7eb; white-space:nowrap; position:sticky; top:0; background:#F7F8FC; z-index:1;">TOTAL</th>
                                <th style="padding:9px 12px; text-align:right; color:#6b7280; font-weight:600; border-bottom:1px solid #e5e7eb; white-space:nowrap; position:sticky; top:0; background:#F7F8FC; z-index:1;">AMOUNT (DJF)</th>
                                <th style="padding:9px 12px; text-align:right; color:#6b7280; font-weight:600; border-bottom:1px solid #e5e7eb; white-space:nowrap; position:sticky; top:0; background:#F7F8FC; z-index:1;">COMPLETED</th>
                                <th style="padding:9px 12px; text-align:right; color:#6b7280; font-weight:600; border-bottom:1px solid #e5e7eb; white-space:nowrap; position:sticky; top:0; background:#F7F8FC; z-index:1;">AUTHORIZED</th>
                                <th style="padding:9px 12px; text-align:right; color:#6b7280; font-weight:600; border-bottom:1px solid #e5e7eb; white-space:nowrap; position:sticky; top:0; background:#F7F8FC; z-index:1;">REVERSED</th>
                                <th style="padding:9px 12px; text-align:right; color:#6b7280; font-weight:600; border-bottom:1px solid #e5e7eb; white-space:nowrap; position:sticky; top:0; background:#F7F8FC; z-index:1;">EXPIRED</th>
                                <th style="padding:9px 12px; text-align:right; color:#6b7280; font-weight:600; border-bottom:1px solid #e5e7eb; white-space:nowrap; position:sticky; top:0; background:#F7F8FC; z-index:1;">CANCELLED</th>
                                <th style="padding:9px 12px; text-align:right; color:#6b7280; font-weight:600; border-bottom:1px solid #e5e7eb; white-space:nowrap; position:sticky; top:0; background:#F7F8FC; z-index:1;">DECLINED</th>
                                <th style="padding:9px 12px; text-align:right; color:#6b7280; font-weight:600; border-bottom:1px solid #e5e7eb; white-space:nowrap; position:sticky; top:0; background:#F7F8FC; z-index:1;">SUCCESS RATE</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($business as $row)
                                <tr style="border-bottom:1px solid #f3f4f6;"
                                    onmouseover="this.style.background='#F7F8FC'"
                                    onmouseout="this.style.background='transparent'">

                                    <td style="padding:9px 12px; font-weight:600; color:#111827; white-space:nowrap;">
                                        {{ $row['transaction_type'] ?? '—' }}
                                    </td>
                                    <td style="padding:9px 12px; text-align:right; color:#374151;">
                                        {{ number_format($row['total'], 0, ',', ' ') }}
                                    </td>
                                    <td style="padding:9px 12px; text-align:right; font-weight:600; color:#111827;">
                                        {{ number_format($row['amount'], 0, ',', ' ') }}
                                    </td>
                                    <td style="padding:9px 12px; text-align:right; color:#16a34a; font-weight:500;">
                                        {{ number_format($row['completed'], 0, ',', ' ') }}
                                    </td>
                                    <td style="padding:9px 12px; text-align:right; color:#378ADD;">
                                        {{ number_format($row['authorized'], 0, ',', ' ') }}
                                    </td>
                                    <td style="padding:9px 12px; text-align:right; color:#6b7280;">
                                        {{ number_format($row['reversed'] ?? 0, 0, ',', ' ') }}
                                    </td>
                                    <td style="padding:9px 12px; text-align:right; color:#6b7280;">
                                        {{ number_format($row['expired'] ?? 0, 0, ',', ' ') }}
                                    </td>
                                    <td style="padding:9px 12px; text-align:right; color:#7A4F00;">
                                        {{ number_format($row['cancelled'], 0, ',', ' ') }}
                                    </td>
                                    <td style="padding:9px 12px; text-align:right; color:#E24B4A;">
                                        {{ number_format($row['declined'], 0, ',', ' ') }}
                                    </td>
                                    <td style="padding:9px 12px; text-align:right;">
                                        @php $rate = $row['success_rate'] ?? 0; @endphp
                                        <span style="
                                            background:{{ $rate >= 90 ? '#E5F5ED' : ($rate >= 70 ? '#FFF3D0' : '#FDECEA') }};
                                            color:{{ $rate >= 90 ? '#005C2B' : ($rate >= 70 ? '#7A4F00' : '#7F1D1D') }};
                                            font-size:10px; font-weight:700; padding:2px 8px; border-radius:12px;">
                                            {{ number_format($rate, 2) }}%
                                        </span>
                                    </td>
                                </tr>
                            @endforeach

                            {{-- Ligne totaux --}}
                            @php
                                $totTotal     = array_sum(array_column($business, 'total'));
                                $totAmount    = array_sum(array_column($business, 'amount'));
                                $totCompleted = array_sum(array_column($business, 'completed'));
                                $totAuth      = array_sum(array_column($business, 'authorized'));
                                $totReversed  = array_sum(array_column($business, 'reversed'));
                                $totExpired   = array_sum(array_column($business, 'expired'));
                                $totCancelled = array_sum(array_column($business, 'cancelled'));
                                $totDeclined  = array_sum(array_column($business, 'declined'));
                                $totRate      = $totTotal > 0 ? round(($totCompleted / $totTotal) * 100, 2) : 0;
                            @endphp
                            <tr style="background:#F7F8FC; border-top:2px solid #e5e7eb;">
                                <td style="padding:9px 12px; font-weight:700; color:#111827;">TOTAL</td>
                                <td style="padding:9px 12px; text-align:right; font-weight:700; color:#111827;">
                                    {{ number_format($totTotal, 0, ',', ' ') }}
                                </td>
                                <td style="padding:9px 12px; text-align:right; font-weight:700; color:#111827;">
                                    {{ number_format($totAmount, 0, ',', ' ') }}
                                </td>
                                <td style="padding:9px 12px; text-align:right; font-weight:700; color:#16a34a;">
                                    {{ number_format($totCompleted, 0, ',', ' ') }}
                                </td>
                                <td style="padding:9px 12px; text-align:right; font-weight:700; color:#378ADD;">
                                    {{ number_format($totAuth, 0, ',', ' ') }}
                                </td>
                                <td style="padding:9px 12px; text-align:right; font-weight:700; color:#6b7280;">
                                    {{ number_format($totReversed, 0, ',', ' ') }}
                                </td>
                                <td style="padding:9px 12px; text-align:right; font-weight:700; color:#6b7280;">
                                    {{ number_format($totExpired, 0, ',', ' ') }}
                                </td>
                                <td style="padding:9px 12px; text-align:right; font-weight:700; color:#7A4F00;">
                                    {{ number_format($totCancelled, 0, ',', ' ') }}
                                </td>
                                <td style="padding:9px 12px; text-align:right; font-weight:700; color:#E24B4A;">
                                    {{ number_format($totDeclined, 0, ',', ' ') }}
                                </td>
                                <td style="padding:9px 12px; text-align:right;">
                                    <span style="
                                        background:{{ $totRate >= 90 ? '#E5F5ED' : ($totRate >= 70 ? '#FFF3D0' : '#FDECEA') }};
                                        color:{{ $totRate >= 90 ? '#005C2B' : ($totRate >= 70 ? '#7A4F00' : '#7F1D1D') }};
                                        font-size:10px; font-weight:700; padding:2px 8px; border-radius:12px;">
                                        {{ number_format($totRate, 2) }}%
                                    </span>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            @endif
        </div>

    @endif

    <style>
        @keyframes spin { from { transform:rotate(0deg); } to { transform:rotate(360deg); } }
    </style>
</div>