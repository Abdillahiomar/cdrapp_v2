<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $title ?? 'Rapport — ' . config('app.name') }}</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @livewireStyles
    {{-- Copies locales de Chart.js : le serveur qui génère le PDF n'a pas forcément accès à internet --}}
    <script src="{{ asset('vendor/chartjs/chart.umd.min.js') }}"></script>
    <script src="{{ asset('vendor/chartjs/chartjs-plugin-datalabels.min.js') }}"></script>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body { font-family: sans-serif; background: #F0F2F5; }
        /* Une carte ne se coupe pas entre deux pages du PDF */
        .report-page div[style*="border-radius:10px"], .report-page div[style*="border-radius:12px"] { break-inside: avoid; page-break-inside: avoid; }
    </style>
</head>
<body>
    <main class="report-page">
        {{ $slot }}
    </main>
    @livewireScripts
</body>
</html>
