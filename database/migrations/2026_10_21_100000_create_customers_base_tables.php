<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Module « Customers » : activité des clients calculée depuis fact_txn_v2
 * (customers:aggregate) et seuils propres aux clients. Le référentiel reste
 * kyc.customer_master (lu directement).
 *
 * Pas d'agrégat jour par jour par client (≈ 50 000 clients actifs par jour) :
 * un agrégat mensuel + la première / dernière transaction de chaque client.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Une ligne par client (MSISDN) et par mois ; montants en FDJ
        Schema::create('customer_monthly_activity', function (Blueprint $table) {
            $table->id();
            $table->date('month');                               // 1er jour du mois
            $table->string('msisdn', 30);
            $table->unsignedInteger('txn_count');
            $table->decimal('txn_amount', 20, 2);
            $table->unsignedInteger('cashin_count');             // Cash In reçus d'un agent
            $table->decimal('cashin_amount', 20, 2);
            $table->unsignedInteger('cashout_count');            // Cash Out effectués
            $table->decimal('cashout_amount', 20, 2);
            $table->unsignedInteger('p2p_sent_count');           // transferts envoyés
            $table->decimal('p2p_sent_amount', 20, 2);
            $table->unsignedInteger('p2p_received_count');       // transferts reçus
            $table->decimal('p2p_received_amount', 20, 2);
            $table->unsignedInteger('payment_count');            // factures + paiements marchands
            $table->decimal('payment_amount', 20, 2);
            $table->timestamp('first_txn_at');
            $table->timestamp('last_txn_at');

            $table->unique(['month', 'msisdn']);
            $table->index(['msisdn', 'month']);
        });

        Schema::create('customer_last_activity', function (Blueprint $table) {
            $table->string('msisdn', 30)->primary();
            $table->date('first_activity_date');
            $table->date('last_activity_date');
            $table->timestamp('last_txn_at');

            $table->index('last_activity_date');
        });

        Schema::create('customer_settings', function (Blueprint $table) {
            $table->string('key', 60)->primary();
            $table->string('value')->nullable();
        });

        // Seuils par défaut des clients (modifiables depuis le tableau de bord)
        DB::table('customer_settings')->insert([
            ['key' => 'active_days',  'value' => '30'],   // actif : dernière transaction il y a ≤ 30 jours
            ['key' => 'dormant_days', 'value' => '90'],   // dormant : ≤ 90 jours, inactif au-delà
            ['key' => 'pending_days', 'value' => '7'],    // « Pending Active » à traiter après N jours
            ['key' => 'new_days',     'value' => '30'],   // client récent : inscrit depuis moins de N jours
            ['key' => 'minor_age',    'value' => '18'],   // âge minimum légal
        ]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('customer_settings');
        Schema::dropIfExists('customer_last_activity');
        Schema::dropIfExists('customer_monthly_activity');
    }
};
