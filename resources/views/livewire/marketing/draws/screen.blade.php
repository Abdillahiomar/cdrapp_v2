<?php

use App\Models\Draw;
use App\Support\DrawEngine;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

/**
 * Tableau d'honneur plein écran, à afficher en fin de cérémonie : gagnants
 * retenus par catégorie et par lot, numéro masqué (2537****542). Se met à jour
 * tout seul pendant le tirage.
 */
new #[Layout('layouts.screen')] class extends Component {

    public Draw $draw;

    public function boot(): void
    {
        abort_unless(auth()->user()?->can('draws.view'), 403);
    }

    public function mount(Draw $draw): void
    {
        abort_unless($draw->isDrawn(), 404);
        $this->draw = $draw;
    }

    public function with(): array
    {
        $prizes = $this->draw->prizes()->with('winners')->get();

        return [
            'groups' => $prizes->groupBy(fn ($p) => $p->category_name ?? 'Lots')->map(fn ($items) => [
                'color'  => $items->first()->category_color ?? '#1B2F6E',
                'prizes' => $items->map(fn ($p) => [
                    'name'    => $p->name,
                    'image'   => $p->imageUrl(),
                    'winners' => $p->winners->filter->isEffective()->map(fn ($w) => ['msisdn' => DrawEngine::mask($w->msisdn), 'name' => $w->name])->values(),
                ]),
            ]),
        ];
    }
};
?>
<div wire:poll.10s style="min-height:100vh; padding:28px 40px;">

    <div style="display:flex; justify-content:space-between; align-items:center; gap:16px; flex-wrap:wrap; margin-bottom:26px;">
        <div>
            <p style="font-size:13px; color:#FFC72C; letter-spacing:2px; text-transform:uppercase; font-weight:700;">D-Money · Tableau d'honneur</p>
            <h1 style="font-size:30px; font-weight:800; margin-top:4px;">{{ $draw->name }}</h1>
        </div>
        <button onclick="document.documentElement.requestFullscreen?.()" style="background:rgba(255,255,255,.12); color:#fff; border:none; border-radius:8px; padding:8px 14px; font-size:12px; cursor:pointer;">Plein écran</button>
    </div>

    @foreach($groups as $category => $group)
        <div style="margin-bottom:28px;">
            <p style="display:inline-block; background:{{ $group['color'] }}; color:#fff; font-size:15px; font-weight:800; letter-spacing:2px; text-transform:uppercase; padding:6px 16px; border-radius:8px; margin-bottom:14px;">{{ $category }}</p>
            <div style="display:grid; grid-template-columns:repeat(auto-fill, minmax(300px, 1fr)); gap:14px;">
                @foreach($group['prizes'] as $prize)
                    <div style="background:rgba(255,255,255,.07); border:1px solid rgba(255,255,255,.12); border-top:4px solid {{ $group['color'] }}; border-radius:12px; overflow:hidden;">
                        <div style="display:flex; gap:12px; align-items:center; padding:12px 14px; background:rgba(255,255,255,.05);">
                            @if($prize['image'])
                                <img src="{{ $prize['image'] }}" alt="" style="width:76px; height:58px; object-fit:cover; border-radius:8px; background:#fff;">
                            @endif
                            <p style="font-size:17px; font-weight:800;">{{ $prize['name'] }}</p>
                        </div>
                        <div style="padding:8px 14px 12px;">
                            @forelse($prize['winners'] as $w)
                                <div style="display:flex; justify-content:space-between; gap:10px; padding:6px 0; border-bottom:1px solid rgba(255,255,255,.08);">
                                    <span style="font-size:14px; font-weight:600;">{{ $w['name'] ?? '—' }}</span>
                                    <span style="font-size:14px; font-family:monospace; color:#FFC72C; white-space:nowrap;">{{ $w['msisdn'] }}</span>
                                </div>
                            @empty
                                <p style="font-size:13px; color:rgba(255,255,255,.45); padding:6px 0;">Pas encore tiré</p>
                            @endforelse
                        </div>
                    </div>
                @endforeach
            </div>
        </div>
    @endforeach
</div>
