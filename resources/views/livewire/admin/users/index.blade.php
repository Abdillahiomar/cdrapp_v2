<?php

use App\Models\User;
use App\Models\Department;
use Livewire\Volt\Component;
use Livewire\WithPagination;

new class extends Component {

    use WithPagination;

    public string $search     = '';
    public string $filterRole = '';

    // Modal assignation rôle
    public bool   $showRoleModal  = false;
    public ?int   $editUserId     = null;
    public string $editUserName   = '';
    public string $editUserEmail  = '';
    public string $selectedRole   = '';
    public ?int   $selectedDept   = null;


    // Modal création utilisateur
    public bool   $showCreateModal = false;
    public string $newName         = '';
    public string $newEmail        = '';
    public string $newPassword     = '';
    public string $newRole         = '';
    public ?int   $newDept         = null;

    public function assignerRole(int $userId): void
    {
        $user = User::with('roles', 'department')->findOrFail($userId);
        $this->editUserId    = $userId;
        $this->editUserName  = $user->name;
        $this->editUserEmail = $user->email;
        $this->selectedRole  = $user->roles->first()?->name ?? '';
        $this->selectedDept  = $user->department_id;
        $this->showRoleModal = true;
    }

    public function ouvrirCreation(): void
    {
        $this->newName     = '';
        $this->newEmail    = '';
        $this->newPassword = '';
        $this->newRole     = '';
        $this->newDept     = null;
        $this->showCreateModal = true;
    }


    public function creerUtilisateur(): void
    {
        $this->validate([
            'newName'     => 'required|string|min:2|max:100',
            'newEmail'    => 'required|email|unique:users,email',
            'newPassword' => 'required|string|min:8',
            'newRole'     => 'required|string',
            'newDept'     => 'nullable|exists:departments,id',
        ], [
            'newName.required'     => 'Le nom est obligatoire.',
            'newEmail.required'    => 'L\'email est obligatoire.',
            'newEmail.unique'      => 'Cet email est déjà utilisé.',
            'newPassword.required' => 'Le mot de passe est obligatoire.',
            'newPassword.min'      => 'Le mot de passe doit contenir au moins 8 caractères.',
            'newRole.required'     => 'Le rôle est obligatoire.',
        ]);

        $user = User::create([
            'name'          => $this->newName,
            'email'         => $this->newEmail,
            'password'      => bcrypt($this->newPassword),
            'department_id' => $this->newDept,
        ]);

        $user->assignRole($this->newRole);

        $this->showCreateModal = false;
        session()->flash('success', "Utilisateur {$user->name} créé avec succès.");
    }

    public function fermerCreateModal(): void
    {
        $this->showCreateModal = false;
        $this->reset(['newName', 'newEmail', 'newPassword', 'newRole', 'newDept']);
    }

    public function sauvegarder(): void
    {
        $this->validate([
            'selectedRole' => 'required|string',
            'selectedDept' => 'nullable|exists:departments,id',
        ]);

        $user = User::findOrFail($this->editUserId);

        // Met à jour le département
        $user->update(['department_id' => $this->selectedDept]);

        // Met à jour le rôle
        $user->syncRoles([$this->selectedRole]);

        $this->showRoleModal = false;
        session()->flash('success', "Rôle de {$user->name} mis à jour avec succès.");
    }

    public function fermerModal(): void
    {
        $this->showRoleModal = false;
        $this->editUserId    = null;
        $this->selectedRole  = '';
        $this->selectedDept  = null;
    }

    public function with(): array
    {
        $roleClass = config('permission.models.role');

        $query = User::with('roles', 'department');

        if ($this->search) {
            $query->where(function ($q) {
                $q->where('name', 'like', '%'.$this->search.'%')
                  ->orWhere('email', 'like', '%'.$this->search.'%');
            });
        }

        if ($this->filterRole) {
            $query->whereHas('roles', fn($q) => $q->where('name', $this->filterRole));
        }

        return [
            'users'       => $query->orderBy('name')->paginate(50),
            'roles'       => $roleClass::orderBy('name')->get(),
            'departments' => Department::orderBy('name')->get(),
        ];
    }
};
?>

<div style="padding:24px;">

    {{-- FLASH --}}
    @if(session('success'))
        <div style="background:#E5F5ED; color:#005C2B; border:1px solid #a7d7b8; border-radius:8px; padding:12px 16px; margin-bottom:16px; font-size:13px; display:flex; align-items:center; gap:8px;">
            <svg width="14" height="14" viewBox="0 0 16 16" fill="#16a34a">
                <path d="M3 8l3.5 3.5L13 5" stroke="#16a34a" stroke-width="1.5" stroke-linecap="round" fill="none"/>
            </svg>
            {{ session('success') }}
        </div>
    @endif

    <div style="display:flex; align-items:center; justify-content:space-between; margin-bottom:20px;">
    <div>
        <h2 style="font-size:16px; font-weight:700; color:#111827; margin:0 0 4px;">Gestion des utilisateurs</h2>
        <p style="font-size:12px; color:#9ca3af; margin:0;">Attribuez des rôles et départements à chaque utilisateur.</p>
    </div>
    <button wire:click="ouvrirCreation"
            style="background:#1B2F6E; color:#fff; font-size:13px; font-weight:600; padding:9px 18px; border-radius:8px; border:none; cursor:pointer; display:flex; align-items:center; gap:7px;">
        <svg width="14" height="14" viewBox="0 0 16 16" fill="white">
            <path d="M8 2v12M2 8h12" stroke="white" stroke-width="1.8" stroke-linecap="round"/>
        </svg>
        Nouvel utilisateur
    </button>
</div>

    {{-- FILTRES --}}
    <div style="background:#fff; border:1px solid #e5e7eb; border-radius:10px; padding:16px; margin-bottom:16px; display:flex; gap:12px; flex-wrap:wrap; align-items:flex-end;">
        <div>
            <label style="font-size:11px; color:#6b7280; display:block; margin-bottom:4px;">Rechercher</label>
            <input type="text" wire:model.live="search"
                   placeholder="Nom ou email..."
                   style="border:1px solid #d1d5db; border-radius:7px; padding:8px 12px; font-size:13px; color:#111827; outline:none; width:220px;">
        </div>
        <div>
            <label style="font-size:11px; color:#6b7280; display:block; margin-bottom:4px;">Filtrer par rôle</label>
            <select wire:model.live="filterRole"
                    style="border:1px solid #d1d5db; border-radius:7px; padding:8px 12px; font-size:13px; color:#111827; outline:none; background:#fff; width:200px;">
                <option value="">Tous les rôles</option>
                @foreach($roles as $role)
                    <option value="{{ $role->name }}">{{ $role->name }}</option>
                @endforeach
            </select>
        </div>
        
    </div>

    {{-- TABLEAU --}}
    <div style="background:#fff; border:1px solid #e5e7eb; border-radius:10px; overflow:hidden;">

        <div style="padding:12px 16px; border-bottom:1px solid #e5e7eb; display:flex; align-items:center; justify-content:space-between;">
            <p style="font-size:13px; font-weight:600; color:#111827; margin:0;">Utilisateurs</p>
            <span style="background:#E8ECF8; color:#1B2F6E; font-size:11px; font-weight:600; padding:3px 10px; border-radius:20px;">
                {{ $users->total() }} utilisateur(s)
            </span>
        </div>

        <div style="overflow-x:auto;">
            <table style="width:100%; border-collapse:collapse; font-size:12px;">
                <thead>
                    <tr style="background:#F7F8FC;">
                        <th style="padding:10px 16px; text-align:left; color:#6b7280; font-weight:500; border-bottom:1px solid #e5e7eb;">#</th>
                        <th style="padding:10px 16px; text-align:left; color:#6b7280; font-weight:500; border-bottom:1px solid #e5e7eb;">Utilisateur</th>
                        <th style="padding:10px 16px; text-align:left; color:#6b7280; font-weight:500; border-bottom:1px solid #e5e7eb;">Département</th>
                        <th style="padding:10px 16px; text-align:left; color:#6b7280; font-weight:500; border-bottom:1px solid #e5e7eb;">Rôle actuel</th>
                        <th style="padding:10px 16px; text-align:center; color:#6b7280; font-weight:500; border-bottom:1px solid #e5e7eb;">Action</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($users as $user)
                        <tr style="border-bottom:1px solid #f3f4f6;"
                            onmouseover="this.style.background='#F7F8FC'"
                            onmouseout="this.style.background='transparent'">

                            <td style="padding:10px 16px; color:#9ca3af;">
                                {{ $users->firstItem() + $loop->index }}
                            </td>

                            <td style="padding:10px 16px;">
                                <div style="display:flex; align-items:center; gap:10px;">
                                    <div style="width:32px; height:32px; border-radius:50%; background:#1B2F6E; display:flex; align-items:center; justify-content:center; color:#fff; font-size:11px; font-weight:700; flex-shrink:0;">
                                        {{ strtoupper(substr($user->name, 0, 2)) }}
                                    </div>
                                    <div>
                                        <p style="font-weight:600; color:#111827; margin:0; font-size:12px;">{{ $user->name }}</p>
                                        <p style="color:#9ca3af; margin:0; font-size:11px;">{{ $user->email }}</p>
                                    </div>
                                </div>
                            </td>

                            <td style="padding:10px 16px; color:#374151;">
                                @if($user->department)
                                    <span style="background:#f3f4f6; color:#374151; font-size:10px; font-weight:600; padding:2px 8px; border-radius:8px;">
                                        {{ $user->department->name }}
                                    </span>
                                @else
                                    <span style="color:#9ca3af; font-size:11px;">—</span>
                                @endif
                            </td>

                            <td style="padding:10px 16px;">
                                @if($user->roles->isNotEmpty())
                                    @php
                                        $roleName = $user->roles->first()->name;
                                        $roleColors = [
                                            'super-admin'         => ['bg'=>'#376553','color'=>'#5cdec2'],
                                            'directeur'           => ['bg'=>'#E8ECF8','color'=>'#1B2F6E'],
                                            'analyste-finance'    => ['bg'=>'#E5F5ED','color'=>'#005C2B'],
                                            'analyste-conformite' => ['bg'=>'#FFF3D0','color'=>'#7A4F00'],
                                            'agent-operations'    => ['bg'=>'#F3E8FF','color'=>'#6B21A8'],
                                            'auditeur'            => ['bg'=>'#f3f4f6','color'=>'#374151'],
                                        ];
                                        $rc = $roleColors[$roleName] ?? ['bg'=>'#f3f4f6','color'=>'#374151'];
                                    @endphp
                                    <span style="background:{{ $rc['bg'] }}; color:{{ $rc['color'] }}; font-size:10px; font-weight:700; padding:3px 10px; border-radius:12px;">
                                        {{ $roleName }}
                                    </span>
                                @else
                                    <span style="background:#FDECEA; color:#7F1D1D; font-size:10px; font-weight:600; padding:3px 10px; border-radius:12px;">
                                        Aucun rôle
                                    </span>
                                @endif
                            </td>

                            <td style="padding:10px 16px; text-align:center;">
                                <button wire:click="assignerRole({{ $user->id }})"
                                        style="background:#1B2F6E; color:#fff; font-size:11px; font-weight:600; padding:6px 14px; border-radius:8px; border:none; cursor:pointer; display:inline-flex; align-items:center; gap:5px;">
                                    <svg width="11" height="11" viewBox="0 0 16 16" fill="white">
                                        <path d="M8 1a3 3 0 100 6A3 3 0 008 1zM4 9a4 4 0 018 0v1H4V9z"/>
                                        <path d="M13 8v4M11 10h4" stroke="white" stroke-width="1.2" stroke-linecap="round"/>
                                    </svg>
                                    Attribuer rôle
                                </button>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="5" style="padding:40px; text-align:center; color:#9ca3af; font-size:13px;">
                                Aucun utilisateur trouvé.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        @if($users->hasPages())
            <div style="padding:12px 16px; border-top:1px solid #e5e7eb;">
                {{ $users->links() }}
            </div>
        @endif
    </div>

    {{-- MODAL ASSIGNATION RÔLE --}}
    @if($showRoleModal)
        <div wire:click="fermerModal"
             style="position:fixed; inset:0; background:rgba(0,0,0,0.45); z-index:998;"></div>

        <div style="position:fixed; top:50%; left:50%; transform:translate(-50%,-50%);
                    width:min(520px,95vw); background:#fff; border-radius:14px; z-index:999;
                    box-shadow:0 20px 60px rgba(0,0,0,0.2);">

            <div style="padding:16px 20px; border-bottom:1px solid #e5e7eb; display:flex; align-items:center; justify-content:space-between;">
                <div>
                    <p style="font-size:14px; font-weight:700; color:#111827; margin:0;">Attribuer un rôle</p>
                    <p style="font-size:11px; color:#9ca3af; margin:2px 0 0;">{{ $editUserName }} — {{ $editUserEmail }}</p>
                </div>
                <button wire:click="fermerModal"
                        style="width:28px; height:28px; border-radius:7px; border:1px solid #e5e7eb; background:#f9fafb; cursor:pointer; display:flex; align-items:center; justify-content:center;">
                    <svg width="12" height="12" viewBox="0 0 12 12" fill="none">
                        <path d="M1 1l10 10M11 1L1 11" stroke="#6b7280" stroke-width="1.5" stroke-linecap="round"/>
                    </svg>
                </button>
            </div>

            <div style="padding:20px;">

                <div style="margin-bottom:16px;">
                    <label style="font-size:11px; color:#6b7280; display:block; margin-bottom:6px; font-weight:600;">
                        Département
                    </label>
                    <select wire:model="selectedDept"
                            style="width:100%; border:1px solid #d1d5db; border-radius:7px; padding:9px 12px; font-size:13px; color:#111827; outline:none; background:#fff;">
                        <option value="">— Aucun département —</option>
                        @foreach($departments as $dept)
                            <option value="{{ $dept->id }}">{{ $dept->name }} ({{ $dept->code }})</option>
                        @endforeach
                    </select>
                </div>

                <div style="margin-bottom:20px;">
                    <label style="font-size:11px; color:#6b7280; display:block; margin-bottom:6px; font-weight:600;">
                        Rôle <span style="color:#E24B4A;">*</span>
                    </label>
                    <div style="display:flex; flex-direction:column; gap:8px;">
                        @foreach($roles as $role)
                            @php
                                $roleColors = [
                                    'super-admin'         => ['bg'=>'#FDECEA','color'=>'#7F1D1D','border'=>'#f5a8a8'],
                                    'directeur'           => ['bg'=>'#E8ECF8','color'=>'#1B2F6E','border'=>'#a8b8e8'],
                                    'analyste-finance'    => ['bg'=>'#E5F5ED','color'=>'#005C2B','border'=>'#a7d7b8'],
                                    'analyste-conformite' => ['bg'=>'#FFF3D0','color'=>'#7A4F00','border'=>'#f5d98a'],
                                    'agent-operations'    => ['bg'=>'#F3E8FF','color'=>'#6B21A8','border'=>'#d4a8f0'],
                                    'auditeur'            => ['bg'=>'#f3f4f6','color'=>'#374151','border'=>'#d1d5db'],
                                ];
                                $rc = $roleColors[$role->name] ?? ['bg'=>'#f3f4f6','color'=>'#374151','border'=>'#d1d5db'];
                                $isSelected = $selectedRole === $role->name;
                            @endphp
                            <label style="display:flex; align-items:center; gap:12px; padding:10px 14px; border-radius:8px; cursor:pointer;
                                          border:2px solid {{ $isSelected ? $rc['border'] : '#e5e7eb' }};
                                          background:{{ $isSelected ? $rc['bg'] : '#fff' }};
                                          transition:all 0.15s;">
                                <input type="radio"
                                       wire:model="selectedRole"
                                       value="{{ $role->name }}"
                                       style="accent-color:#1B2F6E;">
                                <div style="flex:1;">
                                    <p style="font-size:12px; font-weight:700; color:{{ $rc['color'] }}; margin:0;">
                                        {{ $role->name }}
                                    </p>
                                    <p style="font-size:10px; color:#9ca3af; margin:2px 0 0;">
                                        {{ $role->permissions_count ?? $role->permissions()->count() }} permissions
                                    </p>
                                </div>
                            </label>
                        @endforeach
                    </div>
                    @error('selectedRole')
                        <p style="font-size:10px; color:#E24B4A; margin:6px 0 0;">{{ $message }}</p>
                    @enderror
                </div>

            </div>

            <div style="padding:12px 20px; border-top:1px solid #e5e7eb; display:flex; justify-content:flex-end; gap:10px;">
                <button wire:click="fermerModal"
                        style="background:#f3f4f6; color:#374151; font-size:13px; font-weight:500; padding:8px 20px; border-radius:8px; border:1px solid #e5e7eb; cursor:pointer;">
                    Annuler
                </button>
                <button wire:click="sauvegarder"
                        wire:loading.attr="disabled"
                        wire:target="sauvegarder"
                        style="background:#1B2F6E; color:#fff; font-size:13px; font-weight:600; padding:8px 20px; border-radius:8px; border:none; cursor:pointer; display:flex; align-items:center; gap:7px;">
                    <span wire:loading.remove wire:target="sauvegarder">Enregistrer</span>
                    <span wire:loading wire:target="sauvegarder">Sauvegarde...</span>
                </button>
            </div>
        </div>
    @endif


    {{-- MODAL CRÉER UTILISATEUR --}}
@if($showCreateModal)
    <div wire:click="fermerCreateModal"
         style="position:fixed; inset:0; background:rgba(0,0,0,0.45); z-index:998;"></div>

    <div style="position:fixed; top:50%; left:50%; transform:translate(-50%,-50%);
                width:min(520px,95vw); background:#fff; border-radius:14px; z-index:999;
                box-shadow:0 20px 60px rgba(0,0,0,0.2);">

        <div style="padding:16px 20px; border-bottom:1px solid #e5e7eb; display:flex; align-items:center; justify-content:space-between;">
            <div>
                <p style="font-size:14px; font-weight:700; color:#111827; margin:0;">Nouvel utilisateur</p>
                <p style="font-size:11px; color:#9ca3af; margin:2px 0 0;">Créez un compte et attribuez un rôle.</p>
            </div>
            <button wire:click="fermerCreateModal"
                    style="width:28px; height:28px; border-radius:7px; border:1px solid #e5e7eb; background:#f9fafb; cursor:pointer; display:flex; align-items:center; justify-content:center;">
                <svg width="12" height="12" viewBox="0 0 12 12" fill="none">
                    <path d="M1 1l10 10M11 1L1 11" stroke="#6b7280" stroke-width="1.5" stroke-linecap="round"/>
                </svg>
            </button>
        </div>

        <div style="padding:20px; display:flex; flex-direction:column; gap:14px;">

            <div>
                <label style="font-size:11px; color:#6b7280; display:block; margin-bottom:4px; font-weight:600;">
                    Nom complet <span style="color:#E24B4A;">*</span>
                </label>
                <input type="text" wire:model="newName" placeholder="Ex: Ahmed Omar"
                       style="width:100%; border:1px solid #d1d5db; border-radius:7px; padding:9px 12px; font-size:13px; color:#111827; outline:none;">
                @error('newName')
                    <p style="font-size:10px; color:#E24B4A; margin:4px 0 0;">{{ $message }}</p>
                @enderror
            </div>

            <div>
                <label style="font-size:11px; color:#6b7280; display:block; margin-bottom:4px; font-weight:600;">
                    Email <span style="color:#E24B4A;">*</span>
                </label>
                <input type="email" wire:model="newEmail" placeholder="Ex: ahmed@d-money.dj"
                       style="width:100%; border:1px solid #d1d5db; border-radius:7px; padding:9px 12px; font-size:13px; color:#111827; outline:none;">
                @error('newEmail')
                    <p style="font-size:10px; color:#E24B4A; margin:4px 0 0;">{{ $message }}</p>
                @enderror
            </div>

            <div>
                <label style="font-size:11px; color:#6b7280; display:block; margin-bottom:4px; font-weight:600;">
                    Mot de passe <span style="color:#E24B4A;">*</span>
                </label>
                <input type="password" wire:model="newPassword" placeholder="Min. 8 caractères"
                       style="width:100%; border:1px solid #d1d5db; border-radius:7px; padding:9px 12px; font-size:13px; color:#111827; outline:none;">
                @error('newPassword')
                    <p style="font-size:10px; color:#E24B4A; margin:4px 0 0;">{{ $message }}</p>
                @enderror
            </div>

            <div>
                <label style="font-size:11px; color:#6b7280; display:block; margin-bottom:4px; font-weight:600;">Département</label>
                <select wire:model="newDept"
                        style="width:100%; border:1px solid #d1d5db; border-radius:7px; padding:9px 12px; font-size:13px; color:#111827; outline:none; background:#fff;">
                    <option value="">— Aucun département —</option>
                    @foreach($departments as $dept)
                        <option value="{{ $dept->id }}">{{ $dept->name }} ({{ $dept->code }})</option>
                    @endforeach
                </select>
            </div>

            <div>
                <label style="font-size:11px; color:#6b7280; display:block; margin-bottom:6px; font-weight:600;">
                    Rôle <span style="color:#E24B4A;">*</span>
                </label>
                <select wire:model="newRole"
                        style="width:100%; border:1px solid #d1d5db; border-radius:7px; padding:9px 12px; font-size:13px; color:#111827; outline:none; background:#fff;">
                    <option value="">— Choisir un rôle —</option>
                    @foreach($roles as $role)
                        <option value="{{ $role->name }}">{{ $role->name }}</option>
                    @endforeach
                </select>
                @error('newRole')
                    <p style="font-size:10px; color:#E24B4A; margin:4px 0 0;">{{ $message }}</p>
                @enderror
            </div>

        </div>

        <div style="padding:12px 20px; border-top:1px solid #e5e7eb; display:flex; justify-content:flex-end; gap:10px;">
            <button wire:click="fermerCreateModal"
                    style="background:#f3f4f6; color:#374151; font-size:13px; font-weight:500; padding:8px 20px; border-radius:8px; border:1px solid #e5e7eb; cursor:pointer;">
                Annuler
            </button>
            <button wire:click="creerUtilisateur"
                    wire:loading.attr="disabled"
                    wire:target="creerUtilisateur"
                    style="background:#1B2F6E; color:#fff; font-size:13px; font-weight:600; padding:8px 22px; border-radius:8px; border:none; cursor:pointer; display:flex; align-items:center; gap:7px;">
                <span wire:loading.remove wire:target="creerUtilisateur" style="display:flex; align-items:center; gap:6px;">
                    <svg width="13" height="13" viewBox="0 0 16 16" fill="none" stroke="white" stroke-width="1.5" stroke-linecap="round">
                        <path d="M8 2v12M2 8h12"/>
                    </svg>
                    Créer l'utilisateur
                </span>
                <span wire:loading wire:target="creerUtilisateur">Création...</span>
            </button>
        </div>
    </div>
@endif
</div>