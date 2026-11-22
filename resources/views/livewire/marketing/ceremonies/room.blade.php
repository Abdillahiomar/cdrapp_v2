<?php

use App\Models\DrawCeremony;
use App\Models\DrawPrize;
use App\Support\DrawEngine;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Volt\Component;

/**
 * Salle de tirage (jour J) : les lots de la cérémonie, groupés par catégorie.
 * « TIRER » ouvre la page de révélation du lot (?lot=…) ; « LANCER LE TIRAGE »
 * tire le gagnant côté serveur (DrawEngine::drawOne, transaction + verrous),
 * l'animation n'est qu'un habillage du résultat déjà enregistré.
 * Permission : draws.run.
 */
new #[Layout('layouts.tombola')] class extends Component {

    public DrawCeremony $ceremony;

    /** Lot affiché sur la page de révélation (null : liste des lots) */
    #[Url(as: 'lot', history: true)]
    public ?int $lotId = null;

    public ?string $error = null;

    public function boot(): void
    {
        abort_unless(auth()->user()?->can('draws.view') && auth()->user()->can('draws.run'), 403);
    }

    public function mount(DrawCeremony $ceremony): void
    {
        $this->ceremony = $ceremony;
        if ($this->lotId && !$this->findPrize($this->lotId)) {
            $this->lotId = null;
        }
    }

    private function findPrize(int $prizeId): ?DrawPrize
    {
        return DrawPrize::whereKey($prizeId)
            ->whereHas('draw', fn ($q) => $q->where('draw_ceremony_id', $this->ceremony->id))
            ->first();
    }

    public function openLot(int $prizeId): void
    {
        $this->error = null;
        $this->lotId = $this->findPrize($prizeId)?->id;
    }

    public function backToRoom(): void
    {
        $this->error = null;
        $this->lotId = null;
    }

    /**
     * Tire un gagnant pour le lot. Le résultat est enregistré avant toute
     * animation ; on ne renvoie au navigateur que le numéro masqué de fin de défilement.
     */
    public function launch(int $prizeId): array
    {
        $this->error = null;
        $prize = $this->findPrize($prizeId) ?? abort(404);

        try {
            $winner = app(DrawEngine::class)->drawOne($prize, auth()->id());
            $this->lotId = $prize->id;

            return ['ok' => true, 'masked' => DrawEngine::mask($winner->msisdn)];
        } catch (\RuntimeException $e) {
            $this->error = $e->getMessage();

            return ['ok' => false];
        }
    }

    public function with(): array
    {
        $this->ceremony->refresh();

        $draws = $this->ceremony->draws()
            ->with(['category', 'prizes' => fn ($q) => $q->withCount(['winners as drawn_count' => fn ($w) => $w->where('kind', 'winner')])])
            ->get()
            ->filter(fn ($d) => $d->prizes->isNotEmpty());

        $lot = null;
        if ($this->lotId) {
            $draw = $draws->first(fn ($d) => $d->prizes->contains('id', $this->lotId));
            if ($draw) {
                $prize = $draw->prizes->firstWhere('id', $this->lotId);
                $prize->load('winners.replaces');
                $lot = [
                    'draw'    => $draw,
                    'prize'   => $prize,
                    'rank'    => $draw->prizes->search(fn ($p) => $p->id === $prize->id) + 1,
                    'types'   => $draw->typeLabels()->implode(', '),
                    'winners' => $prize->winners->whereIn('kind', ['winner', 'extra'])->values(),
                    'subs'    => $prize->winners->where('kind', 'substitute')->values(),
                    'open'    => in_array($draw->status, ['ready', 'drawing'], true) && !$this->ceremony->isClosed(),
                ];
            }
        }

        return [
            'draws' => $draws,
            'ready' => $this->ceremony->isReady(),
            'lot'   => $lot,
        ];
    }
};
?>
<div>
@php
    $fmt       = fn ($n) => number_format((float) $n, 0, ',', ' ');
    $lotsTotal = $draws->sum(fn ($d) => $d->prizes->sum('quantity'));
    $lotsDrawn = $draws->sum(fn ($d) => $d->prizes->sum(fn ($p) => min($p->drawn_count, $p->quantity)));
    $tickets   = $draws->sum(fn ($d) => $d->ticket_count ?? $d->eligible_count ?? 0);
    $icon      = fn ($path, $color = '#fff') => '<svg viewBox="0 0 24 24" fill="none" stroke="' . $color . '" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">' . $path . '</svg>';
    $code      = fn ($d) => mb_strtoupper(mb_substr(\Illuminate\Support\Str::ascii($d->categoryName()), 0, 3));
    $giftPath  = '<rect x="3" y="8" width="18" height="4" rx="1"/><path d="M5 12v9h14v-9M12 8v13M12 8S10.5 3 8 4s0 4 4 4zM12 8s1.5-5 4-4 0 4-4 4z"/>';
@endphp
<x-tombola.shell :title="$lot ? 'Révélation du gagnant' : 'Salle du tirage'" :eyebrow="'Grand Tombola D-Money · ' . $ceremony->name" active="room" :ceremony="$ceremony" :packs="$draws">
    <x-slot:status>
        <span class="tg-badge tg-s-{{ $ready && $ceremony->status === 'preparation' ? 'ready' : $ceremony->status }}">{{ $ceremony->statusLabel()[0] }}</span>
    </x-slot:status>

<div class="tg-page">

@if($lot)
    {{-- ══════════ PAGE DE RÉVÉLATION D'UN LOT ══════════ --}}
    @php
        $d       = $lot['draw'];
        $p       = $lot['prize'];
        $ref     = $code($d) . '-' . str_pad($lot['rank'], 2, '0', STR_PAD_LEFT);
        $drawn   = $p->drawn_count;
        $remains = $drawn < $p->quantity;
        $current = $lot['winners']->last();
        $pool    = $d->ticket_count ?? $d->eligible_count;
    @endphp

    <div class="tg-toolbar">
        <button wire:click="backToRoom" class="tg-btn tg-btn-ghost">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M15 18l-6-6 6-6"/></svg>
            Retour à la salle
        </button>
        <span class="tg-chip"><b>{{ $drawn }}</b> / {{ $p->quantity }} gagnant(s) tiré(s) pour ce lot</span>
    </div>

    <div class="tg-reveal" style="--c:{{ $d->categoryColor() }};">

        {{-- Lot en grand --}}
        <div class="tg-glass tg-hero">
            <div class="tg-hero-visual">
                <div class="tg-rays"></div>
                @if($p->imageUrl())
                    <img src="{{ $p->imageUrl() }}" alt="{{ $p->name }}">
                @else
                    <span class="tg-hero-icon">{!! $icon($giftPath, $d->categoryColor()) !!}</span>
                @endif
            </div>
            <div class="tg-hero-details">
                <span class="tg-cat">Pack {{ $d->categoryName() }}</span>
                <h2 class="tg-hero-title">{{ $p->name }}</h2>
                @if($lot['types'])
                    <p class="tg-muted" style="margin:-6px 0 14px;">{{ $lot['types'] }}</p>
                @endif
                <div class="tg-kv">
                    <div><small>Pack</small><span>{{ $d->categoryName() }}</span></div>
                    <div><small>Rang</small><span>{{ $lot['rank'] }}</span></div>
                    <div><small>Référence</small><span class="tg-mono" style="font-size:12.5px;">{{ $ref }}</span></div>
                </div>
            </div>
        </div>

        {{-- Tirage + gagnant --}}
        <div style="display:flex; flex-direction:column; gap:20px; min-width:0;">
            <div class="tg-glass tg-stage" wire:key="stage-{{ $p->id }}"
                 x-data="tgDraw({{ $current ? 'true' : 'false' }})">

                @if($error)
                    <div class="tg-alert tg-alert-ko" style="margin-bottom:14px;">{{ $error }}</div>
                @endif

                {{-- Avant / pendant le tirage --}}
                <div x-show="phase !== 'done'" @if($current) x-cloak @endif>
                    <p class="tg-eyebrow" style="letter-spacing:3px;">Tirage au sort</p>
                    <p class="tg-text" style="margin-top:6px;">
                        @if($pool !== null)
                            Un gagnant va être tiré parmi les <b style="color:#fff;">{{ $fmt($pool) }}</b> {{ $d->ticket_count !== null ? 'tickets' : 'clients' }} éligibles du pack {{ $d->categoryName() }}.
                        @else
                            Liste des éligibles du pack {{ $d->categoryName() }}.
                        @endif
                    </p>
                    <div class="tg-slot" :class="{ 'is-rolling': phase === 'rolling' }">
                        <div class="tg-slot-number" x-text="roll" :style="settled ? 'color:#F5C400' : ''">2537****•••</div>
                        <div class="tg-slot-sub" x-text="sub">Numéro du gagnant</div>
                    </div>
                    <div class="tg-progress"><i :style="'width:' + progress + '%'"></i></div>
                    @if($remains && $lot['open'] && $ready)
                        <button class="tg-btn tg-btn-shine tg-launch" :disabled="phase !== 'idle'"
                                x-on:click="launch({{ $p->id }}, @js('Lancer le tirage du lot « ' . $p->name . ' » (Pack ' . $d->categoryName() . ', rang ' . $lot['rank'] . ') ?'))">
                            <span x-text="phase === 'idle' ? @js($drawn ? 'TIRER LE GAGNANT SUIVANT' : 'LANCER LE TIRAGE') : 'TIRAGE EN COURS…'">LANCER LE TIRAGE</span>
                        </button>
                    @elseif(!$remains)
                        <div class="tg-state is-ok" style="max-width:360px; margin:0 auto;">✓ LOT DÉJÀ TIRÉ</div>
                    @else
                        <div class="tg-state" style="max-width:360px; margin:0 auto;">TIRAGE NON DISPONIBLE</div>
                    @endif
                </div>

                {{-- Gagnant --}}
                @if($current)
                    <div x-show="phase === 'done'" x-cloak>
                        <div class="tg-winner">
                            <div class="tg-winner-top tg-step" :class="shown > 0 && 'in'">
                                <div style="display:flex; align-items:center; gap:12px;">
                                    <div class="tg-trophy">{!! $icon('<path d="M8 21h8M12 17v4M7 4h10v5a5 5 0 0 1-10 0z"/><path d="M17 5h3v2a3 3 0 0 1-3 3M7 5H4v2a3 3 0 0 0 3 3"/>', '#10264B') !!}</div>
                                    <div>
                                        <p class="tg-eyebrow" style="letter-spacing:2px;" x-text="fresh ? 'Félicitations !' : 'Gagnant du lot'">Gagnant du lot</p>
                                        <p class="tg-muted" style="font-size:12.5px;">{{ $p->name }}</p>
                                    </div>
                                </div>
                                @if($current->status !== 'valid')
                                    <span class="tg-provisional is-ko">Écarté</span>
                                @else
                                    <span class="tg-provisional">Gagnant provisoire</span>
                                @endif
                            </div>
                            <div class="tg-step" :class="shown > 1 && 'in'">
                                <div class="tg-winner-name">{{ $current->name ?? '—' }}</div>
                                @if($current->kind === 'extra')
                                    <p class="tg-muted" style="margin-top:4px;">Tirage complémentaire (remplacement d'un gagnant écarté)</p>
                                @endif
                            </div>
                            <div class="tg-txn tg-step" :class="shown > 2 && 'in'">
                                <div style="min-width:0;">
                                    <small>Numéro de téléphone</small>
                                    <code x-text="full ? @js(\App\Support\DrawEngine::display($current->msisdn)) : @js(\App\Support\DrawEngine::mask($current->msisdn))">{{ \App\Support\DrawEngine::mask($current->msisdn) }}</code>
                                </div>
                                <div style="display:flex; gap:8px;">
                                    <button type="button" class="tg-copy" x-on:click="full = !full" :title="full ? 'Masquer le numéro' : 'Afficher le numéro complet'">
                                        <svg x-show="!full" viewBox="0 0 24 24" fill="none" stroke="#fff" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7S2 12 2 12z"/><circle cx="12" cy="12" r="3"/></svg>
                                        <svg x-show="full" x-cloak viewBox="0 0 24 24" fill="none" stroke="#fff" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M3 3l18 18M10.6 5.1A10 10 0 0 1 12 5c6.5 0 10 7 10 7a17 17 0 0 1-3.2 4.2M6.6 6.6A17 17 0 0 0 2 12s3.5 7 10 7a9.7 9.7 0 0 0 5.4-1.6"/></svg>
                                    </button>
                                    <button type="button" class="tg-copy" x-show="full" x-cloak title="Copier le numéro"
                                            x-on:click="copy(@js(\App\Support\DrawEngine::display($current->msisdn)))">
                                        <svg viewBox="0 0 24 24" fill="none" stroke="#fff" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><rect x="9" y="9" width="12" height="12" rx="2"/><path d="M5 15H4a1 1 0 0 1-1-1V4a1 1 0 0 1 1-1h10a1 1 0 0 1 1 1v1"/></svg>
                                    </button>
                                </div>
                            </div>
                            <div class="tg-winner-grid tg-step" :class="shown > 3 && 'in'">
                                <div><small>Tickets sur la période</small><span>{{ $fmt($current->txn_count) }}</span></div>
                                <div><small>Lot remporté</small><span>{{ $p->name }}</span></div>
                                <div><small>Tiré le</small><span>{{ $current->created_at?->format('d/m/Y à H:i:s') }}</span></div>
                                <div><small>Gagnant</small><span>{{ $lot['winners']->count() }} / {{ $p->quantity }}</span></div>
                            </div>
                        </div>
                        <p class="tg-muted tg-step" :class="shown > 3 && 'in'" style="text-align:center; margin-top:14px;">En attente de validation par les équipes habilitées.</p>

                        @if($remains && $lot['open'] && $ready)
                            <div class="tg-step" :class="shown > 3 && 'in'" style="text-align:center; margin-top:16px;">
                                <button class="tg-btn tg-launch" x-on:click="again()">TIRER LE GAGNANT SUIVANT</button>
                            </div>
                        @endif

                        @if($lot['winners']->count() > 1)
                            <div class="tg-step" :class="shown > 3 && 'in'" style="margin-top:16px;">
                                <p class="tg-label">Gagnants déjà tirés pour ce lot</p>
                                @foreach($lot['winners']->slice(0, -1) as $w)
                                    <div class="tg-pool-row">
                                        <span style="flex:1; color:#fff; font-weight:700;">{{ $w->name ?? '—' }}</span>
                                        <span class="tg-mono" style="color:var(--tg-ink-2);">{{ \App\Support\DrawEngine::mask($w->msisdn) }}</span>
                                    </div>
                                @endforeach
                            </div>
                        @endif
                    </div>
                @endif
            </div>

            {{-- Suppléants --}}
            @if($current && $lot['subs']->isNotEmpty())
                <div class="tg-glass tg-subs tg-step" x-data :class="($store.tgReveal?.shown ?? 99) > 4 && 'in'">
                    <div class="tg-subs-head">
                        <h3>Suppléants classés</h3>
                        <p>Tirés dans le pack {{ $d->categoryName() }}</p>
                    </div>
                    <div class="tg-subs-list">
                        @foreach($lot['subs'] as $s)
                            <div class="tg-sub">
                                <span class="tg-sub-rank">Suppléant {{ $s->rank }}</span>
                                @if($s->promoted_at)
                                    <span class="tg-provisional" style="font-size:10px; padding:3px 8px; margin-left:6px;">Promu</span>
                                @elseif($s->status !== 'valid')
                                    <span class="tg-provisional is-ko" style="font-size:10px; padding:3px 8px; margin-left:6px;">Écarté</span>
                                @endif
                                <div class="tg-sub-name">{{ $s->name ?? '—' }}</div>
                                <div class="tg-sub-phone">{{ \App\Support\DrawEngine::mask($s->msisdn) }}</div>
                                <div class="tg-sub-txn">{{ $fmt($s->txn_count) }} ticket(s)</div>
                            </div>
                        @endforeach
                    </div>
                </div>
            @endif
        </div>
    </div>

@else
    {{-- ══════════ SALLE : LISTE DES LOTS ══════════ --}}
    @if($error)
        <div class="tg-alert tg-alert-ko" style="text-align:center; font-size:14px; margin:0;">{{ $error }}</div>
    @endif

    @if($ceremony->isClosed())
        <div class="tg-alert tg-alert-warn" style="text-align:center; margin:0;">Cérémonie clôturée : plus aucun tirage possible.</div>
    @elseif(!$ready && !$ceremony->hasStarted())
        <div class="tg-alert tg-alert-warn" style="text-align:center; margin:0;">La cérémonie n'est pas encore prête : toutes les listes d'éligibles doivent être figées et avoir leurs lots.</div>
    @endif

    {{-- STATISTIQUES --}}
    <div class="tg-stats">
        @foreach([
            ['<path d="M3 7l9-4 9 4-9 4z"/><path d="M3 7v10l9 4 9-4V7M12 11v10"/>', $lotsTotal, 'Lots au total', false],
            [$giftPath, $lotsTotal - $lotsDrawn, 'Lots disponibles', true],
            ['<circle cx="12" cy="12" r="9"/><path d="M8 12l3 3 5-6"/>', $lotsDrawn, 'Lots déjà tirés', false],
            ['<path d="M3 8a2 2 0 0 0 2-2h14a2 2 0 0 0 2 2v8a2 2 0 0 0-2 2H5a2 2 0 0 0-2-2z"/><path d="M13 6v12" stroke-dasharray="2 2"/>', $fmt($tickets), 'Tickets éligibles', false],
        ] as [$path, $value, $label, $accent])
            <div class="tg-glass tg-stat {{ $accent ? 'tg-stat-accent' : '' }}">
                <div class="tg-stat-icon">{!! $icon($path, $accent ? '#F5C400' : '#fff') !!}</div>
                <p class="tg-stat-value">{{ $value }}</p>
                <p class="tg-stat-label">{{ $label }}</p>
            </div>
        @endforeach
    </div>

    {{-- UNE SECTION PAR PACK --}}
    @foreach($draws as $d)
        @php
            $open     = in_array($d->status, ['ready', 'drawing'], true) && !$ceremony->isClosed();
            $catDrawn = $d->prizes->sum(fn ($p) => min($p->drawn_count, $p->quantity));
            $catTotal = $d->prizes->sum('quantity');
        @endphp
        <section wire:key="cat-{{ $d->id }}" id="pack-{{ $d->id }}" class="tg-glass tg-section" style="--c:{{ $d->categoryColor() }};">
            <div class="tg-section-head">
                <div style="display:flex; align-items:center; gap:14px;">
                    <div class="tg-pack-badge">{!! $icon('<path d="M6 3h12l4 6-10 12L2 9z"/><path d="M2 9h20M12 21L8 9l4-6 4 6z"/>', '#10264B') !!}</div>
                    <div>
                        <p class="tg-pack-name" style="color:var(--c);">Pack {{ $d->categoryName() }}</p>
                        <p class="tg-muted" style="margin-top:2px;">{{ $d->prizes->count() }} rang(s) de lots</p>
                    </div>
                </div>
                <div style="display:flex; gap:8px; flex-wrap:wrap;">
                    @if($d->ticket_count !== null || $d->eligible_count !== null)
                        <span class="tg-chip"><b>{{ $fmt($d->ticket_count ?? $d->eligible_count) }}</b> tickets</span>
                    @endif
                    @if($d->eligible_count !== null)
                        <span class="tg-chip"><b>{{ $fmt($d->eligible_count) }}</b> clients</span>
                    @endif
                    <span class="tg-chip"><b>{{ $catDrawn }}</b> / {{ $catTotal }} tirés</span>
                </div>
            </div>

            <div class="tg-lots">
                @foreach($d->prizes as $p)
                    @php $done = $p->drawn_count >= $p->quantity; @endphp
                    <article wire:key="lot-{{ $p->id }}" class="tg-lot {{ $done ? 'is-done' : '' }}">
                        <div class="tg-lot-visual">
                            @if($p->imageUrl())
                                <img src="{{ $p->imageUrl() }}" alt="{{ $p->name }}">
                            @else
                                <span class="tg-noimg">{!! $icon($giftPath, $d->categoryColor()) !!}</span>
                            @endif
                            <span class="tg-chip-float" style="left:10px;">Rang {{ $loop->iteration }}</span>
                            <span class="tg-chip-float {{ $done ? 'is-done' : 'is-open' }}" style="right:10px;">{{ $done ? 'Déjà tiré' : 'Disponible' }}</span>
                        </div>
                        <div class="tg-lot-body">
                            <p class="tg-lot-name">{{ $p->name }}</p>
                            <div style="display:flex; justify-content:space-between; gap:8px; font-size:11.5px;" class="tg-muted">
                                <span>Pack {{ $d->categoryName() }}</span>
                                <span class="tg-lot-ref">{{ $code($d) }}-{{ str_pad($loop->iteration, 2, '0', STR_PAD_LEFT) }}</span>
                            </div>
                            @if($p->quantity > 1)
                                <div class="tg-lot-winner">{{ $p->drawn_count }} / {{ $p->quantity }} gagnants tirés</div>
                            @endif
                            @if($done)
                                <button wire:click="openLot({{ $p->id }})" class="tg-btn tg-btn-ghost tg-draw">VOIR LE RÉSULTAT</button>
                            @elseif($open && $ready)
                                <button wire:click="openLot({{ $p->id }})" class="tg-btn tg-btn-shine tg-draw">TIRER</button>
                            @else
                                <div class="tg-state">NON DISPONIBLE</div>
                            @endif
                        </div>
                    </article>
                @endforeach
            </div>
        </section>
    @endforeach

    @if($draws->isEmpty())
        <div class="tg-empty">Aucun lot dans cette cérémonie.</div>
    @endif
@endif

    <div class="tg-fab tg-hide-sm">
        <button onclick="document.fullscreenElement ? document.exitFullscreen() : document.documentElement.requestFullscreen?.()" class="tg-btn tg-btn-ghost tg-btn-sm">⛶ Plein écran</button>
    </div>
</div>
</x-tombola.shell>

<canvas id="tg-confetti" wire:ignore style="position:fixed; inset:0; width:100%; height:100%; pointer-events:none; z-index:60;"></canvas>
<div id="tg-toast" wire:ignore class="tg-toast"></div>


</div>
