<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // The video shoot task an approved script with a shoot date creates for its writer.
        Schema::table('tasks', function (Blueprint $table) {
            $table->foreignId('script_id')->nullable()->unique()->after('client_id')->constrained()->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('tasks', function (Blueprint $table) {
            $table->dropConstrainedForeignId('script_id');
        });
    }
};
