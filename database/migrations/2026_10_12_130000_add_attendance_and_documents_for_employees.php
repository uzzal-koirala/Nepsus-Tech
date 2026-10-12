<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            $table->unsignedTinyInteger('salary_day')->nullable()->after('salary'); // BS day of the month salary is paid
            $table->date('contract_start')->nullable()->after('joined_on');
            $table->date('contract_end')->nullable()->after('contract_start');
        });

        Schema::create('attendances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->date('date');
            $table->string('status', 12); // present, absent, leave, half_day
            $table->string('note')->nullable();
            $table->timestamps();
            $table->unique(['employee_id', 'date']);
        });

        Schema::create('employee_documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->string('type', 20)->default('other');
            $table->string('name');
            $table->string('path');
            $table->unsignedInteger('size')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employee_documents');
        Schema::dropIfExists('attendances');
        Schema::table('employees', function (Blueprint $table) {
            $table->dropColumn(['salary_day', 'contract_start', 'contract_end']);
        });
    }
};
