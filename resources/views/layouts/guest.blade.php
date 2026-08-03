<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    {{-- Si tu utilises un PNG à la place : --}}
     <link rel="icon" type="image/png" href="/build/images/favicon.png">
    <title>{{ config('app.name') }} — Connexion</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @livewireStyles
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body { overflow: hidden; }
    </style>
</head>
<body>
    {{ $slot }}
    @livewireScripts
</body>
</html>