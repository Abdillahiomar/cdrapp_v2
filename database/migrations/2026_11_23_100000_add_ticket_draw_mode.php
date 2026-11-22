<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tirages : mode « par transaction » (ticket). Chaque transaction éligible est un
 * ticket figé dans draw_tickets au calcul de la liste ; le tirage désigne un
 * ticket, dont la transaction_id est conservée dans draw_winners.
 * Mode « client » par défaut : listes et tirages existants inchangés.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('draws', function (Blueprint $table) {
            $table->string('draw_mode', 20)->default('client')->after('chance_mode');   // client | transaction
        });

        Schema::create('draw_tickets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('draw_id')->constrained('draws')->cascadeOnDelete();
            $table->string('msisdn', 30);
            $table->string('transaction_id', 100);
            $table->decimal('amount', 20, 2)->default(0);                                  // FDJ
            $table->timestamp('txn_at')->nullable();
            $table->unique(['draw_id', 'transaction_id']);
            $table->index(['draw_id', 'msisdn']);
        });

        Schema::table('draw_winners', function (Blueprint $table) {
            $table->string('transaction_id', 100)->nullable()->after('msisdn');           // ticket tiré (mode transaction)
        });
    }

    public function down(): void
    {
        Schema::table('draw_winners', function (Blueprint $table) {
            $table->dropColumn('transaction_id');
        });
        Schema::dropIfExists('draw_tickets');
        Schema::table('draws', function (Blueprint $table) {
            $table->dropColumn('draw_mode');
        });
    }
};
