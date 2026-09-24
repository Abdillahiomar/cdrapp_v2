<?php

use App\Models\AuditLog;
use Livewire\Volt\Component;
use Livewire\WithPagination;
use Carbon\Carbon;

new class extends Component {

    use WithPagination;

    public string $search      = '';
    public string $filterEvent = '';
    public string $date_debut  = '';
    public string $date_fin    = '';

    public function mount(): void
    {
        $this->date_debut = Carbon::now()->subDays(30)->format('Y-m-d');
        $this->date_fin   = Carbon::now()->format('Y-m-d');
    }

    public function updated($property): void
    {
        if (in_array($property, ['search', 'filterEvent', 'date_debut', 'date_fin'])) {
            $this->resetPage();
        }
    }

    public function resetFilters(): void
    {
        $this->search      = '';
        $this->filterEvent = '';
        $this->date_debut  = Carbon::now()->subDays(30)->format('Y-m-d');
        $this->date_fin    = Carbon::now()->format('Y-m-d');
        $this->resetPage();
    }

    public function with(): array
    {
        $query = AuditLog::query();

        if ($this->search) {
            $query->where(function ($q) {
                $q->where('user_name', 'like', '%' . $this->search . '%')
                  ->orWhere('email', 'like', '%' . $this->search . '%');
            });
        }

        if ($this->filterEvent) {
            $query->where('event', $this->filterEvent);
        }

        if ($this->date_debut) {
            $query->where('logged_at', '>=', $this->date_debut . ' 00:00:00');
        }

        if ($this->date_fin) {
            $query->where('logged_at', '<', Carbon::parse($this->date_fin)->addDay()->format('Y-m-d') . ' 00:00:00');
        }

        $connexions    = (clone $query)->where('event', 'login')->count();
        $deconnexions  = (clone $query)->where('event', 'logout')->count();
        $utilisateurs  = (clone $query)->whereNotNull('user_id')->distinct('user_id')->count('user_id');

        return [
            'logs'          => $query->orderByDesc('logged_at')->paginate(50),
            'nbConnexions'  => $connexions,
            'nbDeconnexions'=> $deconnexions,
            'nbUtilisateurs'=> $utilisateurs,
        ];
    }
};
?>
<div style="padding:24px;">

    <div style="margin-bottom:20px;">
        <h2 style="font-size:16px; font-weight:700; color:#111827; margin:0 0 4px;">Journal de connexions</h2>
        <p style="font-size:12px; color:#9ca3af; margin:0;">Historique des connexions et déconnexions pour suivre l'utilisation réelle de l'application.</p>
    </div>

    {{-- KPIs --}}
    <div style="display:grid; grid-template-columns:repeat(3, minmax(0,1fr)); gap:12px; margin-bottom:16px;">
        <div style="background:#fff; border:1px solid #e5e7eb; border-radius:10px; padding:16px; border-top:3px solid #00843D;">
            <p style="font-size:11px; color:#6b7280; margin:0 0 6px;">Connexions</p>
            <p style="font-size:20px; font-weight:700; color:#111827; margin:0;">{{ number_format($nbConnexions, 0, ',', ' ') }}</p>
        </div>
        <div style="background:#fff; border:1px solid #e5e7eb; border-radius:10px; padding:16px; border-top:3px solid #E24B4A;">
            <p style="font-size:11px; color:#6b7280; margin:0 0 6px;">Déconnexions</p>
            <p style="font-size:20px; font-weight:700; color:#111827; margin:0;">{{ number_format($nbDeconnexions, 0, ',', ' ') }}</p>
        </div>
        <div style="background:#fff; border:1px solid #e5e7eb; border-radius:10px; padding:16px; border-top:3px solid #1B2F6E;">
            <p style="font-size:11px; color:#6b7280; margin:0 0 6px;">Utilisateurs distincts</p>
            <p style="font-size:20px; font-weight:700; color:#111827; margin:0;">{{ number_format($nbUtilisateurs, 0, ',', ' ') }}</p>
        </div>
    </div>

    {{-- FILTRES --}}
    <div style="background:#fff; border:1px solid #e5e7eb; border-radius:10px; padding:16px; margin-bottom:16px; display:flex; gap:12px; flex-wrap:wrap; align-items:flex-end;">
        <div>
            <label style="font-size:11px; color:#6b7280; display:block; margin-bottom:4px;">Rechercher</label>
            <input type="text" wire:model.live.debounce.400ms="search"
                   placeholder="Nom ou email..."
                   style="border:1px solid #d1d5db; border-radius:7px; padding:8px 12px; font-size:13px; color:#111827; outline:none; width:200px;">
        </div>
        <div>
            <label style="font-size:11px; color:#6b7280; display:block; margin-bottom:4px;">Événement</label>
            <select wire:model.live="filterEvent"
                    style="border:1px solid #d1d5db; border-radius:7px; padding:8px 12px; font-size:13px; color:#111827; outline:none; background:#fff; width:160px;">
                <option value="">Tous</option>
                <option value="login">Connexion</option>
                <option value="logout">Déconnexion</option>
            </select>
        </div>
        <div>
            <label style="font-size:11px; color:#6b7280; display:block; margin-bottom:4px;">Date début</label>
            <input type="date" wire:model.live="date_debut"
                   style="border:1px solid #d1d5db; border-radius:7px; padding:8px 10px; font-size:13px; color:#111827; outline:none;">
        </div>
        <div>
            <label style="font-size:11px; color:#6b7280; display:block; margin-bottom:4px;">Date fin</label>
            <input type="date" wire:model.live="date_fin"
                   style="border:1px solid #d1d5db; border-radius:7px; padding:8px 10px; font-size:13px; color:#111827; outline:none;">
        </div>
        <button wire:click="resetFilters"
                style="background:#f3f4f6; color:#374151; font-size:13px; font-weight:600; padding:9px 16px; border-radius:8px; border:1px solid #d1d5db; cursor:pointer;">
            Réinitialiser
        </button>
    </div>

    {{-- TABLEAU --}}
    <div style="background:#fff; border:1px solid #e5e7eb; border-radius:10px; overflow:hidden;">

        <div style="padding:12px 16px; border-bottom:1px solid #e5e7eb; display:flex; align-items:center; justify-content:space-between;">
            <p style="font-size:13px; font-weight:600; color:#111827; margin:0;">Historique</p>
            <span style="background:#E8ECF8; color:#1B2F6E; font-size:11px; font-weight:600; padding:3px 10px; border-radius:20px;">
                {{ $logs->total() }} événement(s)
            </span>
        </div>

        <div style="overflow-x:auto;">
            <table style="width:100%; border-collapse:collapse; font-size:12px;">
                <thead>
                    <tr style="background:#F7F8FC;">
                        <th style="padding:10px 16px; text-align:left; color:#6b7280; font-weight:500; border-bottom:1px solid #e5e7eb;">Utilisateur</th>
                        <th style="padding:10px 16px; text-align:left; color:#6b7280; font-weight:500; border-bottom:1px solid #e5e7eb;">Email</th>
                        <th style="padding:10px 16px; text-align:center; color:#6b7280; font-weight:500; border-bottom:1px solid #e5e7eb;">Événement</th>
                        <th style="padding:10px 16px; text-align:left; color:#6b7280; font-weight:500; border-bottom:1px solid #e5e7eb;">Adresse IP</th>
                        <th style="padding:10px 16px; text-align:left; color:#6b7280; font-weight:500; border-bottom:1px solid #e5e7eb;">Date / Heure</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($logs as $log)
                        <tr style="border-bottom:1px solid #f3f4f6;"
                            onmouseover="this.style.background='#F7F8FC'"
                            onmouseout="this.style.background='transparent'">
                            <td style="padding:10px 16px; color:#111827; font-weight:500;">{{ $log->user_name ?? '—' }}</td>
                            <td style="padding:10px 16px; color:#6b7280;">{{ $log->email ?? '—' }}</td>
                            <td style="padding:10px 16px; text-align:center;">
                                @if($log->event === 'login')
                                    <span style="background:#E5F5ED; color:#005C2B; font-size:11px; font-weight:600; padding:3px 10px; border-radius:20px;">Connexion</span>
                                @else
                                    <span style="background:#FDE8E8; color:#7F1D1D; font-size:11px; font-weight:600; padding:3px 10px; border-radius:20px;">Déconnexion</span>
                                @endif
                            </td>
                            <td style="padding:10px 16px; color:#6b7280;">{{ $log->ip_address ?? '—' }}</td>
                            <td style="padding:10px 16px; color:#6b7280;">{{ $log->logged_at->format('d/m/Y H:i:s') }}</td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="5" style="padding:24px; text-align:center; color:#9ca3af;">Aucun événement trouvé pour ces critères.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        <div style="padding:12px 16px; border-top:1px solid #e5e7eb;">
            {{ $logs->links() }}
        </div>
    </div>

</div>
