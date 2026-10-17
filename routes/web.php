<?php

use App\Models\Document;
use App\Models\EmployeeDocument;
use App\Models\Payment;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;

Route::view('/', 'welcome')->name('home');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::livewire('dashboard', 'pages::dashboard')->name('dashboard');
    Route::livewire('clients', 'pages::clients')->name('clients.index');
    Route::livewire('clients/{client}', 'pages::client-profile')->name('clients.show');
    Route::livewire('packages', 'pages::packages')->name('packages.index');
    Route::livewire('employees', 'pages::employees')->name('employees.index');
    Route::livewire('finance', 'pages::finance')->name('finance.index');
    Route::livewire('employees/{employee}', 'pages::employee-profile')->name('employees.show');
    Route::livewire('sales', 'pages::sales')->name('sales.index');
    Route::livewire('sales/leads/{lead}', 'pages::lead-profile')->name('sales.leads.show');
    Route::livewire('calendar', 'pages::calendar')->name('calendar.index');
    Route::livewire('invoices', 'pages::invoices')->name('invoices.index');
    Route::livewire('invoices/create', 'pages::invoice-editor')->name('invoices.create');
    Route::livewire('invoices/{invoice}', 'pages::invoice-show')->name('invoices.show');
    Route::livewire('invoices/{invoice}/edit', 'pages::invoice-editor')->name('invoices.edit');
    Route::get('invoices/{invoice}/print', fn (App\Models\Invoice $invoice) => view('invoice-print', ['invoice' => $invoice->load('items')]))->name('invoices.print');

    Route::get('documents/{document}', fn (Document $document) => Storage::disk('local')->download($document->path, $document->name))
        ->name('documents.download');

    Route::get('employee-documents/{employeeDocument}', fn (EmployeeDocument $employeeDocument) => Storage::disk('local')->download($employeeDocument->path, $employeeDocument->name))
        ->name('employee-documents.download');

    Route::get('transactions/{transaction}/receipt', fn (App\Models\Transaction $transaction) => $transaction->receipt_path
        ? Storage::disk('local')->response($transaction->receipt_path)
        : abort(404))->name('transactions.receipt');

    // Proof of payment is private: shown only to signed-in users.
    Route::get('payments/{payment}/proof', fn (Payment $payment) => $payment->proof_path
        ? Storage::disk('local')->response($payment->proof_path)
        : abort(404))->name('payments.proof');
});

require __DIR__.'/settings.php';
