{{--
    Partiel réutilisable d'affichage d'un TOP 10.
    Variables attendues :
      $titre    : titre de la section (string)
      $couleur  : pastille de couleur (hex)
      $bgBadge  : fond des badges (hex)
      $colBadge : couleur du texte des badges (hex)
      $rows     : lignes (array d'array associatifs)
      $cols     : ['cle_sql' => ['label' => 'En-tête', 'type' => 'text|int|amount', 'bold' => bool]]
--}}
<div style="background:#fff; border:1px solid #e5e7eb; border-radius:10px; overflow:hidden; margin-bottom:16px;">
    <div style="padding:12px 16px; border-bottom:1px solid #e5e7eb; display:flex; align-items:center; gap:8px;">
        <span style="width:8px; height:8px; border-radius:50%; background:{{ $couleur }}; display:inline-block;"></span>
        <p style="font-size:13px; font-weight:600; color:#111827; margin:0;">{{ $titre }}</p>
        <span style="margin-left:auto; background:{{ $bgBadge }}; color:{{ $colBadge }}; font-size:10px; font-weight:600; padding:2px 8px; border-radius:12px;">
            {{ count($rows) }} résultat(s)
        </span>
    </div>

    @if(empty($rows))
        <p style="padding:20px; font-size:12px; color:#9ca3af;">Aucun résultat pour cette période.</p>
    @else
        <div style="overflow-x:auto;">
            <table style="width:100%; border-collapse:collapse; font-size:12px;">
                <thead>
                    <tr style="background:#F7F8FC;">
                        <th style="padding:10px 14px; text-align:left; color:#6b7280; font-weight:500; border-bottom:1px solid #e5e7eb; white-space:nowrap;">#</th>
                        @foreach($cols as $c)
                            <th style="padding:10px 14px; text-align:left; color:#6b7280; font-weight:500; border-bottom:1px solid #e5e7eb; white-space:nowrap;">{{ $c['label'] }}</th>
                        @endforeach
                    </tr>
                </thead>
                <tbody>
                    @foreach($rows as $i => $row)
                        <tr style="border-bottom:1px solid #f3f4f6;"
                            onmouseover="this.style.background='#F9FCF9'"
                            onmouseout="this.style.background='transparent'">
                            <td style="padding:10px 14px;">
                                <span style="display:inline-flex; align-items:center; justify-content:center; width:22px; height:22px; border-radius:50%; background:{{ $i < 3 ? $bgBadge : '#f3f4f6' }}; color:{{ $i < 3 ? $colBadge : '#6b7280' }}; font-size:11px; font-weight:700;">
                                    {{ $i + 1 }}
                                </span>
                            </td>
                            @foreach($cols as $key => $c)
                                @php
                                    $val  = $row[$key] ?? 0;
                                    $type = $c['type'] ?? 'text';
                                    if ($type === 'amount') {
                                        $display = number_format((float) $val * 100, 0, ',', ' ') . ' FDJ';
                                    } elseif ($type === 'int') {
                                        $display = number_format((int) $val, 0, ',', ' ');
                                    } else {
                                        $display = $val;
                                    }
                                    $bold = ($c['bold'] ?? false) ? 'font-weight:600; color:#111827;' : '';
                                @endphp
                                <td style="padding:10px 14px; color:#374151; {{ $bold }} white-space:nowrap;">{{ $display }}</td>
                            @endforeach
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
</div>