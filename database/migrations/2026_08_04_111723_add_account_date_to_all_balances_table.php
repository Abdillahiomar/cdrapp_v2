<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('all_balances', function (Blueprint $table) {
            $table->date('account_date')->nullable()->after('id');
        });
    }

    public function down(): void
    {
        Schema::table('all_balances', function (Blueprint $table) {
            $table->dropColumn('account_date');
        });
    }
};