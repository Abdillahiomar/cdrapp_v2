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
        Schema::create('kyc_duplicate_identities', function (Blueprint $table) {
            $table->id();
            $table->string('id_type', 50)->nullable();
            $table->string('id_number', 100)->nullable();
            $table->string('full_name')->nullable();
            $table->string('mother_full_name')->nullable();
            $table->date('date_of_birth')->nullable();
            $table->unsignedInteger('msisdn_count');
            $table->text('msisdns');
            $table->timestamp('computed_at');

            $table->index('id_number');
            $table->index('msisdn_count');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('kyc_duplicate_identities');
    }
};
