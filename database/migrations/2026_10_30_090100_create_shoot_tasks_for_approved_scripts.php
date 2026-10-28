<?php

use App\Models\Script;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    /** Approved scripts already holding an upcoming shoot date get their writer's shoot task now. */
    public function up(): void
    {
        Script::scheduled()->whereDate('shoot_date', '>=', today())->each(fn (Script $s) => $s->syncShootTask());
    }

    public function down(): void {}
};
