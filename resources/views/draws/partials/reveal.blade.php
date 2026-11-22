{{--
    Fenêtre de résultat d'un tirage (plein écran) : logos, titre, catégorie, défilement des
    numéros puis gagnant, feux d'artifice et son. Utilisée par la page d'une liste
    (marketing.draws.show) et la salle de tirage (marketing.ceremonies.room).
    Variables : $reveal (DrawWinner), $revealPrize (DrawPrize). Le composant doit avoir
    une méthode closeReveal() et inclure draws.partials.reveal-script dans un bloc @script.
--}}
    {{-- Plein écran : logos + titre + catégorie, feux d'artifice et son au moment où le gagnant
         s'affiche (pas pendant le défilement). Titre, fond, durée du son et son on/off se règlent
         via ⚙ et sont mémorisés dans ce navigateur (voir drawReveal dans le script en bas de page). --}}
    @if($reveal && $revealPrize)
        <div wire:key="reveal-{{ $reveal->id }}"
             x-data="drawReveal()"
             x-on:keydown.escape.window="if (!rolling) $wire.closeReveal()"
             :style="{ background: s.bg }"
             style="position:fixed; inset:0; z-index:998; overflow-y:auto; background:#0F1D47;">

            {{-- Halos Glass UI par-dessus la couleur de fond choisie --}}
            <div style="position:fixed; inset:0; pointer-events:none; z-index:0;
                        background:radial-gradient(900px 560px at 85% -10%, rgba(29,60,105,.85), transparent 60%),
                                   radial-gradient(700px 500px at -10% 110%, rgba(60,110,190,.28), transparent 60%),
                                   radial-gradient(420px 320px at 50% 8%, rgba(245,196,0,.14), transparent 70%);"></div>
            <canvas x-ref="sky"style="position:fixed; inset:0; width:100%; height:100%; pointer-events:none; z-index:0;"></canvas>

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

                @php $catColor = $revealPrize->category_color ?? '#1B2F6E'; @endphp
                <p style="margin:16px 0 22px; display:inline-flex; align-items:center; gap:10px; color:#fff; font-size:clamp(14px,1.8vw,20px); font-weight:800; letter-spacing:4px; text-transform:uppercase; padding:9px 26px; border-radius:999px;
                          background:color-mix(in srgb, {{ $catColor }} 30%, rgba(255,255,255,.06)); border:1px solid color-mix(in srgb, {{ $catColor }} 70%, transparent);
                          box-shadow:0 0 30px color-mix(in srgb, {{ $catColor }} 35%, transparent); backdrop-filter:blur(10px); -webkit-backdrop-filter:blur(10px);">
                    <span style="width:10px; height:10px; border-radius:50%; background:{{ $catColor }}; box-shadow:0 0 12px {{ $catColor }};"></span>
                    Pack {{ $revealPrize->category_name ?? 'Lot' }}
                </p>

                {{-- Lot + gagnant (Glass UI) --}}
                <div style="position:relative; width:min(780px,100%); color:#fff; text-align:center; border-radius:24px; padding:24px 28px 22px;
                            background:linear-gradient(150deg, rgba(255,255,255,.13), rgba(255,255,255,.04)); border:1px solid rgba(255,255,255,.2);
                            box-shadow:0 30px 80px rgba(0,0,0,.45), inset 0 1px 0 rgba(255,255,255,.18);
                            backdrop-filter:blur(20px) saturate(140%); -webkit-backdrop-filter:blur(20px) saturate(140%);">
                    @if($revealPrize->imageUrl())
                        <div style="border-radius:18px; padding:12px; margin:0 auto 16px; max-width:100%; display:inline-block;
                                    background:radial-gradient(circle at 50% 40%, rgba(245,196,0,.22), transparent 65%), rgba(255,255,255,.92); box-shadow:0 16px 40px rgba(0,0,0,.35);">
                            <img src="{{ $revealPrize->imageUrl() }}" alt="" style="max-width:100%; max-height:32vh; object-fit:contain; display:block;">
                        </div>
                    @endif
                    <p style="font-size:clamp(20px,2.6vw,30px); font-weight:900; margin:0; letter-spacing:-.3px;">🎁 {{ $revealPrize->name }}</p>
                    @if($reveal->kind === 'extra' || $reveal->promoted_at)
                        <p style="font-size:12px; color:#FFE27A; margin:6px 0 0;">Remplacement d'un gagnant écarté</p>
                    @endif

                    <div style="margin:20px 0 6px; padding:20px 18px; border-radius:18px; position:relative; overflow:hidden;"
                         :style="rolling
                            ? { background: 'rgba(7,21,48,.55)', border: '1px solid rgba(255,255,255,.14)', boxShadow: 'inset 0 0 40px rgba(0,0,0,.35)' }
                            : { background: 'linear-gradient(150deg, rgba(245,196,0,.22), rgba(245,196,0,.05) 50%, rgba(255,255,255,.04))', border: '1px solid rgba(245,196,0,.5)', boxShadow: '0 0 0 1px rgba(245,196,0,.15), 0 24px 60px rgba(245,196,0,.18)' }">
                        <p style="font-size:12px; font-weight:800; color:#F5C400; margin:0 0 8px; text-transform:uppercase; letter-spacing:3px;" x-text="rolling ? 'Tirage en cours…' : 'Gagnant'"></p>
                        <p x-show="rolling" style="font-size:clamp(32px,4.4vw,52px); font-weight:800; font-family:ui-monospace,Consolas,monospace; color:#fff; margin:0; letter-spacing:4px;" x-text="roll"></p>
                        <div x-show="!rolling" x-cloak x-transition.scale.90.opacity.duration.500ms>
                            <p style="font-size:clamp(28px,4vw,46px); font-weight:900; color:#fff; margin:0; letter-spacing:-.5px; text-shadow:0 4px 30px rgba(245,196,0,.35);">🎉 {{ $reveal->name ?? '—' }}</p>
                            <p style="font-size:clamp(24px,3.2vw,36px); font-weight:800; font-family:ui-monospace,Consolas,monospace; color:#F5C400; margin:10px 0 0; letter-spacing:3px;"
                               x-text="full ? @js(\App\Support\DrawEngine::display($reveal->msisdn)) : @js(\App\Support\DrawEngine::mask($reveal->msisdn))"></p>
                        </div>
                    </div>

                    <div style="display:flex; justify-content:space-between; align-items:center; margin-top:16px; gap:10px; flex-wrap:wrap;">
                        <label style="font-size:11.5px; color:rgba(255,255,255,.6); display:flex; align-items:center; gap:6px;">
                            <input type="checkbox" x-model="full"> Afficher le numéro complet
                        </label>
                        <button wire:click="closeReveal" :disabled="rolling"
                                style="background:linear-gradient(180deg,#FFD83A,#F5C400); color:#10264B; font-size:15px; font-weight:900; letter-spacing:1px; padding:12px 34px; border-radius:12px; border:none; cursor:pointer; box-shadow:0 10px 24px rgba(245,196,0,.32), inset 0 1px 0 rgba(255,255,255,.55);"
                                :style="rolling ? { opacity: '.4', cursor: 'wait' } : {}">Fermer</button>
                    </div>
                </div>
            </div>
        </div>
    @endif
