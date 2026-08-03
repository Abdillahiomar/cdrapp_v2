<?php

use App\Models\Customer;
use Livewire\Volt\Component;
use Livewire\WithPagination;

new class extends Component {

    use WithPagination;

    public $msisdn = '';
    public $fullname = '';
    public $mothername = '';
    public $customer_profile = '';
    public $channel = '';
    public $nationality = '';
    public $id_type = '';
    public $sex = '';
    public $quality_only = false;      // n'afficher que les fiches avec anomalie
    public $date_debut = '';
    public $date_fin = '';
    public $searched = false;

    public function search()
    {
        $this->resetPage();
        $this->searched = true;
    }

    public function resetFilters()
    {
        $this->reset([
            'msisdn', 'fullname', 'mothername', 'customer_profile',
            'channel', 'nationality', 'id_type', 'sex',
            'quality_only', 'date_debut', 'date_fin', 'searched',
        ]);
        $this->resetPage();
    }

    private function buildQuery()
    {
        $query = Customer::query();

        if ($this->msisdn)           $query->where('msisdn', 'like', '%'.$this->msisdn.'%');
        if ($this->fullname)         $query->where('full_name', 'ilike', '%'.$this->fullname.'%');
        if ($this->mothername)       $query->where('mother_full_name', 'ilike', '%'.$this->mothername.'%');
        if ($this->customer_profile) $query->where('customer_profile', $this->customer_profile);
        if ($this->channel)          $query->where('channel', $this->channel);
        if ($this->nationality)      $query->where('nationality', 'ilike', '%'.$this->nationality.'%');
        if ($this->id_type)          $query->where('id_type', $this->id_type);
        if ($this->sex)              $query->where('sex', $this->sex);
        if ($this->quality_only)     $query->whereNotNull('data_quality_flags');
        if ($this->date_debut)       $query->whereDate('source_datetime', '>=', $this->date_debut);
        if ($this->date_fin)         $query->whereDate('source_datetime', '<=', $this->date_fin);

        return $query;
    }

    public function with()
    {
        if (!$this->searched) {
            return ['customers' => null];
        }

        return [
            'customers' => $this->buildQuery()
                                ->orderByDesc('source_datetime')
                                ->paginate(100),
        ];
    }

};
?>

<div style="padding:24px;">

    {{-- FILTRES --}}
    <div style="background:#fff; border:1px solid #e5e7eb; border-radius:10px; padding:20px; margin-bottom:20px;">

        <p style="font-size:13px; font-weight:600; color:#111827; margin-bottom:14px;">
            Clients KYC : Filtres de recherche
        </p>

        <div style="display:grid; grid-template-columns:repeat(3, 1fr); gap:12px; margin-bottom:12px;">

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
                <label style="font-size:11px; color:#6b7280; display:block; margin-bottom:4px;">MSISDN</label>
                <input type="text" wire:model="msisdn" placeholder="Ex: 25377000000"
                       style="width:100%; border:1px solid #d1d5db; border-radius:7px; padding:8px 10px; font-size:13px; color:#111827; outline:none;">
            </div>

            <div>
                <label style="font-size:11px; color:#6b7280; display:block; margin-bottom:4px;">Nom complet</label>
                <input type="text" wire:model="fullname" placeholder="Ex: Ahmed Omar"
                       style="width:100%; border:1px solid #d1d5db; border-radius:7px; padding:8px 10px; font-size:13px; color:#111827; outline:none;">
            </div>

            <div>
                <label style="font-size:11px; color:#6b7280; display:block; margin-bottom:4px;">Nom de la mère</label>
                <input type="text" wire:model="mothername" placeholder="Ex: Fadumo Ali"
                       style="width:100%; border:1px solid #d1d5db; border-radius:7px; padding:8px 10px; font-size:13px; color:#111827; outline:none;">
            </div>

            <div>
                <label style="font-size:11px; color:#6b7280; display:block; margin-bottom:4px;">Profil client</label>
                <select wire:model="customer_profile"
                        style="width:100%; border:1px solid #d1d5db; border-radius:7px; padding:8px 10px; font-size:13px; color:#111827; outline:none; background:#fff;">
                    <option value="">Tous les profils</option>
                    <option value="RDS">RDS (KYC complet)</option>
                    <option value="RDS_LITE">RDS_LITE (KYC minimal)</option>
                </select>
            </div>

            <div>
                <label style="font-size:11px; color:#6b7280; display:block; margin-bottom:4px;">Canal</label>
                <select wire:model="channel"
                        style="width:100%; border:1px solid #d1d5db; border-radius:7px; padding:8px 10px; font-size:13px; color:#111827; outline:none; background:#fff;">
                    <option value="">Tous les canaux</option>
                    <option value="USSD">USSD</option>
                    <option value="Handset APP">Handset APP</option>
                    <option value="Web">Web</option>
                </select>
            </div>

            <div>
                <label style="font-size:11px; color:#6b7280; display:block; margin-bottom:4px;">Type de pièce</label>
                <select wire:model="id_type"
                        style="width:100%; border:1px solid #d1d5db; border-radius:7px; padding:8px 10px; font-size:13px; color:#111827; outline:none; background:#fff;">
                    <option value="">Tous les types</option>
                    <option value="National ID">National ID</option>
                    <option value="Passport">Passport</option>
                    <option value="Driver License">Driver License</option>
                    <option value="Refugee Card">Refugee Card</option>
                    <option value="Temporary Resident License">Temporary Resident License</option>
                </select>
            </div>

            <div>
                <label style="font-size:11px; color:#6b7280; display:block; margin-bottom:4px;">Nationalité</label>
                <input type="text" wire:model="nationality" placeholder="Ex: Djiboutian"
                       style="width:100%; border:1px solid #d1d5db; border-radius:7px; padding:8px 10px; font-size:13px; color:#111827; outline:none;">
            </div>

            <div>
                <label style="font-size:11px; color:#6b7280; display:block; margin-bottom:4px;">Sexe</label>
                <select wire:model="sex"
                        style="width:100%; border:1px solid #d1d5db; border-radius:7px; padding:8px 10px; font-size:13px; color:#111827; outline:none; background:#fff;">
                    <option value="">Tous</option>
                    <option value="Male">Masculin</option>
                    <option value="Female">Féminin</option>
                </select>
            </div>

            <div style="display:flex; align-items:flex-end; padding-bottom:8px;">
                <label style="font-size:12px; color:#374151; display:flex; align-items:center; gap:7px; cursor:pointer;">
                    <input type="checkbox" wire:model="quality_only" style="width:15px; height:15px; cursor:pointer;">
                    Anomalies qualité uniquement
                </label>
            </div>

        </div>

        {{-- Boutons --}}
        <div style="display:flex; align-items:center; gap:10px; margin-top:4px;">
            <button wire:click="search" wire:loading.attr="disabled" wire:target="search"
                    style="background:#00843D; color:#fff; font-size:13px; font-weight:600; padding:9px 22px; border-radius:8px; border:none; cursor:pointer; display:flex; align-items:center; gap:7px;">

                <span wire:loading.remove wire:target="search" style="display:flex; align-items:center; gap:7px;">
                    <svg width="14" height="14" viewBox="0 0 16 16" fill="white">
                        <circle cx="6.5" cy="6.5" r="4.5" stroke="white" stroke-width="1.5" fill="none"/>
                        <path d="M10.5 10.5L14 14" stroke="white" stroke-width="1.5" stroke-linecap="round"/>
                    </svg>
                    Rechercher
                </span>

                <span wire:loading.inline-flex wire:target="search" style="align-items:center; gap:7px;">
                    <svg width="14" height="14" viewBox="0 0 40 40" fill="none" style="animation:spin 0.8s linear infinite;">
                        <circle cx="20" cy="20" r="16" stroke="rgba(255,255,255,0.3)" stroke-width="4"/>
                        <path d="M20 4a16 16 0 0116 16" stroke="white" stroke-width="4" stroke-linecap="round"/>
                    </svg>
                    Chargement...
                </span>
            </button>

            @if($searched)
                <button wire:click="resetFilters"
                        style="background:#f3f4f6; color:#374151; font-size:13px; font-weight:500; padding:9px 18px; border-radius:8px; border:1px solid #e5e7eb; cursor:pointer;">
                    Réinitialiser
                </button>
            @endif
        </div>
    </div>

    {{-- ÉTAT INITIAL --}}
    @if(!$searched)
        <div style="text-align:center; padding:60px 20px; background:#fff; border:1px solid #e5e7eb; border-radius:10px;">
            <div style="width:52px; height:52px; background:#E5F5ED; border-radius:14px; display:flex; align-items:center; justify-content:center; margin:0 auto 14px;">
                <svg width="26" height="26" viewBox="0 0 24 24" fill="none">
                    <circle cx="10" cy="10" r="7" stroke="#00843D" stroke-width="1.5"/>
                    <path d="M15.5 15.5L20 20" stroke="#00843D" stroke-width="1.5" stroke-linecap="round"/>
                </svg>
            </div>
            <p style="font-size:14px; font-weight:600; color:#111827; margin-bottom:4px;">Lancez une recherche</p>
            <p style="font-size:12px; color:#9ca3af;">Renseignez au moins un critère puis cliquez sur <strong>Rechercher</strong>.</p>
        </div>

    {{-- RÉSULTATS --}}
    @else
        <div style="background:#fff; border:1px solid #e5e7eb; border-radius:10px; overflow:hidden;">

            <div style="padding:12px 16px; border-bottom:1px solid #e5e7eb; display:flex; align-items:center; justify-content:space-between;">
                <p style="font-size:13px; font-weight:600; color:#111827;">Résultats</p>
                <span style="background:#E5F5ED; color:#005C2B; font-size:11px; font-weight:600; padding:3px 10px; border-radius:20px;">
                    {{ $customers->total() }} client(s) trouvé(s)
                </span>
            </div>

            <div style="overflow-x:auto;">
                <table style="width:100%; border-collapse:collapse; font-size:12px;">
                    <thead>
                        <tr style="background:#F9FAF9;">
                            <th style="padding:10px 14px; text-align:left; color:#6b7280; font-weight:500; border-bottom:1px solid #e5e7eb; white-space:nowrap;">#</th>
                            <th style="padding:10px 14px; text-align:left; color:#6b7280; font-weight:500; border-bottom:1px solid #e5e7eb; white-space:nowrap;">Date</th>
                            <th style="padding:10px 14px; text-align:left; color:#6b7280; font-weight:500; border-bottom:1px solid #e5e7eb; white-space:nowrap;">MSISDN</th>
                            <th style="padding:10px 14px; text-align:left; color:#6b7280; font-weight:500; border-bottom:1px solid #e5e7eb; white-space:nowrap;">Nom complet</th>
                            <th style="padding:10px 14px; text-align:left; color:#6b7280; font-weight:500; border-bottom:1px solid #e5e7eb; white-space:nowrap;">Nom de la mère</th>
                            <th style="padding:10px 14px; text-align:left; color:#6b7280; font-weight:500; border-bottom:1px solid #e5e7eb; white-space:nowrap;">Profil</th>
                            <th style="padding:10px 14px; text-align:left; color:#6b7280; font-weight:500; border-bottom:1px solid #e5e7eb; white-space:nowrap;">Canal</th>
                            <th style="padding:10px 14px; text-align:left; color:#6b7280; font-weight:500; border-bottom:1px solid #e5e7eb; white-space:nowrap;">Pièce</th>
                            <th style="padding:10px 14px; text-align:left; color:#6b7280; font-weight:500; border-bottom:1px solid #e5e7eb; white-space:nowrap;">Nationalité</th>
                            <th style="padding:10px 14px; text-align:left; color:#6b7280; font-weight:500; border-bottom:1px solid #e5e7eb; white-space:nowrap;">Qualité</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($customers as $customer)
                            <tr style="border-bottom:1px solid #f3f4f6;" onmouseover="this.style.background='#F9FCF9'" onmouseout="this.style.background='transparent'">
                                <td style="padding:10px 14px; color:#6b7280;">{{ $customers->firstItem() + $loop->index }}</td>
                                <td style="padding:10px 14px; color:#6b7280; white-space:nowrap;">
                                    {{ $customer->source_datetime ? $customer->source_datetime->format('d/m/Y H:i') : '—' }}
                                </td>
                                <td style="padding:10px 14px; color:#111827; font-weight:500;">{{ $customer->msisdn }}</td>
                                <td style="padding:10px 14px; color:#374151;">{{ $customer->full_name ?: '—' }}</td>
                                <td style="padding:10px 14px; color:#374151;">{{ $customer->mother_full_name ?: '—' }}</td>
                                <td style="padding:10px 14px;">
                                    @if($customer->customer_profile === 'RDS')
                                        <span style="background:#E5F5ED; color:#005C2B; font-size:10px; font-weight:600; padding:2px 8px; border-radius:12px;">RDS</span>
                                    @elseif($customer->customer_profile === 'RDS_LITE')
                                        <span style="background:#E6F1FB; color:#0C447C; font-size:10px; font-weight:600; padding:2px 8px; border-radius:12px;">RDS_LITE</span>
                                    @else
                                        <span style="background:#F3F4F6; color:#6b7280; font-size:10px; font-weight:600; padding:2px 8px; border-radius:12px;">—</span>
                                    @endif
                                </td>
                                <td style="padding:10px 14px; color:#374151;">{{ $customer->channel ?: '—' }}</td>
                                <td style="padding:10px 14px; color:#374151;">{{ $customer->id_type ?: '—' }}</td>
                                <td style="padding:10px 14px; color:#374151;">{{ $customer->nationality ?: '—' }}</td>
                                <td style="padding:10px 14px;">
                                    @if($customer->data_quality_flags)
                                        <span title="{{ $customer->data_quality_flags }}"
                                              style="background:#FDECEA; color:#7F1D1D; font-size:10px; font-weight:600; padding:2px 8px; border-radius:12px;">
                                            À vérifier
                                        </span>
                                    @else
                                        <span style="background:#E5F5ED; color:#005C2B; font-size:10px; font-weight:600; padding:2px 8px; border-radius:12px;">OK</span>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="10" style="padding:40px; text-align:center; color:#9ca3af; font-size:13px;">
                                    Aucun client trouvé pour ces critères.
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            @if($customers->hasPages())
                <div style="padding:12px 16px; border-top:1px solid #e5e7eb;">
                    {{ $customers->links() }}
                </div>
            @endif
        </div>
    @endif

    <style>
        @keyframes spin { from { transform: rotate(0deg); } to { transform: rotate(360deg); } }
    </style>
</div>