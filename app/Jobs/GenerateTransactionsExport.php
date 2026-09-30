<?php

namespace App\Jobs;

use App\Exports\TransactionsCsvExport;
use App\Exports\TransactionsExcelExport;
use App\Models\ExportRequest;
use App\Models\Transaction;
use App\Support\TransactionFilters;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Maatwebsite\Excel\Facades\Excel;
use Throwable;

class GenerateTransactionsExport implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 900; // 15 min, génération de gros fichiers

    public function __construct(public ExportRequest $exportRequest)
    {
    }

    public function handle(): void
    {
        $this->exportRequest->update(['status' => 'processing']);

        $query = TransactionFilters::apply(
            Transaction::query(),
            $this->exportRequest->filters ?? []
        )->orderBy('transaction_initiated_time', 'desc');

        $isExcel  = $this->exportRequest->type === 'excel';
        $export   = $isExcel ? new TransactionsExcelExport($query) : new TransactionsCsvExport($query);
        $fileName = $this->exportRequest->fileName();
        $path     = 'exports/' . $this->exportRequest->id . '_' . $fileName;

        Excel::store($export, $path, 'local');

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
