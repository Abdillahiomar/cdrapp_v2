<?php

use App\Models\DrawCeremony;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;
use Livewire\WithPagination;

/**
 * Tirages au sort Marketing : liste des cérémonies et création.
 * Consultation : draws.view ; création et préparation : draws.manage.
 */
new #[Layout('layouts.tombola')] class extends Component {
    use WithPagination;

    public string $search       = '';
    public string $filterStatus = '';

    public bool  $showForm = false;
    public array $form     = ['name' => '', 'description' => '', 'ceremony_date' => ''];

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

        $this->form     = ['name' => '', 'description' => '', 'ceremony_date' => ''];
        $this->showForm = true;
        $this->resetErrorBag();
    }

    public function save(): void
    {
        abort_unless(auth()->user()->can('draws.manage'), 403);

        $this->validate([
            'form.name'          => 'required|string|max:255',
            'form.description'   => 'nullable|string|max:2000',
            'form.ceremony_date' => 'nullable|date',
        ], [
            'form.name.required' => 'Le nom est obligatoire.',
        ]);

        $ceremony = DrawCeremony::create([
            'name'          => trim($this->form['name']),
            'description'   => trim($this->form['description']) ?: null,
            'ceremony_date' => $this->form['ceremony_date'] ?: null,
            'created_by'    => auth()->id(),
        ]);

        $this->redirectRoute('draws.ceremony', $ceremony, navigate: true);
    }

    public function with(): array
    {
        $ceremonies = DrawCeremony::query()
            ->with(['creator:id,name', 'draws' => fn ($q) => $q->with('category:id,name,color')->withCount('prizes')])
            ->withSum('prizes as lots_total', 'quantity')
            ->when($this->search, fn ($q) => $q->where('name', 'ilike', '%' . $this->search . '%'))
            ->when($this->filterStatus, fn ($q) => $q->where('status', $this->filterStatus))
            ->latest()
            ->paginate(20);

        // Gagnants retenus par cérémonie (gagnants valides, suppléants promus, tirages complémentaires)
        $effective = DB::table('draw_winners as w')
            ->join('draws as d', 'd.id', '=', 'w.draw_id')
            ->whereIn('d.draw_ceremony_id', $ceremonies->pluck('id'))
            ->where('w.status', 'valid')
            ->where(fn ($q) => $q->whereIn('w.kind', ['winner', 'extra'])->orWhereNotNull('w.promoted_at'))
            ->groupBy('d.draw_ceremony_id')
            ->selectRaw('d.draw_ceremony_id, COUNT(*) AS n')
            ->pluck('n', 'draw_ceremony_id');

        return [
            'ceremonies' => $ceremonies,
            'effective'  => $effective,
            'counts'     => DrawCeremony::select('status', DB::raw('COUNT(*) AS n'))->groupBy('status')->pluck('n', 'status'),
            'canManage'  => auth()->user()->can('draws.manage'),
        ];
    }
};
?>
<div>
<x-tombola.shell title="Tirages au sort" active="index">
<div class="tg-page">

@php
    $fmt = fn ($n) => number_format((float) $n, 0, ',', ' ');
@endphp

    <div class="tg-toolbar">
        <p class="tg-muted" style="max-width:640px;">Une liste d'éligibles préparée à l'avance par catégorie de lots, puis tirage lot par lot dans la salle de tirage.</p>
        @if($canManage)
            <button wire:click="create" class="tg-btn tg-btn-shine">+ Nouvelle cérémonie</button>
        @endif
    </div>

    {{-- KPIs par statut (cliquables : filtre) --}}
    <div class="tg-stats">
        @foreach(\App\Models\DrawCeremony::STATUSES as $key => [$label])
            <button wire:click="$set('filterStatus', '{{ $filterStatus === $key ? '' : $key }}')"
                    class="tg-glass tg-stat {{ $key === 'in_progress' ? 'tg-stat-accent' : '' }} {{ $filterStatus === $key ? 'is-active' : '' }}">
                <span class="tg-badge tg-s-{{ $key }}" style="margin-bottom:12px;">{{ $label }}</span>
                <p class="tg-stat-value">{{ $counts[$key] ?? 0 }}</p>
                <p class="tg-stat-label">cérémonie(s)</p>
            </button>
        @endforeach
    </div>

    {{-- FILTRES --}}
    <div class="tg-glass tg-pad" style="display:flex; gap:12px; flex-wrap:wrap; align-items:flex-end;">
        <div style="flex:1; min-width:220px;">
            <label class="tg-label">Rechercher</label>
            <input type="text" wire:model.live.debounce.300ms="search" placeholder="Nom de la cérémonie…" class="tg-input">
        </div>
        <div style="min-width:180px;">
            <label class="tg-label">Statut</label>
            <select wire:model.live="filterStatus" class="tg-input">
                <option value="">Tous</option>
                @foreach(\App\Models\DrawCeremony::STATUSES as $key => [$label])
                    <option value="{{ $key }}">{{ $label }}</option>
                @endforeach
            </select>
        </div>
    </div>

    {{-- LISTE --}}
    <div class="tg-glass" style="overflow:hidden;">
        <div style="overflow-x:auto;">
            <table class="tg-table">
                <thead>
                    <tr>
                        @foreach(['Cérémonie', 'Date', 'Statut', 'Catégories', 'Éligibles', 'Lots', 'Gagnants', 'Créée par', ''] as $th)
                            <th class="{{ in_array($th, ['Éligibles', 'Lots', 'Gagnants']) ? 'num' : '' }}">{{ $th }}</th>
                        @endforeach
                    </tr>
                </thead>
                <tbody>
                    @forelse($ceremonies as $ceremony)
                        <tr wire:key="ceremony-{{ $ceremony->id }}">
                            <td style="min-width:220px;">
                                <a href="{{ route('draws.ceremony', $ceremony) }}" wire:navigate class="tg-strong" style="text-decoration:none; font-size:13.5px;">{{ $ceremony->name }}</a>
                                @if($ceremony->description)
                                    <p class="tg-muted" style="font-size:11px; margin-top:2px;">{{ \Illuminate\Support\Str::limit($ceremony->description, 80) }}</p>
                                @endif
                            </td>
                            <td style="white-space:nowrap;">{{ $ceremony->ceremony_date?->format('d/m/Y') ?? '—' }}</td>
                            <td><span class="tg-badge tg-s-{{ $ceremony->status }}">{{ $ceremony->statusLabel()[0] }}</span></td>
                            <td>
                                <div style="display:flex; gap:5px; flex-wrap:wrap;">
                                    @forelse($ceremony->draws as $d)
                                        <span class="tg-cat" style="--c:{{ $d->categoryColor() }}; font-size:10px; padding:3px 9px;">{{ $d->categoryName() }}</span>
                                    @empty
                                        <span class="tg-muted">—</span>
                                    @endforelse
                                </div>
                            </td>
                            <td class="num tg-strong" style="font-weight:700;">{{ $ceremony->draws->whereNotNull('eligible_count')->isNotEmpty() ? $fmt($ceremony->draws->sum('eligible_count')) : '—' }}</td>
                            <td class="num">{{ (int) $ceremony->lots_total }}</td>
                            <td class="num" style="font-weight:900; color:var(--tg-yellow);">{{ $ceremony->hasStarted() ? ($effective[$ceremony->id] ?? 0) : '—' }}</td>
                            <td style="white-space:nowrap;">
                                {{ $ceremony->creator?->name ?? '—' }}
                                <span class="tg-muted" style="font-size:10.5px; display:block;">{{ $ceremony->created_at->format('d/m/Y H:i') }}</span>
                            </td>
                            <td style="text-align:right; white-space:nowrap;">
                                <a href="{{ route('draws.ceremony', $ceremony) }}" wire:navigate class="tg-btn tg-btn-ghost tg-btn-sm">Ouvrir →</a>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="9" style="padding:36px; text-align:center;" class="tg-muted">Aucune cérémonie.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        @if($ceremonies->hasPages())
            <div style="padding:12px 16px; border-top:1px solid var(--tg-stroke-soft); background:rgba(255,255,255,.92); color:#111827;">{{ $ceremonies->links() }}</div>
        @endif
    </div>

    {{-- FORMULAIRE DE CRÉATION --}}
    @if($showForm)
        <div wire:click="$set('showForm', false)" class="tg-backdrop"></div>
        <div class="tg-modal">
            <div class="tg-modal-head">
                <p class="tg-eyebrow">Grand Tombola</p>
                <p class="tg-h2" style="margin-top:4px;">Nouvelle cérémonie</p>
                <p class="tg-muted" style="margin-top:4px;">Les catégories (Platinum, Gold, Bronze…), leurs critères et leurs lots se règlent ensuite sur la page de la cérémonie.</p>
            </div>
            <div class="tg-modal-body">
                <div>
                    <label class="tg-label">Nom de la cérémonie <span class="tg-req">*</span></label>
                    <input type="text" wire:model="form.name" placeholder="Ex. Grande tombola de fin d'année" class="tg-input">
                    @error('form.name') <p class="tg-error">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="tg-label">Description</label>
                    <textarea wire:model="form.description" rows="2" class="tg-input" style="resize:vertical;"></textarea>
                </div>
                <div>
                    <label class="tg-label">Date de la cérémonie</label>
                    <input type="date" wire:model="form.ceremony_date" class="tg-input" style="max-width:200px;">
                    @error('form.ceremony_date') <p class="tg-error">{{ $message }}</p> @enderror
                </div>
            </div>
            <div class="tg-modal-foot">
                <button wire:click="$set('showForm', false)" class="tg-btn tg-btn-ghost">Annuler</button>
                <button wire:click="save" class="tg-btn">Créer</button>
            </div>
        </div>
    @endif

</div>
</x-tombola.shell>
</div>
