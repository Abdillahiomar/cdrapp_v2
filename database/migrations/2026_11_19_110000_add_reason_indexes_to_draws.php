<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tirages : reasons retenus par type de transaction (ex. Merchant Payment
 * « via Main Acc » et « via Sub Acc » seulement). Un type sans reason retenu
 * garde toutes ses transactions : NULL = aucune restriction (listes existantes inchangées).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('draws', function (Blueprint $table) {
            $table->json('reason_indexes')->nullable()->after('txn_indexes');
        });
    }

    public function down(): void
    {
        Schema::table('draws', function (Blueprint $table) {
            $table->dropColumn('reason_indexes');
        });
    }
};
