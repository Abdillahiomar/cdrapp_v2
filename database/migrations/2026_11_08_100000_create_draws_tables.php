<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tirages au sort Marketing : une campagne (draws), ses lots (draw_prizes), la
 * liste figée des clients éligibles (draw_participants, un client = une ligne,
 * doublons supprimés) et le résultat du tirage (draw_winners).
 *
 * Le résultat est rejouable : liste figée (empreinte SHA-256) + graine aléatoire
 * conservée. Les lignes de draw_winners ne sont jamais supprimées : un gagnant
 * écarté passe en « invalidé » et son remplaçant le référence (replaces_id).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('draws', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->text('description')->nullable();

            // Critères d'éligibilité
            $table->date('period_start');
            $table->date('period_end');
            $table->json('txn_indexes');                                   // types de transactions retenus
            $table->unsignedInteger('min_txn_count')->default(1);
            $table->decimal('min_amount', 20, 2)->default(0);              // FDJ, cumul sur la période

            // Options du tirage, au choix de l'équipe Marketing
            $table->string('chance_mode', 20)->default('per_client');      // per_client | per_transaction
            $table->boolean('one_prize_per_client')->default(true);
            $table->boolean('exclude_employees')->default(true);
            $table->unsignedInteger('exclude_winners_days')->nullable();  // gagnants des N derniers jours
            $table->text('blacklist')->nullable();                         // un numéro par ligne

            // draft → computing → ready → drawn → closed
            $table->string('status', 20)->default('draft');
            $table->text('error')->nullable();

            // Liste figée
            $table->json('funnel')->nullable();                            // transactions → clients uniques → exclusions
            $table->unsignedInteger('eligible_count')->nullable();
            $table->unsignedBigInteger('ticket_count')->nullable();        // = éligibles, ou somme des transactions
            $table->char('eligible_hash', 64)->nullable();
            $table->char('seed', 64)->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('computed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('drawn_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('closed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('computed_at')->nullable();
            $table->timestamp('drawn_at')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->timestamps();

            $table->index('status');
        });

        Schema::create('draw_prizes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('draw_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->unsignedInteger('quantity')->default(1);
            $table->unsignedInteger('substitutes')->default(0);            // suppléants par lot
            $table->unsignedInteger('position')->default(0);               // ordre de tirage
            $table->timestamps();
        });

        Schema::create('draw_participants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('draw_id')->constrained()->cascadeOnDelete();
            $table->string('msisdn', 30);
            $table->string('name')->nullable();
            $table->unsignedInteger('txn_count');
            $table->decimal('amount', 20, 2);                              // FDJ
            $table->timestamp('last_txn_at')->nullable();

            $table->unique(['draw_id', 'msisdn']);
        });

        Schema::create('draw_winners', function (Blueprint $table) {
            $table->id();
            $table->foreignId('draw_id')->constrained()->cascadeOnDelete();
            $table->foreignId('draw_prize_id')->constrained()->cascadeOnDelete();
            $table->string('msisdn', 30);
            $table->string('name')->nullable();
            $table->unsignedInteger('txn_count')->default(0);
            $table->string('kind', 15);                                    // winner | substitute | extra
            $table->unsignedInteger('rank');                               // ordre de sortie dans le lot
            $table->string('status', 15)->default('valid');                // valid | invalidated
            $table->foreignId('replaces_id')->nullable()->constrained('draw_winners')->nullOnDelete();
            $table->timestamp('promoted_at')->nullable();                  // suppléant devenu gagnant
            $table->text('invalidated_reason')->nullable();
            $table->foreignId('invalidated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('invalidated_at')->nullable();
            $table->timestamps();

            $table->index(['draw_id', 'draw_prize_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('draw_winners');
        Schema::dropIfExists('draw_participants');
        Schema::dropIfExists('draw_prizes');
        Schema::dropIfExists('draws');
    }
};
