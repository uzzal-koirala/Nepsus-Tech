<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('task_groups', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->foreignId('client_id')->nullable()->constrained()->nullOnDelete();
            $table->string('color', 20)->default('violet');
            $table->unsignedInteger('position')->default(0);
            $table->timestamps();
        });

        Schema::table('tasks', function (Blueprint $table) {
            $table->foreignId('task_group_id')->nullable()->after('client_id')->constrained()->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('tasks', function (Blueprint $table) {
            $table->dropConstrainedForeignId('task_group_id');
        });
        Schema::dropIfExists('task_groups');
    }
};
