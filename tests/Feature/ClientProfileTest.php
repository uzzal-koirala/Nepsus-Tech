<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

class ClientProfileTest extends TestCase
{
    use RefreshDatabase;

    public function test_profile_page_renders_for_signed_in_users_only(): void
    {
        $client = Client::factory()->create(['name' => 'Asha Koirala']);

        $this->get(route('clients.show', $client))->assertRedirect(route('login'));

        $this->actingAs(User::factory()->create())
            ->get(route('clients.show', $client))
            ->assertOk()
            ->assertSee('Asha Koirala');
    }

    public function test_tasks_payments_and_agreement_can_be_managed(): void
    {
        $this->actingAs(User::factory()->create());
        $client = Client::factory()->create(['price' => 25000]);

        $page = Livewire::test('pages::client-profile', ['client' => $client])
            ->set('taskTitle', '')
            ->call('addTask')
            ->assertHasErrors(['taskTitle'])
            ->set('taskTitle', 'Design Facebook cover')
            ->call('addTask')
            ->assertHasNoErrors();

        $task = $client->tasks()->sole();
        $page->call('toggleTask', $task->id);
        $this->assertNotNull($task->fresh()->completed_at);

        $page->call('newPayment')
            ->assertSet('payAmount', '25000')
            ->set('payAmount', '-5')
            ->call('savePayment')
            ->assertHasErrors(['payAmount'])
            ->set('payAmount', '25000')
            ->set('payDue', today()->subDays(3)->toDateString())
            ->call('savePayment')
            ->assertHasNoErrors();

        $payment = $client->payments()->sole();
        $this->assertSame('overdue', $payment->status());
        $page->call('togglePaid', $payment->id);
        $this->assertSame('paid', $payment->fresh()->status());

        Storage::fake('local');

        $page->call('editAgreement')
            ->set('contractStart', '2026-05-01')
            ->set('contractEnd', '2026-04-01')
            ->call('saveAgreement')
            ->assertHasErrors(['contractEnd'])
            ->set('contractEnd', '2027-04-30')
            ->set('agreementSigned', true)
            ->set('agreementFile', UploadedFile::fake()->create('signed-agreement.pdf', 80, 'application/pdf'))
            ->call('saveAgreement')
            ->assertHasNoErrors()
            ->assertSee('१७ बैशाख २०८४'); // 2027-04-30 in BS

        $client->refresh();
        $this->assertSame('2027-04-30', $client->contract_end->toDateString());
        $this->assertNotNull($client->agreement_signed_at);
        $this->assertSame('agreement', $client->documents()->sole()->type);

        $page->call('editAgreement')->set('agreementSigned', false)->call('saveAgreement');
        $this->assertNull($client->fresh()->agreement_signed_at);

        $page->call('editNotes')->set('notes', 'Prefers WhatsApp')->call('saveNotes');
        $this->assertSame('Prefers WhatsApp', $client->fresh()->notes);
    }

    public function test_another_clients_task_cannot_be_changed_from_this_profile(): void
    {
        $this->actingAs(User::factory()->create());
        $client = Client::factory()->create();
        $otherTask = Task::factory()->for(Client::factory())->create(['completed_at' => null]);

        Livewire::test('pages::client-profile', ['client' => $client])
            ->call('toggleTask', $otherTask->id)
            ->assertNotFound();

        $this->assertNull($otherTask->fresh()->completed_at);
    }

    public function test_documents_can_be_uploaded_downloaded_and_deleted(): void
    {
        Storage::fake('local');
        $this->actingAs(User::factory()->create());
        $client = Client::factory()->create();

        Livewire::test('pages::client-profile', ['client' => $client])
            ->set('upload', UploadedFile::fake()->create('script.exe', 10))
            ->call('uploadDocument')
            ->assertHasErrors(['upload'])
            ->set('upload', UploadedFile::fake()->create('agreement.pdf', 120, 'application/pdf'))
            ->set('uploadType', 'agreement')
            ->call('uploadDocument')
            ->assertHasNoErrors();

        $doc = $client->documents()->sole();
        Storage::disk('local')->assertExists($doc->path);

        $this->get(route('documents.download', $doc))->assertOk()->assertDownload('agreement.pdf');

        Livewire::test('pages::client-profile', ['client' => $client])->call('deleteDocument', $doc->id);
        Storage::disk('local')->assertMissing($doc->path);
    }
}
