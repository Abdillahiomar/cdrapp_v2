<?php

use Carbon\Carbon;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Cérémonies de tirage : une cérémonie regroupe une liste d'éligibles (un
 * enregistrement « draws ») par catégorie de lots. Un client ne gagne qu'un
 * seul lot sur toute la cérémonie.
 *
 * Les tirages existants deviennent chacun une cérémonie à liste unique
 * (prize_category_id NULL = « toutes catégories »), historique inchangé.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('draw_ceremonies', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->text('description')->nullable();
            $table->date('ceremony_date')->nullable();
            // preparation → in_progress (premier tirage) → done (tous les lots) → closed
            $table->string('status', 20)->default('preparation')->index();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('drawn_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('closed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('drawn_at')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->timestamps();
        });

        Schema::table('draws', function (Blueprint $table) {
            $table->foreignId('draw_ceremony_id')->nullable()->after('id')->constrained('draw_ceremonies')->cascadeOnDelete();
            $table->foreignId('prize_category_id')->nullable()->after('draw_ceremony_id')->constrained('prize_categories')->nullOnDelete();
            $table->unique(['draw_ceremony_id', 'prize_category_id']);
        });

        // Reprise : chaque tirage existant devient une cérémonie
        $status = ['drawing' => 'in_progress', 'drawn' => 'done', 'closed' => 'closed'];
        $now    = Carbon::now();

        foreach (DB::table('draws')->whereNull('draw_ceremony_id')->orderBy('id')->get() as $d) {
            $first = DB::table('draw_winners')->where('draw_id', $d->id)->min('created_at');

            $id = DB::table('draw_ceremonies')->insertGetId([
                'name'        => $d->name,
                'description' => $d->description,
                'status'      => $status[$d->status] ?? 'preparation',
                'created_by'  => $d->created_by,
                'drawn_by'    => $d->drawn_by,
                'closed_by'   => $d->closed_by,
                'started_at'  => $first,
                'drawn_at'    => $d->drawn_at,
                'closed_at'   => $d->closed_at,
                'created_at'  => $d->created_at ?? $now,
                'updated_at'  => $now,
            ]);

            DB::table('draws')->where('id', $d->id)->update(['draw_ceremony_id' => $id]);
        }
    }

    public function down(): void
    {
        Schema::table('draws', function (Blueprint $table) {
            $table->dropUnique(['draw_ceremony_id', 'prize_category_id']);
            $table->dropConstrainedForeignId('prize_category_id');
            $table->dropConstrainedForeignId('draw_ceremony_id');
        });

        Schema::dropIfExists('draw_ceremonies');
    }
};
