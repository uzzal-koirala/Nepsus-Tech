<?php

use App\Models\Document;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;

Route::view('/', 'welcome')->name('home');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::view('dashboard', 'dashboard')->name('dashboard');
    Route::livewire('clients', 'pages::clients')->name('clients.index');
    Route::livewire('clients/{client}', 'pages::client-profile')->name('clients.show');
    Route::livewire('packages', 'pages::packages')->name('packages.index');
    Route::livewire('employees', 'pages::employees')->name('employees.index');

    Route::get('documents/{document}', fn (Document $document) => Storage::disk('local')->download($document->path, $document->name))
        ->name('documents.download');
});

require __DIR__.'/settings.php';
