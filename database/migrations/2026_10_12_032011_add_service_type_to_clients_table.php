<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * What kind of work the client buys: recurring social media management,
     * one-time website or software projects, or videos charged per video.
     */
    public function up(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            $table->string('service_type', 20)->default('social_media')->index()->after('status');
            $table->unsignedSmallInteger('video_count')->nullable()->after('billing_months');
        });

        // Existing one-time clients are treated as website projects until edited.
        DB::table('clients')->whereNull('billing_months')->update(['service_type' => 'website']);
    }

    public function down(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            $table->dropColumn(['service_type', 'video_count']);
        });
    }
};
