<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Income and expenses entered by hand (client payments and salaries come from their own tables). */
    public function up(): void
    {
        Schema::create('transactions', function (Blueprint $table) {
            $table->id();
            $table->string('kind', 10)->index(); // income or expense
            $table->string('category', 30);
            $table->string('title');
            $table->decimal('amount', 12, 2);
            $table->date('date')->index();
            $table->string('method', 50)->nullable();
            $table->string('note')->nullable();
            $table->string('receipt_path')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('transactions');
    }
};
