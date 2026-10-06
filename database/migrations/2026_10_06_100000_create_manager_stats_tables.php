<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Agrégats du tableau de bord direction (manager_dashboard).
 *
 * Montants en FDJ : le ×100 de fact_txn_v2 est appliqué au chargement par
 * manager:aggregate, jamais à la lecture.
 *
 * - manager_hourly_stats : granularité "heure" (détail d'une journée)
 * - manager_daily_stats  : granularités jour / mois / année (calculée depuis l'horaire)
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('manager_hourly_stats', function (Blueprint $table) {
            $table->id();
            $table->date('activity_date');
            $table->unsignedSmallInteger('hour');             // 0 → 23
            $table->integer('txn_index');
            $table->integer('reason_index')->nullable();
            $table->string('status', 30);
            $table->unsignedInteger('txn_count');
            $table->decimal('amount', 20, 2);                 // FDJ
            $table->decimal('fee', 20, 2);                    // FDJ
            $table->decimal('commission', 20, 2);             // FDJ

            $table->index(['activity_date', 'txn_index']);
        });

        Schema::create('manager_daily_stats', function (Blueprint $table) {
            $table->id();
            $table->date('activity_date');
            $table->integer('txn_index');
            $table->integer('reason_index')->nullable();
            $table->string('status', 30);
            $table->unsignedInteger('txn_count');
            $table->decimal('amount', 20, 2);
            $table->decimal('fee', 20, 2);
            $table->decimal('commission', 20, 2);

            $table->index(['activity_date', 'txn_index']);
            $table->index(['txn_index', 'activity_date']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('manager_daily_stats');
        Schema::dropIfExists('manager_hourly_stats');
    }
};
