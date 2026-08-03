<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="icon" type="image/png" href="/build/images/favicon.png">
    <title>{{ $title ?? config('app.name') }}</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @livewireStyles
    <script src="https://cdnjs.cloudflare.com/ajax/libs/sweetalert2/11.10.5/sweetalert2.all.min.js"></script>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }

        body {
            font-family: sans-serif;
            background: #F0F2F5;
            overflow: hidden; /* empêche le scroll sur le body */
        }

        .app-shell {
            display: flex;
            height: 100vh;
            overflow: hidden;
        }

        /* ── SIDEBAR fixe à gauche ── */
        .app-sidebar {
            flex-shrink: 0;
            width: 224px;
            height: 100vh;
            overflow-y: auto;
            overflow-x: hidden;
            position: sticky;
            top: 0;
        }

        /* scrollbar sidebar discrète */
        .app-sidebar::-webkit-scrollbar { width: 4px; }
        .app-sidebar::-webkit-scrollbar-track { background: transparent; }
        .app-sidebar::-webkit-scrollbar-thumb { background: rgba(255,255,255,0.2); border-radius: 4px; }

        /* ── COLONNE DROITE ── */
        .app-right {
            flex: 1;
            display: flex;
            flex-direction: column;
            height: 100vh;
            overflow: hidden;
            min-width: 0;
        }

        /* ── HEADER fixe en haut ── */
        .app-header {
            flex-shrink: 0;
            position: sticky;
            top: 0;
            z-index: 50;
        }

        /* ── CONTENU scrollable ── */
        .app-content {
            flex: 1;
            overflow-y: auto;
            overflow-x: hidden;
        }

        /* scrollbar contenu discrète */
        .app-content::-webkit-scrollbar { width: 6px; }
        .app-content::-webkit-scrollbar-track { background: #F0F2F5; }
        .app-content::-webkit-scrollbar-thumb { background: #d1d5db; border-radius: 4px; }
        .app-content::-webkit-scrollbar-thumb:hover { background: #9ca3af; }

        /* ── FOOTER fixe en bas ── */
        .app-footer {
            flex-shrink: 0;
        }
    </style>
</head>

<body>
    <div class="app-shell">

        {{-- SIDEBAR fixe --}}
        <div class="app-sidebar">
            @include('components.layouts.sidebar')
        </div>

        {{-- COLONNE DROITE --}}
        <div class="app-right">

            {{-- HEADER fixe --}}
            <div class="app-header">
                @include('components.layouts.header')
            </div>

            {{-- CONTENU scrollable --}}
            <main class="app-content">
                {{ $slot }}
            </main>

            {{-- FOOTER fixe --}}
            <div class="app-footer">
                @include('components.layouts.footer')
            </div>

        </div>
    </div>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/Chart.js/4.4.1/chart.umd.min.js"></script>
    
    @livewireScripts

    <script>
        document.addEventListener('livewire:navigate', () => {
            Swal.fire({
                title: 'Chargement...',
                html: `
                    <div style="width:100%; height:5px; background:#e5e7eb; border-radius:10px; overflow:hidden; margin-top:8px;">
                        <div id="nav-progress" style="height:100%; width:0%; background:#1B2F6E; border-radius:10px; transition:width 0.3s ease;"></div>
                    </div>
                `,
                allowOutsideClick: false,
                showConfirmButton: false,
                didOpen: () => {
                    let progress = 0;
                    window._navInterval = setInterval(() => {
                        progress += (90 - progress) * 0.12;
                        const fill = document.getElementById('nav-progress');
                        if (fill) fill.style.width = progress.toFixed(1) + '%';
                    }, 100);
                }
            });
        });

        document.addEventListener('livewire:navigated', () => {
            clearInterval(window._navInterval);
            const fill = document.getElementById('nav-progress');
            if (fill) {
                fill.style.width = '100%';
                fill.style.background = '#16a34a';
            }
            setTimeout(() => Swal.close(), 300);
        });

        
    </script>

</body>
</html>