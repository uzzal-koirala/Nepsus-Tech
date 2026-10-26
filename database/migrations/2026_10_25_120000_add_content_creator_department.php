<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /** Content Creators write scripts and plan every client's content (see User::canPlanContent). */
    public function up(): void
    {
        DB::table('departments')->insertOrIgnore([
            'slug' => 'content-creator', 'name' => 'Content Creator', 'icon' => 'video-camera',
            'position' => (int) DB::table('departments')->max('position') + 1, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        DB::table('departments')->where('slug', 'content-creator')->delete();
    }
};
