<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Departments the team can add to or remove from the employee form. */
    public function up(): void
    {
        Schema::create('departments', function (Blueprint $table) {
            $table->id();
            $table->string('slug', 40)->unique();
            $table->string('name', 40);
            $table->string('icon', 40)->default('rectangle-group');
            $table->unsignedInteger('position')->default(0);
            $table->timestamps();
        });

        $defaults = [
            ['design', 'Design', 'paint-brush'],
            ['development', 'Development', 'code-bracket'],
            ['marketing', 'Marketing', 'megaphone'],
            ['content', 'Content', 'pencil-square'],
            ['sales', 'Sales', 'briefcase'],
            ['management', 'Management', 'building-office'],
            ['hr', 'HR & Admin', 'user-circle'],
        ];
        foreach ($defaults as $i => [$slug, $name, $icon]) {
            DB::table('departments')->insert(['slug' => $slug, 'name' => $name, 'icon' => $icon, 'position' => $i, 'created_at' => now(), 'updated_at' => now()]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('departments');
    }
};
