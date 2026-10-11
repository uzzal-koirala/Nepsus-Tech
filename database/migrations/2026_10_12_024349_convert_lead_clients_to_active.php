<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Clients are now only active or inactive.
     */
    public function up(): void
    {
        DB::table('clients')->where('status', 'lead')->update(['status' => 'active']);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Former leads can't be told apart from other active clients.
    }
};
