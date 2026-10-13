<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vehicles', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('type', 20)->default('scooter');
            $table->string('brand')->nullable();
            $table->string('model')->nullable();
            $table->string('plate_no', 40)->nullable();
            $table->unsignedSmallInteger('year')->nullable();
            $table->foreignId('employee_id')->nullable()->constrained()->nullOnDelete();
            $table->date('bluebook_expiry')->nullable();
            $table->date('insurance_expiry')->nullable();
            $table->string('notes')->nullable();
            $table->timestamps();
        });

        Schema::create('vehicle_expenses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('vehicle_id')->constrained()->cascadeOnDelete();
            $table->string('category', 20)->index();
            $table->string('title')->nullable();
            $table->decimal('amount', 12, 2);
            $table->date('date')->index();
            $table->unsignedInteger('odometer')->nullable();
            $table->decimal('liters', 8, 2)->nullable();
            $table->string('method', 50)->nullable();
            $table->string('note')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vehicle_expenses');
        Schema::dropIfExists('vehicles');
    }
};
