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
        Schema::create('bank_balances', function (Blueprint $table) {
            $table->id();
            $table->date('balance_date')->index();
            $table->string('bank_name');
            $table->string('account_label'); // ex: "Compte courant n°123456" / "USD - opérations"
            $table->decimal('balance', 20, 2)->default(0);
            $table->string('currency', 10)->default('DJF');
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            // Un seul solde par (date, banque, compte) → on met à jour au lieu de dupliquer
            $table->unique(['balance_date', 'bank_name', 'account_label'], 'bank_balances_unique_entry');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('bank_balances');
    }
};
