<aside style="width:224px; background:#1B2F6E; color:white; min-height:100vh; display:flex; flex-direction:column;">

    {{-- Logo --}}
    <div style="padding:20px 16px 16px; border-bottom:1px solid rgba(255,255,255,0.12);">
        <div style="display:flex; align-items:center; gap:12px;">
            <div style="width:40px; height:40px; background:#FFC72C; border-radius:10px; display:flex; align-items:center; justify-content:center; flex-shrink:0;">
                <svg width="24" height="24" viewBox="0 0 24 24" fill="none">
                    <rect x="3" y="7" width="18" height="12" rx="2" fill="#1B2F6E"/>
                    <path d="M7 12h10M7 15h6" stroke="#FFC72C" stroke-width="1.5" stroke-linecap="round"/>
                    <circle cx="17" cy="15" r="1.5" fill="white"/>
                </svg>
            </div>
            <div>
                <p style="font-size:15px; font-weight:600; line-height:1.2;">CDRAPP</p>
                <p style="font-size:10px; color:rgba(255,255,255,0.45); line-height:1.2;">Digital Mobile Money</p>
            </div>
        </div>
    </div>

    {{-- Navigation --}}
    <nav style="flex:1; padding:12px 8px; overflow-y:auto;">
        @php
            $linkBase   = 'display:flex; align-items:center; gap:10px; padding:8px 12px; border-radius:8px; font-size:12px; text-decoration:none; margin-bottom:2px; transition:background 0.15s;';
            $linkActive = $linkBase . 'background:rgba(255,255,255,0.18); color:#FFC72C; font-weight:600;';
            $linkInactive = $linkBase . 'color:rgba(255,255,255,0.62);';
        @endphp

        {{-- ── PRINCIPAL ── --}}
        <p style="font-size:9px; text-transform:uppercase; letter-spacing:1.2px; color:rgba(255,255,255,0.35); padding:0 8px; margin-bottom:6px; margin-top:4px;">Principal</p>

        @can('dashboard.admin')
            <a href="{{ route('dashboard.manager') }}" wire:navigate
               style="{{ request()->routeIs('dashboard.manager') ? $linkActive : $linkInactive }}">
                <svg width="15" height="15" viewBox="0 0 16 16" fill="currentColor" style="flex-shrink:0;">
                    <rect x="1" y="1" width="6" height="6" rx="1.5"/>
                    <rect x="9" y="1" width="6" height="6" rx="1.5"/>
                    <rect x="1" y="9" width="6" height="6" rx="1.5"/>
                    <rect x="9" y="9" width="6" height="6" rx="1.5"/>
                </svg>
                 Manager - Tableau de bord
                @if(request()->routeIs('dashboard.manager'))
                    <span style="margin-left:auto; width:6px; height:6px; border-radius:50%; background:#FFC72C; flex-shrink:0;"></span>
                @endif
            </a>
        @endcan

        @can('dashboard.analytics')
            <a href="{{ route('dashboard.show') }}" wire:navigate
               style="{{ request()->routeIs('dashboard.show') ? $linkActive : $linkInactive }}">
                <svg width="15" height="15" viewBox="0 0 16 16" fill="currentColor" style="flex-shrink:0;">
                    <rect x="1" y="1" width="6" height="6" rx="1.5"/>
                    <rect x="9" y="1" width="6" height="6" rx="1.5"/>
                    <rect x="1" y="9" width="6" height="6" rx="1.5"/>
                    <rect x="9" y="9" width="6" height="6" rx="1.5"/>
                </svg>
                Tableau de bord
                @if(request()->routeIs('dashboard.show'))
                    <span style="margin-left:auto; width:6px; height:6px; border-radius:50%; background:#FFC72C; flex-shrink:0;"></span>
                @endif
            </a>
        @endcan

        @can('dashboard.view')
            <a href="{{ route('dashboard.index') }}" wire:navigate
               style="{{ request()->routeIs('dashboard.index') ? $linkActive : $linkInactive }}">
                <svg width="15" height="15" viewBox="0 0 16 16" fill="currentColor" style="flex-shrink:0;">
                    <rect x="1" y="1" width="6" height="6" rx="1.5"/>
                    <rect x="9" y="1" width="6" height="6" rx="1.5"/>
                    <rect x="1" y="9" width="6" height="6" rx="1.5"/>
                    <rect x="9" y="9" width="6" height="6" rx="1.5"/>
                </svg>
                TOP 10
                @if(request()->routeIs('dashboard.index'))
                    <span style="margin-left:auto; width:6px; height:6px; border-radius:50%; background:#FFC72C; flex-shrink:0;"></span>
                @endif
            </a>
        @endcan

        @can('transactions.view')
            <a href="{{ route('transactions.index') }}" wire:navigate
               style="{{ request()->routeIs('transactions.index') ? $linkActive : $linkInactive }}">
                <svg width="15" height="15" viewBox="0 0 16 16" fill="currentColor" style="flex-shrink:0;">
                    <path d="M2 4h12v9a1 1 0 01-1 1H3a1 1 0 01-1-1V4z"/>
                </svg>
                Transactions
                @if(request()->routeIs('transactions.index'))
                    <span style="margin-left:auto; width:6px; height:6px; border-radius:50%; background:#FFC72C; flex-shrink:0;"></span>
                @endif
            </a>
        @endcan

        @can('customers.view')
            <a href="{{ route('customers.index') }}" wire:navigate
               style="{{ request()->routeIs('customers.index') ? $linkActive : $linkInactive }}">
                <svg width="15" height="15" viewBox="0 0 16 16" fill="currentColor" style="flex-shrink:0;">
                    <circle cx="8" cy="5" r="3"/>
                    <path d="M2 13c0-3 2.7-5 6-5s6 2 6 5"/>
                </svg>
                Clients
                @if(request()->routeIs('customers.index'))
                    <span style="margin-left:auto; width:6px; height:6px; border-radius:50%; background:#FFC72C; flex-shrink:0;"></span>
                @endif
            </a>
        @endcan

        @can('organizations.view')
            <a href="{{ route('organizations.index') }}" wire:navigate
               style="{{ request()->routeIs('organizations.*') ? $linkActive : $linkInactive }}">
                <svg width="15" height="15" viewBox="0 0 16 16" fill="currentColor" style="flex-shrink:0;">
                    <path d="M2 14V6l6-4 6 4v8H2z"/>
                    <path d="M6 14v-4h4v4" fill="none" stroke="currentColor" stroke-width="1.2"/>
                </svg>
                Organisations
                @if(request()->routeIs('organizations.*'))
                    <span style="margin-left:auto; width:6px; height:6px; border-radius:50%; background:#FFC72C; flex-shrink:0;"></span>
                @endif
            </a>
        @endcan

        @can('transactions.summary.view')
            <a href="{{ route('transactions.summary') }}" wire:navigate
               style="{{ request()->routeIs('transactions.summary') ? $linkActive : $linkInactive }}">
                <svg width="15" height="15" viewBox="0 0 16 16" fill="currentColor" style="flex-shrink:0;">
                    <rect x="1" y="9" width="3" height="6" rx="0.5"/>
                    <rect x="6" y="5" width="3" height="10" rx="0.5"/>
                    <rect x="11" y="2" width="3" height="13" rx="0.5"/>
                </svg>
                Transactions Summary
                @if(request()->routeIs('transactions.summary'))
                    <span style="margin-left:auto; width:6px; height:6px; border-radius:50%; background:#FFC72C; flex-shrink:0;"></span>
                @endif
            </a>
        @endcan

        @can('relever.bancaire')
            <a href="{{ route('finance.bank-balances') }}" wire:navigate
               style="{{ request()->routeIs('finance.*') ? $linkActive : $linkInactive }}">
                <svg width="15" height="15" viewBox="0 0 16 16" fill="currentColor" style="flex-shrink:0;">
                    <rect x="2" y="5" width="12" height="8" rx="1.5" fill="none" stroke="currentColor" stroke-width="1.2"/>
                    <path d="M5 5V4a3 3 0 016 0v1"/>
                    <circle cx="8" cy="9" r="1.5"/>
                </svg>
                Ratio D'equivalance
                @if(request()->routeIs('finance.*'))
                    <span style="margin-left:auto; width:6px; height:6px; border-radius:50%; background:#FFC72C; flex-shrink:0;"></span>
                @endif
            </a>
        
       
            <a href="{{ route('balances') }}" wire:navigate
               style="{{ request()->routeIs('balances') ? $linkActive : $linkInactive }}">
                <svg width="15" height="15" viewBox="0 0 16 16" fill="currentColor" style="flex-shrink:0;">
                    <rect x="2" y="5" width="12" height="8" rx="1.5" fill="none" stroke="currentColor" stroke-width="1.2"/>
                    <path d="M5 5V4a3 3 0 016 0v1"/>
                    <circle cx="8" cy="9" r="1.5"/>
                </svg>
                All Accounts Balance
                @if(request()->routeIs('balances'))
                    <span style="margin-left:auto; width:6px; height:6px; border-radius:50%; background:#FFC72C; flex-shrink:0;"></span>
                @endif
            </a>
        @endcan

        @can('finance.bill-payments.view')
            <a href="{{ route('bill-payments.index') }}" wire:navigate
               style="{{ request()->routeIs('bill-payments.*') ? $linkActive : $linkInactive }}">
                <svg width="15" height="15" viewBox="0 0 16 16" fill="currentColor" style="flex-shrink:0;">
                    <path d="M3 1.5h10v13l-2-1.2-1.5 1.2L8 13.3l-1.5 1.2L5 13.3l-2 1.2z" fill="none" stroke="currentColor" stroke-width="1.2" stroke-linejoin="round"/>
                    <path d="M5.5 5h5M5.5 7.5h5M5.5 10h3" stroke="currentColor" stroke-width="1.2" stroke-linecap="round"/>
                </svg>
                Paiements de factures
                @if(request()->routeIs('bill-payments.*'))
                    <span style="margin-left:auto; width:6px; height:6px; border-radius:50%; background:#FFC72C; flex-shrink:0;"></span>
                @endif
            </a>
        @endcan

        {{-- ── CUSTOMERS (base clients, distinct du lien « Clients » ci-dessus) ── --}}
        @can('customer360.view')
            <p style="font-size:9px; text-transform:uppercase; letter-spacing:1.2px; color:rgba(255,255,255,0.35); padding:0 8px; margin-bottom:6px; margin-top:16px;">Customers</p>
            @foreach([
                'customer360.dashboard' => ['Tableau de bord clients', '<path d="M2 14V9h3v5M6.5 14V5h3v9M11 14V2h3v12" fill="none" stroke="currentColor" stroke-width="1.2" stroke-linejoin="round"/>'],
                'customer360.directory' => ['Annuaire clients', '<circle cx="8" cy="5" r="2.6" fill="none" stroke="currentColor" stroke-width="1.2"/><path d="M2.5 14c.6-2.9 2.8-4.5 5.5-4.5s4.9 1.6 5.5 4.5" fill="none" stroke="currentColor" stroke-width="1.2" stroke-linecap="round"/>'],
                'customer360.controls'  => ['Contrôles clients', '<path d="M8 1.5l5.5 2v4c0 3.4-2.3 6-5.5 7-3.2-1-5.5-3.6-5.5-7v-4z" fill="none" stroke="currentColor" stroke-width="1.2" stroke-linejoin="round"/><path d="M8 5v3.5M8 10.5v.5" stroke="currentColor" stroke-width="1.4" stroke-linecap="round"/>'],
                'customer360.journal'   => ['Journal des statuts', '<rect x="2" y="2" width="12" height="12" rx="1.5" fill="none" stroke="currentColor" stroke-width="1.2"/><path d="M5 6h6M5 9h6M5 12h3" stroke="currentColor" stroke-width="1.2" stroke-linecap="round"/>'],
            ] as $route => [$label, $icon])
                <a href="{{ route($route) }}" wire:navigate style="{{ request()->routeIs($route) ? $linkActive : $linkInactive }}">
                    <svg width="15" height="15" viewBox="0 0 16 16" style="flex-shrink:0;">{!! $icon !!}</svg>
                    {{ $label }}
                    @if(request()->routeIs($route))
                        <span style="margin-left:auto; width:6px; height:6px; border-radius:50%; background:#FFC72C; flex-shrink:0;"></span>
                    @endif
                </a>
            @endforeach
        @endcan

        {{-- ── RÉSEAU DE DISTRIBUTION ── --}}
        @can('network.view')
            <p style="font-size:9px; text-transform:uppercase; letter-spacing:1.2px; color:rgba(255,255,255,0.35); padding:0 8px; margin-bottom:6px; margin-top:16px;">Réseau de distribution</p>
            @foreach([
                'network.dashboard' => ['Tableau de bord réseau', '<path d="M2 14V9h3v5M6.5 14V5h3v9M11 14V2h3v12" fill="none" stroke="currentColor" stroke-width="1.2" stroke-linejoin="round"/>'],
                'network.directory' => ['Annuaire', '<rect x="2.5" y="1.5" width="11" height="13" rx="1.5" fill="none" stroke="currentColor" stroke-width="1.2"/><path d="M5 5h6M5 8h6M5 11h4" stroke="currentColor" stroke-width="1.2" stroke-linecap="round"/>'],
                'network.networks'  => ['Réseaux', '<circle cx="8" cy="3.5" r="1.8" fill="none" stroke="currentColor" stroke-width="1.2"/><circle cx="3.5" cy="12.5" r="1.8" fill="none" stroke="currentColor" stroke-width="1.2"/><circle cx="12.5" cy="12.5" r="1.8" fill="none" stroke="currentColor" stroke-width="1.2"/><path d="M7 5l-2.6 5.8M9 5l2.6 5.8" stroke="currentColor" stroke-width="1.2"/>'],
                'network.controls'  => ['Contrôles réseau', '<path d="M8 1.5l5.5 2v4c0 3.4-2.3 6-5.5 7-3.2-1-5.5-3.6-5.5-7v-4z" fill="none" stroke="currentColor" stroke-width="1.2" stroke-linejoin="round"/><path d="M8 5v3.5M8 10.5v.5" stroke="currentColor" stroke-width="1.4" stroke-linecap="round"/>'],
            ] as $route => [$label, $icon])
                <a href="{{ route($route) }}" wire:navigate style="{{ request()->routeIs($route) ? $linkActive : $linkInactive }}">
                    <svg width="15" height="15" viewBox="0 0 16 16" style="flex-shrink:0;">{!! $icon !!}</svg>
                    {{ $label }}
                    @if(request()->routeIs($route))
                        <span style="margin-left:auto; width:6px; height:6px; border-radius:50%; background:#FFC72C; flex-shrink:0;"></span>
                    @endif
                </a>
            @endforeach
        @endcan

        {{-- ── OPÉRATIONS ── --}}
        @canany(['operations.import-msisdn', 'tools.qrcode'])
            <p style="font-size:9px; text-transform:uppercase; letter-spacing:1.2px; color:rgba(255,255,255,0.35); padding:0 8px; margin-bottom:6px; margin-top:16px;">Opérations</p>
        @endcanany

       @can('operations.import-msisdn')
            <a href="{{ route('operations.index') }}" wire:navigate
               style="{{ request()->routeIs('operations.*') ? $linkActive : $linkInactive }}">
                <svg width="15" height="15" viewBox="0 0 16 16" fill="currentColor" style="flex-shrink:0;">
                    <path d="M8 2v8M5 7l3 3 3-3" stroke="currentColor" stroke-width="1.2" fill="none" stroke-linecap="round"/>
                    <path d="M2 12h12" stroke="currentColor" stroke-width="1.2" stroke-linecap="round"/>
                </svg>
                Import MSISDN
                @if(request()->routeIs('operations.*'))
                    <span style="margin-left:auto; width:6px; height:6px; border-radius:50%; background:#FFC72C; flex-shrink:0;"></span>
                @endif
            </a>
        @endcan

        @can('tools.qrcode')
            <a href="{{ route('qrcode.index') }}" wire:navigate
               style="{{ request()->routeIs('qrcode.*') ? $linkActive : $linkInactive }}">
                <svg width="15" height="15" viewBox="0 0 16 16" fill="currentColor" style="flex-shrink:0;">
                    <path d="M2 2h5v5H2zM9 2h5v5H9zM2 9h5v5H2z" fill="none" stroke="currentColor" stroke-width="1.2"/>
                    <rect x="3.8" y="3.8" width="1.4" height="1.4"/><rect x="10.8" y="3.8" width="1.4" height="1.4"/><rect x="3.8" y="10.8" width="1.4" height="1.4"/>
                    <path d="M9 9h2v2H9zM12 11h2v3h-2zM9 12h2v2H9z"/>
                </svg>
                Générateur QR Code
                @if(request()->routeIs('qrcode.*'))
                    <span style="margin-left:auto; width:6px; height:6px; border-radius:50%; background:#FFC72C; flex-shrink:0;"></span>
                @endif
            </a>
        @endcan
        

        {{-- ── ANALYSE ── --}}
        @canany(['daily-report.view'])
            <p style="font-size:9px; text-transform:uppercase; letter-spacing:1.2px; color:rgba(255,255,255,0.35); padding:0 8px; margin-bottom:6px; margin-top:16px;">Analyse</p>

            @can('daily-report.view')
                <a href="{{ route('daily-report.index') }}" wire:navigate
                   style="{{ request()->routeIs('daily-report.*') ? $linkActive : $linkInactive }}">
                    <svg width="15" height="15" viewBox="0 0 16 16" fill="currentColor" style="flex-shrink:0;">
                        <rect x="1" y="2" width="14" height="12" rx="1.5" fill="none" stroke="currentColor" stroke-width="1.2"/>
                        <path d="M4 6h8M4 9h5" stroke="currentColor" stroke-width="1.2" stroke-linecap="round"/>
                    </svg>
                    Daily Report
                    @if(request()->routeIs('daily-report.*'))
                        <span style="margin-left:auto; width:6px; height:6px; border-radius:50%; background:#FFC72C; flex-shrink:0;"></span>
                    @endif
                </a>
            @endcan

            @can('daily-report.view')
                <a href="{{ route('amana_report.index') }}" wire:navigate
                   style="{{ request()->routeIs('amana_report.*') ? $linkActive : $linkInactive }}">
                    <svg width="15" height="15" viewBox="0 0 16 16" fill="currentColor" style="flex-shrink:0;">
                        <rect x="1" y="2" width="14" height="12" rx="1.5" fill="none" stroke="currentColor" stroke-width="1.2"/>
                        <path d="M4 6h8M4 9h5" stroke="currentColor" stroke-width="1.2" stroke-linecap="round"/>
                    </svg>
                    Amana Report
                    @if(request()->routeIs('amana_report.*'))
                        <span style="margin-left:auto; width:6px; height:6px; border-radius:50%; background:#FFC72C; flex-shrink:0;"></span>
                    @endif
                </a>
            @endcan
           
        @endcanany


        {{--  Fraude and AML Section --}}

        @canany(['fraudes.view', 'aml.alerts.view', 'pca.view'])
           <p style="font-size:9px; text-transform:uppercase; letter-spacing:1.2px; color:rgba(255,255,255,0.35); padding:0 8px; margin-bottom:6px; margin-top:16px;">
            AML & Détection de Fraude</p>
        @endcanany

        @can('pca.view')
            {{-- rescue : le menu s'affiche sur toutes les pages, il ne doit pas casser si la table n'existe pas encore --}}
            @php $pcaActive = rescue(fn () => \App\Models\PcaIncident::whereNull('ended_at')->exists(), false, false); @endphp
            <a href="{{ route('pca.index') }}" wire:navigate
               style="{{ request()->routeIs('pca.*') ? $linkActive : $linkInactive }}">
                <svg width="15" height="15" viewBox="0 0 16 16" fill="currentColor" style="flex-shrink:0;">
                    <path d="M8 1.5l5.5 2v4c0 3.4-2.3 6-5.5 7-3.2-1-5.5-3.6-5.5-7v-4z" fill="none" stroke="currentColor" stroke-width="1.2" stroke-linejoin="round"/>
                    <path d="M5.8 8l1.6 1.6 3-3.2" fill="none" stroke="currentColor" stroke-width="1.3" stroke-linecap="round" stroke-linejoin="round"/>
                </svg>
                PCA — Soldes
                @if($pcaActive)
                    <span style="margin-left:auto; background:#ef4444; color:#fff; font-size:9px; font-weight:700; padding:1px 6px; border-radius:8px; flex-shrink:0;">ACTIF</span>
                @endif
            </a>
        @endcan

        @can('aml.alerts.view')
            @php $amlNewAlerts = \App\Models\AmlAlert::newCount(); @endphp
            <a href="{{ route('aml.alerts') }}" wire:navigate
               style="{{ request()->routeIs('aml.alerts', 'aml.rules', 'aml.watchlist') ? $linkActive : $linkInactive }}">
                <svg width="15" height="15" viewBox="0 0 16 16" fill="currentColor" style="flex-shrink:0;">
                    <path d="M8 1a5 5 0 00-5 5v3l-1.5 2h13L13 9V6a5 5 0 00-5-5zm0 14a2 2 0 01-2-2h4a2 2 0 01-2 2z"/>
                </svg>
                Alertes AML
                @if($amlNewAlerts > 0)
                    <span style="margin-left:auto; background:#ef4444; color:#fff; font-size:10px; font-weight:700; padding:1px 7px; border-radius:10px; flex-shrink:0;">{{ $amlNewAlerts > 999 ? '999+' : $amlNewAlerts }}</span>
                @endif
            </a>
        @endcan

        @can('fraudes.view')

            <a href="{{ route('fraudes.index') }}" wire:navigate
                style="{{ request()->routeIs('fraudes.index') ? $linkActive : $linkInactive }}">
                    <svg width="15" height="15" viewBox="0 0 16 16" fill="currentColor" style="flex-shrink:0;">
                        <path d="M8 2L1 14h14L8 2zm0 5v4"/><circle cx="8" cy="12" r="0.8"/>
                    </svg>
                    Detection de fraude
                <span style="margin-left:auto; width:6px; height:6px; border-radius:50%; background:#ef4444; flex-shrink:0;"></span>
            </a>   

            <a href="{{ route('aml.index') }}" wire:navigate
                   style="{{ request()->routeIs('aml.index') ? $linkActive : $linkInactive }}">
                    <svg width="15" height="15" viewBox="0 0 16 16" fill="currentColor" style="flex-shrink:0;">
                        <path d="M8 2L1 14h14L8 2zm0 5v4"/><circle cx="8" cy="12" r="0.8"/>
                    </svg>
                    AML
                    <span style="margin-left:auto; width:6px; height:6px; border-radius:50%; background:#ef4444; flex-shrink:0;"></span>
            </a>
        @endcan

        @can('kyc.duplicates.view')
            <a href="{{ route('kyc.index') }}" wire:navigate
               style="{{ request()->routeIs('kyc.index') ? $linkActive : $linkInactive }}">
                <svg width="15" height="15" viewBox="0 0 16 16" fill="currentColor" style="flex-shrink:0;">
                    <circle cx="6" cy="6" r="2.5" fill="none" stroke="currentColor" stroke-width="1.2"/>
                    <circle cx="11" cy="9" r="2.5" fill="none" stroke="currentColor" stroke-width="1.2"/>
                    <path d="M2 14c0-2.5 1.8-4 4-4M9 14c0-2 1.4-3.3 3-3.3" stroke="currentColor" stroke-width="1.2" fill="none" stroke-linecap="round"/>
                </svg>
                KYC — Comptes multiples
                @if(request()->routeIs('kyc.index'))
                    <span style="margin-left:auto; width:6px; height:6px; border-radius:50%; background:#FFC72C; flex-shrink:0;"></span>
                @endif
            </a>
        @endcan
            

        {{-- ── ANCIEN CDRAPP ── --}}
        <p style="font-size:9px; text-transform:uppercase; letter-spacing:1.2px; color:rgba(255,255,255,0.35); padding:0 8px; margin-bottom:6px; margin-top:16px;">Ancien CDRAPP</p>

       @can('ancien_cdrapp.view')
        <a href="{{ route('ancien_cdrapp') }}" wire:navigate
           style="{{ request()->routeIs('ancien_cdrapp') ? $linkActive : $linkInactive }}">
            <svg width="15" height="15" viewBox="0 0 16 16" fill="currentColor" style="flex-shrink:0;">
                <path d="M2 4h12v9a1 1 0 01-1 1H3a1 1 0 01-1-1V4z"/>
            </svg>
            Ancien CDRAPP Transaction
            @if(request()->routeIs('ancien_cdrapp'))
                <span style="margin-left:auto; width:6px; height:6px; border-radius:50%; background:#FFC72C; flex-shrink:0;"></span>
            @endif
        </a>
        @endcan

        {{-- ── ADMINISTRATION ── --}}
        @canany(['admin.users.view', 'admin.roles.view', 'admin.departments.view', 'admin.audit-logs.view', 'admin.exports.view', 'admin.agencies.manage'])
            <p style="font-size:9px; text-transform:uppercase; letter-spacing:1.2px; color:rgba(255,255,255,0.35); padding:0 8px; margin-bottom:6px; margin-top:16px;">Administration</p>

            @can('admin.users.view')
                <a href="{{ route('admin.users.index') }}" wire:navigate
                   style="{{ request()->routeIs('admin.users.*') ? $linkActive : $linkInactive }}">
                    <svg width="15" height="15" viewBox="0 0 16 16" fill="currentColor" style="flex-shrink:0;">
                        <circle cx="6" cy="5" r="3"/>
                        <path d="M1 13c0-3 2.2-5 5-5s5 2 5 5"/>
                        <path d="M13 8v4M11 10h4" stroke="currentColor" stroke-width="1.2" stroke-linecap="round" fill="none"/>
                    </svg>
                    Utilisateurs
                    @if(request()->routeIs('admin.users.*'))
                        <span style="margin-left:auto; width:6px; height:6px; border-radius:50%; background:#FFC72C; flex-shrink:0;"></span>
                    @endif
                </a>
            @endcan

            @can('admin.agencies.manage')
                <a href="{{ route('admin.agencies.index') }}" wire:navigate
                   style="{{ request()->routeIs('admin.agencies.*') ? $linkActive : $linkInactive }}">
                    <svg width="15" height="15" viewBox="0 0 16 16" fill="currentColor" style="flex-shrink:0;">
                        <path d="M2 14V6l6-4 6 4v8" fill="none" stroke="currentColor" stroke-width="1.2" stroke-linejoin="round"/>
                        <path d="M6 14v-4h4v4" fill="none" stroke="currentColor" stroke-width="1.2"/>
                    </svg>
                    Agences
                    @if(request()->routeIs('admin.agencies.*'))
                        <span style="margin-left:auto; width:6px; height:6px; border-radius:50%; background:#FFC72C; flex-shrink:0;"></span>
                    @endif
                </a>
            @endcan

            @can('admin.roles.view')
                <a href="{{ route('admin.roles.index') }}" wire:navigate
                   style="{{ request()->routeIs('admin.roles.*') ? $linkActive : $linkInactive }}">
                    <svg width="15" height="15" viewBox="0 0 16 16" fill="currentColor" style="flex-shrink:0;">
                        <path d="M8 1a3 3 0 100 6A3 3 0 008 1zM2 14s-1 0-1-1 1-4 7-4 7 3 7 4-1 1-1 1H2z"/>
                    </svg>
                    Rôles & Permissions
                    @if(request()->routeIs('admin.roles.*'))
                        <span style="margin-left:auto; width:6px; height:6px; border-radius:50%; background:#FFC72C; flex-shrink:0;"></span>
                    @endif
                </a>
            @endcan

            @can('admin.audit-logs.view')
                <a href="{{ route('admin.audit-logs.index') }}" wire:navigate
                   style="{{ request()->routeIs('admin.audit-logs.*') ? $linkActive : $linkInactive }}">
                    <svg width="15" height="15" viewBox="0 0 16 16" fill="currentColor" style="flex-shrink:0;">
                        <rect x="1" y="2" width="14" height="12" rx="1.5" fill="none" stroke="currentColor" stroke-width="1.2"/>
                        <path d="M4 6h8M4 9h8M4 12h5" stroke="currentColor" stroke-width="1.2" stroke-linecap="round"/>
                    </svg>
                    Journal de connexions
                    @if(request()->routeIs('admin.audit-logs.*'))
                        <span style="margin-left:auto; width:6px; height:6px; border-radius:50%; background:#FFC72C; flex-shrink:0;"></span>
                    @endif
                </a>
            @endcan

            @can('admin.exports.view')
                <a href="{{ route('admin.exports.index') }}" wire:navigate
                   style="{{ request()->routeIs('admin.exports.*') ? $linkActive : $linkInactive }}">
                    <svg width="15" height="15" viewBox="0 0 16 16" fill="currentColor" style="flex-shrink:0;">
                        <path d="M3 1.5h6.5L13 5v9.5H3z" fill="none" stroke="currentColor" stroke-width="1.2" stroke-linejoin="round"/>
                        <path d="M8 6.5v5M5.8 9.3 8 11.5l2.2-2.2" fill="none" stroke="currentColor" stroke-width="1.2" stroke-linecap="round" stroke-linejoin="round"/>
                    </svg>
                    Exports
                    @if(request()->routeIs('admin.exports.*'))
                        <span style="margin-left:auto; width:6px; height:6px; border-radius:50%; background:#FFC72C; flex-shrink:0;"></span>
                    @endif
                </a>
            @endcan
        @endcanany

    </nav>
</aside>