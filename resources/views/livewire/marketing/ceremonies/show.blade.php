<?php

use App\Models\Draw;
use App\Models\DrawCeremony;
use App\Models\PrizeCategory;
use App\Support\DrawEngine;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

/**
 * Préparation d'une cérémonie : une liste d'éligibles par catégorie de lots
 * (critères, lots, liste figée, réglés sur la page de chaque liste), puis accès
 * à la salle de tirage, au tableau d'honneur, au PV et à la clôture.
 * Consultation : draws.view ; préparation et clôture : draws.manage ; salle : draws.run.
 */
new #[Layout('layouts.tombola')] class extends Component {

    public DrawCeremony $ceremony;

    public bool  $showEdit = false;
    public array $edit     = ['name' => '', 'description' => '', 'ceremony_date' => ''];

    public bool  $showAdd = false;
    public array $add     = ['prize_category_id' => '', 'period_start' => '', 'period_end' => ''];

    public ?array $verifyResult = null;

    /** Types proposés par défaut pour une nouvelle liste : paiements marchands */
    private const DEFAULT_TYPES = ['Merchant Payment', 'Online Merchant Payment'];

    public function boot(): void
    {
        abort_unless(auth()->user()?->can('draws.view'), 403);
    }

    public function mount(DrawCeremony $ceremony): void
    {
        $this->ceremony = $ceremony;
    }

    private function authorizeManage(): void
    {
        abort_unless(auth()->user()->can('draws.manage'), 403);
    }

    // ── Cérémonie ─────────────────────────────────────────────────────────

    public function openEdit(): void
    {
        $this->authorizeManage();
        $this->edit = [
            'name'          => $this->ceremony->name,
            'description'   => (string) $this->ceremony->description,
            'ceremony_date' => $this->ceremony->ceremony_date?->format('Y-m-d') ?? '',
        ];
        $this->showEdit = true;
        $this->resetErrorBag();
    }

    public function saveEdit(): void
    {
        $this->authorizeManage();
        abort_if($this->ceremony->isClosed(), 403);

        $this->validate([
            'edit.name'          => 'required|string|max:255',
            'edit.description'   => 'nullable|string|max:2000',
            'edit.ceremony_date' => 'nullable|date',
        ], ['edit.name.required' => 'Le nom est obligatoire.']);

        $this->ceremony->update([
            'name'          => trim($this->edit['name']),
            'description'   => trim($this->edit['description']) ?: null,
            'ceremony_date' => $this->edit['ceremony_date'] ?: null,
        ]);
        $this->showEdit = false;
    }

    // ── Catégories (une liste d'éligibles chacune) ───────────────────────

    public function openAdd(): void
    {
        $this->authorizeManage();
        $this->add = [
            'prize_category_id' => '',
            'period_start'      => Carbon::now()->startOfMonth()->format('Y-m-d'),
            'period_end'        => Carbon::now()->format('Y-m-d'),
        ];
        $this->showAdd = true;
        $this->resetErrorBag();
    }

    public function saveAdd(): void
    {
        $this->authorizeManage();

        if ($this->ceremony->hasStarted()) {
            session()->flash('error', 'La cérémonie a commencé : plus aucune catégorie ne peut être ajoutée.');
            $this->showAdd = false;
            return;
        }

        $used = $this->ceremony->draws()->pluck('prize_category_id')->filter()->all();

        $this->validate([
            'add.prize_category_id' => ['required', 'exists:prize_categories,id', \Illuminate\Validation\Rule::notIn($used)],
            'add.period_start'      => 'required|date',
            'add.period_end'        => 'required|date|after_or_equal:add.period_start',
        ], [
            'add.prize_category_id.required' => 'Choisissez une catégorie.',
            'add.prize_category_id.not_in'   => 'Cette catégorie a déjà sa liste dans la cérémonie.',
            'add.period_end.after_or_equal'  => 'La date de fin doit suivre la date de début.',
        ]);

        $category = PrizeCategory::findOrFail($this->add['prize_category_id']);

        $draw = Draw::create([
            'draw_ceremony_id'     => $this->ceremony->id,
            'prize_category_id'    => $category->id,
            'name'                 => $this->ceremony->name . ' — ' . $category->name,
            'period_start'         => $this->add['period_start'],
            'period_end'           => $this->add['period_end'],
            'txn_indexes'          => DB::table('transaction_types')->whereIn('txn_type_name', self::DEFAULT_TYPES)->pluck('txn_index')->map(fn ($v) => (int) $v)->all(),
            'one_prize_per_client' => true,
            'created_by'           => auth()->id(),
        ]);

        $this->redirectRoute('draws.show', $draw, navigate: true);
    }

    // ── Après le tirage ───────────────────────────────────────────────────

    public function verify(): void
    {
        $this->verifyResult = app(DrawEngine::class)->verifyCeremony($this->ceremony) + ['at' => Carbon::now()->format('d/m/Y H:i:s')];
    }

    public function closeCeremony(): void
    {
        $this->authorizeManage();

        if (!in_array($this->ceremony->status, ['in_progress', 'done'], true)) {
            return;
        }

        DB::transaction(function () {
            $now = Carbon::now();
            Draw::where('draw_ceremony_id', $this->ceremony->id)->whereIn('status', ['drawing', 'drawn'])
                ->update(['status' => 'closed', 'closed_by' => auth()->id(), 'closed_at' => $now]);
            $this->ceremony->update(['status' => 'closed', 'closed_by' => auth()->id(), 'closed_at' => $now]);
        });

        session()->flash('success', 'Cérémonie clôturée : elle est désormais en lecture seule.');
    }

    public function deleteCeremony(): void
    {
        $this->authorizeManage();

        if ($this->ceremony->hasStarted()) {
            session()->flash('error', 'Une cérémonie commencée ne peut pas être supprimée (traçabilité).');
            return;
        }

        foreach ($this->ceremony->draws()->pluck('draws.id') as $id) {
            Storage::disk('local')->deleteDirectory('draws/' . $id);
        }
        $this->ceremony->delete();   // listes, lots et éligibles supprimés en cascade

        $this->redirectRoute('draws.index', navigate: true);
    }

    public function with(): array
    {
        $this->ceremony->refresh();

        $draws = $this->ceremony->draws()
            ->with(['category', 'prizes' => fn ($q) => $q->withCount(['winners as drawn_count' => fn ($w) => $w->where('kind', 'winner')])])
            ->withCount('prizes')
            ->get();

        $used = $draws->pluck('prize_category_id')->filter()->all();

        return [
            'draws'      => $draws,
            'categories' => $this->showAdd ? PrizeCategory::whereNotIn('id', $used)->orderBy('position')->orderBy('name')->get() : collect(),
            'canAdd'     => PrizeCategory::whereNotIn('id', $used)->exists(),
            'computing'  => $draws->contains('status', 'computing'),
            'canManage'  => auth()->user()->can('draws.manage'),
            'canRun'     => auth()->user()->can('draws.run'),
        ];
    }
};
?>
<div @if($computing) wire:poll.4s @endif>
@php
    $fmt     = fn ($n) => number_format((float) $n, 0, ',', ' ');
    $ready   = $ceremony->isReady();
    $started = $ceremony->hasStarted();
    $lotsTotal  = $draws->sum(fn ($d) => $d->prizes->sum('quantity'));
    $lotsDrawn  = $draws->sum(fn ($d) => $d->prizes->sum(fn ($p) => min($p->drawn_count, $p->quantity)));
    $eligibles  = $draws->sum('eligible_count');
@endphp
<x-tombola.shell :title="$ceremony->name" eyebrow="Grand Tombola D-Money · Cérémonie" active="ceremony" :ceremony="$ceremony" :packs="$draws">
    <x-slot:status>
        <span class="tg-badge tg-s-{{ $ready && $ceremony->status === 'preparation' ? 'ready' : $ceremony->status }}">{{ $ceremony->statusLabel()[0] }}</span>
    </x-slot:status>
<div class="tg-page">

    {{-- INFORMATIONS ET ACTIONS --}}
    <div class="tg-glass tg-pad">
        <div style="display:flex; flex-direction:column; gap:14px;">
            <div style="min-width:0;">
                <p class="tg-muted">
                    @if($ceremony->ceremony_date) Cérémonie du <b style="color:var(--tg-fg);">{{ $ceremony->ceremony_date->format('d/m/Y') }}</b> · @endif
                    créée par {{ $ceremony->creator?->name ?? '—' }} le {{ $ceremony->created_at->format('d/m/Y H:i') }}
                    · un seul lot par client sur toute la cérémonie
                </p>
                @if($ceremony->description)
                    <p class="tg-text" style="margin-top:6px;">{{ $ceremony->description }}</p>
                @endif
            </div>
            <div style="display:flex; gap:8px; flex-wrap:wrap; padding-top:14px; border-top:1px solid var(--tg-stroke-soft);">
                @if($canRun && $ready && !$ceremony->isClosed())
                    <a href="{{ route('draws.room', $ceremony) }}" target="_blank" class="tg-btn tg-btn-shine">🎲 Salle de tirage ↗</a>
                @endif
                @if($started)
                    <a href="{{ route('draws.screen', $ceremony) }}" target="_blank" class="tg-btn tg-btn-ghost">Tableau d'honneur ↗</a>
                    <a href="{{ route('draws.pv', $ceremony) }}" class="tg-btn tg-btn-ghost">PV (PDF)</a>
                    <a href="{{ route('draws.winners', $ceremony) }}" class="tg-btn tg-btn-ghost">Gagnants (CSV)</a>
                    <button wire:click="verify" class="tg-btn tg-btn-ghost">Vérifier l'intégrité</button>
                @endif
                @if($canManage && in_array($ceremony->status, ['in_progress', 'done']))
                    <button wire:click="closeCeremony" wire:confirm="Clôturer la cérémonie ? Plus aucun lot ne pourra être tiré et aucun gagnant écarté ou remplacé." class="tg-btn tg-btn-ghost">Clôturer</button>
                @endif
                @if($canManage && !$ceremony->isClosed())
                    <button wire:click="openEdit" class="tg-btn tg-btn-ghost">Modifier</button>
                @endif
                @if($canManage && !$started)
                    <button wire:click="deleteCeremony" wire:confirm="Supprimer définitivement la cérémonie, ses listes d'éligibles et ses lots ?" class="tg-btn tg-btn-danger">Supprimer</button>
                @endif
            </div>
        </div>
    </div>

    @if(session('success'))
        <div class="tg-alert tg-alert-ok">{{ session('success') }}</div>
    @endif
    @if(session('error'))
        <div class="tg-alert tg-alert-ko">{{ session('error') }}</div>
    @endif

    @if($verifyResult)
        @php $ok = $verifyResult['list_ok'] && $verifyResult['result_ok']; @endphp
        <div class="tg-alert {{ $ok ? 'tg-alert-ok' : 'tg-alert-ko' }}" style="font-size:12.5px;">
            Contrôle du {{ $verifyResult['at'] }} :
            listes des éligibles {{ $verifyResult['list_ok'] ? 'intactes ✓' : 'MODIFIÉES ✗' }} ·
            tirages rejoués avec leurs graines {{ $verifyResult['result_ok'] ? 'identiques ✓' : 'DIFFÉRENTS ✗' }}
            @unless($ok)
                <br>Listes en cause :
                {{ $draws->filter(fn ($d) => isset($verifyResult['draws'][$d->id]) && !($verifyResult['draws'][$d->id]['list_ok'] && $verifyResult['draws'][$d->id]['result_ok']))->map->categoryName()->implode(', ') }}
            @endunless
        </div>
    @endif

    {{-- CHIFFRES CLÉS --}}
    <div class="tg-stats">
        <div class="tg-glass tg-stat"><p class="tg-stat-value">{{ $draws->count() }}</p><p class="tg-stat-label">Catégorie(s)</p></div>
        <div class="tg-glass tg-stat"><p class="tg-stat-value">{{ $lotsTotal }}</p><p class="tg-stat-label">Lots au total</p></div>
        <div class="tg-glass tg-stat tg-stat-accent"><p class="tg-stat-value">{{ $lotsTotal - $lotsDrawn }}</p><p class="tg-stat-label">Lots disponibles</p></div>
        <div class="tg-glass tg-stat"><p class="tg-stat-value">{{ $lotsDrawn }}</p><p class="tg-stat-label">Lots déjà tirés</p></div>
        <div class="tg-glass tg-stat"><p class="tg-stat-value">{{ $eligibles ? $fmt($eligibles) : '—' }}</p><p class="tg-stat-label">Clients éligibles</p></div>
    </div>

    {{-- PRÉPARATION : une liste par catégorie --}}
    <div class="tg-toolbar" style="margin-bottom:-6px;">
        <div>
            <p class="tg-h2">Listes d'éligibles par catégorie</p>
            <p class="tg-muted" style="margin-top:3px;">
                @if($started)
                    Listes et lots figés depuis le premier tirage.
                @elseif($ready)
                    <span style="color:var(--tg-ok); font-weight:700;">✓ Toutes les listes sont figées et ont leurs lots : la cérémonie est prête.</span>
                @else
                    Pour chaque catégorie : réglez les critères, ajoutez les lots, puis calculez et figez la liste. La salle de tirage s'ouvre quand toutes les listes sont prêtes.
                @endif
            </p>
        </div>
        @if($canManage && !$started && $canAdd)
            <button wire:click="openAdd" class="tg-btn">+ Ajouter une catégorie</button>
        @endif
    </div>

    <div class="tg-pools">
        @forelse($draws as $d)
            @php $dReady = in_array($d->status, ['ready', 'drawing', 'drawn', 'closed'], true); @endphp
            <div wire:key="pool-{{ $d->id }}" id="pack-{{ $d->id }}" class="tg-glass tg-pool" style="scroll-margin-top:20px; --c:{{ $d->categoryColor() }};">
                <div class="tg-pool-top"></div>
                <div style="padding:16px 18px 12px; display:flex; justify-content:space-between; align-items:center; gap:8px;">
                    <span class="tg-cat" style="font-size:12px; padding:5px 13px;">{{ $d->categoryName() }}</span>
                    <span class="tg-badge tg-s-{{ $d->status }}">{{ $d->statusLabel()[0] }}</span>
                </div>
                <div style="padding:0 18px 14px;" class="tg-text">
                    Transactions du {{ $d->period_start->format('d/m/Y') }} au {{ $d->period_end->format('d/m/Y') }},
                    au moins {{ $d->min_txn_count }} transaction(s){{ $d->min_txn_amount > 0 ? ', dont une ≥ ' . $fmt($d->min_txn_amount) . ' FDJ' : '' }}
                    <div style="margin-top:8px;">
                        @if($dReady)
                            <span style="font-size:24px; font-weight:900; color:var(--tg-fg); letter-spacing:-.5px;">{{ $fmt($d->eligible_count) }}</span>
                            <span class="tg-muted">client(s) éligible(s)</span>
                        @elseif($d->status === 'computing')
                            <span style="color:var(--tg-warn); font-weight:700;">⏳ Calcul en cours…</span>
                        @else
                            <span style="color:var(--tg-warn); font-weight:700;">Liste pas encore calculée</span>
                        @endif
                    </div>
                </div>
                <div class="tg-sep" style="padding:8px 18px; flex:1;">
                    @forelse($d->prizes as $p)
                        <div class="tg-pool-row">
                            @if($p->imageUrl())
                                <img src="{{ $p->imageUrl() }}" alt="" class="tg-thumb">
                            @endif
                            <span style="flex:1; color:var(--tg-fg); font-weight:600;">{{ $p->name }}</span>
                            <span style="white-space:nowrap; font-weight:700; color:{{ $p->drawn_count >= $p->quantity ? 'var(--tg-ok)' : 'var(--tg-ink-3)' }};">
                                {{ $started ? $p->drawn_count . ' / ' : '× ' }}{{ $p->quantity }}
                            </span>
                        </div>
                    @empty
                        <p style="font-size:12px; color:var(--tg-ko); margin:6px 0;">Aucun lot : ajoutez-en depuis la page de la liste.</p>
                    @endforelse
                </div>
                <div class="tg-sep" style="padding:12px 18px; text-align:right;">
                    <a href="{{ route('draws.show', $d) }}" wire:navigate class="tg-btn tg-btn-ghost tg-btn-sm">
                        {{ $canManage && !$started ? 'Configurer →' : 'Voir la liste et les résultats →' }}
                    </a>
                </div>
            </div>
        @empty
            <div class="tg-empty" style="grid-column:1/-1;">
                Aucune catégorie pour l'instant. Ajoutez par exemple Platinum, Gold et Bronze : chacune aura ses propres critères d'éligibilité et ses lots.
            </div>
        @endforelse
    </div>

    {{-- MODAL AJOUT D'UNE CATÉGORIE --}}
    @if($showAdd)
        <div wire:click="$set('showAdd', false)" class="tg-backdrop"></div>
        <div class="tg-modal" style="width:min(480px,94vw);">
            <div class="tg-modal-head">
                <p class="tg-h2">Ajouter une catégorie</p>
                <p class="tg-muted" style="margin-top:4px;">Critères, exclusions et lots se règlent ensuite sur la page de la liste.</p>
            </div>
            <div class="tg-modal-body">
                <div>
                    <label class="tg-label">Catégorie de lots <span class="tg-req">*</span></label>
                    <select wire:model="add.prize_category_id" class="tg-input">
                        <option value="">— Choisir —</option>
                        @foreach($categories as $c)
                            <option value="{{ $c->id }}">{{ $c->name }}</option>
                        @endforeach
                    </select>
                    @error('add.prize_category_id') <p class="tg-error">{{ $message }}</p> @enderror
                </div>
                <div style="display:grid; grid-template-columns:1fr 1fr; gap:12px;">
                    <div>
                        <label class="tg-label">Début de période <span class="tg-req">*</span></label>
                        <input type="date" wire:model="add.period_start" class="tg-input">
                        @error('add.period_start') <p class="tg-error">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label class="tg-label">Fin de période <span class="tg-req">*</span></label>
                        <input type="date" wire:model="add.period_end" class="tg-input">
                        @error('add.period_end') <p class="tg-error">{{ $message }}</p> @enderror
                    </div>
                </div>
            </div>
            <div class="tg-modal-foot">
                <button wire:click="$set('showAdd', false)" class="tg-btn tg-btn-ghost">Annuler</button>
                <button wire:click="saveAdd" class="tg-btn">Ajouter</button>
            </div>
        </div>
    @endif

    {{-- MODAL MODIFICATION DE LA CÉRÉMONIE --}}
    @if($showEdit)
        <div wire:click="$set('showEdit', false)" class="tg-backdrop"></div>
        <div class="tg-modal">
            <div class="tg-modal-head">
                <p class="tg-h2">Modifier la cérémonie</p>
            </div>
            <div class="tg-modal-body">
                <div>
                    <label class="tg-label">Nom <span class="tg-req">*</span></label>
                    <input type="text" wire:model="edit.name" class="tg-input">
                    @error('edit.name') <p class="tg-error">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="tg-label">Description</label>
                    <textarea wire:model="edit.description" rows="2" class="tg-input" style="resize:vertical;"></textarea>
                </div>
                <div>
                    <label class="tg-label">Date de la cérémonie</label>
                    <input type="date" wire:model="edit.ceremony_date" class="tg-input" style="max-width:200px;">
                </div>
            </div>
            <div class="tg-modal-foot">
                <button wire:click="$set('showEdit', false)" class="tg-btn tg-btn-ghost">Annuler</button>
                <button wire:click="saveEdit" class="tg-btn">Enregistrer</button>
            </div>
        </div>
    @endif

</div>
</x-tombola.shell>
</div>
