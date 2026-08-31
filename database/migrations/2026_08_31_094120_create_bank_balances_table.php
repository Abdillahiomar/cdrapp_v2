<?php
// database/migrations/2026_08_31_000003_create_bank_balances_table.php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('bank_balances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('bank_account_id')->constrained('bank_accounts')->cascadeOnDelete();
            $table->date('balance_date')->index();
            $table->decimal('balance', 20, 2)->default(0);
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            // Un seul solde par compte et par date → on met à jour au lieu de dupliquer
            $table->unique(['bank_account_id', 'balance_date'], 'bank_balances_unique_entry');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bank_balances');
    }
};