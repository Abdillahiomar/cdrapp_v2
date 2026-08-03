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
        Schema::create('dashboard_aggregations', function (Blueprint $table) {
            $table->id();
            $table->date('jour')->index();
            $table->string('txn_index')->nullable()->index();
            $table->string('txn_type_name')->nullable();
            $table->string('alias')->nullable();
            $table->string('trans_status')->index();

            // Métriques
            $table->unsignedBigInteger('nb_transactions')->default(0);
            $table->decimal('volume_total', 18, 2)->default(0);
            $table->decimal('revenus', 18, 2)->default(0);   // FEE + COMMISSION
            $table->decimal('frais', 18, 2)->default(0);     // FEE seul
            $table->decimal('commission', 18, 2)->default(0);// COMMISSION seul
            $table->decimal('taxe', 18, 2)->default(0);      // TAX
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('dashboard_aggregations');
    }
};
