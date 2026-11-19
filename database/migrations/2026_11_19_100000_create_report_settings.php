<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Réglages des rapports envoyés par email (clé / valeur), par exemple
 * l'envoi du tableau de bord des revenus à la direction.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('report_settings', function (Blueprint $table) {
            $table->string('key', 100)->primary();
            $table->text('value')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('report_settings');
    }
};
