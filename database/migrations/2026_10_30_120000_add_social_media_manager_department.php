<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /** Social Media Managers get the general employee pages: dashboard, my tasks, calendar, attendance and clients. */
    public function up(): void
    {
        DB::table('departments')->insertOrIgnore([
            'slug' => 'social-media-manager', 'name' => 'Social Media Manager', 'icon' => 'hashtag',
            'position' => (int) DB::table('departments')->max('position') + 1, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        DB::table('departments')->where('slug', 'social-media-manager')->delete();
    }
};
