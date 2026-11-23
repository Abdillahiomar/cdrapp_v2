<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    {{-- Thème de fond choisi (sombre, blanc cassé, jaune clair), appliqué avant l'affichage : pas de flash --}}
    <script>
        (function () {
            var apply = function () { try { var t = localStorage.getItem('tg-theme'); if (t) document.documentElement.setAttribute('data-tg-theme', t); } catch (e) {} };
            apply();
            document.addEventListener('livewire:navigated', apply);
            window.tgSetTheme = function (t) {
                document.documentElement.setAttribute('data-tg-theme', t);
                try { localStorage.setItem('tg-theme', t); } catch (e) {}
            };
        })();
    </script>
    <link rel="icon" type="image/png" href="/build/images/favicon.png">
    <title>{{ $title ?? 'Grand Tombola D-Money' }}</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @livewireStyles
    @include('draws.partials.glass-styles')
    @include('draws.partials.tombola-script')
</head>
{{-- Pages du tirage (cérémonies, cérémonie, salle, tableau d'honneur) : plein écran Glass UI, sans l'en-tête ni la barre latérale de l'application --}}
<body class="tg-body">
    <div class="tg-backdrop-fx" aria-hidden="true"><i class="o1"></i><i class="o2"></i><i class="o3"></i></div>
    {{ $slot }}
    @livewireScripts
</body>
</html>
