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

        @can('dashboard.view')
            <a href="{{ route('dashboard.manager') }}" wire:navigate
               style="{{ request()->routeIs('dashboard.manager') ? $linkActive : $linkInactive }}">
                <svg width="15" height="15" viewBox="0 0 16 16" fill="currentColor" style="flex-shrink:0;">
                    <rect x="1" y="1" width="6" height="6" rx="1.5"/>
                    <rect x="9" y="1" width="6" height="6" rx="1.5"/>
                    <rect x="1" y="9" width="6" height="6" rx="1.5"/>
                    <rect x="9" y="9" width="6" height="6" rx="1.5"/>
                </svg>
                Tableau de bord - Manager
                @if(request()->routeIs('dashboard.manager'))
                    <span style="margin-left:auto; width:6px; height:6px; border-radius:50%; background:#FFC72C; flex-shrink:0;"></span>
                @endif
            </a>
        @endcan

        @can('dashboard.view')
            <a href="{{ route('dashboard.show') }}" wire:navigate
               style="{{ request()->routeIs('dashboard.show') ? $linkActive : $linkInactive }}">
                <svg width="15" height="15" viewBox="0 0 16 16" fill="currentColor" style="flex-shrink:0;">
                    <rect x="1" y="1" width="6" height="6" rx="1.5"/>
                    <rect x="9" y="1" width="6" height="6" rx="1.5"/>
                    <rect x="1" y="9" width="6" height="6" rx="1.5"/>
                    <rect x="9" y="9" width="6" height="6" rx="1.5"/>
                </svg>
                Tableau de bord - Transactions
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
                Tableau de bord - Revenue
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

        
            <a href="{{ route('finance.bank-balances') }}" wire:navigate
               style="{{ request()->routeIs('finance.bank-balances.*') ? $linkActive : $linkInactive }}">
                <svg width="15" height="15" viewBox="0 0 16 16" fill="currentColor" style="flex-shrink:0;">
                    <rect x="2" y="5" width="12" height="8" rx="1.5" fill="none" stroke="currentColor" stroke-width="1.2"/>
                    <path d="M5 5V4a3 3 0 016 0v1"/>
                    <circle cx="8" cy="9" r="1.5"/>
                </svg>
                Relevés Bancaire
                @if(request()->routeIs('finance.bank-balances.*'))
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
        

        {{-- ── OPÉRATIONS ── --}}
       
            <p style="font-size:9px; text-transform:uppercase; letter-spacing:1.2px; color:rgba(255,255,255,0.35); padding:0 8px; margin-bottom:6px; margin-top:16px;">Opérations</p>
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
        

        {{-- ── ANALYSE ── --}}
        @canany(['daily-report.view', 'fraudes.view', 'fraudes.analyse'])
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


            <p style="font-size:9px; text-transform:uppercase; letter-spacing:1.2px; color:rgba(255,255,255,0.35); padding:0 8px; margin-bottom:6px; margin-top:16px;">AML & Détection de Fraude</p>
           
                <a href="{{ route('aml.index') }}" wire:navigate
                   style="{{ request()->routeIs('aml.index') ? $linkActive : $linkInactive }}">
                    <svg width="15" height="15" viewBox="0 0 16 16" fill="currentColor" style="flex-shrink:0;">
                        <path d="M8 2L1 14h14L8 2zm0 5v4"/><circle cx="8" cy="12" r="0.8"/>
                    </svg>
                    AML
                    <span style="margin-left:auto; width:6px; height:6px; border-radius:50%; background:#ef4444; flex-shrink:0;"></span>
                </a>
          

           
                <a href="{{ route('fraudes.index') }}" wire:navigate
                   style="{{ request()->routeIs('fraudes.*') ? $linkActive : $linkInactive }}">
                    <svg width="15" height="15" viewBox="0 0 16 16" fill="currentColor" style="flex-shrink:0;">
                        <path d="M8 2L1 14h14L8 2zm0 5v4"/><circle cx="8" cy="12" r="0.8"/>
                    </svg>
                    Détection fraude
                    <span style="margin-left:auto; width:6px; height:6px; border-radius:50%; background:#ef4444; flex-shrink:0;"></span>
                </a>
            

        {{-- ── ANCIEN CDRAPP ── --}}
        <p style="font-size:9px; text-transform:uppercase; letter-spacing:1.2px; color:rgba(255,255,255,0.35); padding:0 8px; margin-bottom:6px; margin-top:16px;">Ancien CDRAPP</p>

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

        {{-- ── ADMINISTRATION ── --}}
        @canany(['admin.users.view', 'admin.roles.view', 'admin.departments.view'])
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
        @endcanany

    </nav>

    
</aside>