<?php

use App\Jobs\ComputeDrawEligibles;
use App\Models\Draw;
use App\Models\DrawPrize;
use App\Models\DrawWinner;
use App\Models\Prize;
use App\Support\DrawEngine;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Livewire\Volt\Component;
use Livewire\WithPagination;

/**
 * Un tirage au sort, en 4 étapes : critères et options → lots (choisis dans le
 * catalogue) → liste des éligibles figée (job de queue) → tirage lot par lot,
 * chaque gagnant annoncé dans une fenêtre. Puis gestion des gagnants écartés et
 * clôture. Consultation : draws.view ; actions : draws.manage.
 */
new class extends Component {
    use WithPagination;

    public Draw $draw;

    public array  $form       = [];
    public string $typeSearch = '';

    public bool  $showPrizeForm  = false;
    public ?int  $editingPrizeId = null;
    public array $prizeForm      = ['prize_id' => '', 'quantity' => 1, 'substitutes' => 0];

    public string $participantSearch = '';

    public ?int   $invalidatingId = null;
    public string $invalidReason  = '';

    public ?int $revealId = null;   // gagnant affiché dans la fenêtre de résultat

    public ?array $verifyResult = null;

    private bool $failed = false;

    /** Le middleware de route ne couvre pas les appels Livewire suivants */
    public function boot(): void
    {
        abort_unless(auth()->user()?->can('draws.view'), 403);
    }

    public function mount(Draw $draw): void
    {
        $this->draw = $draw;
        $this->fillForm();
    }

    private function fillForm(): void
    {
        $d = $this->draw;
        $this->form = [
            'name'                 => $d->name,
            'description'          => (string) $d->description,
            'period_start'         => $d->period_start->format('Y-m-d'),
            'period_end'           => $d->period_end->format('Y-m-d'),
            'txn_indexes'          => array_map('strval', $d->txn_indexes ?? []),
            'min_txn_count'        => $d->min_txn_count,
            'min_txn_amount'       => (float) $d->min_txn_amount,
            'chance_mode'          => $d->chance_mode,
            'one_prize_per_client' => $d->one_prize_per_client,
            'exclude_employees'    => $d->exclude_employees,
            'exclude_rds_lite'     => $d->exclude_rds_lite,
            'exclude_winners_days' => $d->exclude_winners_days,
            'blacklist'            => (string) $d->blacklist,
        ];
    }

    private function authorizeManage(): void
    {
        abort_unless(auth()->user()->can('draws.manage'), 403);
    }

    private function engine(): DrawEngine
    {
        return app(DrawEngine::class);
    }

    /** Exécute une action du moteur et affiche son erreur métier au lieu d'une page 500 */
    private function attempt(callable $action, ?string $success = null): mixed
    {
        $this->failed = false;

        try {
            $result = $action();
            if ($success) {
                session()->flash('success', $success);
            }
            return $result;
        } catch (\RuntimeException $e) {
            $this->failed = true;
            session()->flash('error', $e->getMessage());
            return null;
        } finally {
            $this->draw->refresh();
        }
    }

    // ── Étape 1 : critères et options ─────────────────────────────────────

    public function saveCriteria(bool $flash = true): bool
    {
        $this->authorizeManage();

        if (!$this->draw->isEditable()) {
            session()->flash('error', 'Les critères ne sont modifiables qu\'au statut brouillon.');
            return false;
        }

        $this->validate([
            'form.name'                 => 'required|string|max:255',
            'form.description'          => 'nullable|string|max:2000',
            'form.period_start'         => 'required|date',
            'form.period_end'           => 'required|date|after_or_equal:form.period_start',
            'form.txn_indexes'          => 'required|array|min:1',
            'form.min_txn_count'        => 'required|integer|min:1',
            'form.min_txn_amount'       => 'required|numeric|min:0',
            'form.chance_mode'          => 'required|in:' . implode(',', array_keys(Draw::CHANCE_MODES)),
            'form.exclude_winners_days' => 'nullable|integer|min:1|max:3650',
            'form.blacklist'            => 'nullable|string|max:20000',
        ], [
            'form.name.required'             => 'Le nom est obligatoire.',
            'form.period_end.after_or_equal' => 'La date de fin doit suivre la date de début.',
            'form.txn_indexes.required'      => 'Sélectionnez au moins un type de transaction.',
            'form.txn_indexes.min'           => 'Sélectionnez au moins un type de transaction.',
            'form.min_txn_count.min'         => 'Au moins 1 transaction.',
        ]);

        $this->draw->update([
            'name'                 => trim($this->form['name']),
            'description'          => trim($this->form['description']) ?: null,
            'period_start'         => $this->form['period_start'],
            'period_end'           => $this->form['period_end'],
            'txn_indexes'          => array_values(array_map('intval', $this->form['txn_indexes'])),
            'min_txn_count'        => (int) $this->form['min_txn_count'],
            'min_txn_amount'       => (float) $this->form['min_txn_amount'],
            'chance_mode'          => $this->form['chance_mode'],
            'one_prize_per_client' => (bool) $this->form['one_prize_per_client'],
            'exclude_employees'    => (bool) $this->form['exclude_employees'],
            'exclude_rds_lite'     => (bool) $this->form['exclude_rds_lite'],
            'exclude_winners_days' => $this->form['exclude_winners_days'] ?: null,
            'blacklist'            => trim($this->form['blacklist']) ?: null,
            'error'                => null,
        ]);

        if ($flash) {
            session()->flash('success', 'Critères enregistrés.');
        }

        return true;
    }

    // ── Étape 2 : lots (choisis dans le catalogue) ────────────────────────

    public function createPrize(): void
    {
        $this->authorizeManage();
        $this->editingPrizeId = null;
        $this->prizeForm      = ['prize_id' => '', 'quantity' => 1, 'substitutes' => 0];
        $this->showPrizeForm  = true;
        $this->resetErrorBag();
    }

    public function editPrize(int $id): void
    {
        $this->authorizeManage();
        $prize                = $this->draw->prizes()->findOrFail($id);
        $this->editingPrizeId = $prize->id;
        $this->prizeForm      = ['prize_id' => (string) $prize->prize_id, 'quantity' => $prize->quantity, 'substitutes' => $prize->substitutes];
        $this->showPrizeForm  = true;
        $this->resetErrorBag();
    }

    public function savePrize(): void
    {
        $this->authorizeManage();

        if ($this->draw->isDrawn()) {
            session()->flash('error', 'Le tirage a commencé : les lots ne sont plus modifiables.');
            return;
        }

        $this->validate([
            'prizeForm.prize_id'    => 'required|exists:prizes,id',
            'prizeForm.quantity'    => 'required|integer|min:1|max:10000',
            'prizeForm.substitutes' => 'required|integer|min:0|max:100',
        ], [
            'prizeForm.prize_id.required' => 'Choisissez un lot du catalogue.',
            'prizeForm.quantity.min'      => 'Au moins 1.',
        ]);

        $catalog = Prize::with(['category', 'mainImage'])->findOrFail($this->prizeForm['prize_id']);
        $current = $this->editingPrizeId ? $this->draw->prizes()->findOrFail($this->editingPrizeId) : null;

        $data = [
            'quantity'    => (int) $this->prizeForm['quantity'],
            'substitutes' => (int) $this->prizeForm['substitutes'],
        ];

        // Copie du lot (nom, catégorie, image) : le PV reste juste si le catalogue change ensuite
        if (!$current || $current->prize_id !== $catalog->id) {
            $data += [
                'prize_id'       => $catalog->id,
                'name'           => $catalog->name,
                'category_name'  => $catalog->category->name,
                'category_color' => $catalog->category->color,
                'image_path'     => $this->copyImage($catalog),
            ];
            if ($current?->image_path) {
                Storage::disk('local')->delete($current->image_path);
            }
        }

        $current
            ? $current->update($data)
            : $this->draw->prizes()->create($data + ['position' => (int) $this->draw->prizes()->max('position') + 1]);

        $this->showPrizeForm = false;
    }

    private function copyImage(Prize $catalog): ?string
    {
        $source = $catalog->mainImage?->path;
        $disk   = Storage::disk('local');

        if (!$source || !$disk->exists($source)) {
            return null;
        }

        $target = 'draws/' . $this->draw->id . '/' . Str::uuid() . '.' . pathinfo($source, PATHINFO_EXTENSION);
        $disk->copy($source, $target);

        return $target;
    }

    public function deletePrize(int $id): void
    {
        $this->authorizeManage();

        if (!$this->draw->isDrawn()) {
            $prize = $this->draw->prizes()->findOrFail($id);
            if ($prize->image_path) {
                Storage::disk('local')->delete($prize->image_path);
            }
            $prize->delete();
        }
    }

    /** Monte ou descend un lot dans la liste */
    public function movePrize(int $id, int $direction): void
    {
        $this->authorizeManage();

        if ($this->draw->isDrawn()) {
            return;
        }

        $prizes = $this->draw->prizes()->get()->values();
        $from   = $prizes->search(fn ($p) => $p->id === $id);
        $to     = $from + $direction;

        if ($from === false || $to < 0 || $to >= $prizes->count()) {
            return;
        }

        $ordered = $prizes->all();
        [$ordered[$from], $ordered[$to]] = [$ordered[$to], $ordered[$from]];

        DB::transaction(function () use ($ordered) {
            foreach ($ordered as $i => $prize) {
                $prize->update(['position' => $i + 1]);
            }
        });
    }

    // ── Étape 3 : liste des éligibles ─────────────────────────────────────

    public function computeEligibles(): void
    {
        if (!$this->saveCriteria(false)) {
            return;
        }

        $this->draw->update(['status' => 'computing', 'error' => null]);
        ComputeDrawEligibles::dispatch($this->draw->id, auth()->id());

        session()->flash('success', 'Calcul des éligibles lancé : la page se met à jour automatiquement.');
    }

    public function backToDraft(): void
    {
        $this->authorizeManage();
        $this->attempt(fn () => $this->engine()->resetToDraft($this->draw), 'Liste supprimée : les critères sont de nouveau modifiables.');
        $this->fillForm();
    }

    public function updatedParticipantSearch(): void
    {
        $this->resetPage('participants');
    }

    // ── Étape 4 : tirage lot par lot ──────────────────────────────────────

    /** Tire un gagnant pour ce lot et l'annonce dans la fenêtre de résultat */
    public function drawPrize(int $prizeId): void
    {
        $this->authorizeManage();
        $prize  = $this->draw->prizes()->findOrFail($prizeId);
        $winner = $this->attempt(fn () => $this->engine()->drawOne($prize, auth()->id()));

        $this->revealId = $winner?->id;
    }

    public function closeReveal(): void
    {
        $this->revealId = null;
    }

    public function askInvalidate(int $id): void
    {
        $this->authorizeManage();
        $this->invalidatingId = $id;
        $this->invalidReason  = '';
        $this->resetErrorBag();
    }

    public function confirmInvalidate(): void
    {
        $this->authorizeManage();
        $this->validate(['invalidReason' => 'required|string|min:3|max:500'], ['invalidReason.required' => 'Le motif est obligatoire.']);

        $winner       = $this->draw->winners()->findOrFail($this->invalidatingId);
        $wasEffective = $winner->isEffective();
        $substitute   = $this->attempt(fn () => $this->engine()->invalidate($winner, trim($this->invalidReason), auth()->id()));

        if (!$this->failed) {
            session()->flash('success', match (true) {
                $substitute !== null => 'Gagnant écarté : le suppléant ' . DrawEngine::display($substitute->msisdn) . ' le remplace.',
                $wasEffective        => 'Gagnant écarté. Plus aucun suppléant pour ce lot : lancez un tirage complémentaire si besoin.',
                default              => 'Suppléant écarté.',
            });
        }

        $this->invalidatingId = null;
    }

    public function drawExtra(int $id): void
    {
        $this->authorizeManage();
        $winner = $this->draw->winners()->findOrFail($id);
        $extra  = $this->attempt(fn () => $this->engine()->drawExtra($winner, auth()->id()));

        if (!$this->failed) {
            $extra
                ? $this->revealId = $extra->id
                : session()->flash('error', 'Plus aucun client éligible disponible pour ce lot.');
        }
    }

    public function verify(): void
    {
        $this->verifyResult = $this->engine()->verify($this->draw) + ['at' => Carbon::now()->format('d/m/Y H:i:s')];
    }

    public function closeDraw(): void
    {
        $this->authorizeManage();

        if (in_array($this->draw->status, ['drawing', 'drawn'], true)) {
            $this->draw->update(['status' => 'closed', 'closed_by' => auth()->id(), 'closed_at' => Carbon::now()]);
            session()->flash('success', 'Tirage clôturé : il est désormais en lecture seule.');
        }
    }

    public function deleteDraw(): void
    {
        $this->authorizeManage();

        if ($this->draw->isDrawn()) {
            session()->flash('error', 'Un tirage commencé ne peut pas être supprimé (traçabilité).');
            return;
        }

        Storage::disk('local')->deleteDirectory('draws/' . $this->draw->id);
        $this->draw->delete();
        $this->redirectRoute('draws.index', navigate: true);
    }

    public function with(): array
    {
        $draw      = $this->draw;
        $selected  = array_map('intval', $this->form['txn_indexes'] ?? []);
        $allTypes  = DB::table('transaction_types')->orderBy('txn_type_name')->get(['txn_index', 'txn_type_name']);
        $types     = $allTypes
            ->when($this->typeSearch, fn ($c) => $c->filter(fn ($t) => stripos($t->txn_type_name, $this->typeSearch) !== false))
            ->sortBy(fn ($t) => [in_array((int) $t->txn_index, $selected, true) ? 0 : 1, $t->txn_type_name])
            ->values();

        $prizes  = $draw->prizes()->with(['winners.invalidator:id,name', 'winners.replaces:id,msisdn'])->get();
        $winners = $prizes->flatMap->winners;

        $participants = null;
        if (in_array($draw->status, ['ready', 'drawing', 'drawn', 'closed'], true)) {
            $participants = DB::table('draw_participants')
                ->where('draw_id', $draw->id)
                ->when($this->participantSearch, fn ($q) => $q->where(fn ($q) => $q
                    ->where('msisdn', 'like', '%' . preg_replace('/\D/', '', $this->participantSearch) . '%')
                    ->orWhere('name', 'ilike', '%' . $this->participantSearch . '%')))
                ->orderByDesc('txn_count')
                ->orderBy('msisdn')
                ->paginate(15, ['*'], 'participants');
        }

        // Catalogue proposé dans la fenêtre « Ajouter un lot » (lots actifs + celui déjà choisi)
        $catalog = $this->showPrizeForm
            ? Prize::with(['category', 'mainImage'])
                ->where(fn ($q) => $q->where('is_active', true)->orWhere('id', (int) $this->prizeForm['prize_id']))
                ->get()
                ->sortBy(fn ($p) => [$p->category->position, $p->category->name, $p->name])
                ->groupBy(fn ($p) => $p->category->name)
            : collect();

        $reveal = $this->revealId ? $winners->firstWhere('id', $this->revealId) : null;

        return [
            'types'        => $types,
            'typeNames'    => $allTypes->whereIn('txn_index', $selected)->pluck('txn_type_name'),
            'prizes'       => $prizes,
            'lotsTotal'    => $prizes->sum('quantity'),
            'subsTotal'    => $prizes->sum('substitutes'),
            'effective'    => $winners->filter->isEffective()->count(),
            'replacedIds'  => $winners->pluck('replaces_id')->filter()->flip(),
            'participants' => $participants,
            'catalog'      => $catalog,
            'selectedCatalog' => $this->showPrizeForm && $this->prizeForm['prize_id']
                ? $catalog->flatten()->firstWhere('id', (int) $this->prizeForm['prize_id'])
                : null,
            'reveal'       => $reveal,
            'revealPrize'  => $reveal ? $prizes->firstWhere('id', $reveal->draw_prize_id) : null,
            'canManage'    => auth()->user()->can('draws.manage'),
        ];
    }
};
?>
<div style="padding:24px;" @if($draw->status === 'computing') wire:poll.3s @endif>

@php
    $inputStyle = 'border:1px solid #d1d5db; border-radius:7px; padding:9px 12px; font-size:13px; color:#111827; outline:none; background:#fff; width:100%; box-sizing:border-box;';
    $labelStyle = 'font-size:11px; color:#6b7280; display:block; margin-bottom:4px; font-weight:600;';
    $card       = 'background:#fff; border:1px solid #e5e7eb; border-radius:10px; margin-bottom:16px;';
    $cardHead   = 'padding:14px 18px; border-bottom:1px solid #e5e7eb; display:flex; align-items:center; justify-content:space-between; gap:12px; flex-wrap:wrap;';
    $btnPrimary = 'background:#1B2F6E; color:#fff; font-size:12px; font-weight:600; padding:8px 16px; border-radius:8px; border:none; cursor:pointer; text-decoration:none; display:inline-block;';
    $btnLight   = 'background:#E8ECF8; color:#1B2F6E; font-size:12px; font-weight:600; padding:8px 14px; border-radius:8px; border:none; cursor:pointer; text-decoration:none; display:inline-block;';
    $btnDanger  = 'background:#FDECEC; color:#B42318; font-size:11px; font-weight:600; padding:5px 10px; border-radius:6px; border:none; cursor:pointer;';
    $step       = fn ($n, $done) => '<span style="display:inline-flex; align-items:center; justify-content:center; width:22px; height:22px; border-radius:11px; font-size:11px; font-weight:700; margin-right:8px; ' . ($done ? 'background:#00843D; color:#fff;' : 'background:#E8ECF8; color:#1B2F6E;') . '">' . ($done ? '✓' : $n) . '</span>';
    $badge      = fn ($name, $color) => $name ? '<span style="display:inline-block; padding:2px 8px; border-radius:8px; font-size:10px; font-weight:700; color:#fff; background:' . e($color ?: '#6b7280') . ';">' . e($name) . '</span>' : '';
    [$sLabel, $sFg, $sBg] = $draw->statusLabel();
    $editable   = $canManage && $draw->isEditable();
    $canDraw    = $canManage && in_array($draw->status, ['ready', 'drawing'], true);
    $fmt        = fn ($n) => number_format((float) $n, 0, ',', ' ');
@endphp

    {{-- EN-TÊTE --}}
    <div style="display:flex; align-items:flex-start; justify-content:space-between; margin-bottom:16px; gap:12px; flex-wrap:wrap;">
        <div>
            <a href="{{ route('draws.index') }}" wire:navigate style="font-size:12px; color:#6b7280; text-decoration:none;">← Tirages au sort</a>
            <h2 style="font-size:17px; font-weight:700; color:#111827; margin:6px 0 4px;">
                {{ $draw->name }}
                <span style="display:inline-block; vertical-align:middle; margin-left:8px; padding:3px 10px; border-radius:10px; font-size:11px; font-weight:600; color:{{ $sFg }}; background:{{ $sBg }};">{{ $sLabel }}</span>
            </h2>
            <p style="font-size:12px; color:#9ca3af; margin:0;">
                Créé par {{ $draw->creator?->name ?? '—' }} le {{ $draw->created_at->format('d/m/Y H:i') }}
                · période du {{ $draw->period_start->format('d/m/Y') }} au {{ $draw->period_end->format('d/m/Y') }}
            </p>
        </div>
        <div style="display:flex; gap:8px; flex-wrap:wrap;">
            @if($draw->isDrawn())
                <a href="{{ route('draws.screen', $draw) }}" target="_blank" style="{{ $btnPrimary }}">Tableau d'honneur ↗</a>
                <a href="{{ route('draws.pv', $draw) }}" style="{{ $btnLight }}">PV (PDF)</a>
                <a href="{{ route('draws.winners', $draw) }}" style="{{ $btnLight }}">Gagnants (CSV)</a>
            @endif
            @if($participants)
                <a href="{{ route('draws.eligibles', $draw) }}" style="{{ $btnLight }}">Éligibles (CSV)</a>
            @endif
            @if($canManage && !$draw->isDrawn() && $draw->status !== 'computing')
                <button wire:click="deleteDraw" wire:confirm="Supprimer définitivement ce tirage ?" style="{{ $btnDanger }} padding:8px 14px; font-size:12px;">Supprimer</button>
            @endif
        </div>
    </div>

    @if(session('success'))
        <div style="background:#E5F5ED; color:#005C2B; border:1px solid #a7d7b8; border-radius:8px; padding:12px 16px; margin-bottom:16px; font-size:13px;">{{ session('success') }}</div>
    @endif
    @if(session('error') || $draw->error)
        <div style="background:#FDECEC; color:#B42318; border:1px solid #f5c2c0; border-radius:8px; padding:12px 16px; margin-bottom:16px; font-size:13px;">{{ session('error') ?? $draw->error }}</div>
    @endif

    {{-- ÉTAPE 1 : CRITÈRES ET OPTIONS --}}
    <div style="{{ $card }}">
        <div style="{{ $cardHead }}">
            <p style="font-size:14px; font-weight:700; color:#111827; margin:0;">{!! $step(1, !$draw->isEditable()) !!}Critères d'éligibilité et options</p>
            @if(!$draw->isEditable())
                <span style="font-size:11px; color:#9ca3af;">Figés depuis le calcul de la liste</span>
            @endif
        </div>
        <div style="padding:18px; display:grid; grid-template-columns:repeat(auto-fit, minmax(300px, 1fr)); gap:20px;">

            {{-- Colonne gauche : période, types, seuils --}}
            <div style="display:flex; flex-direction:column; gap:14px;">
                <div>
                    <label style="{{ $labelStyle }}">Nom du tirage</label>
                    <input type="text" wire:model="form.name" @disabled(!$editable) style="{{ $inputStyle }}">
                    @error('form.name') <p style="font-size:10px; color:#E24B4A; margin:4px 0 0;">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label style="{{ $labelStyle }}">Description</label>
                    <textarea wire:model="form.description" rows="2" @disabled(!$editable) style="{{ $inputStyle }} resize:vertical;"></textarea>
                </div>
                <div style="display:grid; grid-template-columns:1fr 1fr; gap:12px;">
                    <div>
                        <label style="{{ $labelStyle }}">Début de période</label>
                        <input type="date" wire:model="form.period_start" @disabled(!$editable) style="{{ $inputStyle }}">
                    </div>
                    <div>
                        <label style="{{ $labelStyle }}">Fin de période (incluse)</label>
                        <input type="date" wire:model="form.period_end" @disabled(!$editable) style="{{ $inputStyle }}">
                        @error('form.period_end') <p style="font-size:10px; color:#E24B4A; margin:4px 0 0;">{{ $message }}</p> @enderror
                    </div>
                </div>
                <div style="display:grid; grid-template-columns:1fr 1fr; gap:12px;">
                    <div>
                        <label style="{{ $labelStyle }}">Nombre minimum de transactions</label>
                        <input type="number" min="1" wire:model="form.min_txn_count" @disabled(!$editable) style="{{ $inputStyle }}">
                        @error('form.min_txn_count') <p style="font-size:10px; color:#E24B4A; margin:4px 0 0;">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label style="{{ $labelStyle }}">Montant minimum d'une transaction (FDJ)</label>
                        <input type="number" min="0" step="1" wire:model="form.min_txn_amount" @disabled(!$editable) style="{{ $inputStyle }}">
                        @error('form.min_txn_amount') <p style="font-size:10px; color:#E24B4A; margin:4px 0 0;">{{ $message }}</p> @enderror
                    </div>
                </div>
                <p style="font-size:10px; color:#9ca3af; margin:-8px 0 0;">Le client doit avoir au moins ce nombre de transactions, dont au moins une d'un montant supérieur ou égal au minimum (0 = pas de minimum).</p>
                <div>
                    <label style="{{ $labelStyle }}">Types de transactions ({{ count($form['txn_indexes']) }} sélectionné(s))</label>
                    @if($editable)
                        <input type="text" wire:model.live.debounce.300ms="typeSearch" placeholder="Filtrer les types…" style="{{ $inputStyle }} margin-bottom:6px;">
                        <div style="border:1px solid #e5e7eb; border-radius:7px; max-height:180px; overflow-y:auto; padding:6px 10px;">
                            @foreach($types as $t)
                                <label wire:key="type-{{ $t->txn_index }}" style="display:flex; align-items:center; gap:8px; font-size:12px; color:#374151; padding:3px 0;">
                                    <input type="checkbox" wire:model="form.txn_indexes" value="{{ $t->txn_index }}"> {{ $t->txn_type_name }}
                                </label>
                            @endforeach
                        </div>
                    @else
                        <p style="font-size:12px; color:#111827; margin:0;">{{ $typeNames->implode(', ') ?: '—' }}</p>
                    @endif
                    @error('form.txn_indexes') <p style="font-size:10px; color:#E24B4A; margin:4px 0 0;">{{ $message }}</p> @enderror
                    <p style="font-size:10px; color:#9ca3af; margin:4px 0 0;">Transactions terminées, payées par un client (compte Customer débité).</p>
                </div>
            </div>

            {{-- Colonne droite : options du tirage et exclusions --}}
            <div style="display:flex; flex-direction:column; gap:14px;">
                <div>
                    <label style="{{ $labelStyle }}">Chances de gagner</label>
                    @foreach(\App\Models\Draw::CHANCE_MODES as $key => $label)
                        <label style="display:flex; align-items:flex-start; gap:8px; font-size:12px; color:#374151; padding:3px 0;">
                            <input type="radio" wire:model="form.chance_mode" value="{{ $key }}" @disabled(!$editable) style="margin-top:2px;"> {{ $label }}
                        </label>
                    @endforeach
                </div>
                <div>
                    <label style="{{ $labelStyle }}">Lots par client</label>
                    <label style="display:flex; align-items:center; gap:8px; font-size:12px; color:#374151;">
                        <input type="checkbox" wire:model="form.one_prize_per_client" @disabled(!$editable)> Un seul lot par client (un gagnant ne peut plus sortir pour les lots tirés ensuite)
                    </label>
                </div>
                <div>
                    <label style="{{ $labelStyle }}">Exclusions</label>
                    <label style="display:flex; align-items:center; gap:8px; font-size:12px; color:#374151; padding:3px 0;">
                        <input type="checkbox" wire:model="form.exclude_employees" @disabled(!$editable)> Exclure les employés D-Money
                    </label>
                    <label style="display:flex; align-items:center; gap:8px; font-size:12px; color:#374151; padding:3px 0;">
                        <input type="checkbox" wire:model="form.exclude_rds_lite" @disabled(!$editable)> Exclure les clients RDS_LITE
                    </label>
                    <div style="display:flex; align-items:center; gap:8px; font-size:12px; color:#374151; padding:3px 0;">
                        Exclure les gagnants des
                        <input type="number" min="1" wire:model="form.exclude_winners_days" @disabled(!$editable) placeholder="—" style="{{ $inputStyle }} width:80px; padding:5px 8px;">
                        derniers jours (vide = non)
                    </div>
                    @error('form.exclude_winners_days') <p style="font-size:10px; color:#E24B4A; margin:4px 0 0;">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label style="{{ $labelStyle }}">Liste noire (un numéro par ligne)</label>
                    <textarea wire:model="form.blacklist" rows="4" @disabled(!$editable) placeholder="77 12 34 56&#10;25377654321" style="{{ $inputStyle }} resize:vertical; font-family:monospace;"></textarea>
                </div>
            </div>
        </div>
        @if($editable)
            <div style="padding:12px 18px; border-top:1px solid #e5e7eb; display:flex; justify-content:flex-end; gap:8px;">
                <button wire:click="saveCriteria" style="{{ $btnLight }}">Enregistrer les critères</button>
            </div>
        @endif
    </div>

    {{-- ÉTAPE 2 : LOTS --}}
    <div style="{{ $card }}">
        <div style="{{ $cardHead }}">
            <p style="font-size:14px; font-weight:700; color:#111827; margin:0;">{!! $step(2, $prizes->isNotEmpty()) !!}Lots à gagner
                <span style="font-size:11px; font-weight:400; color:#9ca3af; margin-left:6px;">{{ $lotsTotal }} gagnant(s), {{ $subsTotal }} suppléant(s)</span>
            </p>
            <div style="display:flex; gap:8px;">
                <a href="{{ route('draws.prizes') }}" wire:navigate style="{{ $btnLight }}">Catalogue des lots</a>
                @if($canManage && !$draw->isDrawn())
                    <button wire:click="createPrize" style="{{ $btnPrimary }}">+ Ajouter un lot</button>
                @endif
            </div>
        </div>
        <table style="width:100%; border-collapse:collapse; font-size:12px;">
            <thead>
                <tr style="background:#F7F8FC;">
                    @foreach(['', 'Lot', 'Catégorie', 'Quantité', 'Suppléants', ''] as $th)
                        <th style="padding:9px 16px; text-align:{{ in_array($th, ['Quantité', 'Suppléants']) ? 'right' : 'left' }}; color:#6b7280; font-weight:500; border-bottom:1px solid #e5e7eb;">{{ $th }}</th>
                    @endforeach
                </tr>
            </thead>
            <tbody>
                @forelse($prizes as $i => $prize)
                    <tr wire:key="prize-{{ $prize->id }}" style="border-bottom:1px solid #f3f4f6;">
                        <td style="padding:6px 16px; width:70px;">
                            @if($prize->imageUrl())
                                <img src="{{ $prize->imageUrl() }}" alt="" style="width:52px; height:40px; object-fit:cover; border-radius:6px; display:block;">
                            @endif
                        </td>
                        <td style="padding:9px 16px; color:#111827; font-weight:600;">{{ $prize->name }}</td>
                        <td style="padding:9px 16px;">{!! $badge($prize->category_name, $prize->category_color) !!}</td>
                        <td style="padding:9px 16px; text-align:right;">{{ $prize->quantity }}</td>
                        <td style="padding:9px 16px; text-align:right;">{{ $prize->substitutes }}</td>
                        <td style="padding:9px 16px; text-align:right; white-space:nowrap;">
                            @if($canManage && !$draw->isDrawn())
                                <button wire:click="movePrize({{ $prize->id }}, -1)" @disabled($i === 0) style="border:none; background:none; cursor:pointer; color:#1B2F6E;">▲</button>
                                <button wire:click="movePrize({{ $prize->id }}, 1)" @disabled($i === $prizes->count() - 1) style="border:none; background:none; cursor:pointer; color:#1B2F6E;">▼</button>
                                <button wire:click="editPrize({{ $prize->id }})" style="{{ $btnLight }} font-size:11px; padding:5px 10px;">Modifier</button>
                                <button wire:click="deletePrize({{ $prize->id }})" wire:confirm="Retirer ce lot du tirage ?" style="{{ $btnDanger }}">Retirer</button>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="6" style="padding:24px; text-align:center; color:#9ca3af;">Aucun lot pour l'instant : ajoutez-en depuis le catalogue.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    {{-- ÉTAPE 3 : ÉLIGIBLES --}}
    <div style="{{ $card }}">
        <div style="{{ $cardHead }}">
            <p style="font-size:14px; font-weight:700; color:#111827; margin:0;">{!! $step(3, !in_array($draw->status, ['draft', 'computing'])) !!}Liste des clients éligibles</p>
            <div style="display:flex; gap:8px;">
                @if($canManage && $draw->status === 'draft')
                    <button wire:click="computeEligibles" wire:loading.attr="disabled" style="{{ $btnPrimary }}">Calculer et figer la liste</button>
                @elseif($canManage && $draw->status === 'ready')
                    <button wire:click="backToDraft" wire:confirm="La liste figée sera supprimée et les critères redeviendront modifiables. Continuer ?" style="{{ $btnLight }}">Modifier les critères</button>
                @endif
            </div>
        </div>
        <div style="padding:18px;">
            @if($draw->status === 'draft')
                <p style="font-size:12px; color:#6b7280; margin:0;">Réglez les critères puis lancez le calcul. Un client ayant plusieurs transactions n'apparaît qu'une fois (doublons supprimés).</p>
            @elseif($draw->status === 'computing')
                <p style="font-size:13px; color:#92400E; margin:0;">⏳ Calcul en cours sur les transactions de la période… (la page se met à jour automatiquement)</p>
            @else
                {{-- Entonnoir --}}
                <div style="display:flex; flex-wrap:wrap; gap:10px; margin-bottom:14px;">
                    @foreach($draw->funnel ?? [] as $stepRow)
                        <div style="flex:1; min-width:150px; border:1px solid #e5e7eb; border-radius:8px; padding:10px 12px; {{ $loop->last ? 'border-top:3px solid #00843D;' : '' }}">
                            <p style="font-size:10px; color:#6b7280; margin:0 0 4px;">{{ $stepRow['label'] }}</p>
                            <p style="font-size:17px; font-weight:700; margin:0; color:{{ $stepRow['value'] < 0 ? '#B42318' : '#111827' }};">{{ $stepRow['value'] < 0 ? '−' . $fmt(-$stepRow['value']) : $fmt($stepRow['value']) }}</p>
                        </div>
                    @endforeach
                </div>
                <div style="font-size:11px; color:#6b7280; margin-bottom:14px; line-height:1.7;">
                    Liste figée le {{ $draw->computed_at?->format('d/m/Y H:i') }} par {{ $draw->computer?->name ?? '—' }}
                    · {{ $fmt($draw->ticket_count) }} chance(s) au total ({{ \App\Models\Draw::CHANCE_MODES[$draw->chance_mode] }})
                    <br>Empreinte SHA-256 : <span style="font-family:monospace; color:#111827;">{{ $draw->eligible_hash }}</span>
                </div>

                <input type="text" wire:model.live.debounce.300ms="participantSearch" placeholder="Rechercher un numéro ou un nom…" style="{{ $inputStyle }} max-width:320px; margin-bottom:10px;">
                <div style="overflow-x:auto;">
                    <table style="width:100%; border-collapse:collapse; font-size:12px;">
                        <thead>
                            <tr style="background:#F7F8FC;">
                                @foreach(['Numéro', 'Nom', 'Transactions', 'Montant total (FDJ)', 'Plus grosse txn (FDJ)', 'Dernière transaction'] as $th)
                                    <th style="padding:8px 12px; text-align:{{ str_contains($th, 'FDJ') || $th === 'Transactions' ? 'right' : 'left' }}; color:#6b7280; font-weight:500; border-bottom:1px solid #e5e7eb;">{{ $th }}</th>
                                @endforeach
                            </tr>
                        </thead>
                        <tbody>
                            @forelse($participants as $p)
                                <tr wire:key="part-{{ $p->id }}" style="border-bottom:1px solid #f3f4f6;">
                                    <td style="padding:7px 12px; font-family:monospace; color:#374151;">{{ \App\Support\DrawEngine::display($p->msisdn) }}</td>
                                    <td style="padding:7px 12px; color:#111827;">{{ $p->name ?? '—' }}</td>
                                    <td style="padding:7px 12px; text-align:right;">{{ $fmt($p->txn_count) }}</td>
                                    <td style="padding:7px 12px; text-align:right;">{{ $fmt($p->amount) }}</td>
                                    <td style="padding:7px 12px; text-align:right;">{{ $fmt($p->max_amount) }}</td>
                                    <td style="padding:7px 12px; color:#6b7280;">{{ $p->last_txn_at ? \Carbon\Carbon::parse($p->last_txn_at)->format('d/m/Y H:i') : '—' }}</td>
                                </tr>
                            @empty
                                <tr><td colspan="6" style="padding:20px; text-align:center; color:#9ca3af;">Aucun client.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
                @if($participants->hasPages())
                    <div style="padding-top:10px;">{{ $participants->links() }}</div>
                @endif
            @endif
        </div>
    </div>

    {{-- ÉTAPE 4 : TIRAGE LOT PAR LOT --}}
    <div style="{{ $card }}">
        <div style="{{ $cardHead }}">
            <p style="font-size:14px; font-weight:700; color:#111827; margin:0;">{!! $step(4, in_array($draw->status, ['drawn', 'closed'])) !!}Tirage au sort, lot par lot</p>
            <div style="display:flex; gap:8px; flex-wrap:wrap;">
                @if($draw->isDrawn())
                    <button wire:click="verify" style="{{ $btnLight }}">Vérifier l'intégrité</button>
                @endif
                @if($canManage && in_array($draw->status, ['drawing', 'drawn']))
                    <button wire:click="closeDraw" wire:confirm="Clôturer le tirage ? Plus aucun lot ne pourra être tiré et aucun gagnant écarté ou remplacé." style="{{ $btnPrimary }}">Clôturer</button>
                @endif
            </div>
        </div>
        <div style="padding:18px;">
            @if(in_array($draw->status, ['draft', 'computing']))
                <p style="font-size:12px; color:#6b7280; margin:0;">Disponible une fois la liste des éligibles figée.</p>
            @elseif($prizes->isEmpty())
                <p style="font-size:12px; color:#B42318; margin:0;">Ajoutez au moins un lot avant de lancer le tirage.</p>
            @else
                <p style="font-size:11px; color:#6b7280; margin:0 0 6px; line-height:1.7;">
                    {{ $fmt($draw->eligible_count) }} client(s) éligible(s) · {{ $effective }} gagnant(s) retenu(s) sur {{ $lotsTotal }}
                    · {{ $draw->one_prize_per_client ? 'un seul lot par client' : 'plusieurs lots possibles par client' }}
                    @if($draw->drawn_at) · tous les lots tirés le {{ $draw->drawn_at->format('d/m/Y H:i') }} @endif
                    @if($draw->closed_at) · clôturé le {{ $draw->closed_at->format('d/m/Y H:i') }} par {{ $draw->closer?->name ?? '—' }} @endif
                </p>
                @if($draw->status === 'ready')
                    <p style="font-size:12px; color:#374151; margin:0 0 6px;">Choisissez un lot et cliquez sur « Tirer au sort » : un gagnant est tiré à chaque clic. Les suppléants du lot sont tirés automatiquement après son dernier gagnant.</p>
                @endif

                @if($verifyResult)
                    @php $ok = $verifyResult['list_ok'] && $verifyResult['result_ok']; @endphp
                    <div style="margin:10px 0; padding:10px 14px; border-radius:8px; font-size:12px; {{ $ok ? 'background:#E5F5ED; color:#005C2B;' : 'background:#FDECEC; color:#B42318;' }}">
                        Contrôle du {{ $verifyResult['at'] }} :
                        liste des éligibles {{ $verifyResult['list_ok'] ? 'intacte ✓' : 'MODIFIÉE ✗' }} ·
                        tirages rejoués avec leurs graines {{ $verifyResult['result_ok'] ? 'identiques ✓' : 'DIFFÉRENTS ✗' }}
                    </div>
                @endif

                @foreach($prizes as $prize)
                    @php
                        $drawnCount = $prize->winners->where('kind', 'winner')->count();
                        $kept       = $prize->winners->filter->isEffective()->count();
                    @endphp
                    <div wire:key="result-{{ $prize->id }}" style="border:1px solid #e5e7eb; border-left:4px solid {{ $prize->category_color ?? '#1B2F6E' }}; border-radius:8px; margin-top:12px; overflow:hidden;">
                        <div style="padding:10px 14px; display:flex; align-items:center; gap:14px; flex-wrap:wrap; background:#FAFBFD;">
                            @if($prize->imageUrl())
                                <img src="{{ $prize->imageUrl() }}" alt="" style="width:64px; height:48px; object-fit:cover; border-radius:6px;">
                            @endif
                            <div style="flex:1; min-width:160px;">
                                <p style="font-size:13px; font-weight:700; color:#111827; margin:0;">{{ $prize->name }}</p>
                                <p style="margin:3px 0 0;">{!! $badge($prize->category_name, $prize->category_color) !!}
                                    <span style="font-size:11px; color:#6b7280; margin-left:6px;">{{ $drawnCount }} / {{ $prize->quantity }} tiré(s) · {{ $kept }} retenu(s)</span>
                                </p>
                            </div>
                            @if($canDraw)
                                @if($drawnCount < $prize->quantity)
                                    <button wire:click="drawPrize({{ $prize->id }})" wire:loading.attr="disabled" wire:target="drawPrize"
                                            style="background:#00843D; color:#fff; font-size:13px; font-weight:700; padding:9px 20px; border-radius:8px; border:none; cursor:pointer;">
                                        🎲 Tirer au sort{{ $prize->quantity > 1 ? ' (' . ($drawnCount + 1) . '/' . $prize->quantity . ')' : '' }}
                                    </button>
                                @else
                                    <span style="font-size:12px; font-weight:700; color:#005C2B;">✓ Lot tiré</span>
                                @endif
                            @endif
                        </div>
                        @if($prize->winners->isNotEmpty())
                            <table style="width:100%; border-collapse:collapse; font-size:12px;">
                                @foreach($prize->winners as $w)
                                    @php
                                        $isEff      = $w->isEffective();
                                        $needsExtra = $w->status === 'invalidated' && ($w->kind !== 'substitute' || $w->promoted_at) && !isset($replacedIds[$w->id])
                                                      && !$prize->winners->contains(fn ($o) => $o->isWaitingSubstitute());
                                    @endphp
                                    <tr wire:key="winner-{{ $w->id }}" style="border-top:1px solid #f3f4f6; {{ $w->status === 'invalidated' ? 'opacity:.6;' : '' }}">
                                        <td style="padding:8px 14px; white-space:nowrap; width:150px;">
                                            <span style="font-size:10px; font-weight:700; padding:2px 8px; border-radius:8px; {{ $isEff ? 'background:#E5F5ED; color:#005C2B;' : 'background:#F3F4F6; color:#6b7280;' }}">
                                                {{ $w->promoted_at ? 'Suppléant promu' : \App\Models\DrawWinner::KINDS[$w->kind] }} {{ $w->kind !== 'extra' ? '#' . $w->rank : '' }}
                                            </span>
                                        </td>
                                        <td style="padding:8px 14px; font-family:monospace; color:#111827; white-space:nowrap;">{{ \App\Support\DrawEngine::display($w->msisdn) }}</td>
                                        <td style="padding:8px 14px; color:#111827; font-weight:{{ $isEff ? '600' : '400' }};">{{ $w->name ?? '—' }}</td>
                                        <td style="padding:8px 14px; color:#6b7280; font-size:11px;">
                                            @if($w->status === 'invalidated')
                                                <span style="color:#B42318; font-weight:600;">Écarté</span> : {{ $w->invalidated_reason }}
                                                ({{ $w->invalidator?->name ?? '—' }}, {{ $w->invalidated_at?->format('d/m/Y H:i') }})
                                            @elseif($w->replaces)
                                                Remplace {{ \App\Support\DrawEngine::display($w->replaces->msisdn) }}
                                            @endif
                                        </td>
                                        <td style="padding:8px 14px; text-align:right; white-space:nowrap;">
                                            @if($w->kind !== 'substitute' || $w->promoted_at)
                                                <button wire:click="$set('revealId', {{ $w->id }})" title="Réafficher l'annonce" style="border:none; background:none; cursor:pointer; font-size:13px;">🔍</button>
                                            @endif
                                            @if($canManage && in_array($draw->status, ['drawing', 'drawn']))
                                                @if($w->status === 'valid')
                                                    <button wire:click="askInvalidate({{ $w->id }})" style="{{ $btnDanger }}">Écarter</button>
                                                @elseif($needsExtra)
                                                    <button wire:click="drawExtra({{ $w->id }})" wire:confirm="Tirer un remplaçant parmi les éligibles restants ?" style="{{ $btnLight }} font-size:11px; padding:5px 10px;">Tirage complémentaire</button>
                                                @endif
                                            @endif
                                        </td>
                                    </tr>
                                @endforeach
                            </table>
                        @endif
                    </div>
                @endforeach
            @endif
        </div>
    </div>

    {{-- FENÊTRE DE RÉSULTAT : un gagnant, fermée par l'utilisateur --}}
    {{-- Plein écran : logos + titre + catégorie, feux d'artifice et son au moment où le gagnant
         s'affiche (pas pendant le défilement). Titre, fond, durée du son et son on/off se règlent
         via ⚙ et sont mémorisés dans ce navigateur (voir drawReveal dans le script en bas de page). --}}
    @if($reveal && $revealPrize)
        <div wire:key="reveal-{{ $reveal->id }}"
             x-data="drawReveal()"
             x-on:keydown.escape.window="if (!rolling) $wire.closeReveal()"
             :style="{ background: s.bg }"
             style="position:fixed; inset:0; z-index:998; overflow-y:auto; background:#0F1D47;">

            <canvas x-ref="sky" style="position:fixed; inset:0; width:100%; height:100%; pointer-events:none; z-index:0;"></canvas>

            {{-- Boutons son / réglages --}}
            <div style="position:fixed; top:14px; right:16px; z-index:3; display:flex; gap:8px;">
                <button type="button" x-on:click="toggleSound()" :title="s.sound ? 'Couper le son' : 'Activer le son'"
                        style="width:40px; height:40px; border-radius:50%; border:none; background:rgba(255,255,255,.18); font-size:18px; cursor:pointer;"
                        x-text="s.sound ? '🔊' : '🔇'"></button>
                <button type="button" x-on:click="settingsOpen = !settingsOpen" title="Réglages de l'écran"
                        style="width:40px; height:40px; border-radius:50%; border:none; background:rgba(255,255,255,.18); font-size:18px; cursor:pointer;">⚙</button>
            </div>
            <div x-show="settingsOpen" x-cloak x-on:click.outside="settingsOpen = false"
                 style="position:fixed; top:62px; right:16px; z-index:3; width:280px; background:#fff; color:#111827; border-radius:12px; box-shadow:0 18px 50px rgba(0,0,0,.35); padding:16px; text-align:left; font-size:12px;">
                <p style="font-weight:800; font-size:13px; margin:0 0 12px;">Réglages de l'écran</p>
                <label style="display:block; color:#6b7280; margin-bottom:4px;">Titre</label>
                <input type="text" x-model="s.title" x-on:input="save()" style="width:100%; border:1px solid #d1d5db; border-radius:8px; padding:6px 8px; font-size:12px; margin-bottom:10px;">
                <label style="display:block; color:#6b7280; margin-bottom:4px;">Couleur du fond</label>
                <div style="display:flex; gap:8px; align-items:center; margin-bottom:10px;">
                    <input type="color" x-model="s.bg" x-on:input="save()" style="width:44px; height:30px; border:none; padding:0; background:none; cursor:pointer;">
                    <span x-text="s.bg" style="font-family:monospace;"></span>
                </div>
                <label style="display:block; color:#6b7280; margin-bottom:4px;">Durée du son (secondes)</label>
                <input type="number" min="1" max="30" x-model.number="s.duration" x-on:change="save()" style="width:90px; border:1px solid #d1d5db; border-radius:8px; padding:6px 8px; font-size:12px; margin-bottom:10px;">
                <label style="display:flex; align-items:center; gap:6px; margin-bottom:12px;">
                    <input type="checkbox" x-model="s.sound" x-on:change="save()"> Son activé
                </label>
                <button type="button" x-on:click="resetSettings()" style="border:1px solid #d1d5db; background:#fff; border-radius:8px; padding:6px 10px; font-size:11px; cursor:pointer;">Valeurs par défaut</button>
                <p style="color:#9ca3af; font-size:10px; margin:10px 0 0;">Réglages mémorisés sur cet ordinateur.</p>
            </div>

            <div style="position:relative; z-index:1; min-height:100%; max-width:1100px; margin:0 auto; padding:28px 24px 32px; display:flex; flex-direction:column; align-items:center; text-align:center;">

                {{-- En-tête : logo D-Money | titre | logo Djibouti Telecom --}}
                <div style="width:100%; display:flex; align-items:center; justify-content:space-between; gap:16px;">
                    <div style="flex:0 0 auto; background:#fff; border-radius:14px; padding:8px; width:clamp(64px,11vw,120px); height:clamp(64px,11vw,120px); display:flex; align-items:center; justify-content:center;">
                        <img src="{{ asset('images/qr-logo.png') }}" alt="D-Money" x-on:error="$el.parentElement.style.visibility = 'hidden'" style="max-width:100%; max-height:100%; object-fit:contain;">
                    </div>
                    <h1 x-text="s.title" :style="{ color: ink }"
                        style="flex:1 1 auto; margin:0; font-size:clamp(20px,3.4vw,40px); font-weight:900; line-height:1.15; text-transform:uppercase; letter-spacing:1px; color:#fff;"></h1>
                    <div style="flex:0 0 auto; background:#fff; border-radius:14px; padding:8px; width:clamp(64px,11vw,120px); height:clamp(64px,11vw,120px); display:flex; align-items:center; justify-content:center;">
                        <img src="{{ asset('images/qr-logo_dt.png') }}" alt="Djibouti Telecom" x-on:error="$el.parentElement.style.visibility = 'hidden'" style="max-width:100%; max-height:100%; object-fit:contain;">
                    </div>
                </div>

                <p style="margin:16px 0 22px; background:{{ $revealPrize->category_color ?? '#1B2F6E' }}; color:#fff; font-size:clamp(14px,1.8vw,20px); font-weight:800; letter-spacing:4px; text-transform:uppercase; padding:8px 26px; border-radius:999px; box-shadow:0 0 0 3px rgba(255,255,255,.35);">
                    {{ $revealPrize->category_name ?? 'Lot' }}
                </p>

                {{-- Lot + gagnant --}}
                <div style="width:min(760px,100%); background:#fff; border-radius:20px; box-shadow:0 30px 80px rgba(0,0,0,.45); padding:24px 28px 22px;">
                    @if($revealPrize->imageUrl())
                        <img src="{{ $revealPrize->imageUrl() }}" alt="" style="max-width:100%; max-height:34vh; object-fit:contain; display:block; margin:0 auto 14px;">
                    @endif
                    <p style="font-size:clamp(20px,2.6vw,30px); font-weight:800; color:#111827; margin:0;">🎁 {{ $revealPrize->name }}</p>
                    @if($reveal->kind === 'extra' || $reveal->promoted_at)
                        <p style="font-size:12px; color:#92400E; margin:4px 0 0;">Remplacement d'un gagnant écarté</p>
                    @endif

                    <div style="margin:20px 0 6px; padding:18px; border-radius:14px; background:#F7F8FC;">
                        <p style="font-size:13px; color:#6b7280; margin:0 0 6px; text-transform:uppercase; letter-spacing:2px;">Gagnant</p>
                        <p x-show="rolling" style="font-size:clamp(32px,4.4vw,52px); font-weight:800; font-family:monospace; color:#1B2F6E; margin:0; letter-spacing:3px;" x-text="roll"></p>
                        <div x-show="!rolling" x-cloak x-transition.scale.90.opacity.duration.500ms>
                            <p style="font-size:clamp(28px,4vw,46px); font-weight:900; color:#00843D; margin:0;">🎉 {{ $reveal->name ?? '—' }}</p>
                            <p style="font-size:clamp(24px,3.2vw,36px); font-weight:700; font-family:monospace; color:#111827; margin:8px 0 0; letter-spacing:2px;"
                               x-text="full ? @js(\App\Support\DrawEngine::display($reveal->msisdn)) : @js(\App\Support\DrawEngine::mask($reveal->msisdn))"></p>
                        </div>
                    </div>

                    <div style="display:flex; justify-content:space-between; align-items:center; margin-top:16px; gap:10px; flex-wrap:wrap;">
                        <label style="font-size:11px; color:#9ca3af; display:flex; align-items:center; gap:6px;">
                            <input type="checkbox" x-model="full"> Afficher le numéro complet
                        </label>
                        <button wire:click="closeReveal" :disabled="rolling"
                                style="background:#1B2F6E; color:#fff; font-size:15px; font-weight:700; padding:11px 32px; border-radius:10px; border:none; cursor:pointer;"
                                :style="rolling ? { opacity: '.4', cursor: 'wait' } : {}">Fermer</button>
                    </div>
                </div>
            </div>
        </div>
    @endif

    {{-- MODAL LOT (choix dans le catalogue) --}}
    @if($showPrizeForm)
        <div wire:click="$set('showPrizeForm', false)" style="position:fixed; inset:0; background:rgba(0,0,0,0.45); z-index:998;"></div>
        <div style="position:fixed; top:50%; left:50%; transform:translate(-50%,-50%); width:min(480px,95vw); background:#fff; border-radius:14px; z-index:999; box-shadow:0 20px 60px rgba(0,0,0,0.2);">
            <div style="padding:16px 20px; border-bottom:1px solid #e5e7eb;">
                <p style="font-size:14px; font-weight:700; color:#111827; margin:0;">{{ $editingPrizeId ? 'Modifier le lot' : 'Ajouter un lot' }}</p>
            </div>
            <div style="padding:20px; display:flex; flex-direction:column; gap:14px;">
                <div>
                    <label style="{{ $labelStyle }}">Lot du catalogue <span style="color:#E24B4A;">*</span></label>
                    <select wire:model.live="prizeForm.prize_id" style="{{ $inputStyle }}">
                        <option value="">— Choisir —</option>
                        @foreach($catalog as $categoryName => $items)
                            <optgroup label="{{ $categoryName }}">
                                @foreach($items as $item)
                                    <option value="{{ $item->id }}">{{ $item->name }}{{ $item->is_active ? '' : ' (archivé)' }}</option>
                                @endforeach
                            </optgroup>
                        @endforeach
                    </select>
                    @error('prizeForm.prize_id') <p style="font-size:10px; color:#E24B4A; margin:4px 0 0;">{{ $message }}</p> @enderror
                    @if($catalog->isEmpty())
                        <p style="font-size:11px; color:#92400E; margin:4px 0 0;">Le catalogue est vide : <a href="{{ route('draws.prizes') }}" wire:navigate>ajoutez d'abord des lots</a>.</p>
                    @endif
                </div>
                @if($selectedCatalog)
                    <div style="display:flex; gap:12px; align-items:center; background:#F7F8FC; border-radius:8px; padding:10px;">
                        @if($selectedCatalog->mainImage)
                            <img src="{{ $selectedCatalog->mainImage->url() }}" alt="" style="width:80px; height:60px; object-fit:cover; border-radius:6px;">
                        @endif
                        <div>
                            <p style="font-size:13px; font-weight:700; color:#111827; margin:0;">{{ $selectedCatalog->name }}</p>
                            <p style="margin:4px 0 0;">{!! $badge($selectedCatalog->category->name, $selectedCatalog->category->color) !!}</p>
                        </div>
                    </div>
                @endif
                <div style="display:grid; grid-template-columns:1fr 1fr; gap:12px;">
                    <div>
                        <label style="{{ $labelStyle }}">Quantité (gagnants)</label>
                        <input type="number" min="1" wire:model="prizeForm.quantity" style="{{ $inputStyle }}">
                        @error('prizeForm.quantity') <p style="font-size:10px; color:#E24B4A; margin:4px 0 0;">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label style="{{ $labelStyle }}">Suppléants</label>
                        <input type="number" min="0" wire:model="prizeForm.substitutes" style="{{ $inputStyle }}">
                        @error('prizeForm.substitutes') <p style="font-size:10px; color:#E24B4A; margin:4px 0 0;">{{ $message }}</p> @enderror
                    </div>
                </div>
                <p style="font-size:11px; color:#9ca3af; margin:0;">Les suppléants sont tirés après le dernier gagnant du lot ; ils remplacent, dans leur ordre de sortie, un gagnant écarté.</p>
            </div>
            <div style="padding:12px 20px; border-top:1px solid #e5e7eb; display:flex; justify-content:flex-end; gap:10px;">
                <button wire:click="$set('showPrizeForm', false)" style="background:#f3f4f6; color:#374151; font-size:13px; font-weight:500; padding:8px 20px; border-radius:8px; border:1px solid #e5e7eb; cursor:pointer;">Annuler</button>
                <button wire:click="savePrize" style="background:#1B2F6E; color:#fff; font-size:13px; font-weight:600; padding:8px 20px; border-radius:8px; border:none; cursor:pointer;">Enregistrer</button>
            </div>
        </div>
    @endif

    {{-- MODAL ÉCARTER UN GAGNANT --}}
    @if($invalidatingId)
        <div wire:click="$set('invalidatingId', null)" style="position:fixed; inset:0; background:rgba(0,0,0,0.45); z-index:998;"></div>
        <div style="position:fixed; top:50%; left:50%; transform:translate(-50%,-50%); width:min(460px,95vw); background:#fff; border-radius:14px; z-index:999; box-shadow:0 20px 60px rgba(0,0,0,0.2);">
            <div style="padding:16px 20px; border-bottom:1px solid #e5e7eb;">
                <p style="font-size:14px; font-weight:700; color:#111827; margin:0;">Écarter ce gagnant</p>
                <p style="font-size:11px; color:#9ca3af; margin:4px 0 0;">Le premier suppléant disponible du lot le remplacera. L'opération est tracée.</p>
            </div>
            <div style="padding:20px;">
                <label style="{{ $labelStyle }}">Motif <span style="color:#E24B4A;">*</span></label>
                <textarea wire:model="invalidReason" rows="3" placeholder="Ex. injoignable après 3 appels, compte suspendu…" style="{{ $inputStyle }} resize:vertical;"></textarea>
                @error('invalidReason') <p style="font-size:10px; color:#E24B4A; margin:4px 0 0;">{{ $message }}</p> @enderror
            </div>
            <div style="padding:12px 20px; border-top:1px solid #e5e7eb; display:flex; justify-content:flex-end; gap:10px;">
                <button wire:click="$set('invalidatingId', null)" style="background:#f3f4f6; color:#374151; font-size:13px; font-weight:500; padding:8px 20px; border-radius:8px; border:1px solid #e5e7eb; cursor:pointer;">Annuler</button>
                <button wire:click="confirmInvalidate" style="background:#B42318; color:#fff; font-size:13px; font-weight:600; padding:8px 20px; border-radius:8px; border:none; cursor:pointer;">Écarter</button>
            </div>
        </div>
    @endif

{{--
    Fenêtre de résultat : Alpine.data('drawReveal'). Le bloc doit commencer par une déclaration
    (const), sinon Livewire l'évalue comme une expression. Le son est synthétisé (Web Audio) :
    fanfare + applaudissements + détonations, aucun fichier audio à déployer.
--}}
@script
<script>
    const REVEAL_KEY = 'draws.reveal.settings';
    const REVEAL_DEFAULTS = { title: 'La grande tombola Djibouti Telecom & D-Money', bg: '#0F1D47', duration: 5, sound: true };

    // Objets navigateur (AudioContext, timers…) gardés hors de l'état réactif d'Alpine :
    // enveloppés dans un proxy, ils lèveraient « Illegal invocation ».
    Alpine.data('drawReveal', () => { const k = { timers: [], raf: null, onResize: null, ctx: null, master: null, noise: null, end: 0 }; return {
        rolling: true, full: false, roll: '', settingsOpen: false,
        s: { ...REVEAL_DEFAULTS },

        init() {
            try { Object.assign(this.s, JSON.parse(localStorage.getItem(REVEAL_KEY) || '{}')); } catch (e) {}
            const t = setInterval(() => this.roll = '2537****' + String(Math.floor(Math.random() * 1000)).padStart(3, '0'), 70);
            k.timers.push(t, setTimeout(() => { clearInterval(t); this.rolling = false; this.celebrate(); }, 2600));
        },

        destroy() {
            k.timers.forEach(id => { clearInterval(id); clearTimeout(id); });
            cancelAnimationFrame(k.raf);
            if (k.onResize) window.removeEventListener('resize', k.onResize);
            this.stopSound();
        },

        save() {
            this.s.duration = Math.min(30, Math.max(1, parseInt(this.s.duration) || REVEAL_DEFAULTS.duration));
            try { localStorage.setItem(REVEAL_KEY, JSON.stringify(this.s)); } catch (e) {}
        },

        resetSettings() { this.s = { ...REVEAL_DEFAULTS }; this.save(); },

        // Couleur du titre selon la clarté du fond choisi
        get ink() {
            const h = (this.s.bg || '#000000').replace('#', '');
            const [r, g, b] = [0, 2, 4].map(i => parseInt(h.substr(i, 2), 16) || 0);
            return (0.299 * r + 0.587 * g + 0.114 * b) > 160 ? '#111827' : '#FFFFFF';
        },

        celebrate() {
            this.fireworks();
            if (this.s.sound) this.playSound();
        },

        toggleSound() {
            this.s.sound = !this.s.sound;
            this.save();
            if (!this.s.sound) this.stopSound();
            else if (!this.rolling) this.playSound();
        },

        // ---------- Feux d'artifice (canvas), tant que la fenêtre est ouverte ----------
        fireworks() {
            const canvas = this.$refs.sky;
            if (!canvas) return;
            const c = canvas.getContext('2d');
            const fit = () => {
                const dpr = window.devicePixelRatio || 1;
                canvas.width = window.innerWidth * dpr; canvas.height = window.innerHeight * dpr;
                c.setTransform(dpr, 0, 0, dpr, 0, 0);
            };
            fit();
            k.onResize = fit;
            window.addEventListener('resize', fit);

            const rockets = [], sparks = [];
            const W = () => window.innerWidth, H = () => window.innerHeight;
            let next = 0;

            const launch = () => {
                rockets.push({ x: W() * (0.1 + Math.random() * 0.8), y: H(), vx: (Math.random() - 0.5) * 1.5,
                               vy: -(H() / 70 + Math.random() * 4), top: H() * (0.12 + Math.random() * 0.35),
                               hue: Math.floor(Math.random() * 360) });
            };
            const explode = (r) => {
                const n = 60 + Math.floor(Math.random() * 50);
                for (let i = 0; i < n; i++) {
                    const a = (Math.PI * 2 * i) / n, v = 2 + Math.random() * 4.5;
                    sparks.push({ x: r.x, y: r.y, vx: Math.cos(a) * v, vy: Math.sin(a) * v, life: 1,
                                  decay: 0.010 + Math.random() * 0.012, hue: r.hue + (Math.random() * 40 - 20) });
                }
                this.boom();
            };

            const frame = (now) => {
                // efface progressivement l'image précédente : traînées lumineuses
                c.globalCompositeOperation = 'destination-out';
                c.fillStyle = 'rgba(0,0,0,0.22)';
                c.fillRect(0, 0, W(), H());
                c.globalCompositeOperation = 'lighter';

                if (now > next) { launch(); if (Math.random() < 0.35) launch(); next = now + 350 + Math.random() * 600; }

                for (let i = rockets.length - 1; i >= 0; i--) {
                    const r = rockets[i];
                    r.x += r.vx; r.y += r.vy; r.vy += 0.12;
                    c.fillStyle = `hsl(${r.hue},100%,75%)`;
                    c.fillRect(r.x - 1.5, r.y - 1.5, 3, 3);
                    if (r.y <= r.top || r.vy >= 0) { explode(r); rockets.splice(i, 1); }
                }
                for (let i = sparks.length - 1; i >= 0; i--) {
                    const p = sparks[i];
                    p.x += p.vx; p.y += p.vy; p.vx *= 0.985; p.vy = p.vy * 0.985 + 0.05; p.life -= p.decay;
                    if (p.life <= 0) { sparks.splice(i, 1); continue; }
                    c.fillStyle = `hsla(${p.hue},100%,${55 + p.life * 25}%,${p.life})`;
                    c.beginPath(); c.arc(p.x, p.y, 2.2, 0, Math.PI * 2); c.fill();
                }
                k.raf = requestAnimationFrame(frame);
            };
            k.raf = requestAnimationFrame(frame);
        },

        // ---------- Son (Web Audio) : durée réglable, coupable à tout moment ----------
        playSound() {
            this.stopSound();
            const AC = window.AudioContext || window.webkitAudioContext;
            if (!AC) return;
            const ctx = k.ctx = new AC();
            ctx.resume?.();
            const t0 = ctx.currentTime, dur = Math.min(30, Math.max(1, +this.s.duration || 5));
            k.end = t0 + dur;

            const master = k.master = ctx.createGain();
            master.gain.setValueAtTime(0.8, t0);
            master.gain.setValueAtTime(0.8, t0 + Math.max(0, dur - 1));
            master.gain.linearRampToValueAtTime(0.0001, t0 + dur);
            master.connect(ctx.destination);

            // bruit blanc réutilisable (applaudissements, détonations)
            const noise = k.noise = ctx.createBuffer(1, ctx.sampleRate, ctx.sampleRate);
            const d = noise.getChannelData(0);
            for (let i = 0; i < d.length; i++) d[i] = Math.random() * 2 - 1;

            // fanfare : do-mi-sol puis accord final
            const note = (f, at, len, vol = 0.12) => {
                ['sawtooth', 'square'].forEach(type => {
                    const o = ctx.createOscillator(), g = ctx.createGain(), lp = ctx.createBiquadFilter();
                    o.type = type; o.frequency.value = f; lp.type = 'lowpass'; lp.frequency.value = 2400;
                    g.gain.setValueAtTime(0.0001, t0 + at);
                    g.gain.exponentialRampToValueAtTime(vol, t0 + at + 0.03);
                    g.gain.exponentialRampToValueAtTime(0.0001, t0 + at + len);
                    o.connect(lp).connect(g).connect(master);
                    o.start(t0 + at); o.stop(t0 + at + len + 0.05);
                });
            };
            [[523.25, 0], [659.25, 0.16], [783.99, 0.32]].forEach(([f, at]) => note(f, at, 0.2));
            [523.25, 659.25, 783.99, 1046.5].forEach(f => note(f, 0.5, Math.max(0.3, Math.min(1.4, dur - 0.5)), 0.08));

            // applaudissements : nombreux petits claquements filtrés jusqu'à la fin de la durée
            for (let at = 0.4; at < dur; at += 0.012 + Math.random() * 0.03) {
                const src = ctx.createBufferSource(), bp = ctx.createBiquadFilter(), g = ctx.createGain();
                src.buffer = noise; bp.type = 'bandpass'; bp.frequency.value = 900 + Math.random() * 1800; bp.Q.value = 1.2;
                g.gain.setValueAtTime(0.05 + Math.random() * 0.12, t0 + at);
                g.gain.exponentialRampToValueAtTime(0.0001, t0 + at + 0.04);
                src.connect(bp).connect(g).connect(master);
                src.start(t0 + at, Math.random() * 0.9, 0.06);
            }
        },

        // détonation à chaque explosion, seulement pendant la durée du son
        boom() {
            const ctx = k.ctx;
            if (!ctx || !this.s.sound || ctx.state === 'closed' || ctx.currentTime > k.end - 0.3) return;
            const t = ctx.currentTime, src = ctx.createBufferSource(), lp = ctx.createBiquadFilter(), g = ctx.createGain();
            src.buffer = k.noise; lp.type = 'lowpass'; lp.frequency.value = 500;
            g.gain.setValueAtTime(0.5, t); g.gain.exponentialRampToValueAtTime(0.0001, t + 0.7);
            src.connect(lp).connect(g).connect(k.master);
            src.start(t, 0, 0.75);
        },

        stopSound() {
            if (k.ctx && k.ctx.state !== 'closed') k.ctx.close();
            k.ctx = null;
        },
    }; });
</script>
@endscript

</div>
