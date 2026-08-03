<?php
// resources/views/livewire/reporting/transactions-dashboard.blade.php

use App\Services\TransactionKpiService;
use Livewire\Volt\Component;

new class extends Component {

    public string $mois = '';
    public array $synthese = [];
    public array $repartition = [];
    public array $comparaison = [];

    public function mount()
    {
        // Mois par défaut = mois précédent (le rapport est mensuel, M-1)
        $this->mois = now()->subMonth()->format('Y-m');
        $this->charger();
    }

    public function updatedMois()
    {
        $this->charger();
    }

    public function charger()
    {
        $svc = app(TransactionKpiService::class);
        $this->synthese    = $svc->synthese($this->mois);
        $this->repartition = $svc->repartitionParUseCase($this->mois);
        $this->comparaison = $svc->comparaisonM1($this->mois);
    }

    public function with(): array
    {
        // valeur totale pour calculer les pourcentages de répartition
        $totalVol = array_sum(array_column($this->repartition, 'volume'));
        $totalVal = array_sum(array_column($this->repartition, 'valeur'));
        return [
            'totalVol' => $totalVol,
            'totalVal' => $totalVal,
        ];
    }
};
?>

<div style="padding:24px;">

    {{-- EN-TÊTE + SÉLECTEUR DE MOIS --}}
    <div style="display:flex; align-items:center; justify-content:space-between; margin-bottom:24px;">
        <div>
            <h2 style="font-size:16px; font-weight:700; color:#111827; margin:0 0 4px;">
                Rapport Transactions D-Money
            </h2>
            <p style="font-size:12px; color:#9ca3af; margin:0;">
                Volume et valeur des transactions par use-case
            </p>
        </div>
        <div>
            <label style="font-size:11px; color:#6b7280; display:block; margin-bottom:4px;">Mois</label>
            <input type="month" wire:model.live="mois"
                   style="border:1px solid #d1d5db; border-radius:8px; padding:8px 12px; font-size:13px; color:#111827; outline:none;">
        </div>
    </div>

    {{-- CARTES DE SYNTHÈSE --}}
    <div style="display:grid; grid-template-columns:repeat(4, minmax(0,1fr)); gap:12px; margin-bottom:20px;">
        <div style="background:#fff; border:1px solid #e5e7eb; border-radius:10px; padding:16px; border-top:3px solid #1B2F6E;">
            <p style="font-size:11px; color:#6b7280; margin:0 0 6px;">Volume total</p>
            <p style="font-size:22px; font-weight:700; color:#111827; margin:0;">
                {{ number_format($synthese['volume_total'] ?? 0, 0, ',', ' ') }}
            </p>
            <p style="font-size:10px; color:#9ca3af; margin:3px 0 0;">transactions</p>
        </div>
        <div style="background:#fff; border:1px solid #e5e7eb; border-radius:10px; padding:16px; border-top:3px solid #16a34a;">
            <p style="font-size:11px; color:#6b7280; margin:0 0 6px;">Valeur totale</p>
            <p style="font-size:22px; font-weight:700; color:#16a34a; margin:0;">
                {{ number_format($synthese['valeur_total'] ?? 0, 0, ',', ' ') }}
            </p>
            <p style="font-size:10px; color:#9ca3af; margin:3px 0 0;">FDJ</p>
        </div>
        <div style="background:#fff; border:1px solid #e5e7eb; border-radius:10px; padding:16px; border-top:3px solid #F5A800;">
            <p style="font-size:11px; color:#6b7280; margin:0 0 6px;">Frais hors Airtime</p>
            <p style="font-size:22px; font-weight:700; color:#B47B00; margin:0;">
                {{ number_format($synthese['frais_hors_airtime'] ?? 0, 0, ',', ' ') }}
            </p>
            <p style="font-size:10px; color:#9ca3af; margin:3px 0 0;">FDJ</p>
        </div>
        <div style="background:#fff; border:1px solid #e5e7eb; border-radius:10px; padding:16px; border-top:3px solid #E24B4A;">
            <p style="font-size:11px; color:#6b7280; margin:0 0 6px;">Frais Airtime</p>
            <p style="font-size:22px; font-weight:700; color:#B93B39; margin:0;">
                {{ number_format($synthese['frais_airtime'] ?? 0, 0, ',', ' ') }}
            </p>
            <p style="font-size:10px; color:#9ca3af; margin:3px 0 0;">FDJ</p>
        </div>
        <div style="background:#fff; border:1px solid #e5e7eb; border-radius:10px; padding:16px; border-top:3px solid #E24B4A;">
            <p style="font-size:11px; color:#6b7280; margin:0 0 6px;">Commission Cash In</p>
            <p style="font-size:22px; font-weight:700; color:#B93B39; margin:0;">
                {{ number_format($synthese['commission_cashin'] ?? 0, 0, ',', ' ') }}
            </p>
            <p style="font-size:10px; color:#9ca3af; margin:3px 0 0;">FDJ</p>
        </div>
        <div style="background:#fff; border:1px solid #e5e7eb; border-radius:10px; padding:16px; border-top:3px solid #E24B4A;">
            <p style="font-size:11px; color:#6b7280; margin:0 0 6px;">Revenue Total </p>
            <p style="font-size:22px; font-weight:700; color:#B93B39; margin:0;">
                {{ number_format($synthese['revenue'] ?? 0, 0, ',', ' ') }}
            </p>
            <p style="font-size:10px; color:#9ca3af; margin:3px 0 0;">FDJ</p>
        </div>
    </div>

    {{-- TABLEAU RÉPARTITION PAR USE-CASE --}}
    <div style="background:#fff; border:1px solid #e5e7eb; border-radius:10px; overflow:hidden;">
        <div style="padding:12px 16px; border-bottom:1px solid #e5e7eb;">
            <p style="font-size:13px; font-weight:600; color:#111827; margin:0;">
                Répartition par use-case — {{ $mois }}
            </p>
        </div>
        <div style="overflow-x:auto;">
            <table style="width:100%; border-collapse:collapse; font-size:12px;">
                <thead>
                    <tr style="background:#F9FAF9;">
                        <th style="padding:10px 14px; text-align:left; color:#6b7280; font-weight:500; border-bottom:1px solid #e5e7eb;">Use-case</th>
                        <th style="padding:10px 14px; text-align:right; color:#6b7280; font-weight:500; border-bottom:1px solid #e5e7eb;">Volume</th>
                        <th style="padding:10px 14px; text-align:right; color:#6b7280; font-weight:500; border-bottom:1px solid #e5e7eb;">% Vol.</th>
                        <th style="padding:10px 14px; text-align:right; color:#6b7280; font-weight:500; border-bottom:1px solid #e5e7eb;">Valeur (FDJ)</th>
                        <th style="padding:10px 14px; text-align:right; color:#6b7280; font-weight:500; border-bottom:1px solid #e5e7eb;">% Val.</th>
                        <th style="padding:10px 14px; text-align:right; color:#6b7280; font-weight:500; border-bottom:1px solid #e5e7eb;">Var. Vol. M-1</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($repartition as $r)
                        @php $c = $comparaison[$r['use_case']] ?? null; @endphp
                        <tr style="border-bottom:1px solid #f3f4f6;">
                            <td style="padding:10px 14px; color:#111827; font-weight:500;">{{ $r['use_case'] }}</td>
                            <td style="padding:10px 14px; text-align:right; color:#374151;">{{ number_format($r['volume'], 0, ',', ' ') }}</td>
                            <td style="padding:10px 14px; text-align:right; color:#6b7280;">
                                {{ $totalVol ? number_format($r['volume'] / $totalVol * 100, 1) : '0' }}%
                            </td>
                            <td style="padding:10px 14px; text-align:right; color:#374151;">{{ number_format($r['valeur'], 0, ',', ' ') }}</td>
                            <td style="padding:10px 14px; text-align:right; color:#6b7280;">
                                {{ $totalVal ? number_format($r['valeur'] / $totalVal * 100, 1) : '0' }}%
                            </td>
                            <td style="padding:10px 14px; text-align:right;">
                                @if($c && $c['var_volume'] !== null)
                                    @php $v = $c['var_volume']; @endphp
                                    <span style="color:{{ $v >= 0 ? '#16a34a' : '#E24B4A' }}; font-weight:600;">
                                        {{ $v >= 0 ? '+' : '' }}{{ $v }}%
                                    </span>
                                @else
                                    <span style="color:#9ca3af;">—</span>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6" style="padding:30px; text-align:center; color:#9ca3af;">
                                Aucune donnée pour {{ $mois }}.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
                @if(!empty($repartition))
                    <tfoot>
                        <tr style="background:#F9FAF9; font-weight:700;">
                            <td style="padding:10px 14px; color:#111827;">Total</td>
                            <td style="padding:10px 14px; text-align:right; color:#111827;">{{ number_format($totalVol, 0, ',', ' ') }}</td>
                            <td style="padding:10px 14px; text-align:right; color:#6b7280;">100%</td>
                            <td style="padding:10px 14px; text-align:right; color:#111827;">{{ number_format($totalVal, 0, ',', ' ') }}</td>
                            <td style="padding:10px 14px; text-align:right; color:#6b7280;">100%</td>
                            <td></td>
                        </tr>
                    </tfoot>
                @endif
            </table>
        </div>
    </div>

    {{-- BOUTON EXPORT PPTX --}}
    <div style="margin-top:16px; display:flex; justify-content:flex-end;">
        <a href="{{ route('reporting.transactions.pptx', ['mois' => $mois]) }}"
           style="background:#1B2F6E; color:#fff; font-size:13px; font-weight:600; padding:9px 22px; border-radius:8px; text-decoration:none; display:inline-flex; align-items:center; gap:8px;">
            <svg width="14" height="14" viewBox="0 0 16 16" fill="white">
                <path d="M2 3h9l3 3v8a1 1 0 01-1 1H2a1 1 0 01-1-1V4a1 1 0 011-1z"/>
                <path d="M9 3v4h4" stroke="#1B2F6E" stroke-width="1" fill="none"/>
            </svg>
            Exporter en PowerPoint
        </a>
    </div>
</div>