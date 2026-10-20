<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Réseau de distribution : activité quotidienne des organisations (calculée
 * depuis fact_txn_v2 par network:aggregate) et seuils d'activité réglables.
 * Le référentiel des organisations reste kyc.org_master (lu directement).
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Une ligne par organisation (shortcode) et par jour ; montants en FDJ
        Schema::create('org_daily_activity', function (Blueprint $table) {
            $table->id();
            $table->date('activity_date');
            $table->string('org_id', 50);                       // shortcode = party identifier
            $table->unsignedInteger('txn_count');                // toutes transactions (débit ou crédit)
            $table->decimal('txn_amount', 20, 2);
            $table->unsignedInteger('cashin_count');             // Customer Cash In faits par l'agent
            $table->decimal('cashin_amount', 20, 2);
            $table->unsignedInteger('cashout_count');            // Customer Cash Out reçus par l'agent
            $table->decimal('cashout_amount', 20, 2);
            $table->unsignedInteger('merchant_count');           // paiements marchands reçus
            $table->decimal('merchant_amount', 20, 2);
            $table->decimal('commission_amount', 20, 2);         // Commission Payment reçues
            $table->timestamp('last_txn_at');

            $table->unique(['activity_date', 'org_id']);
            $table->index(['org_id', 'activity_date']);
        });

        // Première et dernière journée d'activité de chaque organisation
        Schema::create('org_last_activity', function (Blueprint $table) {
            $table->string('org_id', 50)->primary();
            $table->date('first_activity_date');
            $table->date('last_activity_date');
            $table->timestamp('last_txn_at');
        });

        Schema::create('network_settings', function (Blueprint $table) {
            $table->string('key', 60)->primary();
            $table->string('value')->nullable();
        });

        // Seuils par défaut : actif ≤ 7 jours, dormant ≤ 30 jours, inactif au-delà
        DB::table('network_settings')->insert([
            ['key' => 'active_days',  'value' => '7'],
            ['key' => 'dormant_days', 'value' => '30'],
            ['key' => 'new_org_days', 'value' => '30'],   // « nouvel agent » : inscrit depuis moins de N jours
        ]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('network_settings');
        Schema::dropIfExists('org_last_activity');
        Schema::dropIfExists('org_daily_activity');
    }
};
