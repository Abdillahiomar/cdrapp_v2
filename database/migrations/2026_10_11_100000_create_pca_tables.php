<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * PCA (plan de continuité d'activité) : derniers soldes connus de chaque
 * compte, et registre des cash-out payés manuellement pendant une panne.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Dernier solde connu par compte (alimenté par pca:refresh-balances et les fichiers Huawei)
        Schema::create('pca_balances', function (Blueprint $table) {
            $table->id();
            $table->string('party_id', 100);                  // MSISDN ou shortcode
            $table->string('party_type', 30);                 // Customer | Organization
            $table->string('account_type', 60);               // Customer Main Account, Org Main Account…
            $table->string('party_name')->nullable();         // nom porté par les transactions (repli si pas de KYC)
            $table->decimal('balance', 20, 2);                // FDJ
            $table->timestamp('as_of');                       // solde valable à cette date
            $table->string('last_transaction_id', 50)->nullable();
            $table->string('source', 20);                     // transactions | huawei
            $table->timestamp('updated_at');

            $table->unique(['party_id', 'party_type', 'account_type']);
            $table->index('party_id');
        });

        // Incident (mode PCA activé) : regroupe les cash-out payés pendant une panne
        Schema::create('pca_incidents', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->timestamp('started_at');
            $table->timestamp('ended_at')->nullable();
            $table->foreignId('opened_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('closed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('notes')->nullable();
            $table->timestamps();
        });

        Schema::create('pca_cashouts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('incident_id')->constrained('pca_incidents')->cascadeOnDelete();
            $table->string('party_id', 100);
            $table->string('party_type', 30);
            $table->string('account_type', 60);
            $table->decimal('amount', 20, 2);
            $table->decimal('available_before', 20, 2);       // solde PCA disponible au moment du paiement
            $table->string('location');                       // agence / agent payeur
            $table->string('id_document');                    // pièce vérifiée (type + numéro)
            $table->text('notes')->nullable();
            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('cancelled_at')->nullable();
            $table->foreignId('cancelled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('cancel_reason')->nullable();
            $table->timestamps();

            $table->index(['incident_id', 'party_id']);
        });

        // Historique des fichiers de soldes fournis par Huawei
        Schema::create('pca_imports', function (Blueprint $table) {
            $table->id();
            $table->string('file_name');
            $table->timestamp('as_of');
            $table->unsignedInteger('rows_total');
            $table->unsignedInteger('rows_applied');
            $table->unsignedInteger('rows_skipped');
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('created_at');
        });

        // Traçabilité des consultations de soldes (données sensibles)
        Schema::create('pca_access_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('party_id', 100);
            $table->string('action', 30);                     // view | cashout | cancel
            $table->timestamp('created_at');

            $table->index('party_id');
        });

        // Réglages PCA (destinataires email, envoi quotidien, mot de passe du ZIP…)
        Schema::create('pca_settings', function (Blueprint $table) {
            $table->string('key', 60)->primary();
            $table->text('value')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('pca_settings');
        Schema::dropIfExists('pca_access_logs');
        Schema::dropIfExists('pca_imports');
        Schema::dropIfExists('pca_cashouts');
        Schema::dropIfExists('pca_incidents');
        Schema::dropIfExists('pca_balances');
    }
};
