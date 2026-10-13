{{--
    Liste de cash-out PCA. Variables : $cashouts, $showParty (afficher le compte), $cancelId / $cancelReason (composant).
--}}
@php $showParty = $showParty ?? false; @endphp
<table style="width:100%; border-collapse:collapse; font-size:12px;">
    <thead>
        <tr style="background:#F7F8FC;">
            @foreach(array_merge(['N°', 'Date / heure'], $showParty ? ['Compte'] : [], ['Type de compte', 'Montant (FDJ)', 'Agence', 'Pièce vérifiée', 'Saisi par', 'Statut', '']) as $th)
                <th style="padding:8px 10px; text-align:left; color:#6b7280; font-weight:500; border-bottom:1px solid #e5e7eb; white-space:nowrap;">{{ $th }}</th>
            @endforeach
        </tr>
    </thead>
    <tbody>
        @foreach($cashouts as $c)
            <tr wire:key="co-{{ $c->id }}" style="border-bottom:1px solid #f3f4f6; {{ $c->cancelled_at ? 'opacity:.55;' : '' }}">
                <td style="padding:8px 10px; color:#9ca3af;">{{ $c->id }}</td>
                <td style="padding:8px 10px; color:#6b7280; white-space:nowrap;">{{ $c->created_at->format('d/m/Y H:i') }}</td>
                @if($showParty)
                    <td style="padding:8px 10px; font-family:monospace; font-weight:600; color:#111827;">{{ $c->party_id }}</td>
                @endif
                <td style="padding:8px 10px; color:#6b7280;">{{ $c->account_type }}</td>
                <td style="padding:8px 10px; color:#111827; font-weight:700;">{{ number_format($c->amount, 0, ',', ' ') }}</td>
                <td style="padding:8px 10px; color:#374151;">{{ $c->location }}</td>
                <td style="padding:8px 10px; color:#374151;">{{ $c->id_document }}</td>
                <td style="padding:8px 10px; color:#6b7280;">{{ $c->recorder->name ?? '—' }}</td>
                <td style="padding:8px 10px;">
                    @if($c->cancelled_at)
                        <span style="background:#F3F4F6; color:#374151; font-size:10px; font-weight:600; padding:2px 8px; border-radius:12px;" title="{{ $c->cancel_reason }}">Annulé</span>
                    @else
                        <span style="background:#E5F5ED; color:#005C2B; font-size:10px; font-weight:600; padding:2px 8px; border-radius:12px;">Payé</span>
                    @endif
                </td>
                <td style="padding:8px 10px; white-space:nowrap;">
                    @if(!$c->cancelled_at && auth()->user()->can('pca.manage'))
                        @if($cancelId === $c->id)
                            <div style="display:flex; gap:6px; align-items:center;">
                                <input type="text" wire:model="cancelReason" placeholder="Motif d'annulation" style="border:1px solid #d1d5db; border-radius:6px; padding:5px 8px; font-size:12px; width:180px;">
                                <button wire:click="cancelCashout" style="background:#B91C1C; color:#fff; font-size:11px; font-weight:600; padding:5px 10px; border-radius:6px; border:none; cursor:pointer;">Confirmer</button>
                                <button wire:click="$set('cancelId', null)" style="background:none; border:none; color:#6b7280; font-size:11px; cursor:pointer;">Non</button>
                            </div>
                            @error('cancelReason') <p style="font-size:11px; color:#B91C1C; margin:4px 0 0;">{{ $message }}</p> @enderror
                        @else
                            <button wire:click="askCancel({{ $c->id }})" style="background:none; border:none; color:#B91C1C; font-size:11px; font-weight:600; cursor:pointer;">Annuler</button>
                        @endif
                    @endif
                </td>
            </tr>
        @endforeach
    </tbody>
</table>
