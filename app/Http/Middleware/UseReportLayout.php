<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Affiche une page Livewire dans la mise en page « rapport » (sans menu ni en-tête
 * utilisateur), pour la capture PDF envoyée par email.
 */
class UseReportLayout
{
    public function handle(Request $request, Closure $next): Response
    {
        config(['livewire.layout' => 'layouts.report']);

        return $next($request);
    }
}
