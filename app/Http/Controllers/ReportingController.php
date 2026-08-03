<?php
// app/Http/Controllers/ReportingController.php

namespace App\Http\Controllers;

use App\Services\TransactionPptxGenerator;
use Illuminate\Http\Request;

class ReportingController extends Controller
{
    public function exportTransactionsPptx(Request $request, TransactionPptxGenerator $generator)
    {
        $mois = $request->query('mois', now()->subMonth()->format('Y-m'));

        // Validation simple du format YYYY-MM
        if (!preg_match('/^\d{4}-\d{2}$/', $mois)) {
            abort(400, 'Format de mois invalide (attendu YYYY-MM).');
        }

        $path = $generator->generer($mois);

        return response()->download($path, "rapport_transactions_{$mois}.pptx")
                         ->deleteFileAfterSend(true);
    }
}

/*
=====================================================================
ROUTE — à ajouter dans routes/web.php
=====================================================================

use App\Http\Controllers\ReportingController;

Route::get('/reporting/transactions/pptx',
    [ReportingController::class, 'exportTransactionsPptx'])
    ->name('reporting.transactions.pptx');

=====================================================================
COMMANDE ARTISAN (optionnelle) — génération planifiée
Créer app/Console/Commands/GenererRapportTransactions.php
=====================================================================

php artisan make:command GenererRapportTransactions

// Contenu handle() :
//   $mois = now()->subMonth()->format('Y-m');
//   $path = app(\App\Services\TransactionPptxGenerator::class)->generer($mois);
//   $this->info("Rapport généré : $path");
*/