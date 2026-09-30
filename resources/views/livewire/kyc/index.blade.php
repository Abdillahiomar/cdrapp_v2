<?php

use Livewire\Volt\Component;
use Livewire\WithPagination;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

new class extends Component {

    use WithPagination;

    public string $search       = '';
    public int    $min_accounts = 2;
    public string $tier         = ''; // '' = tous, 'confirme', 'probable'

    public function updated($property): void
    {
        if (in_array($property, ['search', 'min_accounts', 'tier'])) {
            $this->resetPage();
        }
    }

    /**
     * Aperçu tronqué de la liste des msisdn — protège le rendu même si une
     * ligne dépasse le seuil de garde-fou par un autre chemin (recherche, etc.).
     */
    public function msisdnPreview(string $msisdns, int $limit = 15): array
    {
        $all = explode('/', $msisdns, $limit + 1);

        $remaining = 0;
        if (count($all) > $limit) {
            array_pop($all);
            $remaining = substr_count($msisdns, '/') + 1 - $limit;
        }

        return ['list' => $all, 'remaining' => $remaining];
    }

    public function with(): array
    {
        $query = DB::table('kyc_duplicate_identities');

        if ($this->search) {
            $query->where(function ($q) {
                $q->where('full_name', 'ilike', '%' . $this->search . '%')
                  ->orWhere('id_number', 'ilike', '%' . $this->search . '%')
                  ->orWhere('msisdns', 'ilike', '%' . $this->search . '%');
            });
        }

        if ($this->tier === 'confirme') {
            $query->whereNotNull('id_number');
        } elseif ($this->tier === 'probable') {
            $query->whereNull('id_number');
        }

        $query->where('msisdn_count', '>=', max(2, $this->min_accounts));

        // Garde-fou : un groupe avec un nombre de comptes déraisonnable est presque
        // toujours un artefact de données (nom/valeur par défaut partagé par erreur),
        // pas un vrai doublon exploitable. On l'exclut par défaut de l'affichage.
        $query->where('msisdn_count', '<=', 100);

        return [
            'duplicates'   => $query->orderByDesc('msisdn_count')->paginate(30),
            'lastComputed' => DB::table('kyc_duplicate_identities')->max('computed_at'),
        ];
    }
};
?>
<div style="padding:24px;">

    <div style="display:flex; align-items:flex-start; justify-content:space-between; margin-bottom:20px;">
        <div>
            <h2 style="font-size:16px; font-weight:700; color:#111827; margin:0 0 4px;">KYC Management — Comptes multiples</h2>
            <p style="font-size:12px; color:#9ca3af; margin:0;">Clients associés à plusieurs comptes (msisdn), par pièce d'identité ou par nom + nom de la mère + date de naissance.</p>
        </div>
        <div style="text-align:right;">
            <p style="font-size:10px; color:#9ca3af; margin:0;">Dernière analyse</p>
            <p style="font-size:12px; color:#111827; font-weight:500; margin:2px 0 0;">
                {{ $lastComputed ? \Carbon\Carbon::parse($lastComputed)->format('d/m/Y H:i') : 'Jamais exécutée' }}
            </p>
        </div>
    </div>

    {{-- FILTRES --}}
    <div style="background:#fff; border:1px solid #e5e7eb; border-radius:10px; padding:16px; margin-bottom:16px; display:flex; gap:12px; flex-wrap:wrap; align-items:flex-end;">
        <div>
            <label style="font-size:11px; color:#6b7280; display:block; margin-bottom:4px;">Rechercher</label>
            <input type="text" wire:model.live.debounce.400ms="search"
                   placeholder="Nom, numéro de pièce, msisdn..."
                   style="border:1px solid #d1d5db; border-radius:7px; padding:8px 12px; font-size:13px; color:#111827; outline:none; width:260px;">
        </div>
        <div>
            <label style="font-size:11px; color:#6b7280; display:block; margin-bottom:4px;">Niveau de confiance</label>
            <select wire:model.live="tier"
                    style="border:1px solid #d1d5db; border-radius:7px; padding:8px 12px; font-size:13px; color:#111827; outline:none; background:#fff; width:220px;">
                <option value="">Tous</option>
                <option value="confirme">Confirmé (pièce d'identité)</option>
                <option value="probable">Probable (nom + mère + naissance)</option>
            </select>
        </div>
        <div>
            <label style="font-size:11px; color:#6b7280; display:block; margin-bottom:4px;">Nb comptes min.</label>
            <input type="number" min="2" wire:model.live="min_accounts"
                   style="width:100px; border:1px solid #d1d5db; border-radius:7px; padding:8px 10px; font-size:13px; color:#111827; outline:none;">
        </div>
    </div>

    {{-- TABLEAU --}}
    <div style="background:#fff; border:1px solid #e5e7eb; border-radius:10px; overflow:hidden;">

        <div style="padding:12px 16px; border-bottom:1px solid #e5e7eb; display:flex; align-items:center; justify-content:space-between;">
            <p style="font-size:13px; font-weight:600; color:#111827; margin:0;">Identités dupliquées</p>
            <span style="background:#E8ECF8; color:#1B2F6E; font-size:11px; font-weight:600; padding:3px 10px; border-radius:20px;">
                {{ $duplicates->total() }} résultat(s)
            </span>
        </div>

        <div style="overflow-x:auto;">
            <table style="width:100%; border-collapse:collapse; font-size:12px;">
                <thead>
                    <tr style="background:#F7F8FC;">
                        <th style="padding:10px 16px; text-align:left; color:#6b7280; font-weight:500; border-bottom:1px solid #e5e7eb;">Niveau</th>
                        <th style="padding:10px 16px; text-align:left; color:#6b7280; font-weight:500; border-bottom:1px solid #e5e7eb;">Nom complet</th>
                        <th style="padding:10px 16px; text-align:left; color:#6b7280; font-weight:500; border-bottom:1px solid #e5e7eb;">Nom de la mère</th>
                        <th style="padding:10px 16px; text-align:left; color:#6b7280; font-weight:500; border-bottom:1px solid #e5e7eb;">Naissance</th>
                        <th style="padding:10px 16px; text-align:left; color:#6b7280; font-weight:500; border-bottom:1px solid #e5e7eb;">Pièce d'identité</th>
                        <th style="padding:10px 16px; text-align:center; color:#6b7280; font-weight:500; border-bottom:1px solid #e5e7eb;">Nb comptes</th>
                        <th style="padding:10px 16px; text-align:left; color:#6b7280; font-weight:500; border-bottom:1px solid #e5e7eb;">Comptes (msisdn)</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($duplicates as $row)
                        <tr style="border-bottom:1px solid #f3f4f6;"
                            onmouseover="this.style.background='#F7F8FC'"
                            onmouseout="this.style.background='transparent'">
                            <td style="padding:10px 16px;">
                                @if($row->id_number)
                                    <span style="background:#FDE8E8; color:#7F1D1D; font-size:10px; font-weight:700; padding:3px 10px; border-radius:20px; white-space:nowrap;">Confirmé</span>
                                @else
                                    <span style="background:#FEF3C7; color:#92400E; font-size:10px; font-weight:700; padding:3px 10px; border-radius:20px; white-space:nowrap;">Probable</span>
                                @endif
                            </td>
                            <td style="padding:10px 16px; color:#111827; font-weight:500;">{{ $row->full_name ?? '—' }}</td>
                            <td style="padding:10px 16px; color:#6b7280;">{{ $row->mother_full_name ?? '—' }}</td>
                            <td style="padding:10px 16px; color:#6b7280;">{{ $row->date_of_birth ? \Carbon\Carbon::parse($row->date_of_birth)->format('d/m/Y') : '—' }}</td>
                            <td style="padding:10px 16px; color:#6b7280;">
                                {{ $row->id_number ? $row->id_type . ' — ' . $row->id_number : '—' }}
                            </td>
                            <td style="padding:10px 16px; text-align:center;">
                                <span style="background:#E8ECF8; color:#1B2F6E; font-size:11px; font-weight:700; padding:2px 10px; border-radius:20px;">
                                    {{ $row->msisdn_count }}
                                </span>
                            </td>
                            <td style="padding:10px 16px; color:#6b7280;">
                                @php $preview = $this->msisdnPreview($row->msisdns); @endphp
                                <div style="display:flex; flex-wrap:wrap; gap:4px; max-width:320px;">
                                    @foreach($preview['list'] as $msisdn)
                                        <span style="background:#F7F8FC; border:1px solid #e5e7eb; font-size:10px; padding:2px 8px; border-radius:6px; white-space:nowrap;">{{ $msisdn }}</span>
                                    @endforeach
                                    @if($preview['remaining'] > 0)
                                        <span style="color:#9ca3af; font-size:10px; padding:2px 4px;">+{{ $preview['remaining'] }} autres</span>
                                    @endif
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="7" style="padding:24px; text-align:center; color:#9ca3af;">Aucune identité dupliquée trouvée pour ces critères.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        <div style="padding:12px 16px; border-top:1px solid #e5e7eb;">
            {{ $duplicates->links() }}
        </div>
    </div>

</div>
