<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            // The employee number this person has on the attendance device.
            $table->string('device_user_id', 32)->nullable()->unique()->after('name');
        });

        Schema::table('attendances', function (Blueprint $table) {
            $table->string('check_in', 5)->nullable()->after('status'); // HH:MM, first punch of the day
            $table->string('source', 10)->default('manual')->after('note'); // manual or device
        });
    }

    public function down(): void
    {
        Schema::table('attendances', function (Blueprint $table) {
            $table->dropColumn(['check_in', 'source']);
        });

        Schema::table('employees', function (Blueprint $table) {
            $table->dropUnique(['device_user_id']);
            $table->dropColumn('device_user_id');
        });
    }
};
