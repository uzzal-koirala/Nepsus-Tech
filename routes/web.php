<?php

use App\Models\Document;
use App\Models\EmployeeDocument;
use App\Models\Payment;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;

// The app is the site: signed-in users land on the Overview, everyone else on sign-in.
Route::redirect('/', '/dashboard')->name('home');

// Open to every login: employee logins see these too.
Route::middleware(['auth', 'verified'])->group(function () {
    Route::livewire('my', 'pages::my-dashboard')->name('my.dashboard');
    Route::livewire('my/tasks', 'pages::my-tasks')->name('my.tasks');
    Route::livewire('my/clients', 'pages::my-clients')->name('my.clients');
    Route::livewire('my/clients/{client}', 'pages::my-client')->name('my.clients.show');
    Route::livewire('calendar', 'pages::calendar')->name('calendar.index');
    Route::livewire('scripts', 'pages::scripts')->name('scripts.index');
    Route::livewire('scripts/{script}', 'pages::script-editor')->name('scripts.edit');
    Route::livewire('videos', 'pages::videos')->name('videos.index');
});

// Admins only. Employee logins are sent to their own page (see AdminOnly) or refused.
Route::middleware(['auth', 'verified', 'admin'])->group(function () {
    Route::livewire('dashboard', 'pages::dashboard')->name('dashboard');
    Route::livewire('clients', 'pages::clients')->name('clients.index');
    Route::livewire('clients/{client}', 'pages::client-profile')->name('clients.show');
    Route::livewire('packages', 'pages::packages')->name('packages.index');
    Route::livewire('employees', 'pages::employees')->name('employees.index');
    Route::livewire('finance', 'pages::finance')->name('finance.index');
    Route::livewire('employees/{employee}', 'pages::employee-profile')->name('employees.show');
    Route::livewire('sales', 'pages::sales')->name('sales.index');
    Route::livewire('sales/leads/{lead}', 'pages::lead-profile')->name('sales.leads.show');
    Route::livewire('tasks', 'pages::tasks')->name('tasks.index');
    Route::livewire('documents', 'pages::documents')->name('library.index');
    Route::livewire('invoices', 'pages::invoices')->name('invoices.index');
    Route::livewire('invoices/create', 'pages::invoice-editor')->name('invoices.create');
    Route::livewire('invoices/{invoice}', 'pages::invoice-show')->name('invoices.show');
    Route::livewire('invoices/{invoice}/edit', 'pages::invoice-editor')->name('invoices.edit');
    Route::get('invoices/{invoice}/print', fn (App\Models\Invoice $invoice) => view('invoice-print', ['invoice' => $invoice->load('items')]))->name('invoices.print');

    Route::get('documents/{document}', fn (Document $document) => Storage::disk('local')->download($document->path, $document->name))
        ->name('documents.download');

    // Company documents (Documents page): open in the browser, or download.
    Route::get('library/{companyDocument}/view', fn (App\Models\CompanyDocument $companyDocument) => Storage::disk('local')
        ->response($companyDocument->path, $companyDocument->name.'.'.$companyDocument->extension, ['X-Content-Type-Options' => 'nosniff']))->name('library.view');
    Route::get('library/{companyDocument}/download', fn (App\Models\CompanyDocument $companyDocument) => Storage::disk('local')
        ->download($companyDocument->path, $companyDocument->name.'.'.$companyDocument->extension))->name('library.download');

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
