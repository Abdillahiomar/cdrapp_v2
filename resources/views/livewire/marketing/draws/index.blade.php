<?php

use App\Models\Draw;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Livewire\Volt\Component;
use Livewire\WithPagination;

/**
 * Tirages au sort Marketing : liste des campagnes et création.
 * Consultation : draws.view ; création et gestion : draws.manage.
 */
new class extends Component {
    use WithPagination;

    public string $search       = '';
    public string $filterStatus = '';

    public bool  $showForm = false;
    public array $form     = ['name' => '', 'description' => '', 'period_start' => '', 'period_end' => ''];

    /** Types proposés par défaut à la création : paiements marchands */
    private const DEFAULT_TYPES = ['Merchant Payment', 'Online Merchant Payment'];

    /** Le middleware de route ne couvre pas les appels Livewire suivants */
    public function boot(): void
    {
        abort_unless(auth()->user()?->can('draws.view'), 403);
    }

    public function updatedSearch(): void { $this->resetPage(); }
    public function updatedFilterStatus(): void { $this->resetPage(); }

    public function create(): void
    {
        abort_unless(auth()->user()->can('draws.manage'), 403);

        $this->form = [
            'name'         => '',
            'description'  => '',
            'period_start' => Carbon::now()->startOfMonth()->format('Y-m-d'),
            'period_end'   => Carbon::now()->format('Y-m-d'),
        ];
        $this->showForm = true;
        $this->resetErrorBag();
    }

    public function save(): void
    {
        abort_unless(auth()->user()->can('draws.manage'), 403);

        $this->validate([
            'form.name'         => 'required|string|max:255',
            'form.description'  => 'nullable|string|max:2000',
            'form.period_start' => 'required|date',
            'form.period_end'   => 'required|date|after_or_equal:form.period_start',
        ], [
            'form.name.required'              => 'Le nom est obligatoire.',
            'form.period_start.required'      => 'La date de début est obligatoire.',
            'form.period_end.required'        => 'La date de fin est obligatoire.',
            'form.period_end.after_or_equal'  => 'La date de fin doit suivre la date de début.',
        ]);

        $draw = Draw::create([
            'name'         => trim($this->form['name']),
            'description'  => trim($this->form['description']) ?: null,
            'period_start' => $this->form['period_start'],
            'period_end'   => $this->form['period_end'],
            'txn_indexes'  => DB::table('transaction_types')->whereIn('txn_type_name', self::DEFAULT_TYPES)->pluck('txn_index')->map(fn ($v) => (int) $v)->all(),
            'created_by'   => auth()->id(),
        ]);

        $this->redirectRoute('draws.show', $draw, navigate: true);
    }

    public function with(): array
    {
        $draws = Draw::query()
            ->with('creator:id,name')
            ->withCount(['prizes', 'winners as effective_count' => fn ($q) => $q->effective()])
            ->withSum('prizes as lots_total', 'quantity')
            ->when($this->search, fn ($q) => $q->where('name', 'ilike', '%' . $this->search . '%'))
            ->when($this->filterStatus, fn ($q) => $q->where('status', $this->filterStatus))
            ->latest()
            ->paginate(20);

        return [
            'draws'     => $draws,
            'counts'    => Draw::select('status', DB::raw('COUNT(*) AS n'))->groupBy('status')->pluck('n', 'status'),
            'canManage' => auth()->user()->can('draws.manage'),
        ];
    }
};
?>
<div style="padding:24px;">

@php
    $inputStyle = 'border:1px solid #d1d5db; border-radius:7px; padding:9px 12px; font-size:13px; color:#111827; outline:none; background:#fff; width:100%; box-sizing:border-box;';
    $labelStyle = 'font-size:11px; color:#6b7280; display:block; margin-bottom:4px; font-weight:600;';
@endphp

    <div style="display:flex; align-items:center; justify-content:space-between; margin-bottom:20px; gap:12px; flex-wrap:wrap;">
        <div>
            <h2 style="font-size:16px; font-weight:700; color:#111827; margin:0 0 4px;">Tirages au sort</h2>
            <p style="font-size:12px; color:#9ca3af; margin:0;">Campagnes Marketing : clients éligibles selon leurs transactions, doublons supprimés, tirage des lots.</p>
        </div>
        @if($canManage)
            <button wire:click="create"
                    style="background:#1B2F6E; color:#fff; font-size:13px; font-weight:600; padding:9px 18px; border-radius:8px; border:none; cursor:pointer;">
                + Nouveau tirage
            </button>
        @endif
    </div>

    {{-- KPIs par statut --}}
    <div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(140px, 1fr)); gap:12px; margin-bottom:16px;">
        @foreach(\App\Models\Draw::STATUSES as $key => [$label, $fg, $bg])
            <button wire:click="$set('filterStatus', '{{ $filterStatus === $key ? '' : $key }}')"
                    style="text-align:left; background:#fff; border:1px solid {{ $filterStatus === $key ? $fg : '#e5e7eb' }}; border-radius:10px; padding:14px 16px; border-top:3px solid {{ $fg }}; cursor:pointer;">
                <p style="font-size:11px; color:#6b7280; margin:0 0 6px;">{{ $label }}</p>
                <p style="font-size:20px; font-weight:700; color:#111827; margin:0;">{{ $counts[$key] ?? 0 }}</p>
            </button>
        @endforeach
    </div>

    <div style="background:#fff; border:1px solid #e5e7eb; border-radius:10px; padding:16px; margin-bottom:16px; display:flex; gap:12px; flex-wrap:wrap; align-items:flex-end;">
        <div style="flex:1; min-width:220px;">
            <label style="{{ $labelStyle }}">Rechercher</label>
            <input type="text" wire:model.live.debounce.300ms="search" placeholder="Nom du tirage…" style="{{ $inputStyle }}">
        </div>
        <div>
            <label style="{{ $labelStyle }}">Statut</label>
            <select wire:model.live="filterStatus" style="{{ $inputStyle }} width:auto;">
                <option value="">Tous</option>
                @foreach(\App\Models\Draw::STATUSES as $key => [$label])
                    <option value="{{ $key }}">{{ $label }}</option>
                @endforeach
            </select>
        </div>
    </div>

    <div style="background:#fff; border:1px solid #e5e7eb; border-radius:10px; overflow:hidden;">
        <div style="overflow-x:auto;">
            <table style="width:100%; border-collapse:collapse; font-size:12px;">
                <thead>
                    <tr style="background:#F7F8FC;">
                        @foreach(['Tirage', 'Période', 'Statut', 'Éligibles', 'Lots', 'Gagnants', 'Créé par', ''] as $th)
                            <th style="padding:10px 16px; text-align:{{ in_array($th, ['Éligibles', 'Lots', 'Gagnants']) ? 'right' : 'left' }}; color:#6b7280; font-weight:500; border-bottom:1px solid #e5e7eb; white-space:nowrap;">{{ $th }}</th>
                        @endforeach
                    </tr>
                </thead>
                <tbody>
                    @forelse($draws as $draw)
                        @php [$sLabel, $sFg, $sBg] = $draw->statusLabel(); @endphp
                        <tr wire:key="draw-{{ $draw->id }}" style="border-bottom:1px solid #f3f4f6;">
                            <td style="padding:10px 16px;">
                                <a href="{{ route('draws.show', $draw) }}" wire:navigate style="color:#1B2F6E; font-weight:600; text-decoration:none;">{{ $draw->name }}</a>
                                @if($draw->description)
                                    <p style="font-size:11px; color:#9ca3af; margin:2px 0 0;">{{ \Illuminate\Support\Str::limit($draw->description, 80) }}</p>
                                @endif
                            </td>
                            <td style="padding:10px 16px; color:#374151; white-space:nowrap;">{{ $draw->period_start->format('d/m/Y') }} → {{ $draw->period_end->format('d/m/Y') }}</td>
                            <td style="padding:10px 16px;">
                                <span style="display:inline-block; padding:3px 10px; border-radius:10px; font-size:11px; font-weight:600; color:{{ $sFg }}; background:{{ $sBg }};">{{ $sLabel }}</span>
                            </td>
                            <td style="padding:10px 16px; text-align:right; color:#111827;">{{ $draw->eligible_count !== null ? number_format($draw->eligible_count, 0, ',', ' ') : '—' }}</td>
                            <td style="padding:10px 16px; text-align:right; color:#111827;">{{ (int) $draw->lots_total }}</td>
                            <td style="padding:10px 16px; text-align:right; color:#111827; font-weight:700;">{{ $draw->isDrawn() ? $draw->effective_count : '—' }}</td>
                            <td style="padding:10px 16px; color:#6b7280; white-space:nowrap;">
                                {{ $draw->creator?->name ?? '—' }}
                                <span style="font-size:10px; color:#9ca3af; display:block;">{{ $draw->created_at->format('d/m/Y H:i') }}</span>
                            </td>
                            <td style="padding:10px 16px; text-align:right; white-space:nowrap;">
                                <a href="{{ route('draws.show', $draw) }}" wire:navigate
                                   style="background:#E8ECF8; color:#1B2F6E; font-size:11px; font-weight:600; padding:5px 12px; border-radius:6px; text-decoration:none;">Ouvrir</a>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="8" style="padding:30px; text-align:center; color:#9ca3af;">Aucun tirage.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        @if($draws->hasPages())
            <div style="padding:12px 16px; border-top:1px solid #e5e7eb;">{{ $draws->links() }}</div>
        @endif
    </div>

    {{-- FORMULAIRE DE CRÉATION --}}
    @if($showForm)
        <div wire:click="$set('showForm', false)" style="position:fixed; inset:0; background:rgba(0,0,0,0.45); z-index:998;"></div>
        <div style="position:fixed; top:50%; left:50%; transform:translate(-50%,-50%); width:min(500px,95vw); background:#fff; border-radius:14px; z-index:999; box-shadow:0 20px 60px rgba(0,0,0,0.2);">
            <div style="padding:16px 20px; border-bottom:1px solid #e5e7eb;">
                <p style="font-size:14px; font-weight:700; color:#111827; margin:0;">Nouveau tirage</p>
                <p style="font-size:11px; color:#9ca3af; margin:4px 0 0;">Critères, options et lots se règlent ensuite sur la page du tirage.</p>
            </div>
            <div style="padding:20px; display:flex; flex-direction:column; gap:14px;">
                <div>
                    <label style="{{ $labelStyle }}">Nom du tirage <span style="color:#E24B4A;">*</span></label>
                    <input type="text" wire:model="form.name" placeholder="Ex. Grand tirage paiement marchand — novembre" style="{{ $inputStyle }}">
                    @error('form.name') <p style="font-size:10px; color:#E24B4A; margin:4px 0 0;">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label style="{{ $labelStyle }}">Description</label>
                    <textarea wire:model="form.description" rows="2" style="{{ $inputStyle }} resize:vertical;"></textarea>
                </div>
                <div style="display:grid; grid-template-columns:1fr 1fr; gap:12px;">
                    <div>
                        <label style="{{ $labelStyle }}">Début de période <span style="color:#E24B4A;">*</span></label>
                        <input type="date" wire:model="form.period_start" style="{{ $inputStyle }}">
                        @error('form.period_start') <p style="font-size:10px; color:#E24B4A; margin:4px 0 0;">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label style="{{ $labelStyle }}">Fin de période <span style="color:#E24B4A;">*</span></label>
                        <input type="date" wire:model="form.period_end" style="{{ $inputStyle }}">
                        @error('form.period_end') <p style="font-size:10px; color:#E24B4A; margin:4px 0 0;">{{ $message }}</p> @enderror
                    </div>
                </div>
            </div>
            <div style="padding:12px 20px; border-top:1px solid #e5e7eb; display:flex; justify-content:flex-end; gap:10px;">
                <button wire:click="$set('showForm', false)" style="background:#f3f4f6; color:#374151; font-size:13px; font-weight:500; padding:8px 20px; border-radius:8px; border:1px solid #e5e7eb; cursor:pointer;">Annuler</button>
                <button wire:click="save" style="background:#1B2F6E; color:#fff; font-size:13px; font-weight:600; padding:8px 20px; border-radius:8px; border:none; cursor:pointer;">Créer</button>
            </div>
        </div>
    @endif

</div>
