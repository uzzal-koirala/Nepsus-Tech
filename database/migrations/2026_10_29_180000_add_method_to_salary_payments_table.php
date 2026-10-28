<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** How the salary was paid: bank transfer, cheque, cash or other. */
    public function up(): void
    {
        Schema::table('salary_payments', function (Blueprint $table) {
            $table->string('method', 20)->nullable()->after('amount');
        });
    }

    public function down(): void
    {
        Schema::table('salary_payments', function (Blueprint $table) {
            $table->dropColumn('method');
        });
    }
};
