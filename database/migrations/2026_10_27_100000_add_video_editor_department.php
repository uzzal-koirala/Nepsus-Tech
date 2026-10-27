<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /** Video Editors get the Videos page and an editing dashboard (see User::isVideoEditor). */
    public function up(): void
    {
        DB::table('departments')->insertOrIgnore([
            'slug' => 'video-editor', 'name' => 'Video Editor', 'icon' => 'film',
            'position' => (int) DB::table('departments')->max('position') + 1, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        DB::table('departments')->where('slug', 'video-editor')->delete();
    }
};
