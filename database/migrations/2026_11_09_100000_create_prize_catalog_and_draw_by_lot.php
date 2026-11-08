<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Tirages au sort, évolution :
 *  - catalogue des lots enregistrés à l'avance (catégories Platinum / Gold /
 *    Bronze…, au moins une image par lot) ;
 *  - critères : exclusion RDS_LITE, montant minimum d'au moins une transaction
 *    (remplace le montant cumulé) ;
 *  - tirage lot par lot : chaque sortie a sa propre graine (rejouable).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('prize_categories', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100)->unique();
            $table->string('color', 7)->default('#1B2F6E');
            $table->unsignedInteger('position')->default(0);
            $table->timestamps();
        });

        DB::table('prize_categories')->insert([
            ['name' => 'Platinum', 'color' => '#5B6B82', 'position' => 1, 'created_at' => now(), 'updated_at' => now()],
            ['name' => 'Gold',     'color' => '#C99A06', 'position' => 2, 'created_at' => now(), 'updated_at' => now()],
            ['name' => 'Bronze',   'color' => '#A0522D', 'position' => 3, 'created_at' => now(), 'updated_at' => now()],
        ]);

        Schema::create('prizes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('prize_category_id')->constrained()->restrictOnDelete();
            $table->string('name');
            $table->text('description')->nullable();
            $table->decimal('value', 20, 2)->nullable();                   // valeur indicative, FDJ
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('prize_images', function (Blueprint $table) {
            $table->id();
            $table->foreignId('prize_id')->constrained()->cascadeOnDelete();
            $table->string('path');                                        // disque local (privé)
            $table->unsignedInteger('position')->default(0);               // 0 = image principale
            $table->timestamps();
        });

        Schema::table('draws', function (Blueprint $table) {
            $table->boolean('exclude_rds_lite')->default(false)->after('exclude_employees');
            $table->decimal('min_txn_amount', 20, 2)->default(0)->after('min_txn_count');   // FDJ, au moins une transaction
        });
        if (Schema::hasColumn('draws', 'min_amount')) {
            Schema::table('draws', fn (Blueprint $table) => $table->dropColumn('min_amount'));
        }

        Schema::table('draw_participants', function (Blueprint $table) {
            $table->decimal('max_amount', 20, 2)->default(0)->after('amount');                // plus grosse transaction, FDJ
        });

        // Lot du tirage = copie du lot du catalogue (le PV ne bouge pas si le catalogue change)
        Schema::table('draw_prizes', function (Blueprint $table) {
            $table->foreignId('prize_id')->nullable()->after('draw_id')->constrained()->nullOnDelete();
            $table->string('category_name', 100)->nullable()->after('name');
            $table->string('category_color', 7)->nullable()->after('category_name');
            $table->string('image_path')->nullable()->after('category_color');
        });

        Schema::table('draw_winners', function (Blueprint $table) {
            $table->char('seed', 64)->nullable()->after('rank');
        });
    }

    public function down(): void
    {
        Schema::table('draw_winners', fn (Blueprint $table) => $table->dropColumn('seed'));
        Schema::table('draw_prizes', function (Blueprint $table) {
            $table->dropConstrainedForeignId('prize_id');
            $table->dropColumn(['category_name', 'category_color', 'image_path']);
        });
        Schema::table('draw_participants', fn (Blueprint $table) => $table->dropColumn('max_amount'));
        Schema::table('draws', function (Blueprint $table) {
            $table->dropColumn(['exclude_rds_lite', 'min_txn_amount']);
            $table->decimal('min_amount', 20, 2)->default(0);
        });
        Schema::dropIfExists('prize_images');
        Schema::dropIfExists('prizes');
        Schema::dropIfExists('prize_categories');
    }
};
