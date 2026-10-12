<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Task;
use App\Support\NepaliDate;
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
            ->assertSee('17 Baisakh 2084') // 2027-04-30 in BS
            ->assertSee('Verified: agreement signed');

        $client->refresh();
        $this->assertSame('2027-04-30', $client->contract_end->toDateString());
        $this->assertNotNull($client->agreement_signed_at);
        $this->assertSame('agreement', $client->documents()->sole()->type);

        $page->call('editAgreement')->set('agreementSigned', false)->call('saveAgreement')->assertDontSee('Verified: agreement signed');
        $this->assertNull($client->fresh()->agreement_signed_at);

        $page->call('editNotes')->set('notes', 'Prefers WhatsApp')->call('saveNotes');
        $this->assertSame('Prefers WhatsApp', $client->fresh()->notes);
    }

    public function test_payments_are_recorded_with_a_type_date_and_method(): void
    {
        $this->actingAs(User::factory()->create());
        $client = Client::factory()->create(['service_type' => 'website', 'price' => 150000, 'billing_months' => null]);

        $page = Livewire::test('pages::client-profile', ['client' => $client])
            ->call('newPayment')
            ->assertSet('payType', 'advance')
            ->set('payType', 'nope')
            ->call('savePayment')
            ->assertHasErrors(['payType'])
            ->set('payType', 'advance')
            ->set('payAmount', '75000')
            ->set('payPaid', true)
            ->set('payDue', '2026-09-20')
            ->set('payMethod', 'eSewa')
            ->set('payReference', 'TXN-88')
            ->call('savePayment')
            ->assertHasNoErrors();

        $advance = $client->payments()->sole();
        $this->assertSame('advance', $advance->type);
        $this->assertSame('2026-09-20', $advance->paid_at->toDateString());
        $this->assertSame('eSewa', $advance->method);
        $this->assertSame('paid', $advance->status());

        // A payment still to come keeps no method and shows as pending.
        $page->call('newPayment')
            ->assertSet('payType', 'partial')
            ->set('payType', 'final')
            ->set('payAmount', '75000')
            ->set('payDue', today()->addWeeks(2)->toDateString())
            ->set('payMethod', 'Cash')
            ->call('savePayment')
            ->assertHasNoErrors();

        $final = $client->payments()->where('type', 'final')->sole();
        $this->assertNull($final->paid_at);
        $this->assertNull($final->method);
        $this->assertSame('pending', $final->status());
    }

    public function test_profile_tabs_and_next_up(): void
    {
        $this->actingAs(User::factory()->create());
        $client = Client::factory()->create();
        $first = $client->payments()->create(['amount' => 12000, 'due_date' => today()->addDays(2), 'note' => 'Advance (50%)']);
        $client->payments()->create(['amount' => 12000, 'due_date' => today()->addMonth()]);
        $client->tasks()->create(['title' => 'Design Facebook cover']);

        $page = Livewire::test('pages::client-profile', ['client' => $client])
            ->assertSet('tab', 'overview')
            ->assertSee('Scheduled work')
            ->assertSee('Design Facebook cover')
            ->call('togglePaid', $first->id);

        $this->assertNotNull($first->fresh()->paid_at);

        $page->call('setTab', 'payments')->assertSet('tab', 'payments')->assertSee('Still due')
            ->call('setTab', 'nope')->assertSet('tab', 'overview');
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

    public function test_work_calendar_adds_work_on_a_day_and_repeats_last_month(): void
    {
        $this->actingAs(User::factory()->create());
        $client = Client::factory()->create(['service_type' => 'social_media']);
        [$y, $m] = NepaliDate::fromAd(today());

        $page = Livewire::test('pages::client-profile', ['client' => $client])
            ->assertSee(NepaliDate::MONTHS[$m - 1].' '.$y)
            ->call('addWorkOn', NepaliDate::toAd($y, $m, 5)->toDateString())
            ->assertSet('taskType', 'post')
            ->set('taskType', 'reel')
            ->set('taskTitle', 'Dashain offer reel')
            ->call('addTask')
            ->assertHasNoErrors()
            ->assertSee('Dashain offer reel');

        $task = $client->tasks()->sole();
        $this->assertSame('reel', $task->type);
        $this->assertSame([$y, $m, 5], NepaliDate::fromAd($task->due_date));

        // Next month starts empty; repeating copies the reel onto the same BS day.
        $page->call('shiftMonth', 1);
        $this->assertCount(0, $page->instance()->calendar['tasks']);
        $page->call('repeatLastMonth');
        $this->assertCount(1, $page->instance()->calendar['tasks']);

        $copy = $client->tasks()->latest('id')->first();
        $this->assertNotSame($task->id, $copy->id);
        $this->assertSame(5, NepaliDate::fromAd($copy->due_date)[2]);
        $this->assertNull($copy->completed_at);

        $page->call('goToday')->assertSet('calMonth', $m);
    }

    public function test_tasks_tab_groups_and_filters_work(): void
    {
        $this->actingAs(User::factory()->create());
        $client = Client::factory()->create();
        $client->tasks()->create(['title' => 'Late banner', 'type' => 'design', 'due_date' => today()->subDays(3)]);
        $client->tasks()->create(['title' => 'Next reel', 'type' => 'reel', 'due_date' => today()->addDays(2)]);
        $client->tasks()->create(['title' => 'Finished post', 'type' => 'post', 'due_date' => today()->subDay(), 'completed_at' => now()]);

        Livewire::test('pages::client-profile', ['client' => $client])
            ->call('setTab', 'tasks')
            ->assertSee(['Late banner', 'Next reel', 'Finished post'])
            ->set('workFilter', 'late')
            ->assertSee('Late banner')
            ->assertDontSee('Next reel')
            ->set('workFilter', 'done')
            ->assertSee('Finished post')
            ->assertDontSee('Late banner')
            ->set('workFilter', 'all')
            ->set('workKind', 'reel')
            ->assertSee('Next reel')
            ->assertDontSee('Finished post');
    }
}
