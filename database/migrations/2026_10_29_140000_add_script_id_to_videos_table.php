<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** The script this footage was shot from, so the editor can read it. */
    public function up(): void
    {
        Schema::table('videos', function (Blueprint $table) {
            $table->foreignId('script_id')->nullable()->after('client_id')->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('videos', function (Blueprint $table) {
            $table->dropConstrainedForeignId('script_id');
        });
    }
};
