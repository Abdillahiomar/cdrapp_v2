<header style="background:#fff; border-bottom:1px solid #e5e7eb; height:56px; padding:0 24px; display:flex; align-items:center; justify-content:space-between; flex-shrink:0;">

    <div>
        <h1 style="font-size:14px; font-weight:600; color:#111827; margin:0;">
            Bonjour, {{ auth()->user()->name }} 👋
        </h1>
        <p style="font-size:11px; color:#9ca3af; margin:1px 0 0;">
            {{ \Carbon\Carbon::now()->translatedFormat('l d F Y') }}
        </p>
    </div>

    <div style="display:flex; align-items:center; gap:10px;">

        {{-- Badge statut --}}
        <span style="background:#FFC72C; color:#005C2B; font-size:10px; font-weight:700; padding:4px 12px; border-radius:20px;">
            Système actif
        </span>

        {{-- Notifications --}}
        <div style="position:relative; width:34px; height:34px; border:1px solid #e5e7eb; border-radius:8px; display:flex; align-items:center; justify-content:center; cursor:pointer;">
            <svg width="15" height="15" viewBox="0 0 16 16" fill="#6b7280">
                <path d="M8 1a5 5 0 00-5 5v3l-1.5 2h13L13 9V6a5 5 0 00-5-5zm0 14a2 2 0 01-2-2h4a2 2 0 01-2 2z"/>
            </svg>
            <span style="position:absolute; top:6px; right:6px; width:7px; height:7px; background:#ef4444; border-radius:50%; border:2px solid #fff;"></span>
        </div>

        {{-- Profil avec dropdown --}}
<div x-data="{ open: false }" style="position:relative;">

    {{-- Bouton profil --}}
    <div x-on:click="open = !open"
         style="display:flex; align-items:center; gap:8px; border:1px solid #e5e7eb; border-radius:24px; padding:4px 12px 4px 4px; cursor:pointer;"
         onmouseover="this.style.background='#f9fafb'"
         onmouseout="this.style.background='transparent'">
        <div style="width:28px; height:28px; border-radius:50%; background:#1B2F6E; display:flex; align-items:center; justify-content:center; color:white; font-size:11px; font-weight:700; flex-shrink:0;">
            {{ strtoupper(substr(auth()->user()->name, 0, 2)) }}
        </div>
        <div style="line-height:1.2;">
            <p style="font-size:12px; font-weight:600; color:#111827; margin:0;">{{ auth()->user()->name }}</p>
            <p style="font-size:10px; color:#9ca3af; margin:0;">{{ auth()->user()->getRoleNames()->first() ?? 'Utilisateur' }}</p>
        </div>
        <svg width="11" height="11" viewBox="0 0 12 12" fill="none"
             x-bind:style="open ? 'transform:rotate(180deg); transition:0.2s' : 'transition:0.2s'">
            <path d="M2 4l4 4 4-4" stroke="#9ca3af" stroke-width="1.5" stroke-linecap="round"/>
        </svg>
    </div>

    {{-- Dropdown menu --}}
    <div x-show="open"
         x-on:click.outside="open = false"
         x-transition:enter="transition ease-out duration-100"
         x-transition:enter-start="opacity-0 scale-95"
         x-transition:enter-end="opacity-100 scale-100"
         x-transition:leave="transition ease-in duration-75"
         x-transition:leave-start="opacity-100 scale-100"
         x-transition:leave-end="opacity-0 scale-95"
         style="position:absolute; top:calc(100% + 8px); right:0; width:220px; background:#fff; border:1px solid #e5e7eb; border-radius:10px; box-shadow:0 8px 24px rgba(0,0,0,0.1); z-index:100; overflow:hidden;">

        {{-- Info utilisateur --}}
        <div style="padding:12px 14px; border-bottom:1px solid #f3f4f6;">
            <div style="display:flex; align-items:center; gap:10px;">
                <div style="width:36px; height:36px; border-radius:50%; background:#1B2F6E; display:flex; align-items:center; justify-content:center; color:white; font-size:12px; font-weight:700; flex-shrink:0;">
                    {{ strtoupper(substr(auth()->user()->name, 0, 2)) }}
                </div>
                <div style="min-width:0;">
                    <p style="font-size:12px; font-weight:600; color:#111827; margin:0; white-space:nowrap; overflow:hidden; text-overflow:ellipsis;">
                        {{ auth()->user()->name }}
                    </p>
                    <p style="font-size:10px; color:#9ca3af; margin:1px 0 0; white-space:nowrap; overflow:hidden; text-overflow:ellipsis;">
                        {{ auth()->user()->email }}
                    </p>
                </div>
            </div>
            @if(auth()->user()->getRoleNames()->first())
                <span style="display:inline-block; margin-top:8px; background:#E8ECF8; color:#1B2F6E; font-size:10px; font-weight:600; padding:2px 8px; border-radius:10px;">
                    {{ auth()->user()->getRoleNames()->first() }}
                </span>
            @endif
        </div>

        {{-- Actions --}}
        <div style="padding:6px;">

            {{-- Mon profil --}}
            <a href="{{ route('profile.index') }}"
               style="display:flex; align-items:center; gap:10px; padding:8px 10px; border-radius:7px; font-size:12px; color:#374151; text-decoration:none; cursor:pointer;"
               onmouseover="this.style.background='#F7F8FC'"
               onmouseout="this.style.background='transparent'">
                <svg width="14" height="14" viewBox="0 0 16 16" fill="#6b7280">
                    <circle cx="8" cy="5" r="3"/>
                    <path d="M2 13c0-3 2.7-5 6-5s6 2 6 5"/>
                </svg>
                Mon profil
            </a>

            {{-- Séparateur --}}
            <div style="height:1px; background:#f3f4f6; margin:4px 0;"></div>

            {{-- Déconnexion --}}
            <form method="POST" action="{{ route('logout') }}">
                @csrf
                <button type="submit"
                        style="width:100%; display:flex; align-items:center; gap:10px; padding:8px 10px; border-radius:7px; font-size:12px; color:#E24B4A; background:none; border:none; cursor:pointer; text-align:left;"
                        onmouseover="this.style.background='#FDECEA'"
                        onmouseout="this.style.background='transparent'">
                    <svg width="14" height="14" viewBox="0 0 16 16" fill="#E24B4A">
                        <path d="M10 3H13a1 1 0 011 1v9a1 1 0 01-1 1h-3M7 11l3-3-3-3M10 8H2"/>
                    </svg>
                    Déconnexion
                </button>
            </form>

        </div>
    </div>
</div>

    </div>
</header>