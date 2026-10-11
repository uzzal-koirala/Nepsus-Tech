<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            $table->integer('position')->default(0)->index()->after('status');
        });

        // Keep the current newest-first order as the starting priority.
        DB::table('clients')->orderByDesc('created_at')->orderByDesc('id')->pluck('id')
            ->each(fn ($id, $i) => DB::table('clients')->where('id', $id)->update(['position' => $i]));
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            $table->dropColumn('position');
        });
    }
};
