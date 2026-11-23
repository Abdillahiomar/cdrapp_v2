<?php

use App\Models\DrawCeremony;
use App\Support\DrawEngine;
use Carbon\Carbon;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

/**
 * Tableau d'honneur d'une cérémonie (plein écran, projection) : dernier gagnant,
 * progression, puis gagnants retenus par pack et par lot, numéro masqué
 * (77 ** ** 42). Se met à jour tout seul pendant le tirage (toutes les 10 s).
 */
new #[Layout('layouts.tombola')] class extends Component {

    public DrawCeremony $ceremony;

    public function boot(): void
    {
        abort_unless(auth()->user()?->can('draws.view'), 403);
    }

    public function mount(DrawCeremony $ceremony): void
    {
        abort_unless($ceremony->hasStarted(), 404);
        $this->ceremony = $ceremony;
    }

    public function with(): array
    {
        $this->ceremony->refresh();
        $draws = $this->ceremony->draws()->with(['category', 'prizes.winners'])->get();

        // Un bloc par pack ; un ancien tirage « toutes catégories » est regroupé par catégorie de lot
        $groups = collect();
        $latest = null;
        foreach ($draws as $d) {
            $types = $d->prize_category_id
                ? $d->typeLabels()->map(fn ($l) => trim(preg_replace('/\s*\(.*\)$/', '', $l)))->unique()->implode(', ')
                : null;

            foreach ($d->prizes as $p) {
                $name  = $d->prize_category_id ? $d->categoryName() : ($p->category_name ?? 'Lots');
                $color = $d->prize_category_id ? $d->categoryColor() : ($p->category_color ?? '#1B2F6E');
                $group = $groups->get($name, ['color' => $color, 'types' => $types, 'prizes' => collect()]);
                $rank  = $group['prizes']->count() + 1;

                $winners = $p->winners->filter->isEffective()->map(function ($w) use ($p, $name, $color, $rank, &$latest) {
                    $at = $w->promoted_at ?? $w->created_at;
                    if (!$latest || $at > $latest['at']) {
                        $latest = ['id' => $w->id, 'at' => $at, 'name' => $w->name, 'msisdn' => DrawEngine::maskShort($w->msisdn),
                                   'lot' => $p->name, 'image' => $p->imageUrl(), 'pack' => $name, 'color' => $color, 'rank' => $rank];
                    }

                    return ['id' => $w->id, 'msisdn' => DrawEngine::maskShort($w->msisdn), 'name' => $w->name, 'at' => $at];
                })->values();

                $group['prizes']->push([
                    'name'     => $p->name,
                    'image'    => $p->imageUrl(),
                    'rank'     => $rank,
                    'quantity' => $p->quantity,
                    'winners'  => $winners,
                ]);
                $groups->put($name, $group);
            }
        }

        $total = $groups->sum(fn ($g) => $g['prizes']->sum('quantity'));
        $done  = $groups->sum(fn ($g) => $g['prizes']->sum(fn ($p) => min($p['winners']->count(), $p['quantity'])));

        return [
            'groups' => $groups,
            'latest' => $latest,
            'total'  => $total,
            'done'   => $done,
            'fresh'  => Carbon::now()->subMinutes(2),   // gagnants « NOUVEAU »
        ];
    }
};
?>
<div wire:poll.10s>
@php
    $icon = fn ($path, $color = '#fff', $w = 1.7) => '<svg viewBox="0 0 24 24" fill="none" stroke="' . $color . '" stroke-width="' . $w . '" stroke-linecap="round" stroke-linejoin="round">' . $path . '</svg>';
    $gift   = '<rect x="3" y="8" width="18" height="4" rx="1"/><path d="M5 12v9h14v-9M12 8v13M12 8S10.5 3 8 4s0 4 4 4zM12 8s1.5-5 4-4 0 4-4 4z"/>';
    $trophy = '<path d="M8 21h8M12 17v4M7 4h10v5a5 5 0 0 1-10 0z"/><path d="M17 5h3v2a3 3 0 0 1-3 3M7 5H4v2a3 3 0 0 0 3 3"/>';
    $star   = '<path d="M12 3l2.7 5.6 6.1.9-4.4 4.3 1 6.1L12 17l-5.4 2.9 1-6.1L3.2 9.5l6.1-.9z"/>';
    $gem    = '<path d="M6 3h12l4 6-10 12L2 9z"/><path d="M2 9h20M12 21L8 9l4-6 4 6z"/>';
@endphp

<div class="tg tg-honor">

    {{-- EN-TÊTE --}}
    <header class="tg-glass tg-h-head">
        <div class="tg-h-brand">
            <div class="tg-h-logo">
                <img src="{{ asset('images/qr-logo.png') }}" alt="D-Money" onerror="this.replaceWith(Object.assign(document.createElement('span'), { textContent: 'D' }))">
            </div>
            <div style="min-width:0;">
                <p class="tg-eyebrow" style="letter-spacing:3px;">Grand Tombola D-Money</p>
                <h1 class="tg-h-title">Tableau d'honneur</h1>
                <p class="tg-h-sub">{{ $ceremony->name }}@if($ceremony->ceremony_date) · tirage public du {{ $ceremony->ceremony_date->format('d/m/Y') }}@endif</p>
            </div>
        </div>
        <div class="tg-h-right">
            <x-tombola.theme-switch />
            @if($ceremony->isClosed())
                <span class="tg-badge tg-s-closed">Cérémonie clôturée</span>
            @else
                <span class="tg-h-live"><i></i>En direct</span>
            @endif
            <span class="tg-h-clock" wire:ignore x-data="{ t: new Date().toLocaleTimeString('fr-FR') }" x-init="setInterval(() => t = new Date().toLocaleTimeString('fr-FR'), 1000)" x-text="t"></span>
            <button class="tg-h-icon" title="Plein écran" onclick="document.fullscreenElement ? document.exitFullscreen() : document.documentElement.requestFullscreen?.()">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M8 3H5a2 2 0 0 0-2 2v3M21 8V5a2 2 0 0 0-2-2h-3M3 16v3a2 2 0 0 0 2 2h3M16 21h3a2 2 0 0 0 2-2v-3"/></svg>
            </button>
        </div>
    </header>

    {{-- DERNIER GAGNANT + PROGRESSION --}}
    <section class="tg-h-top">
        @if($latest)
            <div class="tg-glass tg-h-latest" wire:key="latest-{{ $latest['id'] }}" x-data x-init="tgHonorNew({{ $latest['id'] }}, $el)" style="--c:{{ $latest['color'] }};">
                <div class="tg-h-latest-visual">
                    @if($latest['image'])
                        <img src="{{ $latest['image'] }}" alt="{{ $latest['lot'] }}">
                    @else
                        {!! $icon($gift, $latest['color']) !!}
                    @endif
                </div>
                <div style="min-width:0;">
                    <p class="tg-h-label"><span style="width:16px; height:16px; display:inline-flex;">{!! $icon($star, '#F5C400', 2) !!}</span> Dernier gagnant</p>
                    <p class="tg-h-latest-name">{{ $latest['name'] ?? '—' }}</p>
                    <div class="tg-h-latest-meta">
                        <span class="tg-h-phone">{{ $latest['msisdn'] }}</span>
                        <span class="tg-cat" style="font-size:12px; padding:5px 12px;">Pack {{ $latest['pack'] }}</span>
                        <span>Rang {{ $latest['rank'] }} · <b style="color:#fff;">{{ $latest['lot'] }}</b></span>
                    </div>
                </div>
            </div>
        @else
            <div class="tg-glass tg-h-latest">
                <div class="tg-h-latest-visual">{!! $icon($trophy, '#F5C400') !!}</div>
                <div>
                    <p class="tg-h-label">Tirage à venir</p>
                    <p class="tg-h-latest-name">Les gagnants s'afficheront ici</p>
                </div>
            </div>
        @endif

        <div class="tg-glass tg-h-progress">
            <div class="tg-h-big"><b>{{ $done }}</b><span>/ {{ $total }} lots attribués</span></div>
            <div class="tg-h-bar"><i style="width:{{ $total ? round($done / $total * 100) : 0 }}%;"></i></div>
            <div class="tg-h-packs">
                @foreach($groups as $name => $g)
                    @php
                        $gt = $g['prizes']->sum('quantity');
                        $gd = $g['prizes']->sum(fn ($p) => min($p['winners']->count(), $p['quantity']));
                    @endphp
                    <div class="tg-h-pp" style="--c:{{ $g['color'] }};">
                        <small>{{ $name }}</small>
                        <b>{{ $gd }} / {{ $gt }}</b>
                        <div class="tg-h-mini"><i style="width:{{ $gt ? round($gd / $gt * 100) : 0 }}%;"></i></div>
                    </div>
                @endforeach
            </div>
        </div>
    </section>

    {{-- UN BLOC PAR PACK --}}
    @foreach($groups as $name => $g)
        @php $gd = $g['prizes']->sum(fn ($p) => min($p['winners']->count(), $p['quantity'])); @endphp
        <section class="tg-glass tg-section" style="--c:{{ $g['color'] }};" wire:key="pack-{{ \Illuminate\Support\Str::slug($name) }}">
            <div class="tg-section-head">
                <div style="display:flex; align-items:center; gap:14px;">
                    <div class="tg-pack-badge" style="width:54px; height:54px;">{!! $icon($gem, '#10264B', 1.8) !!}</div>
                    <div>
                        <p class="tg-pack-name" style="font-size:clamp(20px,2vw,28px); letter-spacing:3px;">Pack {{ $name }}</p>
                        <p class="tg-muted" style="font-size:13px; margin-top:2px;">{{ $g['types'] ? $g['types'] . ' · ' : '' }}{{ $g['prizes']->count() }} lot(s)</p>
                    </div>
                </div>
                <span class="tg-chip" style="font-size:12.5px;"><b>{{ $gd }}</b> / {{ $g['prizes']->sum('quantity') }} gagnants</span>
            </div>

            <div class="tg-h-honors">
                @foreach($g['prizes'] as $p)
                    @php $isNew = $p['winners']->contains(fn ($w) => $w['at'] && $w['at'] > $fresh); @endphp
                    <article class="tg-h-honor {{ $p['winners']->isNotEmpty() ? 'is-won' : '' }} {{ $isNew ? 'is-new' : '' }}">
                        @if($isNew)
                            <span class="tg-h-new">NOUVEAU</span>
                        @endif
                        <div class="tg-h-honor-top">
                            <div class="tg-h-honor-visual">
                                @if($p['image'])
                                    <img src="{{ $p['image'] }}" alt="{{ $p['name'] }}">
                                @else
                                    {!! $icon($gift, $g['color']) !!}
                                @endif
                            </div>
                            <div style="min-width:0;">
                                <p class="tg-h-rank">Rang {{ $p['rank'] }}</p>
                                <p class="tg-h-lot">{{ $p['name'] }}</p>
                            </div>
                        </div>
                        <div class="tg-h-honor-body">
                            @forelse($p['winners'] as $w)
                                <div @if(!$loop->first) style="margin-top:10px;" @endif>
                                    <p class="tg-h-winner"><span style="width:18px; height:18px; display:inline-flex; flex-shrink:0;">{!! $icon($trophy, '#F5C400', 2) !!}</span>{{ $w['name'] ?? '—' }}</p>
                                    <p class="tg-h-winner-phone">{{ $w['msisdn'] }}</p>
                                </div>
                            @empty
                                <p class="tg-h-waiting">En attente du tirage <span class="tg-h-dots"><i></i><i></i><i></i></span></p>
                                <div class="tg-h-shimmer"></div>
                            @endforelse
                        </div>
                    </article>
                @endforeach
            </div>
        </section>
    @endforeach

    <p class="tg-muted" style="font-size:12px; padding:0 6px;">Numéros masqués · seuls les gagnants retenus sont affichés · mise à jour automatique toutes les 10 s</p>
</div>

<canvas id="tg-confetti" wire:ignore style="position:fixed; inset:0; width:100%; height:100%; pointer-events:none; z-index:60;"></canvas>
</div>
