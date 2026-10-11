<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Billing becomes "every N months" (null = one-time payment).
     */
    public function up(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            $table->unsignedSmallInteger('billing_months')->nullable()->after('price');
        });

        DB::table('clients')->where('billing_cycle', 'monthly')->update(['billing_months' => 1]);
        DB::table('clients')->where('billing_cycle', 'yearly')->update(['billing_months' => 12]);

        Schema::table('clients', function (Blueprint $table) {
            $table->dropColumn('billing_cycle');
        });
    }

    public function down(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            $table->string('billing_cycle', 20)->nullable()->after('price');
        });

        DB::table('clients')->where('billing_months', 1)->update(['billing_cycle' => 'monthly']);
        DB::table('clients')->where('billing_months', 12)->update(['billing_cycle' => 'yearly']);
        DB::table('clients')->whereNull('billing_months')->update(['billing_cycle' => 'one_time']);
        DB::table('clients')->whereNull('billing_cycle')->update(['billing_cycle' => 'monthly']);

        Schema::table('clients', function (Blueprint $table) {
            $table->dropColumn('billing_months');
        });
    }
};
