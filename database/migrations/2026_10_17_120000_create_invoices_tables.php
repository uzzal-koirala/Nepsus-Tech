<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('invoices', function (Blueprint $table) {
            $table->id();
            $table->string('type', 12)->default('invoice')->index(); // invoice or quotation
            $table->string('number', 30)->unique();
            $table->foreignId('client_id')->nullable()->constrained()->nullOnDelete();
            // Who it is for, kept as written on the document.
            $table->string('bill_name');
            $table->string('bill_company')->nullable();
            $table->string('bill_phone', 50)->nullable();
            $table->string('bill_email')->nullable();
            $table->string('bill_address')->nullable();
            $table->date('issue_date');
            $table->date('due_date')->nullable(); // invoice: due by; quotation: valid until
            $table->string('status', 12)->default('draft')->index();
            $table->decimal('discount', 12, 2)->default(0);
            $table->decimal('tax_rate', 5, 2)->default(0);
            $table->text('notes')->nullable();
            $table->text('terms')->nullable();
            $table->text('payment_info')->nullable();
            $table->string('template', 12)->default('classic');
            $table->string('accent', 12)->default('green');
            $table->foreignId('payment_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('converted_to_id')->nullable()->constrained('invoices')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('invoice_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('invoice_id')->constrained()->cascadeOnDelete();
            $table->string('description');
            $table->string('details')->nullable();
            $table->decimal('quantity', 10, 2)->default(1);
            $table->decimal('price', 12, 2)->default(0);
            $table->unsignedSmallInteger('position')->default(0);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invoice_items');
        Schema::dropIfExists('invoices');
    }
};
