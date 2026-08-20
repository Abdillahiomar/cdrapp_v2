<?php
// resources/views/livewire/organizations/index.blade.php

use App\Models\KycOrganization;
use Livewire\Volt\Component;
use Livewire\WithPagination;

new class extends Component {

    use WithPagination;

    public string $short_code     = '';
    public string $activity       = '';
    public string $company_status = '';
    public string $region         = '';
    public bool   $searched       = false;

    public $date_debut = '';
    public $date_fin = '';

    public ?string $selectedId   = null;
    public bool    $showModal     = false;

    public function voirDetail(string $usi): void
    {
        $this->selectedId = $usi;
        $this->showModal  = true;
    }

    public function fermerModal(): void
    {
        $this->showModal  = false;
        $this->selectedId = null;
    }

    public function search(): void
    {
        $this->resetPage();
        $this->searched = true;
    }

    public function resetFilters(): void
    {
        $this->short_code     = '';
        $this->activity       = '';
        $this->company_status = '';
        $this->region         = '';
        $this->date_debut     = '';
        $this->date_fin       = '';
        $this->searched       = false;
        $this->resetPage();
    }

    public function with(): array
    {
        // Detail pour le modal
        $selected = $this->selectedId
            ? KycOrganization::find($this->selectedId)
            : null;

        // Listes distinctes pour les selects
        $activities = KycOrganization::query()
            ->whereNotNull('activity')
            ->select('activity')->distinct()
            ->orderBy('activity')->pluck('activity');

        $statuses = KycOrganization::query()
            ->whereNotNull('company_status')
            ->select('company_status')->distinct()
            ->orderBy('company_status')->pluck('company_status');

        if (!$this->searched) {
            return [
                'organizations' => null,
                'selected'      => $selected,
                'activities'    => $activities,
                'statuses'      => $statuses,
            ];
        }

        $query = KycOrganization::query();

       if ($this->date_debut) {
            $query->where('source_datetime', '>=', $this->date_debut . ' 00:00:00');
        }
        if ($this->date_fin) {
            $query->where('source_datetime', '<=', $this->date_fin . ' 23:59:59');
        }
        if ($this->short_code) {
            $query->where('short_code', 'like', '%' . $this->short_code . '%');
        }
        if ($this->activity) {
            $query->where('activity', $this->activity);
        }
        if ($this->company_status) {
            $query->where('company_status', $this->company_status);
        }
        if ($this->region) {
            $query->where('region', 'like', '%' . $this->region . '%');
        }

        return [
            'organizations' => $query
                ->orderBy('source_datetime', 'desc')
                ->paginate(100),
            'selected'      => $selected,
            'activities'    => $activities,
            'statuses'      => $statuses,
        ];
    }
};
?>

<div style="padding:24px;">

    {{-- FILTRES --}}
    <div style="background:#fff; border:1px solid #e5e7eb; border-radius:12px; padding:20px; margin-bottom:20px;">

        <p style="font-size:13px; font-weight:600; color:#111827; margin-bottom:14px;">
            Filtres de recherche
        </p>

        <div style="display:grid; grid-template-columns:repeat(4, minmax(0,1fr)); gap:12px; margin-bottom:12px;">

             <div>
                <label style="font-size:11px; color:#6b7280; display:block; margin-bottom:4px;">Date début</label>
                <input type="date" wire:model="date_debut"
                       style="width:100%; border:1px solid #d1d5db; border-radius:7px; padding:8px 10px; font-size:13px; color:#111827; outline:none;">
            </div>

            <div>
                <label style="font-size:11px; color:#6b7280; display:block; margin-bottom:4px;">Date fin</label>
                <input type="date" wire:model="date_fin"
                       style="width:100%; border:1px solid #d1d5db; border-radius:7px; padding:8px 10px; font-size:13px; color:#111827; outline:none;">
            </div>

            <div>
                <label style="font-size:11px; color:#6b7280; display:block; margin-bottom:4px;">Activité</label>
                <select wire:model="activity"
                        style="width:100%; border:1px solid #d1d5db; border-radius:7px; padding:8px 10px; font-size:13px; color:#111827; outline:none; background:#fff;">
                    <option value="">Toutes les activités</option>
                    @foreach($activities as $act)
                        <option value="{{ $act }}">{{ $act }}</option>
                    @endforeach
                </select>
            </div>

            <div>
                <label style="font-size:11px; color:#6b7280; display:block; margin-bottom:4px;">Statut société</label>
                <select wire:model="company_status"
                        style="width:100%; border:1px solid #d1d5db; border-radius:7px; padding:8px 10px; font-size:13px; color:#111827; outline:none; background:#fff;">
                    <option value="">Tous les statuts</option>
                    @foreach($statuses as $st)
                        <option value="{{ $st }}">{{ $st }}</option>
                    @endforeach
                </select>
            </div>

            <div>
                <label style="font-size:11px; color:#6b7280; display:block; margin-bottom:4px;">Région</label>
                <input type="text" wire:model="region" placeholder="Ex: Balbala"
                       style="width:100%; border:1px solid #d1d5db; border-radius:7px; padding:8px 10px; font-size:13px; color:#111827; outline:none;">
            </div>

        </div>

        <div style="display:flex; align-items:center; gap:10px; margin-top:4px;">
            <button wire:click="search" wire:loading.attr="disabled" wire:target="search"
                    style="background:#1B2F6E; color:#fff; font-size:13px; font-weight:600; padding:9px 22px; border-radius:8px; border:none; cursor:pointer; display:flex; align-items:center; gap:7px;">
                <span wire:loading.remove wire:target="search" style="display:flex; align-items:center; gap:7px;">
                    <svg width="14" height="14" viewBox="0 0 16 16" fill="white">
                        <circle cx="6.5" cy="6.5" r="4.5" stroke="white" stroke-width="1.5" fill="none"/>
                        <path d="M10.5 10.5L14 14" stroke="white" stroke-width="1.5" stroke-linecap="round"/>
                    </svg>
                    Rechercher
                </span>
                <span wire:loading wire:target="search">Recherche...</span>
            </button>

            @if($searched)
                <button wire:click="resetFilters"
                        style="background:#f3f4f6; color:#374151; font-size:13px; font-weight:500; padding:9px 18px; border-radius:8px; border:1px solid #e5e7eb; cursor:pointer;">
                    Réinitialiser
                </button>
            @endif
        </div>
    </div>

    {{-- RESULTATS --}}
    @if($organizations)
        <div style="background:#fff; border:1px solid #e5e7eb; border-radius:12px; overflow:hidden;">
            <div style="padding:14px 20px; border-bottom:1px solid #f0f0f0; font-size:13px; color:#6b7280;">
                {{ $organizations->total() }} organisation(s) trouvée(s)
            </div>

            <div style="overflow-x:auto;">
                <table style="width:100%; border-collapse:collapse; font-size:13px;">
                    <thead>
                        <tr style="background:#f9fafb; text-align:left; color:#6b7280; font-size:11px; text-transform:uppercase;">
                        <th style="padding:10px 16px;">CREATION DATE.</th>    
                        <th style="padding:10px 16px;">Short Code</th>
                            <th style="padding:10px 16px;">Représentant légal</th>
                            <th style="padding:10px 16px;">Activité</th>
                            <th style="padding:10px 16px;">Statut</th>
                            <th style="padding:10px 16px;">Région</th>
                            <th style="padding:10px 16px;">Segment</th>
                            <th style="padding:10px 16px;">MSISDN</th>
                            
                            <th style="padding:10px 16px;"></th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($organizations as $org)
                            <tr style="border-top:1px solid #f0f0f0;">
                                <td style="padding:10px 16px; color:#6b7280;">{{ $org->source_datetime?->format('d/m/Y') ?? '—' }}</td>
                                <td style="padding:10px 16px; font-weight:600; color:#111827;">{{ $org->short_code ?? '—' }}</td>
                                <td style="padding:10px 16px; color:#374151;">{{ $org->legal_representative ?? '—' }}</td>
                                <td style="padding:10px 16px; color:#374151;">{{ $org->activity ?? '—' }}</td>
                                <td style="padding:10px 16px; color:#374151;">{{ $org->company_status ?? '—' }}</td>
                                <td style="padding:10px 16px; color:#374151;">{{ $org->region ?? '—' }}</td>
                                <td style="padding:10px 16px; color:#374151;">{{ $org->org_profile ?? '—' }}</td>
                                <td style="padding:10px 16px; color:#374151;">{{ $org->notif_msisdn ?? '—' }}</td>
                                
                                <td style="padding:10px 16px;">
                                    <button wire:click="voirDetail('{{ $org->unique_system_id }}')"
                                            style="background:#eef2ff; color:#1B2F6E; font-size:12px; font-weight:600; padding:5px 12px; border-radius:6px; border:none; cursor:pointer;">
                                        Détail
                                    </button>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            <div style="padding:14px 20px;">
                {{ $organizations->links() }}
            </div>
        </div>
    @elseif($searched)
        <div style="background:#fff; border:1px solid #e5e7eb; border-radius:12px; padding:40px; text-align:center; color:#6b7280;">
            Aucune organisation trouvée.
        </div>
    @endif

    {{-- MODAL DETAIL --}}
    @if($showModal && $selected)
        <div style="position:fixed; inset:0; background:rgba(0,0,0,0.4); display:flex; align-items:center; justify-content:center; z-index:50;"
             wire:click.self="fermerModal">
            <div style="background:#fff; border-radius:12px; width:600px; max-width:90%; max-height:85vh; overflow-y:auto; padding:24px;">
                <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:18px;">
                    <h3 style="font-size:16px; font-weight:700; color:#111827;">
                        Organisation {{ $selected->short_code }}
                    </h3>
                    <button wire:click="fermerModal" style="background:none; border:none; font-size:22px; color:#9ca3af; cursor:pointer;">&times;</button>
                </div>

                <div style="display:grid; grid-template-columns:1fr 1fr; gap:12px; font-size:13px;">
                    @php
                        $champs = [
                            'USI'                => $selected->unique_system_id,
                            'Short Code'         => $selected->short_code,
                            'Profil'             => $selected->org_profile,
                            'Représentant'       => $selected->legal_representative,
                            'Activité'           => $selected->activity,
                            'Statut société'     => $selected->company_status,
                            'Région'             => $selected->region,
                            'Adresse 1'          => $selected->address_line_1,
                            'Ville'              => $selected->city,
                            'Contact'            => $selected->contact_person_name,
                            'Tél. contact'       => $selected->contact_person_phone,
                            'MSISDN notif.'      => $selected->notif_msisdn,
                            'Email notif.'       => $selected->notif_email,
                            'Type ID'            => $selected->id_type,
                            'N° ID'              => $selected->id_number,
                        ];
                    @endphp
                    @foreach($champs as $label => $val)
                        <div>
                            <div style="font-size:11px; color:#6b7280;">{{ $label }}</div>
                            <div style="color:#111827;">{{ $val ?: '—' }}</div>
                        </div>
                    @endforeach
                </div>

                {{-- Pieces jointes --}}
                <div style="margin-top:18px;">
                    <div style="font-size:11px; color:#6b7280; margin-bottom:8px;">Pièces jointes</div>
                    <div style="display:flex; flex-wrap:wrap; gap:6px;">
                        @php
                            $pieces = [
                                'Reg. commerce'  => $selected->has_commercial_register,
                                'Contrat'        => $selected->has_contract,
                                'Doc. ID'        => $selected->has_id_doc,
                                'Propriétaire'   => $selected->has_owner,
                                'Patente'        => $selected->has_patent,
                                'Approvis.'      => $selected->has_procurement,
                                'Form. enreg.'   => $selected->has_registration_form,
                                'Boutique'       => $selected->has_shop,
                            ];
                        @endphp
                        @foreach($pieces as $label => $present)
                            <span style="font-size:11px; padding:4px 10px; border-radius:20px; {{ $present ? 'background:#dcfce7; color:#166534;' : 'background:#f3f4f6; color:#9ca3af;' }}">
                                {{ $present ? '✓' : '✗' }} {{ $label }}
                            </span>
                        @endforeach
                    </div>
                </div>
            </div>
        </div>
    @endif
</div>