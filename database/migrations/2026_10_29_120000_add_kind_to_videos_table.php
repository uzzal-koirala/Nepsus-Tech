<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Raw footage (goes to the editors) or B-roll (extra shots kept for later, no edit). */
    public function up(): void
    {
        Schema::table('videos', function (Blueprint $table) {
            $table->string('kind', 20)->default('raw')->after('url')->index();
        });
    }

    public function down(): void
    {
        Schema::table('videos', function (Blueprint $table) {
            $table->dropColumn('kind');
        });
    }
};
