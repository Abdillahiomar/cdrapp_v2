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
        Schema::create('operators', function (Blueprint $table) {
            $table->id();
            $table->string('OPERATOR_ID')->unique()->index();
            $table->timestamp('CREATE_TIME')->nullable();
            $table->timestamp('ACTIVE_TIME')->nullable();
            $table->string('OWNED_IDENTITY_ID')->nullable()->index();
            $table->string('SP_ID')->nullable()->index();
            $table->string('BIZ_ORG_ID')->nullable()->after('SP_ID')->index();
            $table->foreign('BIZ_ORG_ID')->references('BIZ_ORG_ID')->on('organizations')->nullOnDelete();
            $table->string('USER_NAME')->nullable();
            $table->string('RULE_PROFILE_ID')->nullable();
            $table->string('STATUS')->nullable()->index();
            $table->timestamp('STATUS_CHANGE_TIME')->nullable();
            $table->tinyInteger('IS_ADMIN')->default(0);
            $table->string('PUBLIC_NAME')->nullable();
            $table->string('MODIFY_OPER_ID')->nullable();
            $table->timestamp('MODIFY_TIME')->nullable();
            $table->timestamp('LOAD_DATA_TS')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('operators');
    }
};
