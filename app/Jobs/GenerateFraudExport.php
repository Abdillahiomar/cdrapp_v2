<?php

namespace App\Jobs;

use App\Exports\FraudChainsExport;
use App\Exports\FraudChainsFlatExport;
use App\Exports\FraudCommissionExport;
use App\Exports\FraudStructuringExport;
use App\Exports\FraudStructuringFlatExport;
use App\Models\ExportRequest;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use InvalidArgumentException;
use Maatwebsite\Excel\Excel as ExcelWriter;
use Maatwebsite\Excel\Facades\Excel;
use PhpOffice\PhpSpreadsheet\Cell\Cell;
use PhpOffice\PhpSpreadsheet\Cell\DefaultValueBinder;
use Throwable;

/**
 * Exports de la page detection_de_fraude, en arrière-plan :
 *   - fraud_commission  : Commission indue (Excel ou CSV, une ligne par couple agent/client)
 *   - fraud_structuring : Structuring (Excel 2 onglets ou CSV à plat)
 *   - fraud_chains      : Chaînes S3 (Excel 2 onglets ou CSV à plat)
 */
class GenerateFraudExport implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 900;

    public function __construct(public ExportRequest $exportRequest)
    {
    }

    public function handle(): void
    {
        $this->exportRequest->update(['status' => 'processing']);

        $filters = $this->exportRequest->filters ?? [];
        $isExcel = $this->exportRequest->type === 'excel';
        $path    = 'exports/' . $this->exportRequest->id . '_' . $this->exportRequest->fileName();

        $export = match ($this->exportRequest->source) {
            'fraud_commission'  => new FraudCommissionExport($filters),
            'fraud_structuring' => $isExcel ? new FraudStructuringExport($filters) : new FraudStructuringFlatExport($filters),
            'fraud_chains'      => $isExcel ? new FraudChainsExport($filters) : new FraudChainsFlatExport($filters),
            default             => throw new InvalidArgumentException("Source d'export inconnue : {$this->exportRequest->source}"),
        };

        try {
            Excel::store($export, $path, 'local', $isExcel ? ExcelWriter::XLSX : ExcelWriter::CSV);
        } finally {
            // Binder statique : à réinitialiser, le worker est un processus permanent
            Cell::setValueBinder(new DefaultValueBinder());
        }

        $this->exportRequest->update([
            'status'       => 'done',
            'file_path'    => $path,
            'completed_at' => now(),
        ]);
    }

    public function failed(Throwable $e): void
    {
        $this->exportRequest->update([
            'status' => 'failed',
            'error'  => $e->getMessage(),
        ]);
    }
}
