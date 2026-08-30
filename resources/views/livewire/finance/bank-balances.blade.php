<?php

use App\Models\BankBalance;
use Livewire\Volt\Component;
use Carbon\Carbon;

new class extends Component {
    public string $balance_date = '';
    public array $rows = []; // [id, bank_name, account_label, balance, notes]
    public bool $saved = false;

    public function mount()
    {
        $this->balance_date = Carbon::today()->format('Y-m-d');
        $this->loadRows();
    }

    public function updatedBalanceDate()
    {
        $this->saved = false;
        $this->loadRows();
    }

    protected function loadRows(): void
    {
        $existing = BankBalance::query()
            ->whereDate('balance_date', $this->balance_date)
            ->orderBy('bank_name')
            ->orderBy('account_label')
            ->get();

        if ($existing->isEmpty()) {
            $this->rows = [
                ['id' => null, 'bank_name' => '', 'account_label' => '', 'balance' => '', 'notes' => ''],
            ];
            return;
        }

        $this->rows = $existing->map(fn ($b) => [
            'id'            => $b->id,
            'bank_name'     => $b->bank_name,
            'account_label' => $b->account_label,
            'balance'       => (string) $b->balance,
            'notes'         => $b->notes,
        ])->all();
    }

    public function addRow(): void
    {
        $this->rows[] = ['id' => null, 'bank_name' => '', 'account_label' => '', 'balance' => '', 'notes' => ''];
    }

    public function removeRow(int $index): void
    {
        $row = $this->rows[$index] ?? null;

        // Si la ligne existait déjà en base, on la supprime réellement
        if ($row && $row['id']) {
            BankBalance::destroy($row['id']);
        }

        unset($this->rows[$index]);
        $this->rows = array_values($this->rows);

        if (empty($this->rows)) {
            $this->addRow();
        }
    }

    public function save(): void
    {
        $this->validate([
            'balance_date'            => 'required|date',
            'rows'                    => 'required|array|min:1',
            'rows.*.bank_name'        => 'required|string|max:255',
            'rows.*.account_label'    => 'required|string|max:255',
            'rows.*.balance'          => 'required|numeric',
            'rows.*.notes'            => 'nullable|string|max:500',
        ], [
            'rows.*.bank_name.required'     => 'Le nom de la banque est obligatoire.',
            'rows.*.account_label.required' => 'Le libellé du compte est obligatoire.',
            'rows.*.balance.required'       => 'Le solde est obligatoire.',
            'rows.*.balance.numeric'        => 'Le solde doit être un nombre.',
        ]);

        foreach ($this->rows as $i => $row) {
            $record = BankBalance::updateOrCreate(
                [
                    'balance_date'  => $this->balance_date,
                    'bank_name'     => trim($row['bank_name']),
                    'account_label' => trim($row['account_label']),
                ],
                [
                    'balance'    => (float) str_replace(',', '.', $row['balance']),
                    'notes'      => $row['notes'] ?: null,
                    'currency'   => 'DJF',
                    'updated_by' => auth()->id(),
                    'created_by' => auth()->id(),
                ]
            );

            $this->rows[$i]['id'] = $record->id;
        }

        $this->saved = true;
        $this->loadRows();
    }

    public function with(): array
    {
        // Suggestions pour l'autocomplétion (saisie libre mais guidée)
        $bankSuggestions = BankBalance::query()
            ->select('bank_name')->distinct()->orderBy('bank_name')->pluck('bank_name');

        // Total par banque + grand total pour la date affichée
        $byBank = collect($this->rows)
            ->filter(fn ($r) => $r['bank_name'] !== '')
            ->groupBy('bank_name')
            ->map(fn ($rows) => collect($rows)->sum(fn ($r) => (float) str_replace(',', '.', $r['balance'] ?: 0)));

        $grandTotal = $byBank->sum();

        return [
            'bankSuggestions' => $bankSuggestions,
            'byBank'          => $byBank,
            'grandTotal'      => $grandTotal,
        ];
    }
};
?>
<div style="padding:24px;">
    <div style="background:#fff; border:1px solid #e5e7eb; border-radius:12px; padding:20px; margin-bottom:20px;">
        <div style="display:flex; align-items:center; justify-content:space-between; margin-bottom:14px;">
            <p style="font-size:13px; font-weight:600; color:#111827; margin:0;">Soldes Bancaires</p>
            <div>
                <label style="font-size:11px; color:#6b7280; display:block; margin-bottom:4px;">Date <span style="color:#E24B4A;">*</span></label>
                <input type="date" wire:model.live="balance_date"
                       style="border:1px solid #d1d5db; border-radius:7px; padding:8px 10px; font-size:13px; color:#111827; outline:none;">
            </div>
        </div>

        @if($saved)
            <div style="background:#E5F5ED; color:#005C2B; font-size:12px; font-weight:600; padding:8px 12px; border-radius:8px; margin-bottom:12px;">
                Soldes enregistrés avec succès pour le {{ \Carbon\Carbon::parse($balance_date)->format('d/m/Y') }}.
            </div>
        @endif

        <datalist id="bank-suggestions">
            @foreach($bankSuggestions as $b)
                <option value="{{ $b }}"></option>
            @endforeach
        </datalist>

        <table style="width:100%; border-collapse:collapse; font-size:12px; margin-bottom:12px;">
            <thead>
                <tr style="background:#F7F8FC;">
                    <th style="padding:8px 10px; text-align:left; color:#6b7280; font-weight:500; border-bottom:1px solid #e5e7eb;">Banque</th>
                    <th style="padding:8px 10px; text-align:left; color:#6b7280; font-weight:500; border-bottom:1px solid #e5e7eb;">Compte</th>
                    <th style="padding:8px 10px; text-align:right; color:#6b7280; font-weight:500; border-bottom:1px solid #e5e7eb;">Solde (DJF)</th>
                    <th style="padding:8px 10px; text-align:left; color:#6b7280; font-weight:500; border-bottom:1px solid #e5e7eb;">Notes</th>
                    <th style="padding:8px 10px; border-bottom:1px solid #e5e7eb;"></th>
                </tr>
            </thead>
            <tbody>
                @foreach($rows as $i => $row)
                    <tr style="border-bottom:1px solid #f3f4f6;">
                        <td style="padding:6px 10px;">
                            <input type="text" list="bank-suggestions" wire:model="rows.{{ $i }}.bank_name"
                                   placeholder="Ex: BCI, Bank of Africa..."
                                   style="width:100%; border:1px solid #d1d5db; border-radius:6px; padding:6px 8px; font-size:12px;">
                            @error("rows.$i.bank_name") <p style="font-size:10px; color:#E24B4A; margin:2px 0 0;">{{ $message }}</p> @enderror
                        </td>
                        <td style="padding:6px 10px;">
                            <input type="text" wire:model="rows.{{ $i }}.account_label"
                                   placeholder="Ex: Compte courant n°123456"
                                   style="width:100%; border:1px solid #d1d5db; border-radius:6px; padding:6px 8px; font-size:12px;">
                            @error("rows.$i.account_label") <p style="font-size:10px; color:#E24B4A; margin:2px 0 0;">{{ $message }}</p> @enderror
                        </td>
                        <td style="padding:6px 10px;">
                            <input type="text" inputmode="decimal" wire:model="rows.{{ $i }}.balance"
                                   placeholder="0.00"
                                   style="width:100%; border:1px solid #d1d5db; border-radius:6px; padding:6px 8px; font-size:12px; text-align:right;">
                            @error("rows.$i.balance") <p style="font-size:10px; color:#E24B4A; margin:2px 0 0;">{{ $message }}</p> @enderror
                        </td>
                        <td style="padding:6px 10px;">
                            <input type="text" wire:model="rows.{{ $i }}.notes" placeholder="Optionnel"
                                   style="width:100%; border:1px solid #d1d5db; border-radius:6px; padding:6px 8px; font-size:12px;">
                        </td>
                        <td style="padding:6px 10px; text-align:center;">
                            <button type="button" wire:click="removeRow({{ $i }})"
                                    style="background:#FDECEA; color:#7F1D1D; border:none; border-radius:6px; padding:5px 9px; font-size:11px; cursor:pointer;">
                                Suppr.
                            </button>
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>

        <div style="display:flex; align-items:center; gap:10px;">
            <button type="button" wire:click="addRow"
                    style="background:#f3f4f6; color:#374151; font-size:12px; font-weight:600; padding:8px 16px; border-radius:8px; border:1px solid #e5e7eb; cursor:pointer;">
                + Ajouter un compte
            </button>
            <button type="button" wire:click="save"
                    wire:loading.attr="disabled" wire:target="save"
                    style="background:#1B2F6E; color:#fff; font-size:13px; font-weight:600; padding:9px 22px; border-radius:8px; border:none; cursor:pointer;">
                <span wire:loading.remove wire:target="save">Enregistrer</span>
                <span wire:loading wire:target="save">Enregistrement...</span>
            </button>
        </div>
    </div>

    {{-- RÉCAPITULATIF PAR BANQUE --}}
    @if($byBank->isNotEmpty())
        <div style="background:#fff; border:1px solid #e5e7eb; border-radius:10px; padding:16px;">
            <p style="font-size:13px; font-weight:600; color:#111827; margin-bottom:12px;">
                Récapitulatif — {{ \Carbon\Carbon::parse($balance_date)->format('d/m/Y') }}
            </p>
            <div style="display:grid; grid-template-columns:repeat(auto-fill, minmax(180px,1fr)); gap:10px; margin-bottom:14px;">
                @foreach($byBank as $bank => $total)
                    <div style="background:#F7F8FC; border-radius:8px; padding:10px 12px;">
                        <p style="font-size:11px; color:#6b7280; margin:0 0 4px;">{{ $bank }}</p>
                        <p style="font-size:15px; font-weight:700; color:#111827; margin:0;">{{ number_format($total, 0, ',', ' ') }}</p>
                    </div>
                @endforeach
            </div>
            <div style="border-top:1px solid #e5e7eb; padding-top:10px; display:flex; justify-content:space-between; align-items:center;">
                <span style="font-size:13px; font-weight:600; color:#111827;">Total général</span>
                <span style="font-size:18px; font-weight:700; color:#005C2B;">{{ number_format($grandTotal, 0, ',', ' ') }} DJF</span>
            </div>
        </div>
    @endif
</div>