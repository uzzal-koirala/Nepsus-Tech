<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tasks', function (Blueprint $table) {
            $table->text('description')->nullable()->after('title');
            $table->string('status', 20)->default('todo')->after('type');
            $table->string('priority', 10)->default('medium')->after('status');
            $table->date('start_date')->nullable()->after('priority');
        });

        DB::table('tasks')->whereNotNull('completed_at')->update(['status' => 'done']);
    }

    public function down(): void
    {
        Schema::table('tasks', function (Blueprint $table) {
            $table->dropColumn(['description', 'status', 'priority', 'start_date']);
        });
    }
};
