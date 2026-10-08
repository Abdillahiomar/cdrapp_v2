<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('export_requests', function (Blueprint $table) {
            // Écran d'origine de l'export : transactions | bill_payments
            $table->string('source', 30)->default('transactions')->after('type');
            // Colonnes choisies par l'utilisateur (null = colonnes par défaut de la source)
            $table->json('columns')->nullable()->after('filters');

            $table->index(['user_id', 'source']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('export_requests', function (Blueprint $table) {
            $table->dropIndex(['user_id', 'source']);
            $table->dropColumn(['source', 'columns']);
        });
    }
};
