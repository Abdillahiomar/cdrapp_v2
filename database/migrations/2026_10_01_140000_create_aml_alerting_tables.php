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
        // Règles AML configurables depuis l'interface (seuils, flux, population)
        Schema::create('aml_rules', function (Blueprint $table) {
            $table->id();
            $table->string('code', 20)->unique();             // AL1, AL2…
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('population', 30);                 // rds | corporate | ppe | watchlist | dormant | risk_sector | all
            $table->string('period', 10);                     // day | week | month
            $table->json('flows')->nullable();                // [{txn_index, direction: in|out|both}] — vide = tous les types
            $table->json('sectors')->nullable();              // activités kyc_organizations (population risk_sector)
            $table->unsignedInteger('count_threshold')->nullable();
            $table->decimal('amount_threshold', 18, 2)->nullable(); // en FDJ
            $table->string('logic', 3)->default('or');        // or | and
            $table->string('comparison', 3)->default('gt');   // gt (>) | gte (>=)
            $table->string('severity', 10)->default('medium'); // low | medium | high
            $table->boolean('enabled')->default(true);
            $table->timestamps();
        });

        // Agrégat quotidien par compte / type de transaction / sens, base de
        // calcul des règles (évite de rescanner fact_txn_v2 pour l'hebdo/mensuel)
        Schema::create('aml_party_daily', function (Blueprint $table) {
            $table->id();
            $table->date('activity_date');
            $table->string('party_id', 100);
            $table->string('party_type', 30);                 // Customer | Organization
            $table->string('party_name')->nullable();
            $table->integer('txn_index');
            $table->string('direction', 3);                   // in (crédit) | out (débit)
            $table->unsignedInteger('txn_count');
            $table->decimal('total_amount', 18, 2);           // en FDJ

            $table->index('activity_date');
            $table->index(['party_id', 'activity_date']);
        });

        Schema::create('aml_alerts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('rule_id')->constrained('aml_rules')->cascadeOnDelete();
            $table->string('party_id', 100);
            $table->string('party_type', 30);
            $table->string('party_name')->nullable();
            $table->string('period', 10);
            $table->date('period_start');
            $table->date('period_end');
            $table->unsignedInteger('txn_count');
            $table->decimal('total_amount', 18, 2);
            // Seuils au moment de la détection (les règles peuvent changer ensuite)
            $table->unsignedInteger('count_threshold')->nullable();
            $table->decimal('amount_threshold', 18, 2)->nullable();
            $table->string('logic', 3);
            $table->string('comparison', 3);
            $table->string('severity', 10);
            $table->string('status', 20)->default('new');     // new | in_review | closed | reported
            $table->foreignId('assigned_to')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('first_detected_at');
            $table->timestamp('last_detected_at');
            $table->timestamp('closed_at')->nullable();
            $table->timestamps();

            $table->unique(['rule_id', 'party_type', 'party_id', 'period_start']);
            $table->index(['status', 'created_at']);
            $table->index('party_id');
        });

        // Historique des actions des analystes sur une alerte
        Schema::create('aml_alert_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('alert_id')->constrained('aml_alerts')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('action', 20);                     // comment | status | assign
            $table->string('from_status', 20)->nullable();
            $table->string('to_status', 20)->nullable();
            $table->text('comment')->nullable();
            $table->timestamp('created_at');
        });

        // Créée hors Laravel en base ; on ne la crée que si elle est absente
        if (!Schema::hasTable('aml_watchlist')) {
            Schema::create('aml_watchlist', function (Blueprint $table) {
                $table->id();
                $table->string('msisdn');
                $table->string('list_type');                  // ppe | black_list | grey_list
                $table->string('nom')->nullable();
                $table->text('motif')->nullable();
                $table->boolean('actif')->default(true);
                $table->timestamp('date_ajout')->nullable();
                $table->string('ajoute_par')->nullable();
                $table->timestamp('date_retrait')->nullable();
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('aml_alert_events');
        Schema::dropIfExists('aml_alerts');
        Schema::dropIfExists('aml_party_daily');
        Schema::dropIfExists('aml_rules');
        // aml_watchlist n'est pas supprimée : elle existait avant cette migration
    }
};
