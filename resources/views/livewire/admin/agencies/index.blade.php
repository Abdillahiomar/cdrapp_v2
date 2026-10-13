<?php

use App\Models\Agency;
use App\Models\User;
use Illuminate\Validation\Rule;
use Livewire\Volt\Component;

/**
 * Agences D-Money : création, activation, et rattachement des utilisateurs
 * (une seule agence par utilisateur). L'agence d'un utilisateur est reprise
 * automatiquement sur les cash-out PCA qu'il enregistre.
 */
new class extends Component {

    public bool  $showForm  = false;
    public ?int  $editingId = null;
    public array $form      = ['code' => '', 'name' => '', 'city' => '', 'is_active' => true];

    /** Agence dépliée, et utilisateur à y rattacher */
    public ?int $openAgencyId = null;
    public      $userToAssign = null;

    private function requireManage(): void
    {
        abort_unless(auth()->user()->can('admin.agencies.manage'), 403);
    }

    public function create(): void
    {
        $this->requireManage();
        $this->editingId = null;
        $this->form      = ['code' => '', 'name' => '', 'city' => 'Djibouti', 'is_active' => true];
        $this->showForm  = true;
        $this->resetErrorBag();
    }

    public function edit(int $id): void
    {
        $this->requireManage();
        $agency          = Agency::findOrFail($id);
        $this->editingId = $agency->id;
        $this->form      = $agency->only(['code', 'name', 'city', 'is_active']);
        $this->form['city'] ??= '';
        $this->showForm  = true;
        $this->resetErrorBag();
    }

    public function save(): void
    {
        $this->requireManage();

        $this->validate([
            'form.code' => ['required', 'string', 'max:20', Rule::unique('agencies', 'code')->ignore($this->editingId)],
            'form.name' => 'required|string|max:255',
            'form.city' => 'nullable|string|max:255',
        ], [
            'form.code.required' => 'Le code est obligatoire.',
            'form.code.unique'   => 'Ce code d\'agence existe déjà.',
            'form.name.required' => 'Le nom est obligatoire.',
        ]);

        $data = [
            'code'      => trim($this->form['code']),
            'name'      => trim($this->form['name']),
            'city'      => trim((string) $this->form['city']) ?: null,
            'is_active' => (bool) $this->form['is_active'],
        ];

        if ($this->editingId) {
            Agency::findOrFail($this->editingId)->update($data);
            session()->flash('success', "Agence {$data['code']} mise à jour.");
        } else {
            Agency::create($data);
            session()->flash('success', "Agence {$data['code']} — {$data['name']} créée.");
        }

        $this->showForm = false;
    }

    public function toggle(int $id): void
    {
        $this->requireManage();
        $agency = Agency::findOrFail($id);
        $agency->update(['is_active' => !$agency->is_active]);
    }

    public function openAgency(int $id): void
    {
        $this->openAgencyId = $this->openAgencyId === $id ? null : $id;
        $this->userToAssign = null;
        $this->resetErrorBag();
    }

    /** Rattache un utilisateur existant à l'agence ouverte (il quitte son ancienne agence). */
    public function assignUser(): void
    {
        $this->requireManage();

        $this->validate(['userToAssign' => 'required|exists:users,id'], ['userToAssign.required' => 'Choisissez un utilisateur.']);

        $user     = User::findOrFail($this->userToAssign);
        $agency   = Agency::findOrFail($this->openAgencyId);
        $previous = $user->agency?->label();

        $user->update(['agency_id' => $agency->id]);

        $this->userToAssign = null;
        session()->flash('success', "{$user->name} est maintenant rattaché(e) à l'agence {$agency->label()}" . ($previous ? " (auparavant : {$previous})." : '.'));
    }

    public function removeUser(int $userId): void
    {
        $this->requireManage();
        $user = User::findOrFail($userId);
        $user->update(['agency_id' => null]);
        session()->flash('success', "{$user->name} n'est plus rattaché(e) à une agence.");
    }

    public function with(): array
    {
        return [
            'agencies'  => Agency::withCount('users')->orderBy('code')->get(),
            'openUsers' => $this->openAgencyId ? User::where('agency_id', $this->openAgencyId)->orderBy('name')->get(['id', 'name', 'email']) : collect(),
            'allUsers'  => $this->openAgencyId ? User::with('agency:id,code,name')->where(fn ($q) => $q->whereNull('agency_id')->orWhere('agency_id', '<>', $this->openAgencyId))->orderBy('name')->get(['id', 'name', 'email', 'agency_id']) : collect(),
        ];
    }
};
?>
<div style="padding:24px;">

@php
    $inputStyle = 'border:1px solid #d1d5db; border-radius:7px; padding:9px 12px; font-size:13px; color:#111827; outline:none; background:#fff; width:100%; box-sizing:border-box;';
    $labelStyle = 'font-size:11px; color:#6b7280; display:block; margin-bottom:4px; font-weight:600;';
@endphp

    @if(session('success'))
        <div style="background:#E5F5ED; color:#005C2B; border:1px solid #a7d7b8; border-radius:8px; padding:12px 16px; margin-bottom:16px; font-size:13px;">{{ session('success') }}</div>
    @endif

    <div style="display:flex; align-items:center; justify-content:space-between; margin-bottom:20px; gap:12px; flex-wrap:wrap;">
        <div>
            <h2 style="font-size:16px; font-weight:700; color:#111827; margin:0 0 4px;">Agences</h2>
            <p style="font-size:12px; color:#9ca3af; margin:0;">Chaque utilisateur appartient à une seule agence, reprise automatiquement sur les cash-out PCA qu'il enregistre.</p>
        </div>
        <button wire:click="create"
                style="background:#1B2F6E; color:#fff; font-size:13px; font-weight:600; padding:9px 18px; border-radius:8px; border:none; cursor:pointer;">
            + Nouvelle agence
        </button>
    </div>

    <div style="background:#fff; border:1px solid #e5e7eb; border-radius:10px; overflow:hidden;">
        <table style="width:100%; border-collapse:collapse; font-size:12px;">
            <thead>
                <tr style="background:#F7F8FC;">
                    @foreach(['Code', 'Nom', 'Ville', 'Utilisateurs', 'Active', ''] as $th)
                        <th style="padding:10px 16px; text-align:left; color:#6b7280; font-weight:500; border-bottom:1px solid #e5e7eb;">{{ $th }}</th>
                    @endforeach
                </tr>
            </thead>
            <tbody>
                @forelse($agencies as $agency)
                    <tr wire:key="agency-{{ $agency->id }}" style="border-bottom:1px solid #f3f4f6; {{ $agency->is_active ? '' : 'opacity:.55;' }}">
                        <td style="padding:10px 16px; color:#1B2F6E; font-weight:700; font-family:monospace;">{{ $agency->code }}</td>
                        <td style="padding:10px 16px; color:#111827; font-weight:500;">{{ $agency->name }}</td>
                        <td style="padding:10px 16px; color:#6b7280;">{{ $agency->city ?? '—' }}</td>
                        <td style="padding:10px 16px;">
                            <button wire:click="openAgency({{ $agency->id }})" style="background:none; border:none; color:#1B2F6E; font-weight:600; font-size:12px; cursor:pointer; padding:0;">
                                {{ $agency->users_count }} utilisateur(s) {{ $openAgencyId === $agency->id ? '▾' : '▸' }}
                            </button>
                        </td>
                        <td style="padding:10px 16px;">
                            <button wire:click="toggle({{ $agency->id }})" title="{{ $agency->is_active ? 'Désactiver' : 'Activer' }}"
                                    style="width:36px; height:20px; border-radius:10px; border:none; cursor:pointer; position:relative; background:{{ $agency->is_active ? '#00843D' : '#d1d5db' }};">
                                <span style="position:absolute; top:2px; {{ $agency->is_active ? 'right:2px' : 'left:2px' }}; width:16px; height:16px; border-radius:50%; background:#fff;"></span>
                            </button>
                        </td>
                        <td style="padding:10px 16px; text-align:right;">
                            <button wire:click="edit({{ $agency->id }})"
                                    style="background:#E8ECF8; color:#1B2F6E; font-size:11px; font-weight:600; padding:5px 12px; border-radius:6px; border:none; cursor:pointer;">
                                Modifier
                            </button>
                        </td>
                    </tr>

                    @if($openAgencyId === $agency->id)
                        <tr>
                            <td colspan="6" style="background:#FAFBFC; padding:14px 16px 16px 40px;">
                                <p style="font-size:12px; font-weight:600; color:#111827; margin:0 0 8px;">Utilisateurs de l'agence</p>
                                @forelse($openUsers as $u)
                                    <div style="display:flex; justify-content:space-between; align-items:center; padding:6px 0; border-bottom:1px solid #eef0f3; font-size:12px; max-width:620px;">
                                        <span><strong style="color:#111827;">{{ $u->name }}</strong> <span style="color:#9ca3af;">{{ $u->email }}</span></span>
                                        <button wire:click="removeUser({{ $u->id }})" wire:confirm="Retirer {{ $u->name }} de cette agence ?"
                                                style="background:none; border:none; color:#B91C1C; font-size:11px; font-weight:600; cursor:pointer;">Retirer</button>
                                    </div>
                                @empty
                                    <p style="font-size:12px; color:#9ca3af; margin:0 0 6px;">Aucun utilisateur rattaché.</p>
                                @endforelse

                                <div style="display:flex; gap:8px; align-items:flex-start; margin-top:12px; max-width:620px;">
                                    <div style="flex:1;">
                                        <select wire:model="userToAssign" style="{{ $inputStyle }}">
                                            <option value="">— Rattacher un utilisateur existant —</option>
                                            @foreach($allUsers as $u)
                                                <option value="{{ $u->id }}">{{ $u->name }} ({{ $u->email }}){{ $u->agency ? ' — actuellement : ' . $u->agency->code : '' }}</option>
                                            @endforeach
                                        </select>
                                        @error('userToAssign') <p style="font-size:10px; color:#E24B4A; margin:4px 0 0;">{{ $message }}</p> @enderror
                                    </div>
                                    <button wire:click="assignUser"
                                            style="background:#1B2F6E; color:#fff; font-size:12px; font-weight:600; padding:9px 14px; border-radius:7px; border:none; cursor:pointer; white-space:nowrap;">
                                        Rattacher
                                    </button>
                                </div>
                                <p style="font-size:10px; color:#9ca3af; margin:4px 0 0;">Un utilisateur déjà rattaché à une autre agence la quitte pour celle-ci.</p>
                            </td>
                        </tr>
                    @endif
                @empty
                    <tr><td colspan="6" style="padding:30px; text-align:center; color:#9ca3af;">Aucune agence.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    {{-- FORMULAIRE --}}
    @if($showForm)
        <div wire:click="$set('showForm', false)" style="position:fixed; inset:0; background:rgba(0,0,0,0.45); z-index:998;"></div>
        <div style="position:fixed; top:50%; left:50%; transform:translate(-50%,-50%); width:min(460px,95vw); background:#fff; border-radius:14px; z-index:999; box-shadow:0 20px 60px rgba(0,0,0,0.2);">
            <div style="padding:16px 20px; border-bottom:1px solid #e5e7eb;">
                <p style="font-size:14px; font-weight:700; color:#111827; margin:0;">{{ $editingId ? 'Modifier l\'agence' : 'Nouvelle agence' }}</p>
            </div>
            <div style="padding:20px; display:flex; flex-direction:column; gap:14px;">
                <div>
                    <label style="{{ $labelStyle }}">Code <span style="color:#E24B4A;">*</span></label>
                    <input type="text" wire:model="form.code" placeholder="Ex. 1302" style="{{ $inputStyle }}">
                    @error('form.code') <p style="font-size:10px; color:#E24B4A; margin:4px 0 0;">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label style="{{ $labelStyle }}">Nom <span style="color:#E24B4A;">*</span></label>
                    <input type="text" wire:model="form.name" placeholder="Ex. Agence Balbala" style="{{ $inputStyle }}">
                    @error('form.name') <p style="font-size:10px; color:#E24B4A; margin:4px 0 0;">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label style="{{ $labelStyle }}">Ville</label>
                    <input type="text" wire:model="form.city" style="{{ $inputStyle }}">
                </div>
                <label style="display:flex; align-items:center; gap:8px; font-size:12px; color:#374151;">
                    <input type="checkbox" wire:model="form.is_active"> Agence active
                </label>
            </div>
            <div style="padding:12px 20px; border-top:1px solid #e5e7eb; display:flex; justify-content:flex-end; gap:10px;">
                <button wire:click="$set('showForm', false)" style="background:#f3f4f6; color:#374151; font-size:13px; font-weight:500; padding:8px 20px; border-radius:8px; border:1px solid #e5e7eb; cursor:pointer;">Annuler</button>
                <button wire:click="save" style="background:#1B2F6E; color:#fff; font-size:13px; font-weight:600; padding:8px 20px; border-radius:8px; border:none; cursor:pointer;">Enregistrer</button>
            </div>
        </div>
    @endif

</div>
