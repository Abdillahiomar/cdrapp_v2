<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('all_balances', function (Blueprint $table) {
            $table->id();

            $table->string('identity_type')->nullable();
            $table->string('account_type')->nullable();
            $table->string('account_status')->nullable();

            $table->decimal('balance', 20, 2)->nullable();
            $table->decimal('reserved_balance', 20, 2)->nullable();
            $table->decimal('unclear_balance', 20, 2)->nullable();
            $table->decimal('total', 20, 2)->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('all_balances');
    }
};