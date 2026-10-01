<?php

use App\Models\ExportRequest;
use App\Models\User;
use Illuminate\Support\Facades\Storage;
use Livewire\Volt\Component;
use Livewire\WithPagination;
use Carbon\Carbon;

new class extends Component {

    use WithPagination;

    public string $filterUser   = '';
    public string $filterStatus = '';
    public string $filterType   = '';
    public string $date_debut   = '';
    public string $date_fin     = '';

    public function mount(): void
    {
        $this->date_debut = Carbon::now()->subDays(30)->format('Y-m-d');
        $this->date_fin   = Carbon::now()->format('Y-m-d');
    }

    public function updated($property): void
    {
        if (in_array($property, ['filterUser', 'filterStatus', 'filterType', 'date_debut', 'date_fin'])) {
            $this->resetPage();
        }
    }

    public function resetFilters(): void
    {
        $this->filterUser   = '';
        $this->filterStatus = '';
        $this->filterType   = '';
        $this->date_debut   = Carbon::now()->subDays(30)->format('Y-m-d');
        $this->date_fin     = Carbon::now()->format('Y-m-d');
        $this->resetPage();
    }

    /**
     * Téléchargement par un administrateur : ne modifie pas le statut,
     * "uploaded" reste réservé à l'utilisateur qui a demandé l'export.
     */
    public function download(int $exportId)
    {
        $export = ExportRequest::findOrFail($exportId);

        if (!$export->file_path || !Storage::disk('local')->exists($export->file_path)) {
            session()->flash('export-error', "Le fichier de l'export #{$export->id} n'est plus disponible.");
            return null;
        }

        return response()->download(
            Storage::disk('local')->path($export->file_path),
            $export->fileName()
        );
    }

    public function deleteExport(int $exportId): void
    {
        abort_unless(auth()->user()->can('admin.exports.delete'), 403);

        $export = ExportRequest::findOrFail($exportId);

        // Un export en attente / en cours est encore en train d'être écrit par le worker
        if (!in_array($export->status, ['done', 'uploaded', 'failed'])) {
            session()->flash('export-error', "L'export #{$export->id} ne peut pas être supprimé dans son état actuel.");
            return;
        }

        if (!$export->deleteFile()) {
            session()->flash('export-error', "Impossible de supprimer le fichier de l'export #{$export->id} (permissions ?).");
            return;
        }

        session()->flash('export-message', "Le fichier de l'export #{$export->id} a été supprimé.");
    }

    public function with(): array
    {
        $query = ExportRequest::query()->with('user:id,name,email');

        if ($this->filterUser) {
            $query->where('user_id', $this->filterUser);
        }

        if ($this->filterType) {
            $query->where('type', $this->filterType);
        }

        if ($this->date_debut) {
            $query->where('created_at', '>=', $this->date_debut . ' 00:00:00');
        }

        if ($this->date_fin) {
            $query->where('created_at', '<', Carbon::parse($this->date_fin)->addDay()->format('Y-m-d') . ' 00:00:00');
        }

        // Compteurs calculés avant le filtre statut, pour garder la répartition complète
        $countsByStatus = (clone $query)
            ->selectRaw('status, COUNT(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status');

        if ($this->filterStatus) {
            $query->where('status', $this->filterStatus);
        }

        $disk = Storage::disk('local');

        $diskUsage = collect($disk->files('exports'))->sum(fn ($f) => $disk->size($f));

        return [
            'exports'        => $query->latest()->paginate(20),
            'countsByStatus' => $countsByStatus,
            'diskUsage'      => $diskUsage,
            'nbFiles'        => count($disk->files('exports')),
            'users'          => User::whereIn('id', ExportRequest::select('user_id')->distinct())
                                    ->orderBy('name')
                                    ->get(['id', 'name']),
        ];
    }
};
?>
<div style="padding:24px;">

@php
    $statuses = [
        'pending'    => ['En attente',  '#FEF3C7', '#92400E'],
        'processing' => ['En cours...', '#DBEAFE', '#1E40AF'],
        'done'       => ['Prêt',        '#E5F5ED', '#005C2B'],
        'uploaded'   => ['Téléchargé',  '#E8ECF8', '#1B2F6E'],
        'failed'     => ['Échec',       '#FDE8E8', '#7F1D1D'],
        'deleted'    => ['Supprimé',    '#F3F4F6', '#6B7280'],
    ];

    $humanSize = function (int $bytes): string {
        if ($bytes >= 1048576) return number_format($bytes / 1048576, 1, ',', ' ') . ' Mo';
        if ($bytes >= 1024)    return number_format($bytes / 1024, 0, ',', ' ') . ' Ko';
        return $bytes . ' o';
    };

    $disk = \Illuminate\Support\Facades\Storage::disk('local');
@endphp

    <div style="margin-bottom:20px;">
        <h2 style="font-size:16px; font-weight:700; color:#111827; margin:0 0 4px;">Exports de transactions</h2>
        <p style="font-size:12px; color:#9ca3af; margin:0;">Suivi des fichiers Excel / CSV générés par les utilisateurs, de la demande jusqu'à la suppression.</p>
    </div>

    {{-- MESSAGES --}}
    @if(session('export-message'))
        <div style="background:#E5F5ED; color:#005C2B; border:1px solid #A7E3C1; border-radius:8px; padding:10px 14px; font-size:12px; margin-bottom:16px;">
            {{ session('export-message') }}
        </div>
    @endif
    @if(session('export-error'))
        <div style="background:#FDE8E8; color:#7F1D1D; border:1px solid #F5B5B5; border-radius:8px; padding:10px 14px; font-size:12px; margin-bottom:16px;">
            {{ session('export-error') }}
        </div>
    @endif

    {{-- KPIs --}}
    <div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(130px, 1fr)); gap:12px; margin-bottom:16px;">
        @foreach($statuses as $key => [$label, $bg, $color])
            <div style="background:#fff; border:1px solid #e5e7eb; border-radius:10px; padding:16px; border-top:3px solid {{ $color }};">
                <p style="font-size:11px; color:#6b7280; margin:0 0 6px;">{{ $label }}</p>
                <p style="font-size:20px; font-weight:700; color:#111827; margin:0;">{{ number_format($countsByStatus[$key] ?? 0, 0, ',', ' ') }}</p>
            </div>
        @endforeach
        <div style="background:#fff; border:1px solid #e5e7eb; border-radius:10px; padding:16px; border-top:3px solid #FFC72C;">
            <p style="font-size:11px; color:#6b7280; margin:0 0 6px;">Espace disque</p>
            <p style="font-size:20px; font-weight:700; color:#111827; margin:0;">{{ $humanSize($diskUsage) }}</p>
            <p style="font-size:10px; color:#9ca3af; margin:4px 0 0;">{{ $nbFiles }} fichier(s) sur le serveur</p>
        </div>
    </div>

    {{-- FILTRES --}}
    <div style="background:#fff; border:1px solid #e5e7eb; border-radius:10px; padding:16px; margin-bottom:16px; display:flex; gap:12px; flex-wrap:wrap; align-items:flex-end;">
        <div>
            <label style="font-size:11px; color:#6b7280; display:block; margin-bottom:4px;">Utilisateur</label>
            <select wire:model.live="filterUser"
                    style="border:1px solid #d1d5db; border-radius:7px; padding:8px 12px; font-size:13px; color:#111827; outline:none; background:#fff; width:200px;">
                <option value="">Tous</option>
                @foreach($users as $u)
                    <option value="{{ $u->id }}">{{ $u->name }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label style="font-size:11px; color:#6b7280; display:block; margin-bottom:4px;">Statut</label>
            <select wire:model.live="filterStatus"
                    style="border:1px solid #d1d5db; border-radius:7px; padding:8px 12px; font-size:13px; color:#111827; outline:none; background:#fff; width:160px;">
                <option value="">Tous</option>
                @foreach($statuses as $key => [$label])
                    <option value="{{ $key }}">{{ $label }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label style="font-size:11px; color:#6b7280; display:block; margin-bottom:4px;">Type</label>
            <select wire:model.live="filterType"
                    style="border:1px solid #d1d5db; border-radius:7px; padding:8px 12px; font-size:13px; color:#111827; outline:none; background:#fff; width:120px;">
                <option value="">Tous</option>
                <option value="excel">Excel</option>
                <option value="csv">CSV</option>
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
            <p style="font-size:13px; font-weight:600; color:#111827; margin:0;">Historique des exports</p>
            <span style="background:#E8ECF8; color:#1B2F6E; font-size:11px; font-weight:600; padding:3px 10px; border-radius:20px;">
                {{ $exports->total() }} export(s)
            </span>
        </div>

        <div style="overflow-x:auto;">
            <table style="width:100%; border-collapse:collapse; font-size:12px;">
                <thead>
                    <tr style="background:#F7F8FC;">
                        @foreach(['#', 'Utilisateur', 'Type', 'Statut', 'Filtres', 'Demandé le', 'Généré le', 'Téléchargé le', 'Supprimé le', 'Taille', 'Actions'] as $th)
                            <th style="padding:10px 12px; text-align:left; color:#6b7280; font-weight:500; border-bottom:1px solid #e5e7eb; white-space:nowrap;">{{ $th }}</th>
                        @endforeach
                    </tr>
                </thead>
                <tbody>
                    @forelse($exports as $export)
                        @php
                            [$label, $bg, $color] = $statuses[$export->status] ?? [$export->status, '#F3F4F6', '#374151'];

                            $filters = collect($export->filters ?? [])
                                ->filter(fn ($v) => $v !== null && $v !== '' && $v !== [])
                                ->map(fn ($v, $k) => $k . ' : ' . (is_array($v) ? implode(', ', $v) : $v))
                                ->implode(' · ');

                            $fileExists = $export->file_path && $disk->exists($export->file_path);
                        @endphp
                        <tr wire:key="export-{{ $export->id }}" style="border-bottom:1px solid #f3f4f6;"
                            onmouseover="this.style.background='#F7F8FC'"
                            onmouseout="this.style.background='transparent'">
                            <td style="padding:10px 12px; color:#9ca3af;">{{ $export->id }}</td>
                            <td style="padding:10px 12px;">
                                <span style="color:#111827; font-weight:500;">{{ $export->user->name ?? '—' }}</span><br>
                                <span style="color:#9ca3af; font-size:11px;">{{ $export->user->email ?? '' }}</span>
                            </td>
                            <td style="padding:10px 12px; color:#111827; font-weight:500;">{{ strtoupper($export->type) }}</td>
                            <td style="padding:10px 12px;">
                                <span style="background:{{ $bg }}; color:{{ $color }}; font-size:11px; font-weight:600; padding:3px 10px; border-radius:20px; white-space:nowrap;"
                                      @if($export->status === 'failed' && $export->error) title="{{ $export->error }}" @endif>
                                    {{ $label }}
                                </span>
                            </td>
                            <td style="padding:10px 12px; color:#6b7280; max-width:260px; white-space:nowrap; overflow:hidden; text-overflow:ellipsis;"
                                title="{{ $filters }}">
                                {{ $filters ?: '—' }}
                            </td>
                            <td style="padding:10px 12px; color:#6b7280; white-space:nowrap;">{{ $export->created_at->format('d/m/Y H:i') }}</td>
                            <td style="padding:10px 12px; color:#6b7280; white-space:nowrap;">{{ $export->completed_at?->format('d/m/Y H:i') ?? '—' }}</td>
                            <td style="padding:10px 12px; color:#6b7280; white-space:nowrap;">{{ $export->downloaded_at?->format('d/m/Y H:i') ?? '—' }}</td>
                            <td style="padding:10px 12px; color:#6b7280; white-space:nowrap;">{{ $export->deleted_at?->format('d/m/Y H:i') ?? '—' }}</td>
                            <td style="padding:10px 12px; color:#6b7280; white-space:nowrap;">{{ $fileExists ? $humanSize($disk->size($export->file_path)) : '—' }}</td>
                            <td style="padding:10px 12px;">
                                <div style="display:flex; gap:6px;">
                                    @if($fileExists)
                                        <button wire:click="download({{ $export->id }})"
                                                style="background:#1B2F6E; color:#fff; font-size:11px; font-weight:600; padding:5px 10px; border-radius:6px; border:none; cursor:pointer;">
                                            Télécharger
                                        </button>
                                    @endif
                                    @can('admin.exports.delete')
                                        @if(in_array($export->status, ['done', 'uploaded', 'failed']))
                                            <button wire:click="deleteExport({{ $export->id }})"
                                                    wire:confirm="Supprimer le fichier de l'export #{{ $export->id }} ({{ $export->user->name ?? '—' }}) ? Cette action est irréversible."
                                                    style="background:#fff; color:#B91C1C; font-size:11px; font-weight:600; padding:5px 10px; border-radius:6px; border:1px solid #F5B5B5; cursor:pointer;">
                                                Supprimer
                                            </button>
                                        @endif
                                    @endcan
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="11" style="padding:24px; text-align:center; color:#9ca3af;">Aucun export trouvé pour ces critères.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        <div style="padding:12px 16px; border-top:1px solid #e5e7eb;">
            {{ $exports->links() }}
        </div>
    </div>

</div>
