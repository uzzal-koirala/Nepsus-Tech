<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Each employee's working hours, and how many minutes after the start a check-in still counts as on time. */
    public function up(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            $table->string('work_start', 5)->nullable()->after('salary_day');
            $table->string('work_end', 5)->nullable()->after('work_start');
            $table->unsignedSmallInteger('late_after')->default(0)->after('work_end');
        });
    }

    public function down(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            $table->dropColumn(['work_start', 'work_end', 'late_after']);
        });
    }
};
