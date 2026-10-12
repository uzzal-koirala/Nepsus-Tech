<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /** The four main boxes, in this order: Video shoot, Edit, Post, Meeting. */
    public function up(): void
    {
        DB::table('work_types')->where('slug', 'video')->update(['name' => 'Video shoot', 'icon' => 'video-camera']);

        if (! DB::table('work_types')->where('slug', 'edit')->exists()) {
            DB::table('work_types')->insert(['slug' => 'edit', 'name' => 'Edit', 'color' => 'indigo', 'icon' => 'scissors', 'position' => 1, 'created_at' => now(), 'updated_at' => now()]);
        }

        $order = ['video', 'edit', 'post', 'meeting', 'reel', 'story', 'shoot', 'design', 'task'];
        $next = count($order);
        foreach (DB::table('work_types')->orderBy('position')->orderBy('id')->pluck('slug') as $slug) {
            $position = array_search($slug, $order, true);
            DB::table('work_types')->where('slug', $slug)->update(['position' => $position === false ? $next++ : $position]);
        }
    }

    public function down(): void
    {
        DB::table('work_types')->where('slug', 'video')->update(['name' => 'Video']);
        DB::table('work_types')->where('slug', 'edit')->delete();
    }
};
