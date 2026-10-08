<?php

namespace App\Jobs;

use App\Exports\BillPaymentsExport;
use App\Models\ExportRequest;
use App\Models\Transaction;
use App\Support\BillPayments;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Maatwebsite\Excel\Excel as ExcelWriter;
use Maatwebsite\Excel\Facades\Excel;
use PhpOffice\PhpSpreadsheet\Cell\Cell;
use PhpOffice\PhpSpreadsheet\Cell\DefaultValueBinder;
use Throwable;

/**
 * Génère en arrière-plan l'export des paiements de factures (même cycle de
 * vie que GenerateTransactionsExport : pending → processing → done / failed).
 */
class GenerateBillPaymentsExport implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 900; // 15 min, comme les exports de transactions

    public function __construct(public ExportRequest $exportRequest)
    {
    }

    public function handle(): void
    {
        $this->exportRequest->update(['status' => 'processing']);

        $query = BillPayments::apply(Transaction::query(), $this->exportRequest->filters ?? [])
            ->orderBy('transaction_initiated_time', 'desc');

        $isExcel = $this->exportRequest->type === 'excel';
        $path    = 'exports/' . $this->exportRequest->id . '_' . $this->exportRequest->fileName();

        try {
            Excel::store(
                new BillPaymentsExport($query, $this->exportRequest->columns ?? []),
                $path,
                'local',
                $isExcel ? ExcelWriter::XLSX : ExcelWriter::CSV
            );
        } finally {
            // Le binder de cellules est statique : sans réinitialisation, il resterait actif
            // dans le worker (processus permanent) pour les exports suivants d'autres écrans.
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
