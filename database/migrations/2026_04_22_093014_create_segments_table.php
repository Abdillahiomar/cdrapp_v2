<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('segments', function (Blueprint $table) {
            $table->id();
            $table->string('SEGMENT_ID')->unique()->index();
            $table->string('SEGMENT_NAME')->nullable();
            $table->text('SEGMENT_DESCRIPTION')->nullable();
            $table->string('IDENTITY_TYPE')->nullable()->index();
            $table->string('KYC_FIELD_ID')->nullable();
            $table->string('KYC_GROUP_ID')->nullable();
            $table->string('STATUS')->nullable()->index();
            $table->timestamp('LOAD_DATA_TS')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('segments');
    }
};