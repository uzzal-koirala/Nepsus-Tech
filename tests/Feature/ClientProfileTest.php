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
            ->assertSee('17 Baisakh 2084'); // 2027-04-30 in BS

        $client->refresh();
        $this->assertSame('2027-04-30', $client->contract_end->toDateString());
        $this->assertNotNull($client->agreement_signed_at);
        $this->assertSame('agreement', $client->documents()->sole()->type);

        $page->call('editAgreement')->set('agreementSigned', false)->call('saveAgreement');
        $this->assertNull($client->fresh()->agreement_signed_at);

        $page->call('editNotes')->set('notes', 'Prefers WhatsApp')->call('saveNotes');
        $this->assertSame('Prefers WhatsApp', $client->fresh()->notes);
    }

    public function test_payment_plans_add_the_schedule_from_the_profile(): void
    {
        $this->actingAs(User::factory()->create());

        // Website, half advance already received: 50% paid now, 50% due on the end date.
        $site = Client::factory()->create([
            'service_type' => 'website', 'price' => 150000, 'billing_months' => null,
            'contract_start' => '2026-08-31', 'contract_end' => '2026-11-30',
        ]);

        Livewire::test('pages::client-profile', ['client' => $site])
            ->call('openPlan')
            ->assertSet('planAmount', '150000')
            ->assertSet('planStart', '2026-08-31')
            ->set('planType', 'half_advance')
            ->set('planAdvancePaid', true)
            ->call('savePlan')
            ->assertHasNoErrors();

        $payments = $site->payments()->orderBy('due_date')->get();
        $this->assertCount(2, $payments);
        $this->assertEquals([75000, 75000], $payments->pluck('amount')->map(fn ($a) => (float) $a)->all());
        $this->assertNotNull($payments[0]->paid_at);
        $this->assertNull($payments[1]->paid_at);
        $this->assertSame('2026-11-30', $payments[1]->due_date->toDateString());
        $this->assertSame('half_advance', $site->fresh()->payment_plan);

        // Video, 3 installments of the video total: one per Nepali month, adding up exactly.
        $video = Client::factory()->create(['service_type' => 'video', 'price' => 25000, 'video_count' => 4, 'billing_months' => null]);

        Livewire::test('pages::client-profile', ['client' => $video])
            ->call('openPlan')
            ->assertSet('planAmount', '100000')
            ->set('planStart', '2026-08-31') // 15 Bhadra 2083
            ->set('planType', 'installments')
            ->set('planInstallments', '1')
            ->call('savePlan')
            ->assertHasErrors(['planInstallments'])
            ->set('planInstallments', '3')
            ->assertSee('15 Kartik 2083')
            ->call('savePlan')
            ->assertHasNoErrors();

        $rows = $video->payments()->orderBy('due_date')->get();
        $this->assertCount(3, $rows);
        $this->assertSame(100000.0, (float) $rows->sum('amount'));
        $this->assertSame(['15 Bhadra 2083', '15 Ashoj 2083', '15 Kartik 2083'], $rows->map(fn ($r) => $r->due_date->toNepali())->all());
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
