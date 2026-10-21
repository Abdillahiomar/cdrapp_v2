<?php

namespace App\Jobs;

use App\Exports\CustomersBaseExport;
use App\Models\ExportRequest;
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
 * Export du module Customers (annuaire ou contrôle), en arrière-plan.
 */
class GenerateCustomersExport implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 900;

    public function __construct(public ExportRequest $exportRequest)
    {
    }

    public function handle(): void
    {
        $this->exportRequest->update(['status' => 'processing']);

        $isExcel = $this->exportRequest->type === 'excel';
        $path    = 'exports/' . $this->exportRequest->id . '_' . $this->exportRequest->fileName();

        try {
            Excel::store(new CustomersBaseExport($this->exportRequest->filters ?? []), $path, 'local', $isExcel ? ExcelWriter::XLSX : ExcelWriter::CSV);
        } finally {
            // Binder statique : à réinitialiser, le worker est un processus permanent
            Cell::setValueBinder(new DefaultValueBinder());
        }

        $this->exportRequest->update(['status' => 'done', 'file_path' => $path, 'completed_at' => now()]);
    }

    public function failed(Throwable $e): void
    {
        $this->exportRequest->update(['status' => 'failed', 'error' => $e->getMessage()]);
    }
}
