<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('task_groups', function (Blueprint $table) {
            $table->date('due_date')->nullable()->after('color');
            $table->string('priority', 10)->default('medium')->after('due_date');
        });
    }

    public function down(): void
    {
        Schema::table('task_groups', function (Blueprint $table) {
            $table->dropColumn(['due_date', 'priority']);
        });
    }
};
