@props([
    'title',                         // titre de la page (en-tête)
    'eyebrow' => 'Grand Tombola D-Money',
    'active'  => 'index',            // index | ceremony | room
    'ceremony' => null,              // DrawCeremony courante (liens de la cérémonie)
    'packs'   => null,               // listes (Draw) avec prizes.drawn_count : progression par pack
])
{{--
    Cadre des pages du tirage (layout layouts.tombola) : barre latérale en verre (desktop),
    en-tête avec statut et profil, navigation en pastilles sur mobile.
    Rendu dans le composant Livewire : la progression des packs se met à jour après chaque tirage.
--}}
@php
    $user     = auth()->user();
    $initials = collect(preg_split('/\s+/', trim((string) $user?->name)))->filter()->take(2)
        ->map(fn ($w) => mb_strtoupper(mb_substr($w, 0, 1)))->implode('');
    $role     = $user?->getRoleNames()->first() ?? 'Utilisateur';

    $links = [['index', route('draws.index'), 'Cérémonies', 'list']];
    if ($ceremony) {
        $links[] = ['ceremony', route('draws.ceremony', $ceremony), 'Préparation', 'sliders'];
        if ($user?->can('draws.run') && !$ceremony->isClosed() && ($ceremony->isReady() || $ceremony->hasStarted())) {
            $links[] = ['room', route('draws.room', $ceremony), 'Salle du tirage', 'dice'];
        }
        if ($ceremony->hasStarted()) {
            $links[] = ['screen', route('draws.screen', $ceremony), 'Tableau d\'honneur', 'trophy'];
        }
    }
    $links[] = ['prizes', route('draws.prizes'), 'Catalogue des lots', 'gift'];

    $icons = [
        'list'    => '<path d="M8 6h13M8 12h13M8 18h13"/><circle cx="3.5" cy="6" r="1"/><circle cx="3.5" cy="12" r="1"/><circle cx="3.5" cy="18" r="1"/>',
        'sliders' => '<path d="M4 21v-7M4 10V3M12 21v-9M12 8V3M20 21v-5M20 12V3M1 14h6M9 8h6M17 16h6"/>',
        'dice'    => '<rect x="3" y="3" width="18" height="18" rx="4"/><circle cx="8" cy="8" r="1.2"/><circle cx="16" cy="16" r="1.2"/><circle cx="12" cy="12" r="1.2"/>',
        'trophy'  => '<path d="M8 21h8M12 17v4M7 4h10v5a5 5 0 0 1-10 0z"/><path d="M17 5h3v2a3 3 0 0 1-3 3M7 5H4v2a3 3 0 0 0 3 3"/>',
        'gift'    => '<rect x="3" y="8" width="18" height="4" rx="1"/><path d="M5 12v9h14v-9M12 8v13M12 8S10.5 3 8 4s0 4 4 4zM12 8s1.5-5 4-4 0 4-4 4z"/>',
        'back'    => '<path d="M15 18l-6-6 6-6"/>',
    ];
    $svg = fn ($k) => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">' . $icons[$k] . '</svg>';
@endphp

<div class="tg tg-app">

    {{-- BARRE LATÉRALE (desktop) --}}
    <aside class="tg-glass tg-sidebar">
        <div class="tg-brand">
            <div class="tg-brand-mark">
                <img src="{{ asset('images/qr-logo.png') }}" alt="D-Money" onerror="this.replaceWith(Object.assign(document.createElement('span'), { textContent: 'D' }))">
            </div>
            <div>
                <div class="tg-brand-name">D-<span>Money</span></div>
                <div class="tg-brand-sub">Grand Tombola</div>
            </div>
        </div>

        <nav>
            <p class="tg-nav-title">Tombola</p>
            @foreach($links as [$key, $href, $label, $icon])
                <a href="{{ $href }}" @if($key !== 'screen') wire:navigate @else target="_blank" @endif class="tg-nav-item {{ $active === $key ? 'is-active' : '' }}">
                    {!! $svg($icon) !!}<span>{{ $label }}</span>
                    @if($key === 'screen') <small>↗</small> @endif
                </a>
            @endforeach
        </nav>

        @if($ceremony)
            <div class="tg-side-card">
                <p class="tg-nav-title" style="margin-bottom:6px;">Cérémonie</p>
                <p style="font-size:12.5px; font-weight:800; line-height:1.35; margin:0;">{{ $ceremony->name }}</p>
                @if($ceremony->ceremony_date)
                    <p class="tg-muted" style="font-size:11px; margin-top:3px;">{{ $ceremony->ceremony_date->format('d/m/Y') }}</p>
                @endif
            </div>
        @endif

        @if($packs && $packs->isNotEmpty())
            <div>
                <p class="tg-nav-title">Packs</p>
                @foreach($packs as $d)
                    @php
                        $total = $d->prizes->sum('quantity');
                        $done  = $d->prizes->sum(fn ($p) => min($p->drawn_count ?? 0, $p->quantity));
                    @endphp
                    <a href="#pack-{{ $d->id }}" class="tg-nav-pack" style="--c:{{ $d->categoryColor() }};">
                        <span class="tg-nav-row"><span><i class="tg-dot"></i>Pack {{ $d->categoryName() }}</span><small>{{ $done }}/{{ $total }}</small></span>
                        <span class="tg-bar"><i style="width:{{ $total ? round($done / $total * 100) : 0 }}%;"></i></span>
                    </a>
                @endforeach
            </div>
        @endif

        <div style="margin-top:auto; display:flex; flex-direction:column; gap:12px;">
            @if($active === 'room')
                <div class="tg-rules"><b>Rappel</b><br>Un client ne gagne qu'un seul lot sur l'ensemble de la cérémonie. Le gagnant est tiré et enregistré par le serveur.</div>
            @endif
            <a href="{{ route('dashboard.index') }}" class="tg-nav-item">{!! $svg('back') !!}<span>Retour à l'application</span></a>
        </div>
    </aside>

    <main class="tg-main">
        {{-- EN-TÊTE --}}
        <header class="tg-glass tg-topbar">
            <div style="display:flex; align-items:center; gap:12px; min-width:0;">
                <div class="tg-brand-mark tg-only-mobile">
                    <img src="{{ asset('images/qr-logo.png') }}" alt="D-Money" onerror="this.replaceWith(Object.assign(document.createElement('span'), { textContent: 'D' }))">
                </div>
                <div style="min-width:0;">
                    <p class="tg-eyebrow tg-ellipsis">{{ $eyebrow }}</p>
                    <h1 class="tg-h1 tg-ellipsis" style="margin-bottom:0;">{{ $title }}</h1>
                </div>
            </div>
            <div class="tg-top-right">
                {{ $status ?? '' }}
                @if($user)
                    <div class="tg-profile" title="{{ $user->name }}">
                        <div class="tg-avatar">{{ $initials ?: '?' }}</div>
                        <div class="tg-profile-text">
                            <div style="font-size:13px; font-weight:700; line-height:1.15;">{{ $user->name }}</div>
                            <div class="tg-muted" style="font-size:11px;">{{ $role }}</div>
                        </div>
                    </div>
                @endif
            </div>
        </header>

        {{-- NAVIGATION MOBILE --}}
        <nav class="tg-tabs">
            @foreach($links as [$key, $href, $label])
                <a href="{{ $href }}" @if($key !== 'screen') wire:navigate @else target="_blank" @endif class="tg-tab {{ $active === $key ? 'is-active' : '' }}">{{ $label }}</a>
            @endforeach
            @if($packs)
                @foreach($packs as $d)
                    <a href="#pack-{{ $d->id }}" class="tg-tab" style="--c:{{ $d->categoryColor() }};"><i class="tg-dot"></i>{{ $d->categoryName() }}</a>
                @endforeach
            @endif
        </nav>

        {{ $slot }}
    </main>
</div>
