<?php

use App\Livewire\Forms\LoginForm;
use Illuminate\Support\Facades\Session;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

new #[Layout('layouts.guest')] class extends Component
{
    public LoginForm $form;

    public function login(): void
    {
        $this->validate();
        $this->form->authenticate();
        Session::regenerate();
        $this->redirectIntended(default: route('customers.index', absolute: false), navigate: true);
    }
}; ?>
<div>
<div style="min-height:100vh; display:flex; font-family:sans-serif;">

   {{-- ── PARTIE GAUCHE — image de fond ── --}}
    <div style="
        flex:1;
        display:flex;
        flex-direction:column;
        align-items:center;
        justify-content:center;
        padding:48px;
        position:relative;
        overflow:hidden;
    ">
    {{-- Image de fond via balise --}}
    <img src="build/images/dmoney_office.jpg"
         alt=""
         style="position:absolute; inset:0; width:100%; height:100%; object-fit:cover; z-index:0;">

    {{-- Overlay sombre --}}
    <div style="position:absolute; inset:0; background:rgba(11,25,70,0.30); z-index:1;"></div>

    {{-- Cercles décoratifs --}}
    <div style="position:absolute; top:-80px; left:-80px; width:300px; height:300px; border-radius:50%; background:rgba(255,199,44,0.08); z-index:2;"></div>
    <div style="position:absolute; bottom:-60px; right:-60px; width:240px; height:240px; border-radius:50%; background:rgba(255,199,44,0.06); z-index:2;"></div>

    {{-- Contenu gauche --}}
    <div style="position:relative; z-index:3; text-align:center; max-width:400px;">

        <div style="display:flex; justify-content:center; align-items:center; margin:0 auto 36px;">
            <img src="{{ asset('build/images/favicon.png') }}"
                 alt="D-Money"
                 style="width:220px; height:auto; object-fit:contain;">
        </div>

        <h1 style="font-size:28px; font-weight:700; color:#fff; margin:0 0 12px; line-height:1.2;">
            D-Money Repporting APP
        </h1>
        <p style="font-size:14px; color:rgba(255,255,255,0.55); line-height:1.7; margin:0 0 40px;">
            Plateforme de supervision financière<br>Djibouti Telecom — Digital Mobile Money
        </p>

        {{-- Stats déco --}}
        <div style="display:grid; grid-template-columns:repeat(3,1fr); gap:16px;">
            <div style="background:rgba(255,255,255,0.07); border-radius:12px; padding:16px 10px; border:1px solid rgba(255,255,255,0.1);">
                <p style="font-size:20px; font-weight:700; color:#FFC72C; margin:0 0 4px;">9.5M+</p>
                <p style="font-size:10px; color:rgba(255,255,255,0.45); margin:0; line-height:1.3;">Transactions</p>
            </div>
            <div style="background:rgba(255,255,255,0.07); border-radius:12px; padding:16px 10px; border:1px solid rgba(255,255,255,0.1);">
                <p style="font-size:20px; font-weight:700; color:#FFC72C; margin:0 0 4px;">297K+</p>
                <p style="font-size:10px; color:rgba(255,255,255,0.45); margin:0; line-height:1.3;">Clients</p>
            </div>
            <div style="background:rgba(255,255,255,0.07); border-radius:12px; padding:16px 10px; border:1px solid rgba(255,255,255,0.1);">
                <p style="font-size:20px; font-weight:700; color:#FFC72C; margin:0 0 4px;">7K+</p>
                <p style="font-size:10px; color:rgba(255,255,255,0.45); margin:0; line-height:1.3;">Organisations</p>
            </div>
        </div>
    </div>

    {{-- Footer gauche --}}
    <p style="position:absolute; bottom:20px; font-size:10px; color:rgba(255,255,255,0.25); z-index:3;">
        © {{ date('Y') }} Djibouti Telecom — D-Money
    </p>
</div>

    {{-- ── PARTIE DROITE — formulaire ── --}}
    <div style="
        width:460px;
        flex-shrink:0;
        background:#fff;
        display:flex;
        flex-direction:column;
        align-items:center;
        justify-content:center;
        padding:48px 40px;
        ">

        {{-- Logo D-Money petit --}}
        <div style="display:flex; align-items:center; gap:12px; margin-bottom:36px;">
            <img src="{{ asset('build/images/favicon.png') }}"
         alt="D-Money"
         style="width:44px; height:44px; object-fit:contain; flex-shrink:0;">
    
            <div>
                <p style="font-size:16px; font-weight:700; color:#111827; margin:0; line-height:1.2;">CDRAPP</p>
                <p style="font-size:10px; color:#9ca3af; margin:0; line-height:1.2;">D-Money Dashboard</p>
            </div>
        </div>

        {{-- Titre --}}
        <div style="width:100%; margin-bottom:28px;">
            <h2 style="font-size:22px; font-weight:700; color:#111827; margin:0 0 6px;">Connexion</h2>
            <p style="font-size:13px; color:#9ca3af; margin:0;">Entrez vos identifiants pour accéder à la plateforme.</p>
        </div>

        {{-- Session Status --}}
        @if(session('status'))
            <div style="width:100%; background:#E5F5ED; color:#005C2B; border-radius:8px; padding:10px 14px; font-size:12px; margin-bottom:16px; border:1px solid #a7d7b8;">
                {{ session('status') }}
            </div>
        @endif

        {{-- Formulaire --}}
        <form wire:submit="login" style="width:100%;">

            {{-- Email --}}
            <div style="margin-bottom:16px;">
                <label style="font-size:12px; font-weight:600; color:#374151; display:block; margin-bottom:6px;">
                    Adresse email
                </label>
                <input wire:model="form.email"
                       type="email"
                       id="email"
                       name="email"
                       required
                       autofocus
                       autocomplete="username"
                       placeholder="votre@email.com"
                       style="width:100%; border:1px solid #d1d5db; border-radius:8px; padding:10px 14px; font-size:13px; color:#111827; outline:none; transition:border 0.15s; box-sizing:border-box;"
                       onfocus="this.style.borderColor='#1B2F6E'; this.style.boxShadow='0 0 0 3px rgba(27,47,110,0.08)'"
                       onblur="this.style.borderColor='#d1d5db'; this.style.boxShadow='none'">
                @error('form.email')
                    <p style="font-size:11px; color:#E24B4A; margin:5px 0 0;">{{ $message }}</p>
                @enderror
            </div>

            {{-- Password --}}
            <div style="margin-bottom:20px;">
                <div style="display:flex; align-items:center; justify-content:space-between; margin-bottom:6px;">
                    <label style="font-size:12px; font-weight:600; color:#374151;">
                        Mot de passe
                    </label>
                    @if(Route::has('password.request'))
                        <a href="{{ route('password.request') }}"
                           wire:navigate
                           style="font-size:11px; color:#1B2F6E; text-decoration:none;">
                            Mot de passe oublié ?
                        </a>
                    @endif
                </div>
                <input wire:model="form.password"
                       type="password"
                       id="password"
                       name="password"
                       required
                       autocomplete="current-password"
                       placeholder="••••••••"
                       style="width:100%; border:1px solid #d1d5db; border-radius:8px; padding:10px 14px; font-size:13px; color:#111827; outline:none; transition:border 0.15s; box-sizing:border-box;"
                       onfocus="this.style.borderColor='#1B2F6E'; this.style.boxShadow='0 0 0 3px rgba(27,47,110,0.08)'"
                       onblur="this.style.borderColor='#d1d5db'; this.style.boxShadow='none'">
                @error('form.password')
                    <p style="font-size:11px; color:#E24B4A; margin:5px 0 0;">{{ $message }}</p>
                @enderror
            </div>

            {{-- Remember me --}}
            <div style="margin-bottom:24px;">
                <label style="display:flex; align-items:center; gap:8px; cursor:pointer;">
                    <input wire:model="form.remember"
                           type="checkbox"
                           style="accent-color:#1B2F6E; width:15px; height:15px;">
                    <span style="font-size:12px; color:#6b7280;">Se souvenir de moi</span>
                </label>
            </div>

            <button type="submit"
                    wire:loading.attr="disabled"
                    wire:target="login"
                    style="width:100%; background:#1B2F6E; color:#fff; font-size:14px; font-weight:600; padding:11px; border-radius:8px; border:none; cursor:pointer; display:flex; align-items:center; justify-content:center; gap:8px; transition:background 0.15s;"
                    onmouseover="this.style.background='#111D45'"
                    onmouseout="this.style.background='#1B2F6E'">

                    {{-- Etat normal --}}
                    <span wire:loading.remove wire:target="login">
                        <span style="display:flex; align-items:center; gap:8px;">
                            <svg width="15" height="15" viewBox="0 0 16 16" fill="white">
                                <path d="M10 3H13a1 1 0 011 1v9a1 1 0 01-1 1h-3M7 11l3-3-3-3M10 8H2"/>
                            </svg>
                            Se connecter
                        </span>
                    </span>

                    {{-- Loading --}}
                    <span wire:loading wire:target="login">
                        <span style="display:flex; align-items:center; gap:8px;">
                            <svg width="15" height="15" viewBox="0 0 40 40" fill="none" style="animation:spin 0.8s linear infinite;">
                                <circle cx="20" cy="20" r="16" stroke="rgba(255,255,255,0.3)" stroke-width="4"/>
                                <path d="M20 4a16 16 0 0116 16" stroke="white" stroke-width="4" stroke-linecap="round"/>
                            </svg>
                            Connexion...
                        </span>
                    </span>

                </button>
        </form>

        {{-- Footer --}}
        <p style="margin-top:auto; padding-top:32px; font-size:10px; color:#d1d5db; text-align:center;">
            Accès réservé aux agents autorisés de Djibouti Telecom
        </p>
    </div>

</div>

<style>
    @keyframes spin { from { transform:rotate(0deg); } to { transform:rotate(360deg); } }
</style>
</div>
