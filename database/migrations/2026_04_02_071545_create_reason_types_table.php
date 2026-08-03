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
        Schema::create('reason_types', function (Blueprint $table) {
            $table->id();
            $table->string('UNIQUE_ID')->unique();
            $table->string('REASON_INDEX')->index();
            $table->string('REASON_NAME');
            $table->string('TXN_INDEX')->index();
            $table->string('CHANNELS')->nullable();
            $table->string('STATUS')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('reason_types');
    }
};
