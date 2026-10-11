<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * How the client pays: full or half advance, installments, or after the work is done.
     */
    public function up(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            $table->string('payment_plan', 20)->nullable()->after('video_count');
            $table->unsignedTinyInteger('installments')->nullable()->after('payment_plan');
        });
    }

    public function down(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            $table->dropColumn(['payment_plan', 'installments']);
        });
    }
};
