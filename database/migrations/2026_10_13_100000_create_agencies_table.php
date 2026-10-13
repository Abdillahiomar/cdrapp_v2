<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Agences D-Money : chaque utilisateur est rattaché à une seule agence, qui
 * est reprise automatiquement sur les cash-out PCA qu'il enregistre.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('agencies', function (Blueprint $table) {
            $table->id();
            $table->string('code', 20)->unique();
            $table->string('name');
            $table->string('city')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        // Agences de départ
        DB::table('agencies')->insert([
            ['code' => '1300', 'name' => 'D-Money Agence Principal', 'city' => 'Djibouti', 'is_active' => true, 'created_at' => now(), 'updated_at' => now()],
            ['code' => '1301', 'name' => 'Agence Bawadi Mall',       'city' => 'Djibouti', 'is_active' => true, 'created_at' => now(), 'updated_at' => now()],
        ]);

        Schema::table('users', function (Blueprint $table) {
            $table->foreignId('agency_id')->nullable()->constrained('agencies')->nullOnDelete();
        });

        // L'agence du cash-out ; pca_cashouts.location garde une copie de son nom au moment du paiement
        Schema::table('pca_cashouts', function (Blueprint $table) {
            $table->foreignId('agency_id')->nullable()->after('available_before')->constrained('agencies')->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('pca_cashouts', function (Blueprint $table) {
            $table->dropConstrainedForeignId('agency_id');
        });

        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('agency_id');
        });

        Schema::dropIfExists('agencies');
    }
};
