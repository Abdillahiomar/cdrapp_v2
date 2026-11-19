<?php

use Illuminate\Support\Facades\Route;
use Livewire\Volt\Volt;

use App\Http\Controllers\ReportingController;
use App\Http\Controllers\QrCodeController;
use App\Http\Controllers\DrawController;

Route::get('/reporting/transactions/pptx',
    [ReportingController::class, 'exportTransactionsPptx'])
    ->name('reporting.transactions.pptx');

Route::view('/', 'welcome');

Volt::route('/customers', 'customers.index')->name('customers.index');
Volt::route('/transactions', 'transactions.index')->name('transactions.index');

Volt::route('/summary', 'transactions.summary')->name('transactions.summary')->middleware(['auth', 'permission:transactions.summary.view']);

Volt::route('/manager_dashboard', 'dashboard.manager_dashboard')->name('dashboard.manager')->middleware(['auth', 'permission:dashboard.admin']);
Volt::route('/dashboard', 'dashboard.index')->name('dashboard.index');
Volt::route('/tableau_de_bord_revenue', 'dashboard.show')->name('dashboard.show');
// Même page en mode rapport, ouverte par le navigateur sans affichage qui fabrique le PDF envoyé par email
// (adresse signée et limitée dans le temps, générée par App\Support\RevenueReport)
Volt::route('/rapports/tableau-de-bord-revenus', 'dashboard.show')->name('reports.revenue')
    ->middleware(['signed:relative', \App\Http\Middleware\UseReportLayout::class]);
Volt::route('/admin/rapports-email', 'admin.reports.revenue-email')->name('reports.revenue-email')
    ->middleware(['auth', 'permission:reports.email.manage']);
Volt::route('/reporting/transactions', 'dashboard.transactions-dashboard')->name('reporting.transactions');

Volt::route('/fraudes', 'fraudes.aml')->name('aml.index')->middleware('auth');
Volt::route('/detection_de_fraude', 'fraudes.index')->name('fraudes.index')->middleware('auth');
Volt::route('/aml/alertes', 'aml.alerts')->name('aml.alerts')->middleware(['auth', 'permission:aml.alerts.view']);
Volt::route('/aml/regles', 'aml.rules')->name('aml.rules')->middleware(['auth', 'permission:aml.alerts.view']);
Volt::route('/aml/listes', 'aml.watchlist')->name('aml.watchlist')->middleware(['auth', 'permission:aml.alerts.view']);

Volt::route('/kyc-management', 'kyc.index')->name('kyc.index')->middleware(['auth', 'permission:kyc.duplicates.view']);

Volt::route('/operations/bulk_search', 'operations.index')->name('operations.index')->middleware('auth');
Volt::route('/operations/verification', 'operations.verification')->name('operations.verification')->middleware(['auth', 'permission:operations.verify-customers']);
Volt::route('/organizations', 'organizations.index')->name('organizations.index')->middleware('auth');
Volt::route('/daily-report', 'dashboard.dailly')->name('daily-report.index')->middleware('auth');
Volt::route('/revenue-accounts', 'revenue.index')->name('revenue.index')->middleware('auth');
Volt::route('/all_accounts_balance', 'revenue.balance')->name('balances')->middleware('auth');

Volt::route('finance/banks-accounts', 'finance.banks-accounts')->name('finance.banks-accounts');
Volt::route('finance/bank-balances', 'finance.bank-balances')->name('finance.bank-balances');
// Module « Customers » (base clients kyc.customer_master) — distinct de la page Clients (/customers)
Route::middleware(['auth', 'permission:customer360.view'])->prefix('customers-base')->name('customer360.')->group(function () {
    Volt::route('/', 'customers360.dashboard')->name('dashboard');
    Volt::route('/annuaire', 'customers360.directory')->name('directory');
    Volt::route('/controles', 'customers360.controls')->name('controls');
    Volt::route('/journal', 'customers360.journal')->name('journal');
});
Route::middleware(['auth', 'permission:network.view'])->prefix('reseau')->name('network.')->group(function () {
    Volt::route('/', 'network.dashboard')->name('dashboard');
    Volt::route('/annuaire', 'network.directory')->name('directory');
    Volt::route('/reseaux', 'network.networks')->name('networks');
    Volt::route('/controles', 'network.controls')->name('controls');
});
Volt::route('/pca', 'pca.index')->name('pca.index')->middleware(['auth', 'permission:pca.view']);
Volt::route('/paiements-factures','transactions.bill_payments')->name('bill-payments.index')->middleware(['auth', 'permission:finance.bill-payments.view']);

Volt::route('/amana_report', 'amana_report.index')->name('amana_report.index')->middleware('auth');
Volt::route('/ancien_cdrapp', 'transactions.old_transactions')->name('ancien_cdrapp')->middleware('auth');
Volt::route('/admin/roles', 'admin.roles.index')->name('admin.roles.index')->middleware(['auth', 'permission:admin.roles.view']);
Volt::route('/admin/users', 'admin.users.index')->name('admin.users.index')->middleware(['auth', 'permission:admin.users.view']);
Volt::route('/admin/agences', 'admin.agencies.index')->name('admin.agencies.index')->middleware(['auth', 'permission:admin.agencies.manage']);
Volt::route('/admin/employes', 'admin.employees.index')->name('admin.employees.index')->middleware(['auth', 'role:super-admin']);
Volt::route('/admin/audit-logs', 'admin.audit-logs.index')->name('admin.audit-logs.index')->middleware(['auth', 'permission:admin.audit-logs.view']);
Route::middleware(['auth', 'permission:tools.qrcode'])->prefix('outils/qr-code')->name('qrcode.')->group(function () {
    Volt::route('/', 'tools.qrcode')->name('index');
    Route::get('/modele', [QrCodeController::class, 'template'])->name('template');
    Route::get('/lot/{qrBatch}', [QrCodeController::class, 'batch'])->name('batch');
    Route::get('/{qrCode}/png', [QrCodeController::class, 'png'])->name('png');
    Route::get('/{qrCode}/svg', [QrCodeController::class, 'svg'])->name('svg');
    Route::get('/{qrCode}/imprimer', [QrCodeController::class, 'print'])->name('print');
});
// Tirages au sort Marketing : consultation draws.view ; préparation draws.manage ; salle de tirage draws.run
// (vérifiés dans les composants, le middleware de route ne couvrant pas les appels Livewire suivants)
Route::middleware(['auth', 'permission:draws.view'])->prefix('marketing')->name('draws.')->group(function () {
    Volt::route('/lots', 'marketing.prizes.index')->name('prizes');
    Route::get('/lots/images/{prizeImage}', [DrawController::class, 'prizeImage'])->name('prize-image');
    Route::get('/tirages/lots/{drawPrize}/image', [DrawController::class, 'lotImage'])->name('lot-image');

    // Cérémonies
    Volt::route('/tirages', 'marketing.ceremonies.index')->name('index');
    Route::prefix('tirages/{ceremony}')->whereNumber('ceremony')->group(function () {
        Volt::route('/', 'marketing.ceremonies.show')->name('ceremony');
        Volt::route('/salle', 'marketing.ceremonies.room')->name('room');
        Volt::route('/ecran', 'marketing.draws.screen')->name('screen');
        Route::get('/gagnants.csv', [DrawController::class, 'winners'])->name('winners');
        Route::get('/pv.pdf', [DrawController::class, 'pv'])->name('pv');
    });

    // Listes d'éligibles (une par catégorie d'une cérémonie)
    Route::prefix('tirages/listes/{draw}')->whereNumber('draw')->group(function () {
        Volt::route('/', 'marketing.draws.show')->name('show');
        Route::get('/eligibles.csv', [DrawController::class, 'eligibles'])->name('eligibles');
    });
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
