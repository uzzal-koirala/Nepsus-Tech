<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('document_folders', function (Blueprint $table) {
            $table->id();
            $table->string('slug', 30)->unique();
            $table->string('name', 40);
            $table->string('icon', 40)->default('folder');
            $table->unsignedInteger('position')->default(0);
            $table->timestamps();
        });

        // The starting folders; "other" always stays (deleted folders move their files there).
        $defaults = [
            ['contracts', 'Contracts', 'document-check'],
            ['company', 'Company papers', 'building-office-2'],
            ['finance', 'Finance & tax', 'banknotes'],
            ['hr', 'HR', 'user-group'],
            ['templates', 'Templates', 'document-duplicate'],
            ['marketing', 'Marketing', 'megaphone'],
            ['other', 'Other', 'folder'],
        ];
        foreach ($defaults as $i => [$slug, $name, $icon]) {
            DB::table('document_folders')->insert(['slug' => $slug, 'name' => $name, 'icon' => $icon, 'position' => $i, 'created_at' => now(), 'updated_at' => now()]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('document_folders');
    }
};
