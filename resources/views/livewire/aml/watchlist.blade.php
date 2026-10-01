<?php

use App\Models\AmlWatchlist;
use Livewire\Volt\Component;
use Livewire\WithPagination;

new class extends Component {

    use WithPagination;

    public string $filterType   = '';
    public string $filterActive = '1';
    public string $search       = '';

    public bool  $showForm = false;
    public array $form     = [];

    public function mount(): void
    {
        $this->form = $this->emptyForm();
    }

    private function emptyForm(): array
    {
        return ['msisdn' => '', 'list_type' => 'black_list', 'nom' => '', 'motif' => ''];
    }

    public function updated($property): void
    {
        if (in_array($property, ['filterType', 'filterActive', 'search'])) {
            $this->resetPage();
        }
    }

    public function create(): void
    {
        $this->authorizeManage();

        $this->form     = $this->emptyForm();
        $this->showForm = true;
        $this->resetErrorBag();
    }

    public function cancel(): void
    {
        $this->showForm = false;
        $this->resetErrorBag();
    }

    public function save(): void
    {
        $this->authorizeManage();

        $this->validate([
            'form.msisdn'    => 'required|string|max:30',
            'form.list_type' => 'required|in:' . implode(',', array_keys(AmlWatchlist::LIST_TYPES)),
            'form.nom'       => 'nullable|string|max:255',
            'form.motif'     => 'required|string|min:3',
        ], [
            'form.motif.required' => 'Le motif est obligatoire.',
        ]);

        $msisdn = preg_replace('/\s+/', '', $this->form['msisdn']);

        $exists = AmlWatchlist::where('msisdn', $msisdn)
            ->where('list_type', $this->form['list_type'])
            ->where('actif', true)
            ->exists();

        if ($exists) {
            $this->addError('form.msisdn', 'Ce compte est déjà actif dans cette liste.');
            return;
        }

        AmlWatchlist::create([
            'msisdn'     => $msisdn,
            'list_type'  => $this->form['list_type'],
            'nom'        => $this->form['nom'] ?: null,
            'motif'      => $this->form['motif'],
            'actif'      => true,
            'date_ajout' => now(),
            'ajoute_par' => auth()->user()->name,
        ]);

        $this->showForm = false;
        session()->flash('watchlist-message', "Compte {$msisdn} ajouté à la liste « " . AmlWatchlist::LIST_TYPES[$this->form['list_type']] . ' ».');
    }

    public function deactivate(int $id): void
    {
        $this->authorizeManage();

        AmlWatchlist::findOrFail($id)->update(['actif' => false, 'date_retrait' => now()]);
    }

    public function reactivate(int $id): void
    {
        $this->authorizeManage();

        AmlWatchlist::findOrFail($id)->update(['actif' => true, 'date_retrait' => null]);
    }

    private function authorizeManage(): void
    {
        abort_unless(auth()->user()->can('aml.watchlist.manage'), 403);
    }

    public function with(): array
    {
        $query = AmlWatchlist::query();

        if ($this->filterType) {
            $query->where('list_type', $this->filterType);
        }
        if ($this->filterActive !== '') {
            $query->where('actif', $this->filterActive === '1');
        }
        if ($this->search) {
            $query->where(function ($q) {
                $q->where('msisdn', 'like', '%' . $this->search . '%')
                  ->orWhere('nom', 'ilike', '%' . $this->search . '%');
            });
        }

        $counts = AmlWatchlist::where('actif', true)
            ->selectRaw('list_type, COUNT(*) AS total')
            ->groupBy('list_type')
            ->pluck('total', 'list_type');

        return [
            'entries' => $query->orderByDesc('date_ajout')->paginate(25),
            'counts'  => $counts,
        ];
    }
};
?>
<div style="padding:24px;">

@php
    $inputStyle = 'border:1px solid #d1d5db; border-radius:7px; padding:8px 10px; font-size:13px; color:#111827; outline:none; background:#fff;';
    $labelStyle = 'font-size:11px; color:#6b7280; display:block; margin-bottom:4px;';
    $typeStyles = [
        'ppe'        => ['#E8ECF8', '#1B2F6E'],
        'black_list' => ['#FDE8E8', '#7F1D1D'],
        'grey_list'  => ['#F3F4F6', '#374151'],
    ];
    $canManage = auth()->user()->can('aml.watchlist.manage');
@endphp

    <div style="margin-bottom:16px; display:flex; justify-content:space-between; align-items:flex-start; gap:12px; flex-wrap:wrap;">
        <div>
            <h2 style="font-size:16px; font-weight:700; color:#111827; margin:0 0 4px;">AML — Listes de surveillance</h2>
            <p style="font-size:12px; color:#9ca3af; margin:0;">Comptes PPE (règle AL7) et black list / liste grise (règle AL9). Un compte retiré n'est plus surveillé, mais reste dans l'historique.</p>
        </div>
        @if($canManage)
            <button wire:click="create"
                    style="background:#1B2F6E; color:#fff; font-size:13px; font-weight:600; padding:9px 16px; border-radius:8px; border:none; cursor:pointer;">
                + Ajouter un compte
            </button>
        @endif
    </div>

    <x-aml-tabs active="aml.watchlist" />

    @if(session('watchlist-message'))
        <div style="background:#E5F5ED; color:#005C2B; border:1px solid #A7E3C1; border-radius:8px; padding:10px 14px; font-size:12px; margin-bottom:16px;">
            {{ session('watchlist-message') }}
        </div>
    @endif

    {{-- KPIs --}}
    <div style="display:grid; grid-template-columns:repeat(3, minmax(0,1fr)); gap:12px; margin-bottom:16px;">
        @foreach(\App\Models\AmlWatchlist::LIST_TYPES as $key => $label)
            <div style="background:#fff; border:1px solid #e5e7eb; border-radius:10px; padding:16px; border-top:3px solid {{ $typeStyles[$key][1] }};">
                <p style="font-size:11px; color:#6b7280; margin:0 0 6px;">{{ $label }} (actifs)</p>
                <p style="font-size:20px; font-weight:700; color:#111827; margin:0;">{{ $counts[$key] ?? 0 }}</p>
            </div>
        @endforeach
    </div>

    {{-- FILTRES --}}
    <div style="background:#fff; border:1px solid #e5e7eb; border-radius:10px; padding:16px; margin-bottom:16px; display:flex; gap:12px; flex-wrap:wrap; align-items:flex-end;">
        <div>
            <label style="{{ $labelStyle }}">Rechercher</label>
            <input type="text" wire:model.live.debounce.400ms="search" placeholder="MSISDN ou nom..." style="{{ $inputStyle }} width:200px;">
        </div>
        <div>
            <label style="{{ $labelStyle }}">Liste</label>
            <select wire:model.live="filterType" style="{{ $inputStyle }} width:150px;">
                <option value="">Toutes</option>
                @foreach(\App\Models\AmlWatchlist::LIST_TYPES as $key => $label)
                    <option value="{{ $key }}">{{ $label }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label style="{{ $labelStyle }}">État</label>
            <select wire:model.live="filterActive" style="{{ $inputStyle }} width:130px;">
                <option value="1">Actifs</option>
                <option value="0">Retirés</option>
                <option value="">Tous</option>
            </select>
        </div>
    </div>

    {{-- TABLEAU --}}
    <div style="background:#fff; border:1px solid #e5e7eb; border-radius:10px; overflow:hidden;">
        <div style="overflow-x:auto;">
            <table style="width:100%; border-collapse:collapse; font-size:12px;">
                <thead>
                    <tr style="background:#F7F8FC;">
                        @foreach(['MSISDN', 'Nom', 'Liste', 'Motif', 'Ajouté le', 'Ajouté par', 'Retiré le', ''] as $th)
                            <th style="padding:10px 12px; text-align:left; color:#6b7280; font-weight:500; border-bottom:1px solid #e5e7eb; white-space:nowrap;">{{ $th }}</th>
                        @endforeach
                    </tr>
                </thead>
                <tbody>
                    @forelse($entries as $entry)
                        @php [$bg, $color] = $typeStyles[$entry->list_type] ?? ['#F3F4F6', '#374151']; @endphp
                        <tr wire:key="wl-{{ $entry->id }}" style="border-bottom:1px solid #f3f4f6; {{ $entry->actif ? '' : 'opacity:.55;' }}">
                            <td style="padding:10px 12px; color:#111827; font-family:monospace; font-weight:600;">{{ $entry->msisdn }}</td>
                            <td style="padding:10px 12px; color:#111827;">{{ $entry->nom ?? '—' }}</td>
                            <td style="padding:10px 12px;">
                                <span style="background:{{ $bg }}; color:{{ $color }}; font-size:11px; font-weight:600; padding:3px 10px; border-radius:20px; white-space:nowrap;">{{ \App\Models\AmlWatchlist::LIST_TYPES[$entry->list_type] ?? $entry->list_type }}</span>
                            </td>
                            <td style="padding:10px 12px; color:#6b7280; max-width:280px;">{{ $entry->motif ?? '—' }}</td>
                            <td style="padding:10px 12px; color:#6b7280; white-space:nowrap;">{{ $entry->date_ajout?->format('d/m/Y') ?? '—' }}</td>
                            <td style="padding:10px 12px; color:#6b7280;">{{ $entry->ajoute_par ?? '—' }}</td>
                            <td style="padding:10px 12px; color:#6b7280; white-space:nowrap;">{{ $entry->date_retrait?->format('d/m/Y') ?? '—' }}</td>
                            <td style="padding:10px 12px;">
                                @if($canManage)
                                    @if($entry->actif)
                                        <button wire:click="deactivate({{ $entry->id }})"
                                                wire:confirm="Retirer {{ $entry->msisdn }} de la liste ? Il ne sera plus surveillé."
                                                style="background:#fff; color:#B91C1C; font-size:11px; font-weight:600; padding:5px 10px; border-radius:6px; border:1px solid #F5B5B5; cursor:pointer;">
                                            Retirer
                                        </button>
                                    @else
                                        <button wire:click="reactivate({{ $entry->id }})"
                                                style="background:#E8ECF8; color:#1B2F6E; font-size:11px; font-weight:600; padding:5px 10px; border-radius:6px; border:none; cursor:pointer;">
                                            Réactiver
                                        </button>
                                    @endif
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="8" style="padding:24px; text-align:center; color:#9ca3af;">Aucun compte pour ces critères.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <div style="padding:12px 16px; border-top:1px solid #e5e7eb;">
            {{ $entries->links() }}
        </div>
    </div>

    {{-- FORMULAIRE D'AJOUT --}}
    @if($showForm)
        <div style="position:fixed; inset:0; background:rgba(17,24,39,0.45); z-index:200; display:flex; align-items:center; justify-content:center; padding:16px;"
             wire:click.self="cancel">
            <div style="width:min(480px, 100%); background:#fff; border-radius:12px; box-shadow:0 12px 32px rgba(0,0,0,0.2);">
                <div style="padding:16px 20px; border-bottom:1px solid #e5e7eb; display:flex; justify-content:space-between; align-items:center;">
                    <p style="font-size:15px; font-weight:700; color:#111827; margin:0;">Ajouter un compte</p>
                    <button wire:click="cancel" style="background:none; border:none; font-size:22px; color:#9ca3af; cursor:pointer; line-height:1;">×</button>
                </div>
                <div style="padding:18px 20px; display:flex; flex-direction:column; gap:12px;">
                    <div>
                        <label style="{{ $labelStyle }}">MSISDN (ou short code pour une organisation)</label>
                        <input type="text" wire:model="form.msisdn" placeholder="25377XXXXXX" style="{{ $inputStyle }} width:100%; box-sizing:border-box;">
                        @error('form.msisdn') <p style="font-size:11px; color:#B91C1C; margin:4px 0 0;">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label style="{{ $labelStyle }}">Liste</label>
                        <select wire:model="form.list_type" style="{{ $inputStyle }} width:100%; box-sizing:border-box;">
                            @foreach(\App\Models\AmlWatchlist::LIST_TYPES as $key => $label)
                                <option value="{{ $key }}">{{ $label }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <label style="{{ $labelStyle }}">Nom</label>
                        <input type="text" wire:model="form.nom" style="{{ $inputStyle }} width:100%; box-sizing:border-box;">
                    </div>
                    <div>
                        <label style="{{ $labelStyle }}">Motif</label>
                        <textarea wire:model="form.motif" rows="3" style="{{ $inputStyle }} width:100%; box-sizing:border-box; resize:vertical;"></textarea>
                        @error('form.motif') <p style="font-size:11px; color:#B91C1C; margin:4px 0 0;">{{ $message }}</p> @enderror
                    </div>
                </div>
                <div style="padding:14px 20px; border-top:1px solid #e5e7eb; display:flex; gap:8px; justify-content:flex-end;">
                    <button wire:click="cancel"
                            style="background:#f3f4f6; color:#374151; font-size:13px; font-weight:600; padding:9px 16px; border-radius:8px; border:1px solid #d1d5db; cursor:pointer;">
                        Annuler
                    </button>
                    <button wire:click="save"
                            style="background:#1B2F6E; color:#fff; font-size:13px; font-weight:600; padding:9px 16px; border-radius:8px; border:none; cursor:pointer;">
                        Ajouter
                    </button>
                </div>
            </div>
        </div>
    @endif

</div>
