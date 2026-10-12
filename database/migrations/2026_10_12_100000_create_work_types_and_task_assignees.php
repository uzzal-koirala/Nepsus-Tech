<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('work_types', function (Blueprint $table) {
            $table->id();
            $table->string('slug', 40)->unique();
            $table->string('name', 40);
            $table->string('color', 20)->default('zinc');
            $table->string('icon', 40)->default('tag');
            $table->unsignedInteger('position')->default(0);
            $table->timestamps();
        });

        $defaults = [
            ['post', 'Post', 'sky', 'photo'],
            ['reel', 'Reel', 'fuchsia', 'film'],
            ['story', 'Story', 'amber', 'bolt'],
            ['video', 'Video', 'rose', 'video-camera'],
            ['shoot', 'Photoshoot', 'orange', 'camera'],
            ['design', 'Design', 'emerald', 'paint-brush'],
            ['meeting', 'Meeting', 'violet', 'users'],
            ['task', 'Task', 'zinc', 'check-circle'],
        ];
        foreach ($defaults as $i => [$slug, $name, $color, $icon]) {
            DB::table('work_types')->insert(['slug' => $slug, 'name' => $name, 'color' => $color, 'icon' => $icon, 'position' => $i, 'created_at' => now(), 'updated_at' => now()]);
        }

        Schema::create('employee_task', function (Blueprint $table) {
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->foreignId('task_id')->constrained()->cascadeOnDelete();
            $table->primary(['employee_id', 'task_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employee_task');
        Schema::dropIfExists('work_types');
    }
};
