{{--
    Bouton d'export d'une section de detection_de_fraude.
    Variables : $section (commission | structuring | chains), $applied (filtres de la
    dernière analyse), $formats ([valeur => libellé] pour excel et csv).
--}}
@if(!empty($applied))
    <div style="display:flex; align-items:flex-end; gap:8px; margin-left:auto;">
        <div>
            <label style="font-size:11px; color:#6b7280; display:block; margin-bottom:4px;">Format d'export</label>
            <select wire:model="export_types.{{ $section }}"
                    style="border:1px solid #d1d5db; border-radius:7px; padding:8px 10px; font-size:13px; color:#111827; outline:none; background:#fff;">
                @foreach($formats as $value => $label)
                    <option value="{{ $value }}">{{ $label }}</option>
                @endforeach
            </select>
        </div>
        <button wire:click="exportSection('{{ $section }}')" wire:loading.attr="disabled" wire:target="exportSection('{{ $section }}')"
                title="Exporte tous les résultats du {{ \Carbon\Carbon::parse($applied['date_debut'])->format('d/m/Y') }} au {{ \Carbon\Carbon::parse($applied['date_fin'])->format('d/m/Y') }}, pas seulement la page affichée"
                style="background:#fff; color:#1B2F6E; font-size:13px; font-weight:600; padding:9px 16px; border-radius:8px; border:1px solid #1B2F6E; cursor:pointer;">
            ⬇ Exporter
        </button>
    </div>
@endif
