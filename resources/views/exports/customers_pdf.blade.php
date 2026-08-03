{{-- resources/views/exports/customers_pdf.blade.php --}}
<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <style>
        body { font-family: DejaVu Sans, sans-serif; font-size: 10px; color: #111827; }
        .header { text-align: center; margin-bottom: 20px; }
        .header h1 { font-size: 16px; color: #1B2F6E; margin: 0; }
        .header p { font-size: 9px; color: #6b7280; margin: 4px 0 0; }
        .meta { display: flex; justify-content: space-between; margin-bottom: 14px; font-size: 9px; color: #6b7280; }
        table { width: 100%; border-collapse: collapse; }
        thead tr { background: #1B2F6E; color: #fff; }
        th { padding: 7px 8px; text-align: left; font-weight: 600; font-size: 9px; }
        td { padding: 6px 8px; border-bottom: 1px solid #f3f4f6; font-size: 9px; }
        tr:nth-child(even) td { background: #F7F8FC; }
        .badge-active   { background: #E5F5ED; color: #005C2B; padding: 2px 6px; border-radius: 4px; }
        .badge-closed   { background: #FDECEA; color: #7F1D1D; padding: 2px 6px; border-radius: 4px; }
        .badge-pending  { background: #FFF3D0; color: #7A4F00; padding: 2px 6px; border-radius: 4px; }
        .badge-kyc      { background: #E8ECF8; color: #1B2F6E; padding: 2px 6px; border-radius: 4px; }
        .footer { margin-top: 20px; text-align: center; font-size: 8px; color: #9ca3af; }
    </style>
</head>
<body>
    <div class="header">
        <h1>D-Money — Liste des clients</h1>
        <p>Exporté le {{ now()->format('d/m/Y à H:i') }} — {{ count($customers) }} client(s)</p>
    </div>

    <table>
        <thead>
            <tr>
                <th>#</th>
                <th>Date création</th>
                <th>MSISDN</th>
                <th>Nom complet</th>
                <th>Nom de la mère</th>
                <th>Trust Level</th>
                <th>Statut</th>
            </tr>
        </thead>
        <tbody>
            @foreach($customers as $i => $customer)
                <tr>
                    <td>{{ $i + 1 }}</td>
                    <td>{{ \Carbon\Carbon::parse($customer->CREATE_TIME)->format('d/m/Y H:i') }}</td>
                    <td><strong>{{ $customer->MSISDN }}</strong></td>
                    <td>{{ $customer->FULL_NAME }}</td>
                    <td>{{ $customer->MOTHER_NAME }}</td>
                    <td>
                        <span class="badge-kyc">
                            @if($customer->TRUST_LEVEL == 9) Full KYC
                            @elseif($customer->TRUST_LEVEL == 3) Lite Customer
                            @elseif($customer->TRUST_LEVEL == 1) Unregistered
                            @else Unknown @endif
                        </span>
                    </td>
                    <td>
                        @if($customer->STATUS == '03')
                            <span class="badge-active">Active</span>
                        @elseif($customer->STATUS == '06')
                            <span class="badge-closed">Closed</span>
                        @elseif($customer->STATUS == '02')
                            <span class="badge-pending">Pending</span>
                        @else
                            <span class="badge-closed">Inactif</span>
                        @endif
                    </td>
                </tr>
            @endforeach
        </tbody>
    </table>

    <div class="footer">D-Money — Djibouti Telecom &nbsp;|&nbsp; Document confidentiel</div>
</body>
</html>