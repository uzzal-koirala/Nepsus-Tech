<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

class EmployeeDocumentViewTest extends TestCase
{
    use RefreshDatabase;

    public function test_admins_view_employee_documents_in_the_app(): void
    {
        Storage::fake('local');
        $this->actingAs(User::factory()->create());
        $e = Employee::factory()->create(['name' => 'Niksan Adhikari']);
        Storage::disk('local')->put('employee-documents/'.$e->id.'/a.pdf', '%PDF-1.4 test');
        Storage::disk('local')->put('employee-documents/'.$e->id.'/b.docx', 'word');
        $pdf = $e->documents()->create(['type' => 'agreement', 'name' => 'Agreement.pdf', 'path' => 'employee-documents/'.$e->id.'/a.pdf', 'size' => 13]);
        $word = $e->documents()->create(['type' => 'cv', 'name' => 'CV.docx', 'path' => 'employee-documents/'.$e->id.'/b.docx', 'size' => 4]);

        // PDFs and photos open in the browser; anything else is downloaded.
        $view = $this->get(route('employee-documents.view', $pdf))->assertOk();
        $this->assertStringContainsString('application/pdf', $view->headers->get('Content-Type'));
        $this->assertStringStartsWith('inline', (string) $view->headers->get('Content-Disposition'));
        $this->assertSame('nosniff', $view->headers->get('X-Content-Type-Options'));
        $this->assertStringStartsWith('attachment', (string) $this->get(route('employee-documents.view', $word))->headers->get('Content-Disposition'));

        // The profile: View buttons and the preview popup.
        $page = Livewire::test('pages::employee-profile', ['employee' => $e])->call('setTab', 'documents')->assertSee('View');
        $page->call('viewDocument', $pdf->id)->assertSee(['Agreement.pdf', 'Open in new tab', 'Download'])->assertSee(route('employee-documents.view', $pdf), false);
        $page->call('viewDocument', $word->id)->assertSee('This file cannot be shown here')->assertDontSee('Open in new tab');

        // Another employee's document cannot be opened through this profile.
        $other = Employee::factory()->create();
        $theirs = $other->documents()->create(['type' => 'id', 'name' => 'ID.pdf', 'path' => 'x.pdf', 'size' => 1]);
        Livewire::test('pages::employee-profile', ['employee' => $e])->call('viewDocument', $theirs->id)->assertNotFound();

        // Employee logins cannot open documents at all.
        $this->actingAs(User::factory()->staff()->create());
        $this->get(route('employee-documents.view', $pdf))->assertRedirect(route('my.dashboard'));
    }
}
