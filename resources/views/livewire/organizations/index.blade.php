<?php

use App\Models\Organization;
use App\Models\Operator;
use Livewire\Volt\Component;
use Livewire\WithPagination;

new class extends Component {

    use WithPagination;

    public string $biz_org_name      = '';
    public string $organization_type = '';
    public string $status            = '';
    public string $hier_level        = '';
    public string $short_code        = '';
    public bool   $searched          = false;


    public ?string $selectedOrgId   = null;
    public string  $selectedOrgName = '';
    public bool    $showModal       = false;

    public function voirOperators(string $orgId, string $orgName): void
    {
        $this->selectedOrgId   = $orgId;
        $this->selectedOrgName = $orgName;
        $this->showModal       = true;
    }

    public function fermerModal(): void
    {
        $this->showModal       = false;
        $this->selectedOrgId   = null;
        $this->selectedOrgName = '';
    }

    public function search()
    {
        $this->resetPage();
        $this->searched = true;
    }

    public function resetFilters()
    {
        $this->biz_org_name      = '';
        $this->organization_type = '';
        $this->status            = '';
        $this->hier_level        = '';
        $this->short_code        = '';
        $this->searched          = false;
        $this->resetPage();
    }

    public function with(): array
    {
        // Opérateurs toujours calculés (pour le modal)
        $operators = $this->selectedOrgId
            ? Operator::where('OWNED_IDENTITY_ID', $this->selectedOrgId)
                ->orderBy('CREATE_TIME', 'desc')
                ->get()
            : collect();

        if (!$this->searched) {
            return [
                'organizations' => null,
                'operators'     => $operators,
                'types'         => Organization::select('ORGANIZATION_TYPE')
                                    ->distinct()->orderBy('ORGANIZATION_TYPE')
                                    ->pluck('ORGANIZATION_TYPE'),
                'levels'        => Organization::select('HIER_LEVEL')
                                    ->distinct()->orderBy('HIER_LEVEL')
                                    ->pluck('HIER_LEVEL'),
            ];
        }

        $query = Organization::query();

        if ($this->biz_org_name) {
            $query->where('BIZ_ORG_NAME', 'like', '%' . $this->biz_org_name . '%');
        }
        if ($this->organization_type) {
            $query->where('ORGANIZATION_TYPE', $this->organization_type);
        }
        if ($this->status) {
            $query->where('STATUS', $this->status);
        }
        if ($this->hier_level) {
            $query->where('HIER_LEVEL', $this->hier_level);
        }
        if ($this->short_code) {
            $query->where('SHORT_CODE', 'like', '%' . $this->short_code . '%');
        }

        return [
            'organizations' => $query
                ->withCount('operators')
                ->orderBy('CREATE_TIME', 'desc')
                ->paginate(100),
            'operators'     => $operators,
            'types'         => Organization::select('ORGANIZATION_TYPE')
                                ->distinct()->orderBy('ORGANIZATION_TYPE')
                                ->pluck('ORGANIZATION_TYPE'),
            'levels'        => Organization::select('HIER_LEVEL')
                                ->distinct()->orderBy('HIER_LEVEL')
                                ->pluck('HIER_LEVEL'),
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

        <div style="display:grid; grid-template-columns:repeat(3, minmax(0,1fr)); gap:12px; margin-bottom:12px;">

            <div>
                <label style="font-size:11px; color:#6b7280; display:block; margin-bottom:4px;">Nom de l'organisation</label>
                <input type="text"
                       wire:model="biz_org_name"
                       placeholder="Ex: D-Money"
                       style="width:100%; border:1px solid #d1d5db; border-radius:7px; padding:8px 10px; font-size:13px; color:#111827; outline:none;">
            </div>

            <div>
                <label style="font-size:11px; color:#6b7280; display:block; margin-bottom:4px;">Short Code</label>
                <input type="text"
                       wire:model="short_code"
                       placeholder="Ex: DMY"
                       style="width:100%; border:1px solid #d1d5db; border-radius:7px; padding:8px 10px; font-size:13px; color:#111827; outline:none;">
            </div>

            <div>
                <label style="font-size:11px; color:#6b7280; display:block; margin-bottom:4px;">Type d'organisation</label>
                <select wire:model="organization_type"
                        style="width:100%; border:1px solid #d1d5db; border-radius:7px; padding:8px 10px; font-size:13px; color:#111827; outline:none; background:#fff;">
                    <option value="">Tous les types</option>
                    @foreach($types as $type)
                        <option value="{{ $type }}">{{ $type }}</option>
                    @endforeach
                </select>
            </div>

            <div>
                <label style="font-size:11px; color:#6b7280; display:block; margin-bottom:4px;">Niveau hiérarchique</label>
                <select wire:model="hier_level"
                        style="width:100%; border:1px solid #d1d5db; border-radius:7px; padding:8px 10px; font-size:13px; color:#111827; outline:none; background:#fff;">
                    <option value="">Tous les niveaux</option>
                    @foreach($levels as $level)
                        <option value="{{ $level }}">{{ $level }}</option>
                    @endforeach
                </select>
            </div>

            <div>
                <label style="font-size:11px; color:#6b7280; display:block; margin-bottom:4px;">Statut</label>
                <select wire:model="status"
                        style="width:100%; border:1px solid #d1d5db; border-radius:7px; padding:8px 10px; font-size:13px; color:#111827; outline:none; background:#fff;">
                    <option value="">Tous les statuts</option>
                    <option value="3">Active</option>
                    <option value="6">Closed</option>
                    <option value="5">Frozen</option>
                    <option value="2">Pending</option>
                </select>
            </div>

            <div>
                <label style="font-size:11px; color:#6b7280; display:block; margin-bottom:4px;">Statut</label>
                <select wire:model="trust_level"
                        style="width:100%; border:1px solid #d1d5db; border-radius:7px; padding:8px 10px; font-size:13px; color:#111827; outline:none; background:#fff;">
                    <option value="">Trust Lavel</option>
                    <option value="3">Active</option>
                    <option value="6">Closed</option>
                    <option value="5">Frozen</option>
                    <option value="2">Pending</option>
                </select>
            </div>

        </div>

        {{-- Boutons --}}
        <div style="display:flex; align-items:center; gap:10px; margin-top:4px;">
            <button wire:click="search"
                    wire:loading.attr="disabled"
                    wire:target="search"
                    style="background:#1B2F6E; color:#fff; font-size:13px; font-weight:600; padding:9px 22px; border-radius:8px; border:none; cursor:pointer; display:flex; align-items:center; gap:7px;">
                <span wire:loading.remove wire:target="search" style="display:flex; align-items:center; gap:7px;">
                    <svg width="14" height="14" viewBox="0 0 16 16" fill="white">
                        <circle cx="6.5" cy="6.5" r="4.5" stroke="white" stroke-width="1.5" fill="none"/>
                        <path d="M10.5 10.5L14 14" stroke="white" stroke-width="1.5" stroke-linecap="round"/>
                    </svg>
                    Rechercher
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
            <div style="width:52px; height:52px; background:#E8ECF8; border-radius:14px; display:flex; align-items:center; justify-content:center; margin:0 auto 14px;">
                <svg width="26" height="26" viewBox="0 0 24 24" fill="none">
                    <circle cx="10" cy="10" r="7" stroke="#1B2F6E" stroke-width="1.5"/>
                    <path d="M15.5 15.5L20 20" stroke="#1B2F6E" stroke-width="1.5" stroke-linecap="round"/>
                </svg>
            </div>
            <p style="font-size:14px; font-weight:600; color:#111827; margin-bottom:4px;">Lancez une recherche</p>
            <p style="font-size:12px; color:#9ca3af;">Renseignez au moins un critère puis cliquez sur <strong>Rechercher</strong>.</p>
        </div>

    {{-- RÉSULTATS --}}
    @else
        <div style="background:#fff; border:1px solid #e5e7eb; border-radius:10px; overflow:hidden;">

            {{-- En-tête --}}
            <div style="padding:12px 16px; border-bottom:1px solid #e5e7eb; display:flex; align-items:center; justify-content:space-between;">
                <p style="font-size:13px; font-weight:600; color:#111827; margin:0;">Résultats</p>
                <span style="background:#E8ECF8; color:#1B2F6E; font-size:11px; font-weight:600; padding:3px 10px; border-radius:20px;">
                    {{ $organizations->total() }} organisation(s)
                </span>
            </div>

            {{-- TABLE --}}
            <div style="overflow-x:auto; overflow-y:auto; max-height:600px;">
                <table style="width:100%; border-collapse:collapse; font-size:12px;">
                    <thead>
                        <tr style="background:#F7F8FC;">
                            <th style="padding:10px 14px; text-align:left; color:#6b7280; font-weight:500; border-bottom:1px solid #e5e7eb; white-space:nowrap; position:sticky; top:0; background:#F7F8FC; z-index:1;">#</th>
                            <th style="padding:10px 14px; text-align:left; color:#6b7280; font-weight:500; border-bottom:1px solid #e5e7eb; white-space:nowrap; position:sticky; top:0; background:#F7F8FC; z-index:1;">Org ID</th>
                            <th style="padding:10px 14px; text-align:left; color:#6b7280; font-weight:500; border-bottom:1px solid #e5e7eb; white-space:nowrap; position:sticky; top:0; background:#F7F8FC; z-index:1;">Nom</th>
                            <th style="padding:10px 14px; text-align:left; color:#6b7280; font-weight:500; border-bottom:1px solid #e5e7eb; white-space:nowrap; position:sticky; top:0; background:#F7F8FC; z-index:1;">Short Code</th>
                            <th style="padding:10px 14px; text-align:left; color:#6b7280; font-weight:500; border-bottom:1px solid #e5e7eb; white-space:nowrap; position:sticky; top:0; background:#F7F8FC; z-index:1;">Type</th>
                            <th style="padding:10px 14px; text-align:left; color:#6b7280; font-weight:500; border-bottom:1px solid #e5e7eb; white-space:nowrap; position:sticky; top:0; background:#F7F8FC; z-index:1;">Niveau</th>
                            <th style="padding:10px 14px; text-align:left; color:#6b7280; font-weight:500; border-bottom:1px solid #e5e7eb; white-space:nowrap; position:sticky; top:0; background:#F7F8FC; z-index:1;">Trust Level</th>
                            <th style="padding:10px 14px; text-align:left; color:#6b7280; font-weight:500; border-bottom:1px solid #e5e7eb; white-space:nowrap; position:sticky; top:0; background:#F7F8FC; z-index:1;">Statut</th>
                            <th style="padding:10px 14px; text-align:left; color:#6b7280; font-weight:500; border-bottom:1px solid #e5e7eb; white-space:nowrap; position:sticky; top:0; background:#F7F8FC; z-index:1;">Date création</th>
                            <th style="padding:10px 14px; text-align:left; color:#6b7280; font-weight:500; border-bottom:1px solid #e5e7eb; white-space:nowrap; position:sticky; top:0; background:#F7F8FC; z-index:1;">Opérateurs</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($organizations as $org)
                            <tr style="border-bottom:1px solid #f3f4f6;"
                                onmouseover="this.style.background='#F7F8FC'"
                                onmouseout="this.style.background='transparent'">

                                <td style="padding:10px 14px; color:#9ca3af;">
                                    {{ $organizations->firstItem() + $loop->index }}
                                </td>

                                <td style="padding:10px 14px; font-family:monospace; font-size:11px; color:#6b7280;">
                                    {{ $org->BIZ_ORG_ID }}
                                </td>

                                <td style="padding:10px 14px; font-weight:600; color:#111827; white-space:nowrap;">
                                    {{ $org->BIZ_ORG_NAME ?? '—' }}
                                </td>

                                <td style="padding:10px 14px; color:#374151;">
                                    {{ $org->SHORT_CODE ?? '—' }}
                                </td>

                                <td style="padding:10px 14px;">
                                    @if($org->ORGANIZATION_TYPE)
                                        <span style="background:#E8ECF8; color:#1B2F6E; font-size:10px; font-weight:600; padding:2px 8px; border-radius:12px; white-space:nowrap;">
                                            {{ $org->ORGANIZATION_TYPE }}
                                        </span>
                                    @else
                                        <span style="color:#9ca3af;">—</span>
                                    @endif
                                </td>

                                <td style="padding:10px 14px; color:#374151; text-align:center;">
                                    

                                    @php $hl = $org->HIER_LEVEL; @endphp
                                    @if($hl === '1')
                                        <span style="background:#E5F5ED; color:#005C2B; font-size:10px; font-weight:600; padding:2px 8px; border-radius:12px;">Top Org Level 1</span>
                                    @elseif($hl === '2')
                                        <span style="background:#FDECEA; color:#7F1D1D; font-size:10px; font-weight:600; padding:2px 8px; border-radius:12px;">Child Org Level 1</span>
                                    @elseif($hl === '3')
                                        <span style="background:#FFF3D0; color:#7A4F00; font-size:10px; font-weight:600; padding:2px 8px; border-radius:12px;">Child Org Level 2</span>
                                    @else
                                        <span style="background:#f3f4f6; color:#6b7280; font-size:10px; font-weight:600; padding:2px 8px; border-radius:12px;">{{ $hl ?? '—' }}</span>
                                    @endif
                                </td>

                                <td style="padding:10px 14px; color:#374151; text-align:center;">
                                    {{ $org->TRUST_LEVEL ?? '—' }}
                                </td>

                                <td style="padding:10px 14px;">
                                    @php $st = $org->STATUS; @endphp
                                    @if($st === '3')
                                        <span style="background:#E5F5ED; color:#005C2B; font-size:10px; font-weight:600; padding:2px 8px; border-radius:12px;">Active</span>
                                    @elseif($st === '6')
                                        <span style="background:#FDECEA; color:#7F1D1D; font-size:10px; font-weight:600; padding:2px 8px; border-radius:12px;">Closed</span>
                                    @elseif($st === '5')
                                        <span style="background:#FFF3D0; color:#7A4F00; font-size:10px; font-weight:600; padding:2px 8px; border-radius:12px;">Frozen</span>
                                    @elseif($st === '2')
                                        <span style="background:#F3E8FF; color:#6B21A8; font-size:10px; font-weight:600; padding:2px 8px; border-radius:12px;">Pending</span>
                                    @else
                                        <span style="background:#f3f4f6; color:#6b7280; font-size:10px; font-weight:600; padding:2px 8px; border-radius:12px;">{{ $st ?? '—' }}</span>
                                    @endif
                                </td>

                                <td style="padding:10px 14px; color:#6b7280; white-space:nowrap;">
                                    {{ $org->CREATE_TIME ? \Carbon\Carbon::parse($org->CREATE_TIME)->format('d/m/Y') : '—' }}
                                </td>

                                <td style="padding:10px 14px; text-align:center;">
                                    <button wire:click="voirOperators('{{ $org->BIZ_ORG_ID }}', '{{ addslashes($org->BIZ_ORG_NAME) }}')"
                                            style="background:#E8ECF8; color:#1B2F6E; font-size:10px; font-weight:600; padding:4px 10px; border-radius:12px; border:none; cursor:pointer; display:inline-flex; align-items:center; gap:4px;">
                                        <svg width="11" height="11" viewBox="0 0 16 16" fill="#1B2F6E">
                                            <circle cx="6" cy="5" r="3"/>
                                            <path d="M1 13c0-3 2.2-5 5-5s5 2 5 5"/>
                                            <circle cx="13" cy="6" r="2.5"/>
                                            <path d="M13 10c2 0 3 1 3 2.5"/>
                                        </svg>
                                        {{ $org->operators_count }}
                                    </button>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="10" style="padding:40px; text-align:center; color:#9ca3af; font-size:13px;">
                                    Aucune organisation trouvée pour ces critères.
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            {{-- PAGINATION --}}
            @if($organizations->hasPages())
                <div style="padding:12px 16px; border-top:1px solid #e5e7eb;">
                    {{ $organizations->links() }}
                </div>
            @endif
        </div>
    @endif


    @if($showModal)
    {{-- Overlay --}}
        <div wire:click="fermerModal"
            style="position:fixed; inset:0; background:rgba(0,0,0,0.45); z-index:998;">
        </div>

        {{-- Modal --}}
        <div style="position:fixed; top:50%; left:50%; transform:translate(-50%,-50%);
                    width:min(860px, 95vw); max-height:85vh;
                    background:#fff; border-radius:14px; z-index:999;
                    display:flex; flex-direction:column;
                    box-shadow:0 20px 60px rgba(0,0,0,0.2);">

            {{-- Header modal --}}
            <div style="padding:16px 20px; border-bottom:1px solid #e5e7eb; display:flex; align-items:center; justify-content:space-between; flex-shrink:0;">
                <div>
                    <p style="font-size:14px; font-weight:700; color:#111827; margin:0;">
                        Opérateurs
                    </p>
                    <p style="font-size:11px; color:#9ca3af; margin:2px 0 0;">
                        {{ $selectedOrgName }}
                    </p>
                </div>
                <div style="display:flex; align-items:center; gap:10px;">
                    <span style="background:#E8ECF8; color:#1B2F6E; font-size:11px; font-weight:600; padding:3px 10px; border-radius:20px;">
                        {{ $operators->count() }} opérateur(s)
                    </span>
                    <button wire:click="fermerModal"
                            style="width:28px; height:28px; border-radius:7px; border:1px solid #e5e7eb; background:#f9fafb; cursor:pointer; display:flex; align-items:center; justify-content:center;">
                        <svg width="12" height="12" viewBox="0 0 12 12" fill="none">
                            <path d="M1 1l10 10M11 1L1 11" stroke="#6b7280" stroke-width="1.5" stroke-linecap="round"/>
                        </svg>
                    </button>
                </div>
            </div>

            {{-- Corps modal scrollable --}}
            <div style="flex:1; overflow-y:auto; overflow-x:auto;">
                @if($operators->isEmpty())
                    <div style="text-align:center; padding:40px;">
                        <p style="font-size:13px; color:#9ca3af;">Aucun opérateur pour cette organisation.</p>
                    </div>
                @else
                    <table style="width:100%; border-collapse:collapse; font-size:12px;">
                        <thead>
                            <tr style="background:#F7F8FC;">
                                <th style="padding:10px 14px; text-align:left; color:#6b7280; font-weight:500; border-bottom:1px solid #e5e7eb; white-space:nowrap; position:sticky; top:0; background:#F7F8FC; z-index:1;">#</th>
                                <th style="padding:10px 14px; text-align:left; color:#6b7280; font-weight:500; border-bottom:1px solid #e5e7eb; white-space:nowrap; position:sticky; top:0; background:#F7F8FC; z-index:1;">Operator ID</th>
                                <th style="padding:10px 14px; text-align:left; color:#6b7280; font-weight:500; border-bottom:1px solid #e5e7eb; white-space:nowrap; position:sticky; top:0; background:#F7F8FC; z-index:1;">Nom public</th>
                                <th style="padding:10px 14px; text-align:left; color:#6b7280; font-weight:500; border-bottom:1px solid #e5e7eb; white-space:nowrap; position:sticky; top:0; background:#F7F8FC; z-index:1;">Username</th>
                                <th style="padding:10px 14px; text-align:left; color:#6b7280; font-weight:500; border-bottom:1px solid #e5e7eb; white-space:nowrap; position:sticky; top:0; background:#F7F8FC; z-index:1;">Admin</th>
                                <th style="padding:10px 14px; text-align:left; color:#6b7280; font-weight:500; border-bottom:1px solid #e5e7eb; white-space:nowrap; position:sticky; top:0; background:#F7F8FC; z-index:1;">Statut</th>
                                <th style="padding:10px 14px; text-align:left; color:#6b7280; font-weight:500; border-bottom:1px solid #e5e7eb; white-space:nowrap; position:sticky; top:0; background:#F7F8FC; z-index:1;">Date création</th>
                                <th style="padding:10px 14px; text-align:left; color:#6b7280; font-weight:500; border-bottom:1px solid #e5e7eb; white-space:nowrap; position:sticky; top:0; background:#F7F8FC; z-index:1;">Date activation</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($operators as $i => $op)
                                <tr style="border-bottom:1px solid #f3f4f6;"
                                    onmouseover="this.style.background='#F7F8FC'"
                                    onmouseout="this.style.background='transparent'">

                                    <td style="padding:9px 14px; color:#9ca3af;">{{ $i + 1 }}</td>

                                    <td style="padding:9px 14px; font-family:monospace; font-size:11px; color:#6b7280;">
                                        {{ $op->OPERATOR_ID }}
                                    </td>

                                    <td style="padding:9px 14px; font-weight:600; color:#111827;">
                                        {{ $op->PUBLIC_NAME ?? '—' }}
                                    </td>

                                    <td style="padding:9px 14px; color:#374151;">
                                        {{ $op->USER_NAME ?? '—' }}
                                    </td>

                                    <td style="padding:9px 14px; text-align:center;">
                                        @if($op->IS_ADMIN)
                                            <span style="background:#FFF3D0; color:#7A4F00; font-size:10px; font-weight:600; padding:2px 8px; border-radius:12px;">Admin</span>
                                        @else
                                            <span style="background:#f3f4f6; color:#6b7280; font-size:10px; padding:2px 8px; border-radius:12px;">—</span>
                                        @endif
                                    </td>

                                    <td style="padding:9px 14px;">
                                        @php $st = $op->STATUS; @endphp
                                        @if($st === '03')
                                            <span style="background:#E5F5ED; color:#005C2B; font-size:10px; font-weight:600; padding:2px 8px; border-radius:12px;">Active</span>
                                        @elseif($st === '06')
                                            <span style="background:#FDECEA; color:#7F1D1D; font-size:10px; font-weight:600; padding:2px 8px; border-radius:12px;">Closed</span>
                                        @elseif($st === '05')
                                            <span style="background:#FFF3D0; color:#7A4F00; font-size:10px; font-weight:600; padding:2px 8px; border-radius:12px;">Frozen</span>
                                        @elseif($st === '02')
                                            <span style="background:#F3E8FF; color:#6B21A8; font-size:10px; font-weight:600; padding:2px 8px; border-radius:12px;">Pending</span>
                                        @else
                                            <span style="background:#f3f4f6; color:#6b7280; font-size:10px; padding:2px 8px; border-radius:12px;">{{ $st ?? '—' }}</span>
                                        @endif
                                    </td>

                                    <td style="padding:9px 14px; color:#6b7280; white-space:nowrap;">
                                        {{ $op->CREATE_TIME ? \Carbon\Carbon::parse($op->CREATE_TIME)->format('d/m/Y H:i') : '—' }}
                                    </td>

                                    <td style="padding:9px 14px; color:#6b7280; white-space:nowrap;">
                                        {{ $op->ACTIVE_TIME ? \Carbon\Carbon::parse($op->ACTIVE_TIME)->format('d/m/Y H:i') : '—' }}
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                @endif
            </div>

            {{-- Footer modal --}}
            <div style="padding:12px 20px; border-top:1px solid #e5e7eb; display:flex; justify-content:flex-end; flex-shrink:0;">
                <button wire:click="fermerModal"
                        style="background:#f3f4f6; color:#374151; font-size:13px; font-weight:500; padding:8px 20px; border-radius:8px; border:1px solid #e5e7eb; cursor:pointer;">
                    Fermer
                </button>
            </div>
        </div>
    @endif

    <style>
        @keyframes spin { from { transform:rotate(0deg); } to { transform:rotate(360deg); } }
    </style>
</div>