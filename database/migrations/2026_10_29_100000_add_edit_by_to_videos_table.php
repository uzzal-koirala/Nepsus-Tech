<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** The day the edit must be finished, shown to the Video Editors. */
    public function up(): void
    {
        Schema::table('videos', function (Blueprint $table) {
            $table->date('edit_by')->nullable()->after('shot_on');
        });
    }

    public function down(): void
    {
        Schema::table('videos', function (Blueprint $table) {
            $table->dropColumn('edit_by');
        });
    }
};
