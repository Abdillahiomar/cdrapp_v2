<?php

namespace App\Http\Controllers;

use App\Exports\QrBatchTemplateExport;
use App\Models\QrBatch;
use App\Models\QrCode;
use App\Support\QrImageGenerator;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Maatwebsite\Excel\Facades\Excel;

class QrCodeController extends Controller
{
    public function __construct(private QrImageGenerator $images)
    {
    }

    public function png(Request $request, QrCode $qrCode)
    {
        $size = array_key_exists($request->query('size'), QrImageGenerator::SIZES) ? $request->query('size') : 'medium';

        return response($this->images->png($qrCode->payload, $qrCode->with_logo, $size), 200, [
            'Content-Type'        => 'image/png',
            'Content-Disposition' => 'attachment; filename="' . $qrCode->fileBaseName() . '.png"',
        ]);
    }

    public function svg(QrCode $qrCode)
    {
        return response($this->images->svg($qrCode->payload, $qrCode->with_logo), 200, [
            'Content-Type'        => 'image/svg+xml',
            'Content-Disposition' => 'attachment; filename="' . $qrCode->fileBaseName() . '.svg"',
        ]);
    }

    public function print(QrCode $qrCode)
    {
        return view('qrcode.print', [
            'qrCode' => $qrCode,
            'svg'    => $this->images->svg($qrCode->payload, $qrCode->with_logo, xmlHeader: false),
        ]);
    }

    public function batch(QrBatch $qrBatch)
    {
        abort_unless($qrBatch->zip_path && Storage::disk('local')->exists($qrBatch->zip_path), 404, "Le fichier ZIP de ce lot n'est plus disponible.");

        $name = 'qrcodes_' . pathinfo($qrBatch->file_name, PATHINFO_FILENAME) . '_' . $qrBatch->created_at->format('Ymd_His') . '.zip';

        return Storage::disk('local')->download($qrBatch->zip_path, $name);
    }

    public function template()
    {
        return Excel::download(new QrBatchTemplateExport(), 'modele_qrcodes.xlsx');
    }
}
