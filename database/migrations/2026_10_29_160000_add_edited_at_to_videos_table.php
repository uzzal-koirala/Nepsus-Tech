<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** When the edit was finished, for the editor's charts and weekly counts. */
    public function up(): void
    {
        Schema::table('videos', function (Blueprint $table) {
            $table->timestamp('edited_at')->nullable()->after('edit_by');
        });
        // Footage already edited: the last change is the best guess.
        DB::table('videos')->whereIn('status', ['done', 'completed'])->update(['edited_at' => DB::raw('updated_at')]);
    }

    public function down(): void
    {
        Schema::table('videos', function (Blueprint $table) {
            $table->dropColumn('edited_at');
        });
    }
};
