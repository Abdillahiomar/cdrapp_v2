<?php

use Livewire\Volt\Component;

new class extends Component {

    // ── Modal voir permissions ────────────────────────────────
    public bool    $showModal        = false;
    public ?int    $selectedRoleId   = null;
    public string  $selectedRoleName = '';
    public array   $rolePermissions  = [];

    // ── Modal créer / modifier rôle ───────────────────────────
    public bool   $showEditModal  = false;
    public bool   $isCreating     = false;
    public ?int   $editRoleId     = null;
    public string $editRoleName   = '';
    public array  $editPermissions = [];


    // ── Modal permissions ─────────────────────────────────────
    public bool   $showPermModal  = false;
    public string $newPermName    = '';
    public string $searchPerm     = '';


    public function ouvrirPermissions(): void
    {
        $this->newPermName  = '';
        $this->searchPerm   = '';
        $this->showPermModal = true;
    }

    public function fermerPermModal(): void
    {
        $this->showPermModal = false;
        $this->newPermName   = '';
        $this->searchPerm    = '';
    }

    public function creerPermission(): void
    {
        $this->validate([
            'newPermName' => 'required|string|min:3|max:100',
        ], [
            'newPermName.required' => 'Le nom de la permission est obligatoire.',
            'newPermName.min'      => 'Le nom doit contenir au moins 3 caractères.',
        ]);

        $permissionClass = config('permission.models.permission');

        if ($permissionClass::where('name', $this->newPermName)->exists()) {
            $this->addError('newPermName', 'Cette permission existe déjà.');
            return;
        }

        $permissionClass::create([
            'name'       => $this->newPermName,
            'guard_name' => 'web',
        ]);

        $this->newPermName = '';
        session()->flash('perm_success', "Permission « {$this->newPermName} » créée.");
    }

    public function supprimerPermission(int $id): void
    {
        $permissionClass = config('permission.models.permission');
        $permissionClass::findOrFail($id)->delete();
    }

    public function voirPermissions(int $id, string $name): void
    {
        $roleClass = config('permission.models.role');
        $role = $roleClass::with('permissions')->findOrFail($id);

        $this->selectedRoleId   = $id;
        $this->selectedRoleName = $name;
        $this->rolePermissions  = $role->permissions->pluck('name')->toArray();
        $this->showModal        = true;
    }

    public function fermerModal(): void
    {
        $this->showModal        = false;
        $this->selectedRoleId   = null;
        $this->selectedRoleName = '';
        $this->rolePermissions  = [];
    }

    public function ouvrirCreation(): void
    {
        $this->isCreating      = true;
        $this->editRoleId      = null;
        $this->editRoleName    = '';
        $this->editPermissions = [];
        $this->showEditModal   = true;
    }

    public function ouvrirModification(int $id, string $name): void
    {
        $roleClass = config('permission.models.role');
        $role = $roleClass::with('permissions')->findOrFail($id);

        $this->isCreating      = false;
        $this->editRoleId      = $id;
        $this->editRoleName    = $name;
        $this->editPermissions = $role->permissions->pluck('name')->toArray();
        $this->showEditModal   = true;
    }

    public function sauvegarder(): void
    {
        $this->validate([
            'editRoleName' => 'required|string|min:2|max:50',
        ], [
            'editRoleName.required' => 'Le nom du rôle est obligatoire.',
            'editRoleName.min'      => 'Le nom doit contenir au moins 2 caractères.',
        ]);

        $roleClass = config('permission.models.role');

        if ($this->isCreating) {
            // Vérifier que le nom n'existe pas déjà
            if ($roleClass::where('name', $this->editRoleName)->exists()) {
                $this->addError('editRoleName', 'Ce nom de rôle existe déjà.');
                return;
            }
            $role = $roleClass::create([
                'name'       => $this->editRoleName,
                'guard_name' => 'web',
            ]);
        } else {
            $role = $roleClass::findOrFail($this->editRoleId);
            $role->update(['name' => $this->editRoleName]);
        }

        $role->syncPermissions($this->editPermissions);

        $this->showEditModal = false;
        $this->reset(['editRoleId', 'editRoleName', 'editPermissions']);
    }

    public function fermerEditModal(): void
    {
        $this->showEditModal = false;
        $this->reset(['editRoleId', 'editRoleName', 'editPermissions']);
    }

    public function with(): array
    {
        $roleClass       = config('permission.models.role');
        $permissionClass = config('permission.models.permission');

        // Grouper les permissions par module
        $allPermissions = $permissionClass::orderBy('name')
            ->get()
            ->groupBy(fn($p) => explode('.', $p->name)[0]);

        // Liste filtrée pour le modal permissions
        $allPermissionsList = $permissionClass::orderBy('name')
            ->when($this->searchPerm, fn($q) => $q->where('name', 'like', '%'.$this->searchPerm.'%'))
            ->get()
            ->groupBy(fn($p) => explode('.', $p->name)[0]);

        return [
            'roles'          => $roleClass::withCount('permissions', 'users')->get(),
            'allPermissions'     => $allPermissions,
            'allPermissionsList' => $allPermissionsList,
        ];
    }
};
?>

<div style="padding:24px;">

    <div style="display:flex; align-items:center; gap:10px;">
        <button wire:click="ouvrirPermissions"
                style="background:#f3f4f6; color:#374151; font-size:13px; font-weight:600; padding:9px 18px; border-radius:8px; border:1px solid #e5e7eb; cursor:pointer; display:flex; align-items:center; gap:7px;">
            <svg width="14" height="14" viewBox="0 0 16 16" fill="#374151">
                <path d="M8 1a3 3 0 100 6A3 3 0 008 1zM2 14s-1 0-1-1 1-4 7-4 7 3 7 4-1 1-1 1H2z"/>
            </svg>
            Permissions
        </button>
        <button wire:click="ouvrirCreation"
                style="background:#1B2F6E; color:#fff; font-size:13px; font-weight:600; padding:9px 18px; border-radius:8px; border:none; cursor:pointer; display:flex; align-items:center; gap:7px;">
            <svg width="14" height="14" viewBox="0 0 16 16" fill="white">
                <path d="M8 2v12M2 8h12" stroke="white" stroke-width="1.8" stroke-linecap="round"/>
            </svg>
            Nouveau rôle
        </button>
    </div>
    

    <div style="background:#fff; border:1px solid #e5e7eb; border-radius:10px; overflow:hidden;">

        <div style="padding:12px 16px; border-bottom:1px solid #e5e7eb; display:flex; align-items:center; justify-content:space-between;">
            <p style="font-size:13px; font-weight:600; color:#111827; margin:0;">Liste des rôles</p>
            <span style="background:#E8ECF8; color:#1B2F6E; font-size:11px; font-weight:600; padding:3px 10px; border-radius:20px;">
                {{ $roles->count() }} rôle(s)
            </span>
        </div>

        <table style="width:100%; border-collapse:collapse; font-size:12px;">
            <thead>
                <tr style="background:#F7F8FC;">
                    <th style="padding:10px 16px; text-align:left; color:#6b7280; font-weight:500; border-bottom:1px solid #e5e7eb;">#</th>
                    <th style="padding:10px 16px; text-align:left; color:#6b7280; font-weight:500; border-bottom:1px solid #e5e7eb;">Rôle</th>
                    <th style="padding:10px 16px; text-align:center; color:#6b7280; font-weight:500; border-bottom:1px solid #e5e7eb;">Permissions</th>
                    <th style="padding:10px 16px; text-align:center; color:#6b7280; font-weight:500; border-bottom:1px solid #e5e7eb;">Utilisateurs</th>
                    <th style="padding:10px 16px; text-align:center; color:#6b7280; font-weight:500; border-bottom:1px solid #e5e7eb;">Actions</th>
                </tr>
            </thead>
            <tbody>
                @foreach($roles as $i => $role)
                    <tr style="border-bottom:1px solid #f3f4f6;"
                        onmouseover="this.style.background='#F7F8FC'"
                        onmouseout="this.style.background='transparent'">
                        <td style="padding:10px 16px; color:#9ca3af;">{{ $i + 1 }}</td>
                        <td style="padding:10px 16px;">
                            @php
                                $colors = [
                                    'super-admin'         => ['bg'=>'#FDECEA','color'=>'#7F1D1D'],
                                    'directeur'           => ['bg'=>'#E8ECF8','color'=>'#1B2F6E'],
                                    'analyste-finance'    => ['bg'=>'#E5F5ED','color'=>'#005C2B'],
                                    'analyste-conformite' => ['bg'=>'#FFF3D0','color'=>'#7A4F00'],
                                    'agent-operations'    => ['bg'=>'#F3E8FF','color'=>'#6B21A8'],
                                    'auditeur'            => ['bg'=>'#f3f4f6','color'=>'#374151'],
                                ];
                                $c = $colors[$role->name] ?? ['bg'=>'#f3f4f6','color'=>'#374151'];
                            @endphp
                            <span style="background:{{ $c['bg'] }}; color:{{ $c['color'] }}; font-size:11px; font-weight:700; padding:4px 12px; border-radius:12px;">
                                {{ $role->name }}
                            </span>
                        </td>
                        <td style="padding:10px 16px; text-align:center;">
                            <span style="background:#E8ECF8; color:#1B2F6E; font-size:11px; font-weight:600; padding:2px 10px; border-radius:12px;">
                                {{ $role->permissions_count }}
                            </span>
                        </td>
                        <td style="padding:10px 16px; text-align:center; color:#374151;">
                            {{ $role->users_count }}
                        </td>
                        <td style="padding:10px 16px; text-align:center;">
                            <div style="display:flex; align-items:center; justify-content:center; gap:6px;">
                                <button wire:click="voirPermissions({{ $role->id }}, '{{ $role->name }}')"
                                        style="background:#E8ECF8; color:#1B2F6E; font-size:11px; font-weight:600; padding:5px 10px; border-radius:8px; border:none; cursor:pointer; display:inline-flex; align-items:center; gap:4px;">
                                    <svg width="11" height="11" viewBox="0 0 16 16" fill="none" stroke="#1B2F6E" stroke-width="1.5">
                                        <circle cx="8" cy="8" r="2.5"/>
                                        <path d="M1 8s2.5-5 7-5 7 5 7 5-2.5 5-7 5-7-5-7-5z"/>
                                    </svg>
                                    Voir
                                </button>
                                <button wire:click="ouvrirModification({{ $role->id }}, '{{ $role->name }}')"
                                        style="background:#FFF3D0; color:#7A4F00; font-size:11px; font-weight:600; padding:5px 10px; border-radius:8px; border:none; cursor:pointer; display:inline-flex; align-items:center; gap:4px;">
                                    <svg width="11" height="11" viewBox="0 0 16 16" fill="none" stroke="#7A4F00" stroke-width="1.5" stroke-linecap="round">
                                        <path d="M11 2l3 3-9 9H2v-3l9-9z"/>
                                    </svg>
                                    Modifier
                                </button>
                            </div>
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>

    {{-- ── MODAL VOIR PERMISSIONS ── --}}
    @if($showModal)
        <div wire:click="fermerModal"
             style="position:fixed; inset:0; background:rgba(0,0,0,0.45); z-index:998;"></div>

        <div style="position:fixed; top:50%; left:50%; transform:translate(-50%,-50%);
                    width:min(700px,95vw); max-height:85vh; background:#fff;
                    border-radius:14px; z-index:999; display:flex; flex-direction:column;
                    box-shadow:0 20px 60px rgba(0,0,0,0.2);">

            <div style="padding:16px 20px; border-bottom:1px solid #e5e7eb; display:flex; align-items:center; justify-content:space-between; flex-shrink:0;">
                <div>
                    <p style="font-size:14px; font-weight:700; color:#111827; margin:0;">Permissions du rôle</p>
                    <p style="font-size:11px; color:#9ca3af; margin:2px 0 0;">{{ $selectedRoleName }}</p>
                </div>
                <div style="display:flex; align-items:center; gap:10px;">
                    <span style="background:#E8ECF8; color:#1B2F6E; font-size:11px; font-weight:600; padding:3px 10px; border-radius:20px;">
                        {{ count($rolePermissions) }} permission(s)
                    </span>
                    <button wire:click="fermerModal"
                            style="width:28px; height:28px; border-radius:7px; border:1px solid #e5e7eb; background:#f9fafb; cursor:pointer; display:flex; align-items:center; justify-content:center;">
                        <svg width="12" height="12" viewBox="0 0 12 12" fill="none">
                            <path d="M1 1l10 10M11 1L1 11" stroke="#6b7280" stroke-width="1.5" stroke-linecap="round"/>
                        </svg>
                    </button>
                </div>
            </div>

            <div style="flex:1; overflow-y:auto; padding:20px;">
                @php
                    $grouped = collect($rolePermissions)->groupBy(fn($p) => explode('.', $p)[0]);
                @endphp
                @foreach($grouped as $module => $perms)
                    <div style="margin-bottom:16px;">
                        <p style="font-size:10px; font-weight:700; color:#9ca3af; text-transform:uppercase; letter-spacing:1px; margin:0 0 8px;">
                            {{ $module }}
                        </p>
                        <div style="display:flex; flex-wrap:wrap; gap:6px;">
                            @foreach($perms as $perm)
                                <span style="background:#E8ECF8; color:#1B2F6E; font-size:11px; font-weight:500; padding:4px 10px; border-radius:8px; display:inline-flex; align-items:center; gap:4px;">
                                    <svg width="10" height="10" viewBox="0 0 10 10" fill="none">
                                        <path d="M2 5l2.5 2.5L8 3" stroke="#16a34a" stroke-width="1.5" stroke-linecap="round"/>
                                    </svg>
                                    {{ $perm }}
                                </span>
                            @endforeach
                        </div>
                    </div>
                @endforeach
            </div>

            <div style="padding:12px 20px; border-top:1px solid #e5e7eb; display:flex; justify-content:flex-end; flex-shrink:0;">
                <button wire:click="fermerModal"
                        style="background:#f3f4f6; color:#374151; font-size:13px; font-weight:500; padding:8px 20px; border-radius:8px; border:1px solid #e5e7eb; cursor:pointer;">
                    Fermer
                </button>
            </div>
        </div>
    @endif

    {{-- ── MODAL CRÉER / MODIFIER ── --}}
    @if($showEditModal)
        <div wire:click="fermerEditModal"
             style="position:fixed; inset:0; background:rgba(0,0,0,0.45); z-index:998;"></div>

        <div style="position:fixed; top:50%; left:50%; transform:translate(-50%,-50%);
                    width:min(760px,95vw); max-height:90vh; background:#fff;
                    border-radius:14px; z-index:999; display:flex; flex-direction:column;
                    box-shadow:0 20px 60px rgba(0,0,0,0.2);">

            {{-- Header --}}
            <div style="padding:16px 20px; border-bottom:1px solid #e5e7eb; display:flex; align-items:center; justify-content:space-between; flex-shrink:0;">
                <div>
                    <p style="font-size:14px; font-weight:700; color:#111827; margin:0;">
                        {{ $isCreating ? 'Créer un nouveau rôle' : 'Modifier le rôle' }}
                    </p>
                    <p style="font-size:11px; color:#9ca3af; margin:2px 0 0;">
                        {{ $isCreating ? 'Définissez le nom et les permissions du rôle.' : 'Modifiez le nom et les permissions de ' . $editRoleName }}
                    </p>
                </div>
                <button wire:click="fermerEditModal"
                        style="width:28px; height:28px; border-radius:7px; border:1px solid #e5e7eb; background:#f9fafb; cursor:pointer; display:flex; align-items:center; justify-content:center;">
                    <svg width="12" height="12" viewBox="0 0 12 12" fill="none">
                        <path d="M1 1l10 10M11 1L1 11" stroke="#6b7280" stroke-width="1.5" stroke-linecap="round"/>
                    </svg>
                </button>
            </div>

            {{-- Corps --}}
            <div style="flex:1; overflow-y:auto; padding:20px;">

                {{-- Nom du rôle --}}
                <div style="margin-bottom:20px;">
                    <label style="font-size:11px; color:#6b7280; display:block; margin-bottom:6px; font-weight:600;">
                        Nom du rôle <span style="color:#E24B4A;">*</span>
                    </label>
                    <input type="text"
                           wire:model="editRoleName"
                           placeholder="Ex: analyste-fraude"
                           style="width:100%; border:1px solid #d1d5db; border-radius:7px; padding:9px 12px; font-size:13px; color:#111827; outline:none;">
                    @error('editRoleName')
                        <p style="font-size:10px; color:#E24B4A; margin:5px 0 0;">{{ $message }}</p>
                    @enderror
                    <p style="font-size:10px; color:#9ca3af; margin:4px 0 0;">Utilisez des minuscules et des tirets. Ex: analyste-fraude</p>
                </div>

                {{-- Permissions groupées par module --}}
                <div>
                    <label style="font-size:11px; color:#6b7280; display:block; margin-bottom:12px; font-weight:600;">
                        Permissions
                        <span style="background:#E8ECF8; color:#1B2F6E; font-size:10px; padding:1px 8px; border-radius:10px; margin-left:6px;">
                            {{ count($editPermissions) }} sélectionnée(s)
                        </span>
                    </label>

                    @foreach($allPermissions as $module => $perms)
                        <div style="margin-bottom:14px; background:#F7F8FC; border-radius:8px; padding:12px 14px;">


                            {{-- Header module avec "tout sélectionner" --}}
                            <div style="display:flex; align-items:center; justify-content:space-between; margin-bottom:10px;">
                                <p style="font-size:10px; font-weight:700; color:#1B2F6E; text-transform:uppercase; letter-spacing:1px; margin:0;">
                                    {{ $module }}
                                </p>
                                @php
                                    $modulePerms = $perms->pluck('name')->toArray();
                                    $allSelected = count(array_intersect($modulePerms, $editPermissions)) === count($modulePerms);
                                @endphp
                                <label style="font-size:10px; color:#6b7280; cursor:pointer; display:flex; align-items:center; gap:4px;">
                                    <input type="checkbox"
                                        style="accent-color:#1B2F6E;"
                                        {{ $allSelected ? 'checked' : '' }}
                                        x-data
                                        x-on:change="
                                            const names = {{ Js::from($modulePerms) }};
                                            names.forEach(n => {
                                                const idx = $wire.editPermissions.indexOf(n);
                                                if ($event.target.checked && idx === -1) {
                                                    $wire.editPermissions.push(n);
                                                } else if (!$event.target.checked && idx > -1) {
                                                    $wire.editPermissions.splice(idx, 1);
                                                }
                                            });
                                        ">
                                    Tout sélectionner
                                </label>
                            </div>

                            <div style="display:grid; grid-template-columns:repeat(2, 1fr); gap:6px;">
                                @foreach($perms as $perm)
                                    @php $isChecked = in_array($perm->name, $editPermissions); @endphp
                                    <label style="display:flex; align-items:center; gap:8px; padding:6px 10px; border-radius:6px; cursor:pointer;
                                                  background:{{ $isChecked ? '#E8ECF8' : '#fff' }};
                                                  border:1px solid {{ $isChecked ? '#a8b8e8' : '#e5e7eb' }};
                                                  transition:all 0.1s;">
                                        <input type="checkbox"
                                               wire:model="editPermissions"
                                               value="{{ $perm->name }}"
                                               style="accent-color:#1B2F6E;">
                                        <span style="font-size:11px; color:#374151;">{{ $perm->name }}</span>
                                    </label>
                                @endforeach
                            </div>
                        </div>
                    @endforeach
                </div>
            </div>

            {{-- Footer --}}
            <div style="padding:14px 20px; border-top:1px solid #e5e7eb; display:flex; justify-content:space-between; align-items:center; flex-shrink:0;">
                <p style="font-size:11px; color:#9ca3af; margin:0;">
                    {{ count($editPermissions) }} permission(s) sélectionnée(s)
                </p>
                <div style="display:flex; gap:10px;">
                    <button wire:click="fermerEditModal"
                            style="background:#f3f4f6; color:#374151; font-size:13px; font-weight:500; padding:8px 20px; border-radius:8px; border:1px solid #e5e7eb; cursor:pointer;">
                        Annuler
                    </button>
                    <button wire:click="sauvegarder"
                            wire:loading.attr="disabled"
                            wire:target="sauvegarder"
                            style="background:#1B2F6E; color:#fff; font-size:13px; font-weight:600; padding:8px 22px; border-radius:8px; border:none; cursor:pointer; display:flex; align-items:center; gap:7px;">
                        <span wire:loading.remove wire:target="sauvegarder" style="display:flex; align-items:center; gap:6px;">
                            <svg width="13" height="13" viewBox="0 0 16 16" fill="none" stroke="white" stroke-width="1.5" stroke-linecap="round">
                                <path d="M2 8l4 4 8-8"/>
                            </svg>
                            {{ $isCreating ? 'Créer le rôle' : 'Enregistrer les modifications' }}
                        </span>
                        <span wire:loading wire:target="sauvegarder">Sauvegarde...</span>
                    </button>
                </div>
            </div>
        </div>
    @endif


    {{-- ── MODAL PERMISSIONS ── --}}
    @if($showPermModal)
        <div wire:click="fermerPermModal"
            style="position:fixed; inset:0; background:rgba(0,0,0,0.45); z-index:998;"></div>

        <div style="position:fixed; top:50%; left:50%; transform:translate(-50%,-50%);
                    width:min(700px,95vw); max-height:90vh; background:#fff;
                    border-radius:14px; z-index:999; display:flex; flex-direction:column;
                    box-shadow:0 20px 60px rgba(0,0,0,0.2);">

            {{-- Header --}}
            <div style="padding:16px 20px; border-bottom:1px solid #e5e7eb; display:flex; align-items:center; justify-content:space-between; flex-shrink:0;">
                <div>
                    <p style="font-size:14px; font-weight:700; color:#111827; margin:0;">Gestion des permissions</p>
                    <p style="font-size:11px; color:#9ca3af; margin:2px 0 0;">Créez et gérez toutes les permissions disponibles.</p>
                </div>
                <button wire:click="fermerPermModal"
                        style="width:28px; height:28px; border-radius:7px; border:1px solid #e5e7eb; background:#f9fafb; cursor:pointer; display:flex; align-items:center; justify-content:center;">
                    <svg width="12" height="12" viewBox="0 0 12 12" fill="none">
                        <path d="M1 1l10 10M11 1L1 11" stroke="#6b7280" stroke-width="1.5" stroke-linecap="round"/>
                    </svg>
                </button>
            </div>

            {{-- Créer une nouvelle permission --}}
            <div style="padding:16px 20px; border-bottom:1px solid #e5e7eb; flex-shrink:0;">
                <p style="font-size:11px; font-weight:600; color:#374151; margin:0 0 8px;">
                    Nouvelle permission
                </p>
                <div style="display:flex; gap:8px;">
                    <div style="flex:1;">
                        <input type="text"
                            wire:model="newPermName"
                            wire:keydown.enter="creerPermission"
                            placeholder="Ex: customers.export"
                            style="width:100%; border:1px solid #d1d5db; border-radius:7px; padding:8px 12px; font-size:13px; color:#111827; outline:none; box-sizing:border-box;">
                        @error('newPermName')
                            <p style="font-size:10px; color:#E24B4A; margin:4px 0 0;">{{ $message }}</p>
                        @enderror
                        <p style="font-size:10px; color:#9ca3af; margin:3px 0 0;">Format recommandé : module.action — Ex: transactions.export</p>
                    </div>
                    <button wire:click="creerPermission"
                            wire:loading.attr="disabled"
                            wire:target="creerPermission"
                            style="background:#1B2F6E; color:#fff; font-size:12px; font-weight:600; padding:8px 16px; border-radius:7px; border:none; cursor:pointer; white-space:nowrap; display:flex; align-items:center; gap:5px; flex-shrink:0;">
                        <span wire:loading.remove wire:target="creerPermission" style="display:flex; align-items:center; gap:5px;">
                            <svg width="12" height="12" viewBox="0 0 16 16" fill="white">
                                <path d="M8 2v12M2 8h12" stroke="white" stroke-width="1.8" stroke-linecap="round"/>
                            </svg>
                            Ajouter
                        </span>
                        <span wire:loading wire:target="creerPermission">...</span>
                    </button>
                </div>
            </div>

            {{-- Recherche --}}
            <div style="padding:12px 20px; border-bottom:1px solid #e5e7eb; flex-shrink:0;">
                <input type="text"
                    wire:model.live="searchPerm"
                    placeholder="Rechercher une permission..."
                    style="width:100%; border:1px solid #d1d5db; border-radius:7px; padding:8px 12px; font-size:13px; color:#111827; outline:none; box-sizing:border-box;">
            </div>

            {{-- Liste des permissions groupées --}}
            <div style="flex:1; overflow-y:auto; padding:16px 20px;">

                @if($allPermissionsList->isEmpty())
                    <p style="text-align:center; font-size:12px; color:#9ca3af; padding:20px 0;">
                        Aucune permission trouvée.
                    </p>
                @else
                    @foreach($allPermissionsList as $module => $perms)
                        <div style="margin-bottom:16px;">
                            <div style="display:flex; align-items:center; justify-content:space-between; margin-bottom:8px;">
                                <p style="font-size:10px; font-weight:700; color:#1B2F6E; text-transform:uppercase; letter-spacing:1px; margin:0;">
                                    {{ $module }}
                                    <span style="background:#E8ECF8; color:#1B2F6E; font-size:9px; padding:1px 6px; border-radius:8px; margin-left:4px;">
                                        {{ $perms->count() }}
                                    </span>
                                </p>
                            </div>

                            <div style="display:flex; flex-direction:column; gap:4px;">
                                @foreach($perms as $perm)
                                    <div style="display:flex; align-items:center; justify-content:space-between; padding:7px 12px; background:#F7F8FC; border-radius:7px; border:1px solid #f3f4f6;"
                                        onmouseover="this.style.background='#E8ECF8'"
                                        onmouseout="this.style.background='#F7F8FC'">
                                        <div style="display:flex; align-items:center; gap:8px;">
                                            <svg width="10" height="10" viewBox="0 0 10 10" fill="none">
                                                <path d="M2 5l2.5 2.5L8 3" stroke="#16a34a" stroke-width="1.5" stroke-linecap="round"/>
                                            </svg>
                                            <span style="font-size:12px; color:#374151; font-weight:500;">{{ $perm->name }}</span>
                                        </div>
                                        <button wire:click="supprimerPermission({{ $perm->id }})"
                                                wire:confirm="Supprimer la permission « {{ $perm->name }} » ? Elle sera retirée de tous les rôles."
                                                style="background:none; border:none; cursor:pointer; color:#9ca3af; padding:2px 6px; border-radius:4px; font-size:10px; display:flex; align-items:center; gap:3px;"
                                                onmouseover="this.style.color='#E24B4A'; this.style.background='#FDECEA'"
                                                onmouseout="this.style.color='#9ca3af'; this.style.background='none'">
                                            <svg width="11" height="11" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round">
                                                <path d="M2 4h12M5 4V2h6v2M6 7v5M10 7v5M3 4l1 10h8l1-10"/>
                                            </svg>
                                            Supprimer
                                        </button>
                                    </div>
                                @endforeach
                            </div>
                        </div>
                    @endforeach
                @endif
            </div>

            {{-- Footer --}}
            <div style="padding:12px 20px; border-top:1px solid #e5e7eb; display:flex; align-items:center; justify-content:space-between; flex-shrink:0;">
                <span style="font-size:11px; color:#9ca3af;">
                    {{ $allPermissionsList->flatten()->count() }} permission(s) au total
                </span>
                <button wire:click="fermerPermModal"
                        style="background:#f3f4f6; color:#374151; font-size:13px; font-weight:500; padding:8px 20px; border-radius:8px; border:1px solid #e5e7eb; cursor:pointer;">
                    Fermer
                </button>
            </div>
        </div>
    @endif

    <style>
        @keyframes spin { from{transform:rotate(0deg);} to{transform:rotate(360deg);} }
    </style>
</div>