<?php

use App\Models\User;
use Livewire\Volt\Component;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;

new class extends Component {

    // ── Infos profil ──────────────────────────────────────────
    public string $name  = '';
    public string $email = '';

    // ── Mot de passe ──────────────────────────────────────────
    public string $current_password  = '';
    public string $new_password      = '';
    public string $confirm_password  = '';

    // ── UI ────────────────────────────────────────────────────
    public bool $successProfil = false;
    public bool $successPass   = false;

    public function mount(): void
    {
        $user        = Auth::user();
        $this->name  = $user->name;
        $this->email = $user->email;
    }

    public function updateProfile(): void
    {
        $this->validate([
            'name'  => 'required|string|min:2|max:100',
            'email' => 'required|email|unique:users,email,' . Auth::id(),
        ], [
            'name.required'  => 'Le nom est obligatoire.',
            'name.min'       => 'Le nom doit contenir au moins 2 caractères.',
            'email.required' => 'L\'email est obligatoire.',
            'email.unique'   => 'Cet email est déjà utilisé.',
        ]);

        Auth::user()->update([
            'name'  => $this->name,
            'email' => $this->email,
        ]);

        $this->successProfil = true;
        $this->dispatch('profile-updated');
    }

    public function updatePassword(): void
    {
        $this->validate([
            'current_password' => 'required',
            'new_password'     => ['required', 'min:8', Password::defaults()],
            'confirm_password' => 'required|same:new_password',
        ], [
            'current_password.required' => 'Le mot de passe actuel est obligatoire.',
            'new_password.required'     => 'Le nouveau mot de passe est obligatoire.',
            'new_password.min'          => 'Le mot de passe doit contenir au moins 8 caractères.',
            'confirm_password.required' => 'La confirmation est obligatoire.',
            'confirm_password.same'     => 'Les mots de passe ne correspondent pas.',
        ]);

        if (!Hash::check($this->current_password, Auth::user()->password)) {
            $this->addError('current_password', 'Le mot de passe actuel est incorrect.');
            return;
        }

        Auth::user()->update([
            'password' => Hash::make($this->new_password),
        ]);

        $this->current_password  = '';
        $this->new_password      = '';
        $this->confirm_password  = '';
        $this->successPass       = true;
    }

    public function with(): array
    {
        return [
            'user' => Auth::user()->load('roles', 'department'),
        ];
    }
};
?>

<div style="padding:24px; max-width:860px; margin:0 auto;">

    {{-- En-tête --}}
    <div style="margin-bottom:24px;">
        <h2 style="font-size:16px; font-weight:700; color:#111827; margin:0 0 4px;">Mon profil</h2>
        <p style="font-size:12px; color:#9ca3af; margin:0;">Gérez vos informations personnelles et votre mot de passe.</p>
    </div>

    {{-- Card info utilisateur --}}
    <div style="background:#fff; border:1px solid #e5e7eb; border-radius:12px; padding:20px; margin-bottom:20px; display:flex; align-items:center; gap:20px;">
        <div style="width:64px; height:64px; border-radius:50%; background:#1B2F6E; display:flex; align-items:center; justify-content:center; color:#fff; font-size:22px; font-weight:700; flex-shrink:0;">
            {{ strtoupper(substr($user->name, 0, 2)) }}
        </div>
        <div style="flex:1; min-width:0;">
            <p style="font-size:16px; font-weight:700; color:#111827; margin:0 0 4px;">{{ $user->name }}</p>
            <p style="font-size:12px; color:#9ca3af; margin:0 0 8px;">{{ $user->email }}</p>
            <div style="display:flex; align-items:center; gap:8px; flex-wrap:wrap;">
                @if($user->roles->isNotEmpty())
                    @php
                        $roleColors = [
                            'super-admin'         => ['bg'=>'#FDECEA','color'=>'#7F1D1D'],
                            'directeur'           => ['bg'=>'#E8ECF8','color'=>'#1B2F6E'],
                            'analyste-finance'    => ['bg'=>'#E5F5ED','color'=>'#005C2B'],
                            'analyste-conformite' => ['bg'=>'#FFF3D0','color'=>'#7A4F00'],
                            'agent-operations'    => ['bg'=>'#F3E8FF','color'=>'#6B21A8'],
                            'auditeur'            => ['bg'=>'#f3f4f6','color'=>'#374151'],
                        ];
                        $role = $user->roles->first()->name;
                        $rc = $roleColors[$role] ?? ['bg'=>'#f3f4f6','color'=>'#374151'];
                    @endphp
                    <span style="background:{{ $rc['bg'] }}; color:{{ $rc['color'] }}; font-size:10px; font-weight:700; padding:3px 10px; border-radius:12px;">
                        {{ $role }}
                    </span>
                @endif
                @if($user->department)
                    <span style="background:#f3f4f6; color:#374151; font-size:10px; font-weight:600; padding:3px 10px; border-radius:12px;">
                        {{ $user->department->name }}
                    </span>
                @endif
                <span style="background:#f3f4f6; color:#9ca3af; font-size:10px; padding:3px 10px; border-radius:12px;">
                    Membre depuis {{ $user->created_at->format('d/m/Y') }}
                </span>
            </div>
        </div>
    </div>

    <div style="display:grid; grid-template-columns:repeat(2, minmax(0,1fr)); gap:20px;">

        {{-- ── MODIFIER LE PROFIL ── --}}
        <div style="background:#fff; border:1px solid #e5e7eb; border-radius:12px; overflow:hidden;">
            <div style="padding:14px 20px; border-bottom:1px solid #e5e7eb;">
                <p style="font-size:13px; font-weight:600; color:#111827; margin:0;">Informations personnelles</p>
                <p style="font-size:11px; color:#9ca3af; margin:2px 0 0;">Modifiez votre nom et votre adresse email.</p>
            </div>

            <div style="padding:20px;">

                @if($successProfil)
                    <div style="background:#E5F5ED; color:#005C2B; border-radius:8px; padding:10px 14px; font-size:12px; margin-bottom:16px; border:1px solid #a7d7b8; display:flex; align-items:center; gap:8px;">
                        <svg width="14" height="14" viewBox="0 0 16 16" fill="#16a34a">
                            <path d="M3 8l3.5 3.5L13 5" stroke="#16a34a" stroke-width="1.5" stroke-linecap="round" fill="none"/>
                        </svg>
                        Profil mis à jour avec succès.
                    </div>
                @endif

                <div style="margin-bottom:14px;">
                    <label style="font-size:11px; font-weight:600; color:#374151; display:block; margin-bottom:5px;">
                        Nom complet <span style="color:#E24B4A;">*</span>
                    </label>
                    <input type="text"
                           wire:model="name"
                           style="width:100%; border:1px solid #d1d5db; border-radius:7px; padding:9px 12px; font-size:13px; color:#111827; outline:none; box-sizing:border-box;"
                           onfocus="this.style.borderColor='#1B2F6E'"
                           onblur="this.style.borderColor='#d1d5db'">
                    @error('name')
                        <p style="font-size:10px; color:#E24B4A; margin:4px 0 0;">{{ $message }}</p>
                    @enderror
                </div>

                <div style="margin-bottom:20px;">
                    <label style="font-size:11px; font-weight:600; color:#374151; display:block; margin-bottom:5px;">
                        Adresse email <span style="color:#E24B4A;">*</span>
                    </label>
                    <input type="email"
                           wire:model="email"
                           style="width:100%; border:1px solid #d1d5db; border-radius:7px; padding:9px 12px; font-size:13px; color:#111827; outline:none; box-sizing:border-box;"
                           onfocus="this.style.borderColor='#1B2F6E'"
                           onblur="this.style.borderColor='#d1d5db'">
                    @error('email')
                        <p style="font-size:10px; color:#E24B4A; margin:4px 0 0;">{{ $message }}</p>
                    @enderror
                </div>

                <button wire:click="updateProfile"
                        wire:loading.attr="disabled"
                        wire:target="updateProfile"
                        style="background:#1B2F6E; color:#fff; font-size:13px; font-weight:600; padding:9px 20px; border-radius:8px; border:none; cursor:pointer; display:flex; align-items:center; gap:7px;">
                    <span wire:loading.remove wire:target="updateProfile" style="display:flex; align-items:center; gap:6px;">
                        <svg width="13" height="13" viewBox="0 0 16 16" fill="none" stroke="white" stroke-width="1.5" stroke-linecap="round">
                            <path d="M2 8l4 4 8-8"/>
                        </svg>
                        Enregistrer
                    </span>
                    <span wire:loading wire:target="updateProfile">Sauvegarde...</span>
                </button>
            </div>
        </div>

        {{-- ── MODIFIER LE MOT DE PASSE ── --}}
        <div style="background:#fff; border:1px solid #e5e7eb; border-radius:12px; overflow:hidden;">
            <div style="padding:14px 20px; border-bottom:1px solid #e5e7eb;">
                <p style="font-size:13px; font-weight:600; color:#111827; margin:0;">Changer le mot de passe</p>
                <p style="font-size:11px; color:#9ca3af; margin:2px 0 0;">Utilisez un mot de passe fort d'au moins 8 caractères.</p>
            </div>

            <div style="padding:20px;">

                @if($successPass)
                    <div style="background:#E5F5ED; color:#005C2B; border-radius:8px; padding:10px 14px; font-size:12px; margin-bottom:16px; border:1px solid #a7d7b8; display:flex; align-items:center; gap:8px;">
                        <svg width="14" height="14" viewBox="0 0 16 16" fill="#16a34a">
                            <path d="M3 8l3.5 3.5L13 5" stroke="#16a34a" stroke-width="1.5" stroke-linecap="round" fill="none"/>
                        </svg>
                        Mot de passe modifié avec succès.
                    </div>
                @endif

                <div style="margin-bottom:14px;">
                    <label style="font-size:11px; font-weight:600; color:#374151; display:block; margin-bottom:5px;">
                        Mot de passe actuel <span style="color:#E24B4A;">*</span>
                    </label>
                    <input type="password"
                           wire:model="current_password"
                           placeholder="••••••••"
                           style="width:100%; border:1px solid #d1d5db; border-radius:7px; padding:9px 12px; font-size:13px; color:#111827; outline:none; box-sizing:border-box;"
                           onfocus="this.style.borderColor='#1B2F6E'"
                           onblur="this.style.borderColor='#d1d5db'">
                    @error('current_password')
                        <p style="font-size:10px; color:#E24B4A; margin:4px 0 0;">{{ $message }}</p>
                    @enderror
                </div>

                <div style="margin-bottom:14px;">
                    <label style="font-size:11px; font-weight:600; color:#374151; display:block; margin-bottom:5px;">
                        Nouveau mot de passe <span style="color:#E24B4A;">*</span>
                    </label>
                    <input type="password"
                           wire:model="new_password"
                           placeholder="••••••••"
                           style="width:100%; border:1px solid #d1d5db; border-radius:7px; padding:9px 12px; font-size:13px; color:#111827; outline:none; box-sizing:border-box;"
                           onfocus="this.style.borderColor='#1B2F6E'"
                           onblur="this.style.borderColor='#d1d5db'">
                    @error('new_password')
                        <p style="font-size:10px; color:#E24B4A; margin:4px 0 0;">{{ $message }}</p>
                    @enderror
                </div>

                <div style="margin-bottom:20px;">
                    <label style="font-size:11px; font-weight:600; color:#374151; display:block; margin-bottom:5px;">
                        Confirmer le mot de passe <span style="color:#E24B4A;">*</span>
                    </label>
                    <input type="password"
                           wire:model="confirm_password"
                           placeholder="••••••••"
                           style="width:100%; border:1px solid #d1d5db; border-radius:7px; padding:9px 12px; font-size:13px; color:#111827; outline:none; box-sizing:border-box;"
                           onfocus="this.style.borderColor='#1B2F6E'"
                           onblur="this.style.borderColor='#d1d5db'">
                    @error('confirm_password')
                        <p style="font-size:10px; color:#E24B4A; margin:4px 0 0;">{{ $message }}</p>
                    @enderror
                </div>

                <button wire:click="updatePassword"
                        wire:loading.attr="disabled"
                        wire:target="updatePassword"
                        style="background:#1B2F6E; color:#fff; font-size:13px; font-weight:600; padding:9px 20px; border-radius:8px; border:none; cursor:pointer; display:flex; align-items:center; gap:7px;">
                    <span wire:loading.remove wire:target="updatePassword" style="display:flex; align-items:center; gap:6px;">
                        <svg width="13" height="13" viewBox="0 0 16 16" fill="none" stroke="white" stroke-width="1.5" stroke-linecap="round">
                            <path d="M11 5a3 3 0 00-6 0v2H4v7h8V7h-1V5zM8 10v2"/>
                        </svg>
                        Changer le mot de passe
                    </span>
                    <span wire:loading wire:target="updatePassword">Mise à jour...</span>
                </button>
            </div>
        </div>
    </div>
</div>