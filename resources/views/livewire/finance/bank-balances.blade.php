<?php

use App\Models\Bank;
use App\Models\BankAccount;
use App\Models\BankBalance;
use Livewire\Volt\Component;
use Carbon\Carbon;

new class extends Component {
    public string $balance_date = '';
    public string $money_circulation_date = ''; // nouvelle date, indépendante
    public array $rows = []; // [id, bank_id, bank_account_id, balance, notes]
    public bool $saved = false;

    public function mount()
    {
        $this->balance_date = Carbon::today()->format('Y-m-d');
        $this->money_circulation_date = $this->balance_date; // par défaut = même date, modifiable ensuite
        $this->loadRows();
    }

    public function updatedBalanceDate(): void
    {
        $this->saved = false;
        $this->loadRows();
    }

    protected function loadRows(): void
    {
        $existing = BankBalance::with('account.bank')
            ->whereDate('balance_date', $this->balance_date)
            ->get();

        if ($existing->isEmpty()) {
            $this->rows = [
                ['id' => null, 'bank_id' => '', 'bank_account_id' => '', 'balance' => '', 'notes' => ''],
            ];
            return;
        }

        $this->rows = $existing->map(fn ($b) => [
            'id'              => $b->id,
            'bank_id'         => $b->account->bank_id,
            'bank_account_id' => $b->bank_account_id,
            'balance'         => (string) $b->balance,
            'notes'           => $b->notes,
        ])->all();
    }

    public function addRow(): void
    {
        $this->rows[] = ['id' => null, 'bank_id' => '', 'bank_account_id' => '', 'balance' => '', 'notes' => ''];
    }

    // Quand on change la banque d'une ligne, on vide le compte sélectionné
    public function updatedRows($value, $key): void
    {
        if (str_ends_with($key, '.bank_id')) {
            $index = explode('.', $key)[0];
            $this->rows[$index]['bank_account_id'] = '';
        }
    }

    public function removeRow(int $index): void
    {
        $row = $this->rows[$index] ?? null;

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
            'balance_date'              => 'required|date',
            'rows'                      => 'required|array|min:1',
            'rows.*.bank_account_id'    => 'required|exists:bank_accounts,id',
            'rows.*.balance'            => 'required|numeric',
            'rows.*.notes'              => 'nullable|string|max:500',
        ], [
            'rows.*.bank_account_id.required' => 'Sélectionnez un compte.',
            'rows.*.balance.required'         => 'Le solde est obligatoire.',
            'rows.*.balance.numeric'          => 'Le solde doit être un nombre.',
        ]);

        foreach ($this->rows as $i => $row) {
            $record = BankBalance::updateOrCreate(
                [
                    'bank_account_id' => $row['bank_account_id'],
                    'balance_date'    => $this->balance_date,
                ],
                [
                    'balance'    => (float) str_replace(',', '.', $row['balance']),
                    'notes'      => $row['notes'] ?: null,
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
        $banks = Bank::where('is_active', true)->orderBy('name')->get();

        $accountsByBank = BankAccount::where('is_active', true)
            ->orderBy('account_label')
            ->get()
            ->groupBy('bank_id');

        $entries = BankBalance::with('account.bank')
            ->whereDate('balance_date', $this->balance_date)
            ->get();

        $byBank = $entries->groupBy(fn ($e) => $e->account->bank->name)
            ->map(fn ($group) => $group->sum('balance'));

        $grandTotal = $byBank->sum();

        // ─── Money en Circulation, sur la date choisie par l'utilisateur ───
        $totalBalanceAllAccounts = (float) AllBalance::query()
            ->whereDate('account_date', $this->money_circulation_date)
            ->sum('balance');

        $totalEmoneyDestroy = (float) AllBalance::query()
            ->where('account_type', 'SP E-Money Destroy Account')
            ->whereDate('account_date', $this->money_circulation_date)
            ->sum('balance');

        $moneyEnCirculation = $totalBalanceAllAccounts - $totalEmoneyDestroy;

        // Avertit si aucune donnée n'existe pour la date choisie (évite un ratio trompeur basé sur du vide)
        $hasMoneyCirculationData = AllBalance::query()
            ->whereDate('account_date', $this->money_circulation_date)
            ->exists();

        $ratioEquivalence = ($moneyEnCirculation > 0 && $hasMoneyCirculationData)
            ? ($grandTotal / $moneyEnCirculation) * 100
            : null;

        return [
            'banks'                    => $banks,
            'accountsByBank'           => $accountsByBank,
            'byBank'                   => $byBank,
            'grandTotal'               => $grandTotal,
            'moneyEnCirculation'       => $moneyEnCirculation,
            'ratioEquivalence'         => $ratioEquivalence,
            'hasMoneyCirculationData'  => $hasMoneyCirculationData,
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

        @if($banks->isEmpty())
            <div style="background:#FFF3D0; color:#7A4F00; font-size:12px; padding:10px 12px; border-radius:8px;">
                Aucune banque configurée. Ajoutez d'abord des banques et des comptes dans la page de gestion.
            </div>
        @else
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
                                <select wire:model.live="rows.{{ $i }}.bank_id"
                                        style="width:100%; border:1px solid #d1d5db; border-radius:6px; padding:6px 8px; font-size:12px; background:#fff;">
                                    <option value="">Choisir...</option>
                                    @foreach($banks as $bank)
                                        <option value="{{ $bank->id }}">{{ $bank->name }}</option>
                                    @endforeach
                                </select>
                            </td>
                            <td style="padding:6px 10px;">
                                <select wire:model="rows.{{ $i }}.bank_account_id"
                                        style="width:100%; border:1px solid #d1d5db; border-radius:6px; padding:6px 8px; font-size:12px; background:#fff;">
                                    <option value="">Choisir un compte...</option>
                                    @foreach($accountsByBank->get($row['bank_id'], []) as $acc)
                                        <option value="{{ $acc->id }}">{{ $acc->account_label }}</option>
                                    @endforeach
                                </select>
                                @error("rows.$i.bank_account_id") <p style="font-size:10px; color:#E24B4A; margin:2px 0 0;">{{ $message }}</p> @enderror
                            </td>
                            <td style="padding:6px 10px;">
                                <input type="text" inputmode="decimal" wire:model="rows.{{ $i }}.balance" placeholder="0.00"
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
                    + Ajouter une ligne
                </button>
                <button type="button" wire:click="save"
                        wire:loading.attr="disabled" wire:target="save"
                        style="background:#1B2F6E; color:#fff; font-size:13px; font-weight:600; padding:9px 22px; border-radius:8px; border:none; cursor:pointer;">
                    <span wire:loading.remove wire:target="save">Enregistrer</span>
                    <span wire:loading wire:target="save">Enregistrement...</span>
                </button>
            </div>
        @endif
    </div>

    {{-- RÉCAPITULATIF PAR BANQUE --}}
    {{-- RÉCAPITULATIF PAR BANQUE --}}
    @if($byBank->isNotEmpty())
        <div style="background:#fff; border:1px solid #e5e7eb; border-radius:10px; padding:16px;">
            <p style="font-size:13px; font-weight:600; color:#111827; margin-bottom:12px;">
                Récapitulatif — {{ \Carbon\Carbon::parse($balance_date)->format('d/m/Y') }}
            </p>
            <div style="display:grid; grid-template-columns:repeat(auto-fill, minmax(180px,1fr)); gap:10px; margin-bottom:14px;">
                @foreach($byBank as $bankName => $total)
                    <div style="background:#F7F8FC; border-radius:8px; padding:10px 12px;">
                        <p style="font-size:11px; color:#6b7280; margin:0 0 4px;">{{ $bankName }}</p>
                        <p style="font-size:15px; font-weight:700; color:#111827; margin:0;">{{ number_format($total, 0, ',', ' ') }}</p>
                    </div>
                @endforeach
            </div>

            <div style="border-top:1px solid #e5e7eb; padding-top:10px; display:flex; justify-content:space-between; align-items:center;">
                <span style="font-size:13px; font-weight:600; color:#111827;">Total général</span>
                <span style="font-size:18px; font-weight:700; color:#005C2B;">{{ number_format($grandTotal, 0, ',', ' ') }} DJF</span>
            </div>

            {{-- Sélecteur de date pour Money en Circulation --}}
            <div style="border-top:1px solid #e5e7eb; padding-top:12px; margin-top:10px;">
                <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:8px;">
                    <span style="font-size:13px; font-weight:600; color:#111827;">Money en Circulation</span>
                    <div>
                        <label style="font-size:10px; color:#6b7280; display:block; margin-bottom:2px;">Date de comparaison</label>
                        <input type="date" wire:model.live="money_circulation_date"
                               style="border:1px solid #d1d5db; border-radius:6px; padding:6px 8px; font-size:12px; color:#111827; outline:none;">
                    </div>
                </div>

                @if(!$hasMoneyCirculationData)
                    <div style="background:#FFF3D0; color:#7A4F00; font-size:11px; padding:6px 10px; border-radius:6px; margin-bottom:8px;">
                        Aucune donnée "All Accounts Balance" trouvée pour le {{ \Carbon\Carbon::parse($money_circulation_date)->format('d/m/Y') }}.
                    </div>
                @endif

                <div style="display:flex; justify-content:space-between; align-items:center;">
                    <span style="font-size:11px; color:#9ca3af;">{{ \Carbon\Carbon::parse($money_circulation_date)->format('d/m/Y') }}</span>
                    <span style="font-size:16px; font-weight:700; color:#378ADD;">{{ number_format($moneyEnCirculation, 0, ',', ' ') }} DJF</span>
                </div>
            </div>

            <div style="border-top:1px solid #e5e7eb; padding-top:10px; margin-top:10px; display:flex; justify-content:space-between; align-items:center;">
                <span style="font-size:14px; font-weight:700; color:#111827;">Ratio d'équivalence</span>
                @if($ratioEquivalence !== null)
                    <span style="font-size:22px; font-weight:800; color:{{ $ratioEquivalence >= 100 ? '#005C2B' : '#E24B4A' }};">
                        {{ number_format($ratioEquivalence, 2, ',', ' ') }} %
                    </span>
                @else
                    <span style="font-size:13px; color:#9ca3af;">N/A</span>
                @endif
            </div>
        </div>
    @endif
</div>