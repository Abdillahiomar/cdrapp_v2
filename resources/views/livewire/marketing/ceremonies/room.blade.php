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
new #[Layout('layouts.screen')] class extends Component {

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
<div style="min-height:100vh; padding:24px 32px 40px;">

    {{-- En-tête : logo D-Money | cérémonie | logo Djibouti Telecom --}}
    <div style="display:flex; align-items:center; justify-content:space-between; gap:16px; margin-bottom:26px;">
        <div style="flex:0 0 auto; background:#fff; border-radius:12px; padding:6px; width:clamp(56px,7vw,86px); height:clamp(56px,7vw,86px); display:flex; align-items:center; justify-content:center;">
            <img src="{{ asset('images/qr-logo.png') }}" alt="D-Money" onerror="this.parentElement.style.visibility='hidden'" style="max-width:100%; max-height:100%; object-fit:contain;">
        </div>
        <div style="flex:1; text-align:center;">
            <p style="font-size:12px; color:#FFC72C; letter-spacing:3px; text-transform:uppercase; font-weight:700;">Salle de tirage</p>
            <h1 style="font-size:clamp(20px,3vw,34px); font-weight:900; text-transform:uppercase; letter-spacing:1px; margin-top:4px;">{{ $ceremony->name }}</h1>
        </div>
        <div style="flex:0 0 auto; display:flex; align-items:center; gap:10px;">
            <div style="background:#fff; border-radius:12px; padding:6px; width:clamp(56px,7vw,86px); height:clamp(56px,7vw,86px); display:flex; align-items:center; justify-content:center;">
                <img src="{{ asset('images/qr-logo_dt.png') }}" alt="Djibouti Telecom" onerror="this.parentElement.style.visibility='hidden'" style="max-width:100%; max-height:100%; object-fit:contain;">
            </div>
        </div>
    </div>

    <div style="position:fixed; bottom:14px; right:16px; z-index:5;">
        <button onclick="document.fullscreenElement ? document.exitFullscreen() : document.documentElement.requestFullscreen?.()"
                style="background:rgba(255,255,255,.12); color:#fff; border:none; border-radius:8px; padding:8px 14px; font-size:12px; cursor:pointer;">⛶ Plein écran</button>
    </div>

    @if($error)
        <div style="max-width:800px; margin:0 auto 18px; background:#FDECEC; color:#B42318; border-radius:10px; padding:12px 16px; font-size:14px; font-weight:600; text-align:center;">{{ $error }}</div>
    @endif

    @if($ceremony->isClosed())
        <p style="text-align:center; font-size:16px; color:rgba(255,255,255,.7); margin-bottom:20px;">Cérémonie clôturée : plus aucun tirage possible.</p>
    @elseif(!$ready && !$ceremony->hasStarted())
        <p style="text-align:center; font-size:16px; color:#FFC72C; margin-bottom:20px;">La cérémonie n'est pas encore prête : toutes les listes d'éligibles doivent être figées et avoir leurs lots.</p>
    @endif

    @foreach($draws as $d)
        @php $open = in_array($d->status, ['ready', 'drawing'], true) && !$ceremony->isClosed(); @endphp
        <div wire:key="cat-{{ $d->id }}" style="margin-bottom:30px;">
            <div style="display:flex; align-items:center; gap:14px; margin-bottom:14px; flex-wrap:wrap;">
                <span style="background:{{ $d->categoryColor() }}; color:#fff; font-size:18px; font-weight:900; letter-spacing:3px; text-transform:uppercase; padding:8px 22px; border-radius:999px; box-shadow:0 0 0 3px rgba(255,255,255,.25);">{{ $d->categoryName() }}</span>
                @if($d->eligible_count !== null)
                    <span style="font-size:13px; color:rgba(255,255,255,.65);">{{ number_format($d->eligible_count, 0, ',', ' ') }} clients éligibles</span>
                @endif
            </div>

            <div style="display:grid; grid-template-columns:repeat(auto-fill, minmax(min(100%, 340px), 1fr)); gap:14px;">
                @foreach($d->prizes as $p)
                    @php $done = $p->drawn_count >= $p->quantity; @endphp
                    <div wire:key="lot-{{ $p->id }}"
                         style="display:flex; align-items:center; gap:14px; padding:14px; border-radius:14px; background:rgba(255,255,255,{{ $done ? '.04' : '.09' }}); border:1px solid rgba(255,255,255,.12); border-left:5px solid {{ $d->categoryColor() }};">
                        @if($p->imageUrl())
                            <img src="{{ $p->imageUrl() }}" alt="" style="width:96px; height:72px; object-fit:cover; border-radius:10px; background:#fff; flex-shrink:0; {{ $done ? 'opacity:.5;' : '' }}">
                        @endif
                        <div style="flex:1; min-width:0;">
                            <p style="font-size:17px; font-weight:800; line-height:1.2; {{ $done ? 'color:rgba(255,255,255,.55);' : '' }}">{{ $p->name }}</p>
                            <p style="font-size:12px; color:rgba(255,255,255,.6); margin-top:4px;">{{ $p->drawn_count }} / {{ $p->quantity }} gagnant(s)</p>
                        </div>
                        @if($done)
                            <span style="font-size:14px; font-weight:800; color:#4ADE80; white-space:nowrap;">✓ Terminé</span>
                        @elseif($open && $ready)
                            <button wire:click="drawPrize({{ $p->id }})" wire:loading.attr="disabled" wire:target="drawPrize"
                                    style="background:#00843D; color:#fff; font-size:15px; font-weight:800; padding:12px 18px; border-radius:10px; border:none; cursor:pointer; white-space:nowrap; box-shadow:0 6px 18px rgba(0,132,61,.35);">
                                🎲 Tirer au sort
                            </button>
                        @else
                            <span style="font-size:12px; color:rgba(255,255,255,.5); white-space:nowrap;">Non disponible</span>
                        @endif
                    </div>
                @endforeach
            </div>
        </div>
    @endforeach

    @if($draws->isEmpty())
        <p style="text-align:center; font-size:15px; color:rgba(255,255,255,.6); margin-top:40px;">Aucun lot dans cette cérémonie.</p>
    @endif

    {{-- FENÊTRE DE RÉSULTAT (même fenêtre que la page des listes) --}}
    @include('draws.partials.reveal')

@script
<script>
@include('draws.partials.reveal-script')
</script>
@endscript

</div>
