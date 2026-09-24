<?php

use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

new #[Layout('layouts.guest')] class extends Component
{
    //
}; ?>
<div>
<div style="min-height:100vh; display:flex; align-items:center; justify-content:center; padding:24px; font-family:sans-serif; background:#F0F2F5;">

    <div style="background:#fff; border-radius:14px; box-shadow:0 1px 3px rgba(0,0,0,0.06); padding:44px 40px; max-width:420px; width:100%; text-align:center;">

        <div style="width:64px; height:64px; background:#FEF3C7; border-radius:16px; display:flex; align-items:center; justify-content:center; margin:0 auto 20px;">
            <svg width="30" height="30" viewBox="0 0 24 24" fill="none">
                <circle cx="12" cy="12" r="9" stroke="#92400E" stroke-width="1.5"/>
                <path d="M12 7v6" stroke="#92400E" stroke-width="1.8" stroke-linecap="round"/>
                <circle cx="12" cy="16.3" r="1" fill="#92400E"/>
            </svg>
        </div>

        <h1 style="font-size:19px; font-weight:700; color:#111827; margin:0 0 10px;">
            Session expirée
        </h1>
        <p style="font-size:13px; color:#6b7280; line-height:1.6; margin:0 0 28px;">
            Votre session a expiré ou vous n'êtes plus connecté. Veuillez vous reconnecter pour continuer à utiliser la plateforme.
        </p>

        <a href="{{ route('login') }}" wire:navigate
           style="display:inline-flex; align-items:center; justify-content:center; gap:8px; width:100%; background:#1B2F6E; color:#fff; font-size:14px; font-weight:600; padding:11px; border-radius:8px; text-decoration:none; box-sizing:border-box;">
            <svg width="15" height="15" viewBox="0 0 16 16" fill="white">
                <path d="M6 3H3a1 1 0 00-1 1v9a1 1 0 001 1h3M9 11l3-3-3-3M12 8H4" stroke="white" stroke-width="1.3" fill="none" stroke-linecap="round"/>
            </svg>
            Se reconnecter
        </a>
    </div>

</div>
</div>
