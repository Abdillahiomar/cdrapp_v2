<?php

use App\Models\DrawCeremony;
use App\Models\DrawPrize;
use App\Models\DrawWinner;
use App\Support\DrawEngine;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

/**
 * Salle de tirage (plein écran, jour J) : uniquement les lots de la cérémonie,
 * groupés par catégorie, chacun avec son bouton « Tirer au sort ». Un clic tire
 * un gagnant parmi les éligibles de la catégorie et l'annonce dans la fenêtre
 * de résultat. Permission : draws.run.
 */
new #[Layout('layouts.tombola')] class extends Component {

    public DrawCeremony $ceremony;

    public ?int $revealId = null;

    public ?string $error = null;

    public function boot(): void
    {
        abort_unless(auth()->user()?->can('draws.view') && auth()->user()->can('draws.run'), 403);
    }

    public function mount(DrawCeremony $ceremony): void
    {
        $this->ceremony = $ceremony;
    }

    public function drawPrize(int $prizeId): void
    {
        $this->error = null;

        $prize = DrawPrize::whereKey($prizeId)
            ->whereHas('draw', fn ($q) => $q->where('draw_ceremony_id', $this->ceremony->id))
            ->firstOrFail();

        try {
            $this->revealId = app(DrawEngine::class)->drawOne($prize, auth()->id())->id;
        } catch (\RuntimeException $e) {
            $this->error = $e->getMessage();
        }
    }

    public function closeReveal(): void
    {
        $this->revealId = null;
    }

    public function with(): array
    {
        $this->ceremony->refresh();

        $draws = $this->ceremony->draws()
            ->with(['category', 'prizes' => fn ($q) => $q->withCount(['winners as drawn_count' => fn ($w) => $w->where('kind', 'winner')])])
            ->get()
            ->filter(fn ($d) => $d->prizes->isNotEmpty());

        $reveal = $this->revealId
            ? DrawWinner::whereKey($this->revealId)->whereIn('draw_id', $draws->pluck('id'))->first()
            : null;

        return [
            'draws'       => $draws,
            'ready'       => $this->ceremony->isReady(),
            'reveal'      => $reveal,
            'revealPrize' => $reveal ? DrawPrize::find($reveal->draw_prize_id) : null,
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
@endphp
<x-tombola.shell title="Salle du tirage" :eyebrow="'Grand Tombola D-Money · ' . $ceremony->name" active="room" :ceremony="$ceremony" :packs="$draws">
    <x-slot:status>
        <span class="tg-badge tg-s-{{ $ready && $ceremony->status === 'preparation' ? 'ready' : $ceremony->status }}">{{ $ceremony->statusLabel()[0] }}</span>
    </x-slot:status>

<div class="tg-page">

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
            ['<rect x="3" y="8" width="18" height="4" rx="1"/><path d="M5 12v9h14v-9M12 8v13M12 8S10.5 3 8 4s0 4 4 4zM12 8s1.5-5 4-4 0 4-4 4z"/>', $lotsTotal - $lotsDrawn, 'Lots disponibles', true],
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
            $code     = mb_strtoupper(mb_substr(\Illuminate\Support\Str::ascii($d->categoryName()), 0, 3));
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
                                <span class="tg-noimg">{!! $icon('<rect x="3" y="8" width="18" height="4" rx="1"/><path d="M5 12v9h14v-9M12 8v13M12 8S10.5 3 8 4s0 4 4 4zM12 8s1.5-5 4-4 0 4-4 4z"/>', $d->categoryColor()) !!}</span>
                            @endif
                            <span class="tg-chip-float" style="left:10px;">Rang {{ $loop->iteration }}</span>
                            <span class="tg-chip-float {{ $done ? 'is-done' : 'is-open' }}" style="right:10px;">{{ $done ? 'Déjà tiré' : 'Disponible' }}</span>
                        </div>
                        <div class="tg-lot-body">
                            <p class="tg-lot-name">{{ $p->name }}</p>
                            <div style="display:flex; justify-content:space-between; gap:8px; font-size:11.5px;" class="tg-muted">
                                <span>Pack {{ $d->categoryName() }}</span>
                                <span class="tg-lot-ref">{{ $code }}-{{ str_pad($loop->iteration, 2, '0', STR_PAD_LEFT) }}</span>
                            </div>
                            @if($p->quantity > 1)
                                <div class="tg-lot-winner">{{ $p->drawn_count }} / {{ $p->quantity }} gagnants tirés</div>
                            @endif
                            @if($done)
                                <div class="tg-state is-ok">✓ DÉJÀ TIRÉ</div>
                            @elseif($open && $ready)
                                <button wire:click="drawPrize({{ $p->id }})" wire:loading.attr="disabled" wire:target="drawPrize"
                                        class="tg-btn tg-btn-shine tg-draw">
                                    <span wire:loading.remove wire:target="drawPrize">TIRER</span>
                                    <span wire:loading wire:target="drawPrize">TIRAGE…</span>
                                </button>
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

    <div class="tg-fab tg-hide-sm">
        <button onclick="document.fullscreenElement ? document.exitFullscreen() : document.documentElement.requestFullscreen?.()" class="tg-btn tg-btn-ghost tg-btn-sm">⛶ Plein écran</button>
    </div>
</div>
</x-tombola.shell>

    {{-- FENÊTRE DE RÉSULTAT (même fenêtre que la page des listes) --}}
    @include('draws.partials.reveal')

@script
<script>
@include('draws.partials.reveal-script')
</script>
@endscript

</div>
