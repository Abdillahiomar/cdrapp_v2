<?php
// database/migrations/2026_08_31_000002_create_bank_accounts_table.php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('bank_accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('bank_id')->constrained('banks')->cascadeOnDelete();
            $table->string('account_label');   // ex: "Compte courant principal"
            $table->string('account_number')->nullable(); // ex: numéro IBAN/compte
            $table->string('currency', 10)->default('DJF');
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['bank_id', 'account_label']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bank_accounts');
    }
};