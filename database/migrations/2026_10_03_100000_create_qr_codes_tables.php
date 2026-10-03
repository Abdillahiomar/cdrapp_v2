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
        // Génération en masse depuis un fichier (un ZIP par lot)
        Schema::create('qr_batches', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('file_name');                 // fichier importé
            $table->unsignedInteger('total');
            $table->string('format', 10);                // png | svg | both
            $table->boolean('with_logo')->default(false);
            $table->string('zip_path')->nullable();      // storage/app/private
            $table->timestamps();
        });

        // Historique : un enregistrement par QR code généré
        Schema::create('qr_codes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->text('payload');
            $table->string('merchant_name')->nullable();
            $table->string('short_code', 50)->nullable();
            $table->boolean('with_logo')->default(false);
            $table->foreignUuid('batch_id')->nullable()->constrained('qr_batches')->nullOnDelete();
            $table->timestamps();

            $table->index('short_code');
            $table->index('created_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('qr_codes');
        Schema::dropIfExists('qr_batches');
    }
};
