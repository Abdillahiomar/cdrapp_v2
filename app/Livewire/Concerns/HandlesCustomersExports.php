<?php

namespace App\Livewire\Concerns;

use App\Jobs\GenerateCustomersExport;
use App\Models\ExportRequest;
use Illuminate\Support\Facades\Storage;

/**
 * Exports du module Customers (annuaire, contrôles) : mis en file d'attente,
 * suivis dans « Mes exports », téléchargés une fois prêts.
 */
trait HandlesCustomersExports
{
    public string $exportType = 'excel';

    /** Filtres de l'export : fournis par le composant */
    abstract protected function exportFilters(): array;

    public function queueCustomersExport(): void
    {
        abort_unless(auth()->user()->can('customer360.export'), 403);

        $request = ExportRequest::create([
            'user_id' => auth()->id(),
            'type'    => $this->exportType === 'csv' ? 'csv' : 'excel',
            'source'  => 'customers',
            'status'  => 'pending',
            'filters' => $this->exportFilters(),
        ]);

        GenerateCustomersExport::dispatch($request);

        session()->flash('customers-export', 'Export ' . strtoupper($request->type) . ' lancé : il apparaîtra ci-dessous dès qu\'il sera prêt.');
    }

    public function downloadCustomersExport(int $id)
    {
        $export = ExportRequest::where('user_id', auth()->id())->where('source', 'customers')->findOrFail($id);

        if (!in_array($export->status, ['done', 'uploaded']) || !$export->file_path || !Storage::disk('local')->exists($export->file_path)) {
            session()->flash('customers-export', 'Ce fichier n\'est plus disponible.');
            return null;
        }

        if ($export->status === 'done') {
            $export->update(['status' => 'uploaded', 'downloaded_at' => now()]);
        }

        return response()->download(Storage::disk('local')->path($export->file_path), $export->fileName());
    }

    protected function myCustomersExports()
    {
        return ExportRequest::where('user_id', auth()->id())
            ->where('source', 'customers')
            ->whereIn('status', ['pending', 'processing', 'done'])
            ->latest()->limit(5)->get();
    }
}
