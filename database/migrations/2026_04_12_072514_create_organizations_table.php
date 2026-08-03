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
        Schema::create('organizations', function (Blueprint $table) {
            $table->id();
            $table->string('BIZ_ORG_ID')->unique()->index();
            $table->string('ORGANIZATION_TYPE')->nullable()->index();
            $table->string('BIZ_ORG_NAME')->nullable();
            $table->string('TRUST_LEVEL')->nullable();
            $table->string('SHORT_CODE')->nullable();
            $table->string('ORGANIZATION_CODE')->nullable();
            $table->string('REGION_ID')->nullable();
            $table->timestamp('MODIFY_TIME')->nullable();
            $table->string('MODIFY_OPER_ID')->nullable();
            $table->timestamp('CREATE_TIME')->nullable()->index();
            $table->string('CREATE_OPER_ID')->nullable();
            $table->string('SP_ID')->nullable()->index();
            $table->string('STATUS')->nullable()->index();
            $table->timestamp('STATUS_CHANGE_TIME')->nullable();
            $table->string('HIER_LEVEL')->nullable();
            $table->string('IDENTITY_MODEL')->nullable();
            $table->string('PARENT_ID')->nullable()->index();
            $table->string('TOP_BIZ_ORG')->nullable();
            $table->string('AGGREGATOR_ACC')->nullable();
            $table->string('HIER_TYPE')->nullable();
            $table->tinyInteger('IS_TOP')->default(0);
        });

        
        
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('operators', function (Blueprint $table) {
            $table->dropForeign(['BIZ_ORG_ID']);
            $table->dropColumn('BIZ_ORG_ID');
        });

        Schema::dropIfExists('organizations');
    }
};
