<?php

use App\Models\Employee;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Livewire\Volt\Component;

/**
 * Classement des employés D-Money par nombre de transactions terminées, selon
 * le sens choisi : débit, crédit ou les deux (numéro débiteur et/ou créditeur).
 * Réservé au super-admin.
 */
new class extends Component {

    public string $date_debut   = '';
    public string $date_fin     = '';
    public bool   $showInactive = false;
    public string $direction    = 'both';   // debit | credit | both

    public const DIRECTIONS = [
        'both'   => 'Les deux (débit + crédit)',
        'debit'  => 'Débit (envoyées / payées)',
        'credit' => 'Crédit (reçues)',
    ];

    public array $chart = ['labels' => [], 'values' => []];

    public bool  $showForm  = false;
    public ?int  $editingId = null;
    public array $form      = ['full_name' => '', 'msisdn' => '', 'is_active' => true];

    /** Le middleware de route ne couvre pas les appels Livewire suivants */
    public function boot(): void
    {
        abort_unless(auth()->user()?->hasRole('super-admin'), 403);
    }

    public function mount(): void
    {
        $this->date_debut = Carbon::now()->startOfMonth()->format('Y-m-d');
        $this->date_fin   = Carbon::now()->format('Y-m-d');
    }

    public function create(): void
    {
        $this->editingId = null;
        $this->form      = ['full_name' => '', 'msisdn' => '', 'is_active' => true];
        $this->showForm  = true;
        $this->resetErrorBag();
    }

    public function edit(int $id): void
    {
        $employee        = Employee::findOrFail($id);
        $this->editingId = $employee->id;
        $this->form      = ['full_name' => $employee->full_name, 'msisdn' => $employee->displayMsisdn(), 'is_active' => $employee->is_active];
        $this->showForm  = true;
        $this->resetErrorBag();
    }

    public function save(): void
    {
        $msisdn = Employee::normalizeMsisdn($this->form['msisdn']);

        $this->validate(['form.full_name' => 'required|string|max:255'], ['form.full_name.required' => 'Le nom est obligatoire.']);

        if (!$msisdn) {
            $this->addError('form.msisdn', 'Numéro invalide : 8 chiffres commençant par 77 (ex. 77 08 46 51).');
            return;
        }

        $taken = Employee::where('msisdn', $msisdn)
            ->when($this->editingId, fn ($q) => $q->where('id', '<>', $this->editingId))
            ->first();

        if ($taken) {
            $this->addError('form.msisdn', "Ce numéro est déjà attribué à {$taken->full_name}.");
            return;
        }

        $data = [
            'full_name' => mb_strtoupper(trim(preg_replace('/\s+/', ' ', $this->form['full_name']))),
            'msisdn'    => $msisdn,
            'is_active' => (bool) $this->form['is_active'],
        ];

        if ($this->editingId) {
            Employee::findOrFail($this->editingId)->update($data);
            session()->flash('success', "Employé {$data['full_name']} mis à jour.");
        } else {
            Employee::create($data);
            session()->flash('success', "Employé {$data['full_name']} ajouté.");
        }

        $this->showForm = false;
    }

    /** Nombre de transactions et dernière transaction par numéro, sur la période. */
    private function stats(array $msisdns): array
    {
        if (empty($msisdns) || !$this->date_debut || !$this->date_fin) {
            return [];
        }

        $debut = $this->date_debut . ' 00:00:00';
        $fin   = Carbon::parse($this->date_fin)->addDay()->format('Y-m-d') . ' 00:00:00';
        $in    = implode(',', array_fill(0, count($msisdns), '?'));

        // Une sous-requête par sens retenu (débit et/ou crédit)
        $columns = match ($this->direction) {
            'debit'  => ['debit_party_identifier'],
            'credit' => ['credit_party_identifier'],
            default  => ['debit_party_identifier', 'credit_party_identifier'],
        };
        $parts    = [];
        $bindings = [];
        foreach ($columns as $column) {
            $parts[]  = "SELECT {$column} AS msisdn, transaction_initiated_time AS t FROM fact_txn_v2
                          WHERE transaction_initiated_time >= ? AND transaction_initiated_time < ?
                            AND status = 'Completed' AND {$column} IN ($in)";
            $bindings = array_merge($bindings, [$debut, $fin], $msisdns);
        }

        $rows = DB::select("
            SELECT msisdn, COUNT(*) AS nb, MAX(t) AS derniere
              FROM (" . implode(' UNION ALL ', $parts) . ") x
             GROUP BY msisdn
        ", $bindings);

        return collect($rows)->keyBy('msisdn')->all();
    }

    public function with(): array
    {
        $employees = Employee::when(!$this->showInactive, fn ($q) => $q->where('is_active', true))->get();
        $stats     = $this->stats($employees->pluck('msisdn')->all());

        $ranking = $employees
            ->map(fn ($e) => [
                'employee' => $e,
                'nb'       => (int) ($stats[$e->msisdn]->nb ?? 0),
                'derniere' => isset($stats[$e->msisdn]) ? Carbon::parse($stats[$e->msisdn]->derniere) : null,
            ])
            ->sort(fn ($a, $b) => [$b['nb'], $a['employee']->full_name] <=> [$a['nb'], $b['employee']->full_name])
            ->values();

        // Rang : ex-aequo au même rang (1, 2, 2, 4…)
        $rank = 0;
        $prev = null;
        $ranking = $ranking->map(function ($r, $i) use (&$rank, &$prev) {
            if ($r['nb'] !== $prev) {
                $rank = $i + 1;
                $prev = $r['nb'];
            }
            return $r + ['rank' => $rank];
        });

        $top = $ranking->take(15);
        $this->chart = [
            'labels' => $top->map(fn ($r) => $r['employee']->full_name)->all(),
            'values' => $top->pluck('nb')->all(),
        ];
        $this->dispatch('employees-chart');

        return [
            'ranking'  => $ranking,
            'total'    => $ranking->sum('nb'),
            'withTxn'  => $ranking->where('nb', '>', 0)->count(),
            'inactive' => Employee::where('is_active', false)->count(),
        ];
    }
};
?>
<div style="padding:24px;">

@php
    $inputStyle = 'border:1px solid #d1d5db; border-radius:7px; padding:9px 12px; font-size:13px; color:#111827; outline:none; background:#fff; width:100%; box-sizing:border-box;';
    $labelStyle = 'font-size:11px; color:#6b7280; display:block; margin-bottom:4px; font-weight:600;';
    $medals     = [1 => ['#FFC72C', '#1B2F6E'], 2 => ['#D1D5DB', '#374151'], 3 => ['#E8B48A', '#7C2D12']];
@endphp

    @if(session('success'))
        <div style="background:#E5F5ED; color:#005C2B; border:1px solid #a7d7b8; border-radius:8px; padding:12px 16px; margin-bottom:16px; font-size:13px;">{{ session('success') }}</div>
    @endif

    <div style="display:flex; align-items:center; justify-content:space-between; margin-bottom:20px; gap:12px; flex-wrap:wrap;">
        <div>
            <h2 style="font-size:16px; font-weight:700; color:#111827; margin:0 0 4px;">Classement des employés</h2>
            <p style="font-size:12px; color:#9ca3af; margin:0;">Nombre de transactions terminées du numéro D-Money de chaque employé, en débit, en crédit ou les deux.</p>
        </div>
        <button wire:click="create"
                style="background:#1B2F6E; color:#fff; font-size:13px; font-weight:600; padding:9px 18px; border-radius:8px; border:none; cursor:pointer;">
            + Nouvel employé
        </button>
    </div>

    {{-- FILTRES --}}
    <div style="background:#fff; border:1px solid #e5e7eb; border-radius:10px; padding:16px; margin-bottom:16px; display:flex; gap:12px; flex-wrap:wrap; align-items:flex-end;">
        <div>
            <label style="{{ $labelStyle }}">Date début</label>
            <input type="date" wire:model.live="date_debut" style="{{ $inputStyle }} width:auto;">
        </div>
        <div>
            <label style="{{ $labelStyle }}">Date fin</label>
            <input type="date" wire:model.live="date_fin" style="{{ $inputStyle }} width:auto;">
        </div>
        <div>
            <label style="{{ $labelStyle }}">Sens des transactions</label>
            <select wire:model.live="direction" style="{{ $inputStyle }} width:auto;">
                @foreach($this::DIRECTIONS as $key => $label)
                    <option value="{{ $key }}">{{ $label }}</option>
                @endforeach
            </select>
        </div>
        <label style="display:flex; align-items:center; gap:8px; font-size:12px; color:#374151; padding-bottom:9px;">
            <input type="checkbox" wire:model.live="showInactive"> Inclure les employés désactivés ({{ $inactive }})
        </label>
        <span wire:loading style="font-size:12px; color:#9ca3af; padding-bottom:9px;">Calcul…</span>
    </div>

    {{-- KPIs --}}
    <div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(160px, 1fr)); gap:12px; margin-bottom:16px;">
        @foreach([
            ['Employés suivis', number_format($ranking->count(), 0, ',', ' '), '#1B2F6E'],
            ['Transactions', number_format($total, 0, ',', ' '), '#00843D'],
            ['Employés actifs sur la période', $withTxn . ' / ' . $ranking->count(), '#F5A800'],
        ] as [$label, $value, $color])
            <div style="background:#fff; border:1px solid #e5e7eb; border-radius:10px; padding:16px; border-top:3px solid {{ $color }};">
                <p style="font-size:11px; color:#6b7280; margin:0 0 6px;">{{ $label }}</p>
                <p style="font-size:22px; font-weight:700; color:#111827; margin:0;">{{ $value }}</p>
            </div>
        @endforeach
    </div>

    {{-- GRAPHIQUE --}}
    <div style="background:#fff; border:1px solid #e5e7eb; border-radius:10px; padding:16px; margin-bottom:16px;">
        <p style="font-size:13px; font-weight:600; color:#111827; margin:0 0 12px;">Top 15 — nombre de transactions</p>
        <div wire:ignore style="position:relative; height:{{ max(120, min(15, $ranking->count()) * 26) }}px;">
            <canvas id="employees-chart"></canvas>
        </div>
    </div>

    {{-- CLASSEMENT --}}
    <div style="background:#fff; border:1px solid #e5e7eb; border-radius:10px; overflow:hidden;">
        <div style="overflow-x:auto;">
            <table style="width:100%; border-collapse:collapse; font-size:12px;">
                <thead>
                    <tr style="background:#F7F8FC;">
                        @foreach(['Rang', 'Employé', 'Numéro', 'Transactions', 'Dernière transaction', ''] as $th)
                            <th style="padding:10px 16px; text-align:{{ $th === 'Transactions' ? 'right' : 'left' }}; color:#6b7280; font-weight:500; border-bottom:1px solid #e5e7eb; white-space:nowrap;">{{ $th }}</th>
                        @endforeach
                    </tr>
                </thead>
                <tbody>
                    @forelse($ranking as $r)
                        @php [$bg, $fg] = $medals[$r['rank']] ?? ['#F3F4F6', '#6b7280']; @endphp
                        <tr wire:key="emp-{{ $r['employee']->id }}" style="border-bottom:1px solid #f3f4f6; {{ $r['employee']->is_active ? '' : 'opacity:.55;' }}">
                            <td style="padding:10px 16px;">
                                <span style="display:inline-flex; align-items:center; justify-content:center; min-width:26px; height:26px; padding:0 6px; border-radius:13px; background:{{ $bg }}; color:{{ $fg }}; font-weight:700; font-size:11px;">{{ $r['rank'] }}</span>
                            </td>
                            <td style="padding:10px 16px; color:#111827; font-weight:500;">
                                {{ $r['employee']->full_name }}
                                @unless($r['employee']->is_active) <span style="font-size:10px; color:#9ca3af;">(désactivé)</span> @endunless
                            </td>
                            <td style="padding:10px 16px; color:#6b7280; font-family:monospace; white-space:nowrap;">{{ $r['employee']->displayMsisdn() }}</td>
                            <td style="padding:10px 16px; text-align:right; color:#111827; font-weight:700;">{{ number_format($r['nb'], 0, ',', ' ') }}</td>
                            <td style="padding:10px 16px; color:#6b7280; white-space:nowrap;">{{ $r['derniere']?->format('d/m/Y H:i') ?? '—' }}</td>
                            <td style="padding:10px 16px; text-align:right;">
                                <button wire:click="edit({{ $r['employee']->id }})"
                                        style="background:#E8ECF8; color:#1B2F6E; font-size:11px; font-weight:600; padding:5px 12px; border-radius:6px; border:none; cursor:pointer;">
                                    Modifier
                                </button>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="6" style="padding:30px; text-align:center; color:#9ca3af;">Aucun employé.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    {{-- FORMULAIRE --}}
    @if($showForm)
        <div wire:click="$set('showForm', false)" style="position:fixed; inset:0; background:rgba(0,0,0,0.45); z-index:998;"></div>
        <div style="position:fixed; top:50%; left:50%; transform:translate(-50%,-50%); width:min(460px,95vw); background:#fff; border-radius:14px; z-index:999; box-shadow:0 20px 60px rgba(0,0,0,0.2);">
            <div style="padding:16px 20px; border-bottom:1px solid #e5e7eb;">
                <p style="font-size:14px; font-weight:700; color:#111827; margin:0;">{{ $editingId ? 'Modifier l\'employé' : 'Nouvel employé' }}</p>
            </div>
            <div style="padding:20px; display:flex; flex-direction:column; gap:14px;">
                <div>
                    <label style="{{ $labelStyle }}">Nom complet <span style="color:#E24B4A;">*</span></label>
                    <input type="text" wire:model="form.full_name" placeholder="Ex. SALEM AHMED MOHAMED" style="{{ $inputStyle }}">
                    @error('form.full_name') <p style="font-size:10px; color:#E24B4A; margin:4px 0 0;">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label style="{{ $labelStyle }}">Numéro D-Money <span style="color:#E24B4A;">*</span></label>
                    <input type="text" wire:model="form.msisdn" placeholder="Ex. 77 08 46 51" style="{{ $inputStyle }}">
                    @error('form.msisdn') <p style="font-size:10px; color:#E24B4A; margin:4px 0 0;">{{ $message }}</p> @enderror
                </div>
                <label style="display:flex; align-items:center; gap:8px; font-size:12px; color:#374151;">
                    <input type="checkbox" wire:model="form.is_active"> Employé actif (inclus dans le classement)
                </label>
            </div>
            <div style="padding:12px 20px; border-top:1px solid #e5e7eb; display:flex; justify-content:flex-end; gap:10px;">
                <button wire:click="$set('showForm', false)" style="background:#f3f4f6; color:#374151; font-size:13px; font-weight:500; padding:8px 20px; border-radius:8px; border:1px solid #e5e7eb; cursor:pointer;">Annuler</button>
                <button wire:click="save" style="background:#1B2F6E; color:#fff; font-size:13px; font-weight:600; padding:8px 20px; border-radius:8px; border:none; cursor:pointer;">Enregistrer</button>
            </div>
        </div>
    @endif

@script
<script>
    const empFmt = new Intl.NumberFormat('fr-FR');

    function empDraw(attempt = 0) {
        if (typeof Chart === 'undefined') {
            if (attempt < 20) setTimeout(() => empDraw(attempt + 1), 150);
            return;
        }
        const el = document.getElementById('employees-chart');
        if (!el) return;
        const d = JSON.parse(JSON.stringify($wire.chart));
        el.parentElement.style.height = Math.max(120, d.labels.length * 26) + 'px';
        if (el.__chart) {
            el.__chart.data.labels = d.labels;
            el.__chart.data.datasets[0].data = d.values;
            el.__chart.update();
            return;
        }
        el.__chart = new Chart(el, {
            type: 'bar',
            data: { labels: d.labels, datasets: [{ data: d.values, backgroundColor: '#1B2F6E', borderRadius: 4, borderSkipped: 'start', maxBarThickness: 18 }] },
            options: {
                indexAxis: 'y', responsive: true, maintainAspectRatio: false,
                plugins: { legend: { display: false }, tooltip: { callbacks: { label: (c) => ' ' + empFmt.format(c.parsed.x) + ' transactions' } } },
                scales: {
                    x: { beginAtZero: true, grid: { color: '#eef0f4' }, border: { display: false }, ticks: { color: '#6b7280', font: { size: 10 }, precision: 0 } },
                    y: { grid: { display: false }, ticks: { color: '#374151', font: { size: 10 } } },
                },
            },
        });
    }

    empDraw();
    $wire.on('employees-chart', () => setTimeout(() => empDraw(), 50));
</script>
@endscript

</div>
