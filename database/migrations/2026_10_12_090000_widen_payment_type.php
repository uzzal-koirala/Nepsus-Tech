<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Payment type is now free text (e.g. "Dashain bonus advance"). */
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->string('type', 60)->default('other')->change();
        });
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->string('type', 20)->default('other')->change();
        });
    }
};
