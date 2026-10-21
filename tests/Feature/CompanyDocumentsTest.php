<?php

namespace Tests\Feature;

use App\Models\CompanyDocument;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

class CompanyDocumentsTest extends TestCase
{
    use RefreshDatabase;

    public function test_documents_are_uploaded_viewed_downloaded_renamed_and_deleted(): void
    {
        Storage::fake('local');
        $this->get(route('library.index'))->assertRedirect(route('login'));
        $user = User::factory()->create();
        $this->actingAs($user);
        $this->get(route('library.index'))->assertOk()->assertSee(['Documents', 'No documents yet']);

        Livewire::test('pages::documents')
            ->call('openUpload')
            ->set('uploads', [UploadedFile::fake()->create('PAN certificate.pdf', 200, 'application/pdf'), UploadedFile::fake()->create('Office photo.jpg', 120, 'image/jpeg')])
            ->assertSet('names', ['PAN certificate', 'Office photo']) // file names to start with
            ->set('names.1', 'Office front photo')
            ->set('uploadFolder', 'company')
            ->call('upload')
            ->assertHasNoErrors()
            ->assertSee(['PAN certificate', 'Office front photo']);

        $pdf = CompanyDocument::where('name', 'PAN certificate')->sole();
        $this->assertSame(['company', 'pdf', 'pdf', $user->id], [$pdf->folder, $pdf->extension, $pdf->kind(), $pdf->uploaded_by]);
        Storage::disk('local')->assertExists($pdf->path);

        // Not allowed: scripts or pages.
        Livewire::test('pages::documents')->set('uploads', [UploadedFile::fake()->create('evil.html', 1, 'text/html')])->call('upload')->assertHasErrors('uploads.0');

        $this->get(route('library.view', $pdf))->assertOk()->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->get(route('library.download', $pdf))->assertOk()->assertDownload('PAN certificate.pdf');

        Livewire::test('pages::documents')
            ->set('folder', 'company')->assertSee('PAN certificate')
            ->set('folder', 'hr')->assertDontSee('PAN certificate')
            ->set('folder', '')->set('search', 'office')->assertSee('Office front photo')->assertDontSee('PAN certificate')
            ->call('edit', $pdf->id)->set('editName', 'PAN 2083')->set('editFolder', 'finance')->call('save')->assertHasNoErrors()
            ->call('open', $pdf->id)->assertSet('previewId', $pdf->id)
            ->call('confirmDelete', $pdf->id)->call('delete');

        $this->assertModelMissing($pdf);
        Storage::disk('local')->assertMissing($pdf->path); // the file goes too
        $this->assertSame(['Office front photo'], CompanyDocument::pluck('name')->all());

        // Folders: add one from the dropdown, then delete it; its files move to Other.
        $page = Livewire::test('pages::documents')
            ->set('newFolder', 'Vehicle papers')->call('addFolder', 'editFolder')->assertSet('editFolder', 'vehicle-papers');
        CompanyDocument::first()->update(['folder' => 'vehicle-papers']);
        $page->call('confirmDeleteFolder', 'vehicle-papers')->assertSee('Delete the folder “Vehicle papers”?')->call('deleteFolder');
        $this->assertNull(\App\Models\DocumentFolder::firstWhere('slug', 'vehicle-papers'));
        $this->assertSame('other', CompanyDocument::first()->folder);
        $this->assertSame(1, CompanyDocument::count()); // no files deleted
        $page->call('confirmDeleteFolder', 'other')->assertStatus(422); // Other always stays

        // Signed-out people cannot open files.
        auth()->logout();
        $this->get(route('library.view', CompanyDocument::first()))->assertRedirect(route('login'));
    }
}
