<?php

namespace App\Listeners;

use App\Models\AuditLog;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;

class LogAuthenticationActivity
{
    public function handleLogin(Login $event): void
    {
        $this->record('login', $event->user);
    }

    public function handleLogout(Logout $event): void
    {
        $this->record('logout', $event->user);
    }

    private function record(string $eventName, $user): void
    {
        AuditLog::create([
            'user_id'    => $user?->id,
            'user_name'  => $user?->name,
            'email'      => $user?->email,
            'event'      => $eventName,
            'ip_address' => request()->ip(),
            'user_agent' => (string) request()->userAgent(),
            'logged_at'  => now(),
        ]);
    }
}
