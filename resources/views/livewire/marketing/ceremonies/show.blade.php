<?php

use App\Models\Draw;
use App\Models\DrawCeremony;
use App\Models\PrizeCategory;
use App\Support\DrawEngine;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Livewire\Volt\Component;

/**
 * Préparation d'une cérémonie : une liste d'éligibles par catégorie de lots
 * (critères, lots, liste figée, réglés sur la page de chaque liste), puis accès
 * à la salle de tirage, au tableau d'honneur, au PV et à la clôture.
 * Consultation : draws.view ; préparation et clôture : draws.manage ; salle : draws.run.
 */
new class extends Component {

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
<div style="padding:24px;" @if($computing) wire:poll.4s @endif>

@php
    $inputStyle = 'border:1px solid #d1d5db; border-radius:7px; padding:9px 12px; font-size:13px; color:#111827; outline:none; background:#fff; width:100%; box-sizing:border-box;';
    $labelStyle = 'font-size:11px; color:#6b7280; display:block; margin-bottom:4px; font-weight:600;';
    $btnPrimary = 'background:#1B2F6E; color:#fff; font-size:12px; font-weight:600; padding:8px 16px; border-radius:8px; border:none; cursor:pointer; text-decoration:none; display:inline-block;';
    $btnLight   = 'background:#E8ECF8; color:#1B2F6E; font-size:12px; font-weight:600; padding:8px 14px; border-radius:8px; border:none; cursor:pointer; text-decoration:none; display:inline-block;';
    $btnDanger  = 'background:#FDECEC; color:#B42318; font-size:12px; font-weight:600; padding:8px 14px; border-radius:8px; border:none; cursor:pointer;';
    $fmt        = fn ($n) => number_format((float) $n, 0, ',', ' ');
    [$sLabel, $sFg, $sBg] = $ceremony->statusLabel();
    $ready   = $ceremony->isReady();
    $started = $ceremony->hasStarted();
@endphp

    {{-- EN-TÊTE --}}
    <div style="display:flex; align-items:flex-start; justify-content:space-between; margin-bottom:16px; gap:12px; flex-wrap:wrap;">
        <div>
            <a href="{{ route('draws.index') }}" wire:navigate style="font-size:12px; color:#6b7280; text-decoration:none;">← Tirages au sort</a>
            <h2 style="font-size:17px; font-weight:700; color:#111827; margin:6px 0 4px;">
                {{ $ceremony->name }}
                <span style="display:inline-block; vertical-align:middle; margin-left:8px; padding:3px 10px; border-radius:10px; font-size:11px; font-weight:600; color:{{ $sFg }}; background:{{ $sBg }};">{{ $sLabel }}</span>
            </h2>
            <p style="font-size:12px; color:#9ca3af; margin:0;">
                @if($ceremony->ceremony_date) Cérémonie du {{ $ceremony->ceremony_date->format('d/m/Y') }} · @endif
                créée par {{ $ceremony->creator?->name ?? '—' }} le {{ $ceremony->created_at->format('d/m/Y H:i') }}
                · un seul lot par client sur toute la cérémonie
            </p>
            @if($ceremony->description)
                <p style="font-size:12px; color:#374151; margin:6px 0 0;">{{ $ceremony->description }}</p>
            @endif
        </div>
        <div style="display:flex; gap:8px; flex-wrap:wrap;">
            @if($canRun && $ready && !$ceremony->isClosed())
                <a href="{{ route('draws.room', $ceremony) }}" target="_blank" style="background:#00843D; color:#fff; font-size:12px; font-weight:700; padding:8px 16px; border-radius:8px; text-decoration:none;">🎲 Salle de tirage ↗</a>
            @endif
            @if($started)
                <a href="{{ route('draws.screen', $ceremony) }}" target="_blank" style="{{ $btnPrimary }}">Tableau d'honneur ↗</a>
                <a href="{{ route('draws.pv', $ceremony) }}" style="{{ $btnLight }}">PV (PDF)</a>
                <a href="{{ route('draws.winners', $ceremony) }}" style="{{ $btnLight }}">Gagnants (CSV)</a>
                <button wire:click="verify" style="{{ $btnLight }}">Vérifier l'intégrité</button>
            @endif
            @if($canManage && in_array($ceremony->status, ['in_progress', 'done']))
                <button wire:click="closeCeremony" wire:confirm="Clôturer la cérémonie ? Plus aucun lot ne pourra être tiré et aucun gagnant écarté ou remplacé." style="{{ $btnPrimary }}">Clôturer</button>
            @endif
            @if($canManage && !$ceremony->isClosed())
                <button wire:click="openEdit" style="{{ $btnLight }}">Modifier</button>
            @endif
            @if($canManage && !$started)
                <button wire:click="deleteCeremony" wire:confirm="Supprimer définitivement la cérémonie, ses listes d'éligibles et ses lots ?" style="{{ $btnDanger }}">Supprimer</button>
            @endif
        </div>
    </div>

    @if(session('success'))
        <div style="background:#E5F5ED; color:#005C2B; border:1px solid #a7d7b8; border-radius:8px; padding:12px 16px; margin-bottom:16px; font-size:13px;">{{ session('success') }}</div>
    @endif
    @if(session('error'))
        <div style="background:#FDECEC; color:#B42318; border:1px solid #f5c2c0; border-radius:8px; padding:12px 16px; margin-bottom:16px; font-size:13px;">{{ session('error') }}</div>
    @endif

    @if($verifyResult)
        @php $ok = $verifyResult['list_ok'] && $verifyResult['result_ok']; @endphp
        <div style="margin-bottom:16px; padding:10px 14px; border-radius:8px; font-size:12px; {{ $ok ? 'background:#E5F5ED; color:#005C2B;' : 'background:#FDECEC; color:#B42318;' }}">
            Contrôle du {{ $verifyResult['at'] }} :
            listes des éligibles {{ $verifyResult['list_ok'] ? 'intactes ✓' : 'MODIFIÉES ✗' }} ·
            tirages rejoués avec leurs graines {{ $verifyResult['result_ok'] ? 'identiques ✓' : 'DIFFÉRENTS ✗' }}
            @unless($ok)
                <br>Listes en cause :
                {{ $draws->filter(fn ($d) => isset($verifyResult['draws'][$d->id]) && !($verifyResult['draws'][$d->id]['list_ok'] && $verifyResult['draws'][$d->id]['result_ok']))->map->categoryName()->implode(', ') }}
            @endunless
        </div>
    @endif

    {{-- PRÉPARATION : une liste par catégorie --}}
    <div style="display:flex; align-items:center; justify-content:space-between; gap:12px; margin-bottom:10px; flex-wrap:wrap;">
        <div>
            <p style="font-size:14px; font-weight:700; color:#111827; margin:0;">Listes d'éligibles par catégorie</p>
            <p style="font-size:11px; color:#9ca3af; margin:2px 0 0;">
                @if($started)
                    Listes et lots figés depuis le premier tirage.
                @elseif($ready)
                    ✓ Toutes les listes sont figées et ont leurs lots : la cérémonie est prête.
                @else
                    Pour chaque catégorie : réglez les critères, ajoutez les lots, puis calculez et figez la liste. La salle de tirage s'ouvre quand toutes les listes sont prêtes.
                @endif
            </p>
        </div>
        @if($canManage && !$started && $canAdd)
            <button wire:click="openAdd" style="{{ $btnPrimary }}">+ Ajouter une catégorie</button>
        @endif
    </div>

    <div style="display:grid; grid-template-columns:repeat(auto-fill, minmax(320px, 1fr)); gap:14px;">
        @forelse($draws as $d)
            @php
                [$dLabel, $dFg, $dBg] = $d->statusLabel();
                $dReady = in_array($d->status, ['ready', 'drawing', 'drawn', 'closed'], true);
            @endphp
            <div wire:key="pool-{{ $d->id }}" style="background:#fff; border:1px solid #e5e7eb; border-top:4px solid {{ $d->categoryColor() }}; border-radius:10px; display:flex; flex-direction:column;">
                <div style="padding:14px 16px; display:flex; justify-content:space-between; align-items:center; gap:8px;">
                    <span style="display:inline-block; padding:4px 12px; border-radius:8px; font-size:12px; font-weight:800; letter-spacing:1px; text-transform:uppercase; color:#fff; background:{{ $d->categoryColor() }};">{{ $d->categoryName() }}</span>
                    <span style="padding:3px 10px; border-radius:10px; font-size:11px; font-weight:600; color:{{ $dFg }}; background:{{ $dBg }};">{{ $dLabel }}</span>
                </div>
                <div style="padding:0 16px 12px; font-size:12px; color:#374151; line-height:1.7;">
                    Transactions du {{ $d->period_start->format('d/m/Y') }} au {{ $d->period_end->format('d/m/Y') }},
                    au moins {{ $d->min_txn_count }} transaction(s){{ $d->min_txn_amount > 0 ? ', dont une ≥ ' . $fmt($d->min_txn_amount) . ' FDJ' : '' }}
                    <br>
                    @if($dReady)
                        <strong style="font-size:15px;">{{ $fmt($d->eligible_count) }}</strong> client(s) éligible(s)
                    @elseif($d->status === 'computing')
                        <span style="color:#92400E;">⏳ Calcul en cours…</span>
                    @else
                        <span style="color:#92400E;">Liste pas encore calculée</span>
                    @endif
                </div>
                <div style="border-top:1px solid #f3f4f6; padding:10px 16px; flex:1;">
                    @forelse($d->prizes as $p)
                        <div style="display:flex; align-items:center; gap:10px; padding:4px 0; font-size:12px;">
                            @if($p->imageUrl())
                                <img src="{{ $p->imageUrl() }}" alt="" style="width:38px; height:28px; object-fit:cover; border-radius:4px;">
                            @endif
                            <span style="flex:1; color:#111827;">{{ $p->name }}</span>
                            <span style="color:{{ $p->drawn_count >= $p->quantity ? '#005C2B' : '#6b7280' }}; white-space:nowrap;">
                                {{ $started ? $p->drawn_count . ' / ' : '× ' }}{{ $p->quantity }}
                            </span>
                        </div>
                    @empty
                        <p style="font-size:12px; color:#B42318; margin:0;">Aucun lot : ajoutez-en depuis la page de la liste.</p>
                    @endforelse
                </div>
                <div style="padding:10px 16px; border-top:1px solid #f3f4f6; text-align:right;">
                    <a href="{{ route('draws.show', $d) }}" wire:navigate style="{{ $btnLight }} font-size:11px; padding:6px 12px;">
                        {{ $canManage && !$started ? 'Configurer →' : 'Voir la liste et les résultats →' }}
                    </a>
                </div>
            </div>
        @empty
            <div style="grid-column:1/-1; background:#fff; border:1px dashed #d1d5db; border-radius:10px; padding:30px; text-align:center; color:#9ca3af; font-size:13px;">
                Aucune catégorie pour l'instant. Ajoutez par exemple Platinum, Gold et Bronze : chacune aura ses propres critères d'éligibilité et ses lots.
            </div>
        @endforelse
    </div>

    {{-- MODAL AJOUT D'UNE CATÉGORIE --}}
    @if($showAdd)
        <div wire:click="$set('showAdd', false)" style="position:fixed; inset:0; background:rgba(0,0,0,0.45); z-index:998;"></div>
        <div style="position:fixed; top:50%; left:50%; transform:translate(-50%,-50%); width:min(480px,95vw); background:#fff; border-radius:14px; z-index:999; box-shadow:0 20px 60px rgba(0,0,0,0.2);">
            <div style="padding:16px 20px; border-bottom:1px solid #e5e7eb;">
                <p style="font-size:14px; font-weight:700; color:#111827; margin:0;">Ajouter une catégorie</p>
                <p style="font-size:11px; color:#9ca3af; margin:4px 0 0;">Critères, exclusions et lots se règlent ensuite sur la page de la liste.</p>
            </div>
            <div style="padding:20px; display:flex; flex-direction:column; gap:14px;">
                <div>
                    <label style="{{ $labelStyle }}">Catégorie de lots <span style="color:#E24B4A;">*</span></label>
                    <select wire:model="add.prize_category_id" style="{{ $inputStyle }}">
                        <option value="">— Choisir —</option>
                        @foreach($categories as $c)
                            <option value="{{ $c->id }}">{{ $c->name }}</option>
                        @endforeach
                    </select>
                    @error('add.prize_category_id') <p style="font-size:10px; color:#E24B4A; margin:4px 0 0;">{{ $message }}</p> @enderror
                </div>
                <div style="display:grid; grid-template-columns:1fr 1fr; gap:12px;">
                    <div>
                        <label style="{{ $labelStyle }}">Début de période <span style="color:#E24B4A;">*</span></label>
                        <input type="date" wire:model="add.period_start" style="{{ $inputStyle }}">
                        @error('add.period_start') <p style="font-size:10px; color:#E24B4A; margin:4px 0 0;">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label style="{{ $labelStyle }}">Fin de période <span style="color:#E24B4A;">*</span></label>
                        <input type="date" wire:model="add.period_end" style="{{ $inputStyle }}">
                        @error('add.period_end') <p style="font-size:10px; color:#E24B4A; margin:4px 0 0;">{{ $message }}</p> @enderror
                    </div>
                </div>
            </div>
            <div style="padding:12px 20px; border-top:1px solid #e5e7eb; display:flex; justify-content:flex-end; gap:10px;">
                <button wire:click="$set('showAdd', false)" style="background:#f3f4f6; color:#374151; font-size:13px; font-weight:500; padding:8px 20px; border-radius:8px; border:1px solid #e5e7eb; cursor:pointer;">Annuler</button>
                <button wire:click="saveAdd" style="background:#1B2F6E; color:#fff; font-size:13px; font-weight:600; padding:8px 20px; border-radius:8px; border:none; cursor:pointer;">Ajouter</button>
            </div>
        </div>
    @endif

    {{-- MODAL MODIFICATION DE LA CÉRÉMONIE --}}
    @if($showEdit)
        <div wire:click="$set('showEdit', false)" style="position:fixed; inset:0; background:rgba(0,0,0,0.45); z-index:998;"></div>
        <div style="position:fixed; top:50%; left:50%; transform:translate(-50%,-50%); width:min(500px,95vw); background:#fff; border-radius:14px; z-index:999; box-shadow:0 20px 60px rgba(0,0,0,0.2);">
            <div style="padding:16px 20px; border-bottom:1px solid #e5e7eb;">
                <p style="font-size:14px; font-weight:700; color:#111827; margin:0;">Modifier la cérémonie</p>
            </div>
            <div style="padding:20px; display:flex; flex-direction:column; gap:14px;">
                <div>
                    <label style="{{ $labelStyle }}">Nom <span style="color:#E24B4A;">*</span></label>
                    <input type="text" wire:model="edit.name" style="{{ $inputStyle }}">
                    @error('edit.name') <p style="font-size:10px; color:#E24B4A; margin:4px 0 0;">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label style="{{ $labelStyle }}">Description</label>
                    <textarea wire:model="edit.description" rows="2" style="{{ $inputStyle }} resize:vertical;"></textarea>
                </div>
                <div>
                    <label style="{{ $labelStyle }}">Date de la cérémonie</label>
                    <input type="date" wire:model="edit.ceremony_date" style="{{ $inputStyle }} max-width:200px;">
                </div>
            </div>
            <div style="padding:12px 20px; border-top:1px solid #e5e7eb; display:flex; justify-content:flex-end; gap:10px;">
                <button wire:click="$set('showEdit', false)" style="background:#f3f4f6; color:#374151; font-size:13px; font-weight:500; padding:8px 20px; border-radius:8px; border:1px solid #e5e7eb; cursor:pointer;">Annuler</button>
                <button wire:click="saveEdit" style="background:#1B2F6E; color:#fff; font-size:13px; font-weight:600; padding:8px 20px; border-radius:8px; border:none; cursor:pointer;">Enregistrer</button>
            </div>
        </div>
    @endif

</div>
