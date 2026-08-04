<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('accounts_balance', function (Blueprint $table) {
            $table->id();

            $table->date('data_date')->nullable();
            $table->string('account_no')->nullable();
            $table->string('alias')->nullable();
            $table->string('account_type_id')->nullable();
            $table->string('identity_type')->nullable();
            $table->string('identity_id')->nullable();
            $table->string('value_type')->nullable();
            $table->string('currency')->nullable();

            $table->decimal('balance', 20, 2)->nullable();
            $table->decimal('reserved_balance', 20, 2)->nullable();
            $table->decimal('unclear_balance', 20, 2)->nullable();

            $table->string('account_status')->nullable();
            $table->timestamp('load_data_ts')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('accounts_balance');
    }
};