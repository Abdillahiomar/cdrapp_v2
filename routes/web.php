<?php

use Illuminate\Support\Facades\Route;
use Livewire\Volt\Volt;

use App\Http\Controllers\ReportingController;

Route::get('/reporting/transactions/pptx',
    [ReportingController::class, 'exportTransactionsPptx'])
    ->name('reporting.transactions.pptx');

Route::view('/', 'welcome');

Route::view('dashboard', 'dashboard')->middleware(['auth', 'verified'])->name('dashboard');

Route::view('profile', 'profile')->middleware(['auth'])->name('profile');

Volt::route('/customers', 'customers.index')->name('customers.index');
Volt::route('/transactions', 'transactions.index')->name('transactions.index');

Volt::route('/summary', 'transactions.summary')->name('transactions.summary')->middleware('auth');

Volt::route('/manager_dashboard', 'dashboard.manager_dashboard')->name('dashboard.manager');
Volt::route('/tableau_de_bord', 'dashboard.index')->name('dashboard.index');
Volt::route('/tableau_de_bord_revenue', 'dashboard.show')->name('dashboard.show');
Volt::route('/reporting/transactions', 'dashboard.transactions-dashboard')->name('reporting.transactions');

Volt::route('/fraudes', 'fraudes.index')->name('fraudes.index')->middleware('auth');
Volt::route('/blanchiment', 'fraudes.aml')->name('aml.index')->middleware('auth');

Volt::route('/operations/bulk_search', 'operations.index')->name('operations.index')->middleware('auth');
Volt::route('/organizations', 'organizations.index')->name('organizations.index')->middleware('auth');
Volt::route('/daily-report', 'dashboard.dailly')->name('daily-report.index')->middleware('auth');
Volt::route('/revenue-accounts', 'revenue.index')->name('revenue.index')->middleware('auth');
Volt::route('/all_accounts_balance', 'revenue.balance')->name('balances')->middleware('auth');

Volt::route('finance/bank-balances', 'finance.bank-balances')->name('finance.bank-balances');

Volt::route('/amana_report', 'amana_report.index')->name('amana_report.index')->middleware('auth');
Volt::route('/ancien_cdrapp', 'transactions.old_transactions')->name('ancien_cdrapp')->middleware('auth');
Volt::route('/admin/roles', 'admin.roles.index')->name('admin.roles.index')->middleware(['auth', 'permission:admin.roles.view']);
Volt::route('/admin/users', 'admin.users.index')->name('admin.users.index');
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
