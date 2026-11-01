<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Frais payés par compte et par jour (transactions terminées avec frais), pour le
 * « Top 10 des payeurs de frais » du tableau de bord revenue. Alimentée par
 * manager:aggregate, comme manager_daily_stats. Montants en FDJ.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('manager_fee_payers_daily', function (Blueprint $table) {
            $table->id();
            $table->date('activity_date');
            $table->string('party_id', 100);
            $table->string('party_type', 30)->nullable();     // Customer | Organization
            $table->string('party_name')->nullable();
            $table->unsignedInteger('txn_count');
            $table->decimal('fee', 20, 2);                    // FDJ
            $table->decimal('commission', 20, 2);             // FDJ

            $table->index(['activity_date', 'party_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('manager_fee_payers_daily');
    }
};
