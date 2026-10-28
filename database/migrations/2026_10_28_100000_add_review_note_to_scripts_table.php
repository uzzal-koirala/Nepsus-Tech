<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** The admin's reason when a submitted script is approved or not approved. */
    public function up(): void
    {
        Schema::table('scripts', function (Blueprint $table) {
            $table->string('review_note', 500)->nullable()->after('notes');
        });
    }

    public function down(): void
    {
        Schema::table('scripts', function (Blueprint $table) {
            $table->dropColumn('review_note');
        });
    }
};
