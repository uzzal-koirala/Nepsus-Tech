<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Employee;
use App\Models\WorkType;
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
            ->set('payMethod', 'Bank')
            ->set('payDue', today()->subDays(3)->toDateString())
            ->call('savePayment')
            ->assertHasNoErrors();

        $payment = $client->payments()->sole();
        $this->assertSame('paid', $payment->status());
        $page->call('togglePaid', $payment->id);
        $this->assertSame('overdue', $payment->fresh()->status());

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
            ->assertSet('payType', 'Advance payment')
            ->set('payType', '')
            ->call('savePayment')
            ->assertHasErrors(['payType'])
            ->set('payType', 'Advance payment')
            ->set('payAmount', '75000')
            ->set('payDue', '2026-09-20')
            ->set('payMethod', 'Card')
            ->call('savePayment')
            ->assertHasErrors(['payMethod'])
            ->set('payMethod', 'eSewa')
            ->set('payReference', 'TXN-88')
            ->call('savePayment')
            ->assertHasNoErrors();

        $advance = $client->payments()->sole();
        $this->assertSame('Advance payment', $advance->typeLabel());
        $this->assertSame('2026-09-20', $advance->paid_at->toDateString());
        $this->assertSame('eSewa', $advance->method);
        $this->assertSame('paid', $advance->status());

        // A custom type is kept as typed.
        $page->call('newPayment')
            ->assertSet('payType', 'Partial payment')
            ->set('payType', 'Final payment after launch')
            ->set('payAmount', '75000')
            ->set('payMethod', 'Cheque')
            ->call('savePayment')
            ->assertHasNoErrors();

        $final = $client->payments()->where('type', 'Final payment after launch')->sole();
        $this->assertSame('Final payment after launch', $final->typeLabel());
        $this->assertSame('Cheque', $final->method);
        $this->assertSame('paid', $final->status());
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
            ->assertSeeInOrder(['To do', 'Late banner', 'Next reel', 'Completed work', 'Finished post'])
            ->set('workKind', 'reel')
            ->assertSee('Next reel')
            ->assertDontSee('Finished post');
    }

    public function test_tasks_can_be_edited_moved_and_prioritised(): void
    {
        $this->actingAs(User::factory()->create());
        $client = Client::factory()->create();

        $page = Livewire::test('pages::client-profile', ['client' => $client])
            ->call('newTask', 'in_progress')
            ->assertSet('taskStatus', 'in_progress')
            ->set('taskTitle', 'Employee details page')
            ->set('taskDescription', 'Create a page with employee info')
            ->set('taskStart', '2026-10-01')
            ->set('taskDue', '2026-09-01')
            ->call('addTask')
            ->assertHasErrors(['taskDue'])
            ->set('taskDue', '2026-10-20')
            ->set('taskPriority', 'high')
            ->call('addTask')
            ->assertHasNoErrors();

        $task = $client->tasks()->sole();
        $this->assertSame(['in_progress', 'high', 'Create a page with employee info'], [$task->status, $task->priority, $task->description]);
        $this->assertSame('2026-10-01', $task->start_date->toDateString());

        $page->call('editTask', $task->id)
            ->assertSet('taskTitle', 'Employee details page')
            ->set('taskTitle', 'Employee details')
            ->call('addTask')
            ->assertHasNoErrors();
        $this->assertSame('Employee details', $task->fresh()->title);
        $this->assertCount(1, $client->tasks()->get());

        $page->call('setTaskStatus', $task->id, 'done');
        $this->assertNotNull($task->fresh()->completed_at);
        $page->call('setTaskStatus', $task->id, 'review');
        $this->assertSame('review', $task->fresh()->stage());

        $page->call('setTaskPriority', $task->id, 'urgent');
        $this->assertSame('urgent', $task->fresh()->priority);
    }

    public function test_work_types_can_be_added_and_deleted_and_people_assigned(): void
    {
        $this->actingAs(User::factory()->create());
        $client = Client::factory()->create();
        $bina = Employee::factory()->create(['name' => 'Bina Rai', 'status' => 'active']);
        $kiran = Employee::factory()->create(['name' => 'Kiran Shrestha', 'status' => 'active']);

        $page = Livewire::test('pages::client-profile', ['client' => $client])
            ->call('newTask')
            ->set('newTypeName', 'Blog article')
            ->call('addWorkType')
            ->assertSet('taskType', 'blog-article')
            ->set('taskTitle', 'Write Dashain blog')
            ->set('taskAssignees', [$bina->id, $kiran->id])
            ->call('addTask')
            ->assertHasNoErrors()
            ->call('setTab', 'tasks')
            ->assertSee(['Blog article', 'Write Dashain blog', 'BR']);

        $task = $client->tasks()->sole();
        $this->assertSame('blog-article', $task->type);
        $this->assertEqualsCanonicalizing([$bina->id, $kiran->id], $task->assignees->pluck('id')->all());

        $page->call('editTask', $task->id)
            ->assertSet('taskAssignees', [$bina->id, $kiran->id])
            ->set('taskAssignees', [$kiran->id])
            ->call('addTask');
        $this->assertSame([$kiran->id], $task->fresh()->assignees->pluck('id')->all());

        $page->call('deleteWorkType', 'blog-article');
        $this->assertNull(WorkType::firstWhere('slug', 'blog-article'));
        $this->assertSame('blog-article', $task->fresh()->type); // the task keeps its name
        $page->call('setTab', 'tasks')->assertSee('Blog Article');
    }

    public function test_a_task_can_be_opened_changed_and_closed(): void
    {
        $this->actingAs(User::factory()->create());
        $client = Client::factory()->create();
        $task = $client->tasks()->create(['title' => 'Logo animation', 'description' => 'Short intro for reels', 'type' => 'video']);

        $page = Livewire::test('pages::client-profile', ['client' => $client])
            ->call('setTab', 'tasks')
            ->call('openTask', $task->id)
            ->assertSet('viewTaskId', $task->id)
            ->assertSee('Short intro for reels')
            ->call('setTaskStatus', $task->id, 'review');
        $this->assertSame('review', $task->fresh()->stage());

        $page->call('toggleTask', $task->id);
        $this->assertNotNull($task->fresh()->completed_at);

        $page->call('deleteTask', $task->id)->assertSet('viewTaskId', null);
        $this->assertModelMissing($task);
    }
}
