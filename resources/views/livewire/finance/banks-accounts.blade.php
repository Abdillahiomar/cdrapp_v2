<?php

use App\Models\Bank;
use App\Models\BankAccount;
use Livewire\Volt\Component;

new class extends Component {
    public string $new_bank_name = '';
    public array $new_account = ['bank_id' => '', 'account_label' => '', 'account_number' => ''];

    public function addBank(): void
    {
        $this->validate(['new_bank_name' => 'required|string|max:255|unique:banks,name']);
        Bank::create(['name' => trim($this->new_bank_name)]);
        $this->new_bank_name = '';
    }

    public function deleteBank(int $id): void
    {
        Bank::findOrFail($id)->delete(); // supprime aussi ses comptes (cascade)
    }

    public function addAccount(): void
    {
        $this->validate([
            'new_account.bank_id'        => 'required|exists:banks,id',
            'new_account.account_label'  => 'required|string|max:255',
            'new_account.account_number' => 'nullable|string|max:255',
        ]);

        BankAccount::create([
            'bank_id'        => $this->new_account['bank_id'],
            'account_label'  => trim($this->new_account['account_label']),
            'account_number' => $this->new_account['account_number'] ?: null,
        ]);

        $this->new_account = ['bank_id' => $this->new_account['bank_id'], 'account_label' => '', 'account_number' => ''];
    }

    public function deleteAccount(int $id): void
    {
        BankAccount::findOrFail($id)->delete();
    }

    public function with(): array
    {
        return [
            'banks' => Bank::with('accounts')->orderBy('name')->get(),
        ];
    }
};
?>
<div style="padding:24px;">
    <div style="background:#fff; border:1px solid #e5e7eb; border-radius:12px; padding:20px; margin-bottom:20px;">
        <p style="font-size:13px; font-weight:600; color:#111827; margin-bottom:14px;">Ajouter une banque</p>
        <div style="display:flex; gap:10px; align-items:flex-start;">
            <div style="flex:1;">
                <input type="text" wire:model="new_bank_name" placeholder="Ex: BCI, Bank of Africa..."
                       style="width:100%; border:1px solid #d1d5db; border-radius:7px; padding:8px 10px; font-size:13px;">
                @error('new_bank_name') <p style="font-size:10px; color:#E24B4A; margin:3px 0 0;">{{ $message }}</p> @enderror
            </div>
            <button wire:click="addBank"
                    style="background:#1B2F6E; color:#fff; font-size:13px; font-weight:600; padding:9px 20px; border-radius:8px; border:none; cursor:pointer;">
                Ajouter
            </button>
        </div>
    </div>

    <div style="background:#fff; border:1px solid #e5e7eb; border-radius:12px; padding:20px;">
        <p style="font-size:13px; font-weight:600; color:#111827; margin-bottom:14px;">Ajouter un compte à une banque</p>
        <div style="display:grid; grid-template-columns:1fr 2fr 2fr auto; gap:10px; align-items:flex-start;">
            <select wire:model="new_account.bank_id"
                    style="border:1px solid #d1d5db; border-radius:7px; padding:8px 10px; font-size:13px; background:#fff;">
                <option value="">Banque...</option>
                @foreach($banks as $bank)
                    <option value="{{ $bank->id }}">{{ $bank->name }}</option>
                @endforeach
            </select>
            <input type="text" wire:model="new_account.account_label" placeholder="Libellé (ex: Compte courant principal)"
                   style="border:1px solid #d1d5db; border-radius:7px; padding:8px 10px; font-size:13px;">
            <input type="text" wire:model="new_account.account_number" placeholder="Numéro de compte (optionnel)"
                   style="border:1px solid #d1d5db; border-radius:7px; padding:8px 10px; font-size:13px;">
            <button wire:click="addAccount"
                    style="background:#1B2F6E; color:#fff; font-size:13px; font-weight:600; padding:9px 20px; border-radius:8px; border:none; cursor:pointer; white-space:nowrap;">
                Ajouter
            </button>
        </div>
        @error('new_account.bank_id') <p style="font-size:10px; color:#E24B4A; margin:6px 0 0;">{{ $message }}</p> @enderror
        @error('new_account.account_label') <p style="font-size:10px; color:#E24B4A; margin:6px 0 0;">{{ $message }}</p> @enderror
    </div>

    {{-- LISTE --}}
    <div style="margin-top:20px; display:flex; flex-direction:column; gap:14px;">
        @foreach($banks as $bank)
            <div style="background:#fff; border:1px solid #e5e7eb; border-radius:10px; padding:16px;">
                <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:10px;">
                    <p style="font-size:13px; font-weight:700; color:#1B2F6E; margin:0;">{{ $bank->name }}</p>
                    <button wire:click="deleteBank({{ $bank->id }})"
                            wire:confirm="Supprimer cette banque et tous ses comptes ?"
                            style="background:#FDECEA; color:#7F1D1D; border:none; border-radius:6px; padding:5px 10px; font-size:11px; cursor:pointer;">
                        Supprimer la banque
                    </button>
                </div>
                @forelse($bank->accounts as $acc)
                    <div style="display:flex; justify-content:space-between; align-items:center; padding:6px 0; border-top:1px solid #f3f4f6; font-size:12px;">
                        <span style="color:#374151;">
                            {{ $acc->account_label }}
                            @if($acc->account_number) <span style="color:#9ca3af;">— {{ $acc->account_number }}</span> @endif
                        </span>
                        <button wire:click="deleteAccount({{ $acc->id }})"
                                wire:confirm="Supprimer ce compte ?"
                                style="background:#f3f4f6; color:#6b7280; border:none; border-radius:6px; padding:3px 8px; font-size:10px; cursor:pointer;">
                            Suppr.
                        </button>
                    </div>
                @empty
                    <p style="font-size:12px; color:#9ca3af; margin:0;">Aucun compte pour cette banque.</p>
                @endforelse
            </div>
        @endforeach
    </div>
</div>