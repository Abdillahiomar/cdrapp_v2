{{--
    Messages et « Mes exports » d'une section de detection_de_fraude.
    Variables : $section, $exports (ExportRequest de la section, 5 max), $title.
--}}
@if(session("export-message-{$section}"))
    <div style="background:#E5F5ED; color:#005C2B; border:1px solid #A7E3C1; border-radius:8px; padding:10px 14px; font-size:12px; margin-bottom:12px;">{{ session("export-message-{$section}") }}</div>
@endif
@if(session("export-error-{$section}"))
    <div style="background:#FDE8E8; color:#7F1D1D; border:1px solid #F5B5B5; border-radius:8px; padding:10px 14px; font-size:12px; margin-bottom:12px;">{{ session("export-error-{$section}") }}</div>
@endif

@if($exports->isNotEmpty())
    <div style="background:#F7F8FC; border-radius:8px; padding:12px 14px; margin-bottom:16px;"
         @if($exports->whereIn('status', ['pending', 'processing'])->isNotEmpty()) wire:poll.5s @endif>
        <p style="font-size:12px; font-weight:600; color:#111827; margin:0 0 8px;">{{ $title }}</p>
        @foreach($exports as $export)
            <div wire:key="export-{{ $export->id }}" style="display:flex; align-items:center; justify-content:space-between; gap:10px; padding:6px 0; font-size:12px; border-top:1px solid #eceef4;">
                <div style="display:flex; align-items:center; gap:10px; flex-wrap:wrap;">
                    <span style="color:#111827; font-weight:500;">{{ strtoupper($export->type) }}</span>
                    <span style="color:#6b7280;">
                        {{ \Carbon\Carbon::parse($export->filters['date_debut'] ?? null)->format('d/m/Y') }} → {{ \Carbon\Carbon::parse($export->filters['date_fin'] ?? null)->format('d/m/Y') }}
                        @isset($export->filters['min_cashin']) · ≥ {{ $export->filters['min_cashin'] }} cash-in @endisset
                        @isset($export->filters['min_agents']) · ≥ {{ $export->filters['min_agents'] }} agents @endisset
                    </span>
                    <span style="color:#9ca3af;">demandé le {{ $export->created_at->format('d/m/Y H:i') }}</span>
                    @if($export->status === 'pending')
                        <span style="background:#FEF3C7; color:#92400E; font-size:10px; font-weight:600; padding:2px 8px; border-radius:20px;">En attente</span>
                    @elseif($export->status === 'processing')
                        <span style="background:#DBEAFE; color:#1E40AF; font-size:10px; font-weight:600; padding:2px 8px; border-radius:20px;">En cours...</span>
                    @else
                        <span style="background:#E5F5ED; color:#005C2B; font-size:10px; font-weight:600; padding:2px 8px; border-radius:20px;">Prêt</span>
                    @endif
                </div>
                @if($export->status === 'done')
                    <button wire:click="downloadFraudExport({{ $export->id }})"
                            style="background:#1B2F6E; color:#fff; font-size:11px; font-weight:600; padding:5px 12px; border-radius:6px; border:none; cursor:pointer;">
                        Télécharger
                    </button>
                @endif
            </div>
        @endforeach
    </div>
@endif
