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
        Schema::table('export_requests', function (Blueprint $table) {
            $table->timestamp('downloaded_at')->nullable()->after('completed_at'); // passage en uploaded
            $table->timestamp('deleted_at')->nullable()->after('downloaded_at');   // fichier supprimé par exports:cleanup
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('export_requests', function (Blueprint $table) {
            $table->dropColumn(['downloaded_at', 'deleted_at']);
        });
    }
};
