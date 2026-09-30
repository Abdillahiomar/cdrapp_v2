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
        Schema::create('fraud_processing_runs', function (Blueprint $table) {
            $table->id();
            $table->date('activity_date');
            $table->string('process_name', 100);
            $table->string('status', 30);
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->unsignedBigInteger('rows_processed')->default(0);
            $table->text('error_message')->nullable();
            $table->timestamps();
            $table->unique([
                'activity_date',
                'process_name'
            ]);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('fraud_processing_runs');
    }
};
