<?php

use Illuminate\Support\Facades\Route;
use Livewire\Volt\Volt;

use App\Http\Controllers\ReportingController;
use App\Http\Controllers\QrCodeController;

Route::get('/reporting/transactions/pptx',
    [ReportingController::class, 'exportTransactionsPptx'])
    ->name('reporting.transactions.pptx');

Route::view('/', 'welcome');

Volt::route('/customers', 'customers.index')->name('customers.index');
Volt::route('/transactions', 'transactions.index')->name('transactions.index');

Volt::route('/summary', 'transactions.summary')->name('transactions.summary')->middleware('auth');

Volt::route('/manager_dashboard', 'dashboard.manager_dashboard')->name('dashboard.manager')->middleware(['auth', 'permission:dashboard.admin']);
Volt::route('/dashboard', 'dashboard.index')->name('dashboard.index');
Volt::route('/tableau_de_bord_revenue', 'dashboard.show')->name('dashboard.show');
Volt::route('/reporting/transactions', 'dashboard.transactions-dashboard')->name('reporting.transactions');

Volt::route('/fraudes', 'fraudes.aml')->name('aml.index')->middleware('auth');
Volt::route('/detection_de_fraude', 'fraudes.index')->name('fraudes.index')->middleware('auth');
Volt::route('/aml/alertes', 'aml.alerts')->name('aml.alerts')->middleware(['auth', 'permission:aml.alerts.view']);
Volt::route('/aml/regles', 'aml.rules')->name('aml.rules')->middleware(['auth', 'permission:aml.alerts.view']);
Volt::route('/aml/listes', 'aml.watchlist')->name('aml.watchlist')->middleware(['auth', 'permission:aml.alerts.view']);

Volt::route('/kyc-management', 'kyc.index')->name('kyc.index')->middleware(['auth', 'permission:kyc.duplicates.view']);

Volt::route('/operations/bulk_search', 'operations.index')->name('operations.index')->middleware('auth');
Volt::route('/organizations', 'organizations.index')->name('organizations.index')->middleware('auth');
Volt::route('/daily-report', 'dashboard.dailly')->name('daily-report.index')->middleware('auth');
Volt::route('/revenue-accounts', 'revenue.index')->name('revenue.index')->middleware('auth');
Volt::route('/all_accounts_balance', 'revenue.balance')->name('balances')->middleware('auth');

Volt::route('finance/banks-accounts', 'finance.banks-accounts')->name('finance.banks-accounts');
Volt::route('finance/bank-balances', 'finance.bank-balances')->name('finance.bank-balances');

Volt::route('/amana_report', 'amana_report.index')->name('amana_report.index')->middleware('auth');
Volt::route('/ancien_cdrapp', 'transactions.old_transactions')->name('ancien_cdrapp')->middleware('auth');
Volt::route('/admin/roles', 'admin.roles.index')->name('admin.roles.index')->middleware(['auth', 'permission:admin.roles.view']);
Volt::route('/admin/users', 'admin.users.index')->name('admin.users.index');
Volt::route('/admin/audit-logs', 'admin.audit-logs.index')->name('admin.audit-logs.index')->middleware(['auth', 'permission:admin.audit-logs.view']);
Route::middleware(['auth', 'permission:tools.qrcode'])->prefix('outils/qr-code')->name('qrcode.')->group(function () {
    Volt::route('/', 'tools.qrcode')->name('index');
    Route::get('/modele', [QrCodeController::class, 'template'])->name('template');
    Route::get('/lot/{qrBatch}', [QrCodeController::class, 'batch'])->name('batch');
    Route::get('/{qrCode}/png', [QrCodeController::class, 'png'])->name('png');
    Route::get('/{qrCode}/svg', [QrCodeController::class, 'svg'])->name('svg');
    Route::get('/{qrCode}/imprimer', [QrCodeController::class, 'print'])->name('print');
});
Volt::route('/admin/exports','admin.exports.index')->name('admin.exports.index')->middleware(['auth', 'permission:admin.exports.view']);
Volt::route('/profiles', 'admin.users.profile')->name('profile.index')->middleware('auth');


Route::get('/test-export-csv', function () {
    set_time_limit(600);

    $t0 = microtime(true);

    // Query simple : juste un mois, sans passer par buildQuery()
    $query = \App\Models\Transaction::query()
        ->where('transaction_initiated_time', '>=', '2026-05-01')
        ->where('transaction_initiated_time', '<', '2026-05-01');

    $count = $query->count();
    \Log::info("TEST EXPORT: {$count} lignes, count en " . round(microtime(true)-$t0,2) . "s");

    return response()->streamDownload(function () use ($query) {
        $handle = fopen('php://output', 'w');
        $i = 1;
        foreach ($query->cursor() as $t) {
            fputcsv($handle, [$i++, $t->transaction_id, $t->actual_amount], ';');
            if ($i % 1000 === 0) flush();
        }
        fclose($handle);
    }, 'test.csv', ['Content-Type' => 'text/csv']);
});

require __DIR__.'/auth.php';
