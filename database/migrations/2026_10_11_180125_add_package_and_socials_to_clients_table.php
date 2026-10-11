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
        Schema::table('clients', function (Blueprint $table) {
            $table->string('package')->nullable()->after('status');
            $table->decimal('price', 12, 2)->nullable()->after('package');
            $table->string('billing_cycle', 20)->nullable()->after('price');
            $table->string('facebook_url')->nullable()->after('billing_cycle');
            $table->string('instagram_url')->nullable()->after('facebook_url');
            $table->string('tiktok_url')->nullable()->after('instagram_url');
            $table->string('google_business_url')->nullable()->after('tiktok_url');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            $table->dropColumn(['package', 'price', 'billing_cycle', 'facebook_url', 'instagram_url', 'tiktok_url', 'google_business_url']);
        });
    }
};
