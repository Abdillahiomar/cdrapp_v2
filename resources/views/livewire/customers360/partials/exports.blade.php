{{-- « Mes exports » du module Customers. Variable : $exports --}}
@if(session('customers-export'))
    <div style="background:#E5F5ED; color:#005C2B; border:1px solid #A7E3C1; border-radius:8px; padding:10px 14px; font-size:12px; margin-bottom:12px;">{{ session('customers-export') }}</div>
@endif
@if($exports->isNotEmpty())
    <div style="background:#fff; border:1px solid #e5e7eb; border-radius:10px; padding:12px 14px; margin-bottom:14px;"
         @if($exports->whereIn('status', ['pending', 'processing'])->isNotEmpty()) wire:poll.5s @endif>
        <p style="font-size:12px; font-weight:600; color:#111827; margin:0 0 6px;">Mes exports</p>
        @foreach($exports as $e)
            <div wire:key="cexp-{{ $e->id }}" style="display:flex; justify-content:space-between; align-items:center; gap:10px; padding:5px 0; font-size:12px; border-top:1px solid #f3f4f6;">
                <span>
                    <strong>{{ strtoupper($e->type) }}</strong>
                    <span style="color:#6b7280;">{{ \App\Support\Customers::CHECKS[$e->filters['check'] ?? ''][0] ?? 'Annuaire' }}</span>
                    <span style="color:#9ca3af;">· {{ $e->created_at->format('d/m/Y H:i') }}</span>
                    @if($e->status === 'pending') <span style="background:#FEF3C7; color:#92400E; font-size:10px; font-weight:600; padding:2px 8px; border-radius:20px;">En attente</span>
                    @elseif($e->status === 'processing') <span style="background:#DBEAFE; color:#1E40AF; font-size:10px; font-weight:600; padding:2px 8px; border-radius:20px;">En cours...</span>
                    @else <span style="background:#E5F5ED; color:#005C2B; font-size:10px; font-weight:600; padding:2px 8px; border-radius:20px;">Prêt</span> @endif
                </span>
                @if($e->status === 'done')
                    <button wire:click="downloadCustomersExport({{ $e->id }})" style="background:#1B2F6E; color:#fff; font-size:11px; font-weight:600; padding:5px 12px; border-radius:6px; border:none; cursor:pointer;">Télécharger</button>
                @endif
            </div>
        @endforeach
    </div>
@endif
