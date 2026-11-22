<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" style="background:#071530;">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="icon" type="image/png" href="/build/images/favicon.png">
    <title>{{ $title ?? 'Grand Tombola D-Money' }}</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @livewireStyles
    @include('draws.partials.glass-styles')
    @include('draws.partials.tombola-script')
</head>
{{-- Pages du tirage (cérémonies, cérémonie, salle) : plein écran Glass UI, sans l'en-tête ni la barre latérale de l'application --}}
<body class="tg-body" style="background-color:#071530;">
    <div class="tg-backdrop-fx" aria-hidden="true"><i class="o1"></i><i class="o2"></i><i class="o3"></i></div>
    {{ $slot }}
    @livewireScripts
</body>
</html>
