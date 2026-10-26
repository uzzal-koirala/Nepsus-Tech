<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Employee;
use App\Models\Script;
use App\Models\Task;
use App\Models\User;
use App\Models\Video;
use App\Models\WorkType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class StaffAccessTest extends TestCase
{
    use RefreshDatabase;

    public function test_employee_logins_get_their_own_area_without_money(): void
    {
        $type = WorkType::first()?->slug ?? WorkType::create(['slug' => 'post', 'label' => 'Post', 'icon' => 'photo', 'color' => 'sky'])->slug;
        $client = Client::factory()->create(['company' => 'Momo House', 'status' => 'active', 'price' => 45000]);
        $user = User::factory()->staff()->create(['name' => 'Sita Rai']);
        $sita = Employee::factory()->create(['name' => 'Sita Rai', 'department' => 'content', 'status' => 'active']);
        Employee::whereKey($sita->id)->update(['user_id' => $user->id]);
        $other = Employee::factory()->create(['name' => 'Ram', 'status' => 'active']);

        $mine = Task::create(['title' => 'Write Dashain caption', 'type' => $type, 'priority' => 'high', 'status' => 'todo', 'due_date' => today()]);
        $mine->client_id = $client->id; $mine->save();
        $mine->assignees()->sync([$sita->id]);
        $theirs = Task::create(['title' => 'Fix the website', 'type' => $type, 'priority' => 'medium', 'status' => 'todo', 'due_date' => today()]);
        $theirs->client_id = $client->id; $theirs->save();
        $theirs->assignees()->sync([$other->id]);
        Script::create(['title' => 'Momo reel', 'client_id' => $client->id, 'type' => 'reel', 'status' => 'draft', 'shoot_date' => today()->addDay()]);
        Video::create(['title' => 'Kitchen clips', 'url' => 'https://drive.google.com/file/d/x/view', 'client_id' => $client->id, 'status' => 'raw']);

        $this->actingAs($user);

        // Admin pages send them to their own version, or to their Dashboard.
        $this->get(route('dashboard'))->assertRedirect(route('my.dashboard'));
        $this->get(route('clients.index'))->assertRedirect(route('my.clients'));
        $this->get(route('tasks.index'))->assertRedirect(route('my.tasks'));
        foreach (['finance.index', 'packages.index', 'employees.index', 'invoices.index', 'sales.index', 'library.index', 'settings.business', 'settings.ai-connections'] as $page) {
            $this->get(route($page))->assertRedirect(route('my.dashboard'));
        }
        $this->get('/settings')->assertRedirect(route('profile.edit'));

        // Dashboard: their task, shoots and footage; never someone else's task.
        $this->get(route('my.dashboard'))->assertOk()
            ->assertSee(['Sita', 'Write Dashain caption', 'Dashboard', 'My tasks'])
            ->assertDontSee(['Fix the website', 'Finance', 'Invoices'])
            ->assertDontSee('pages::ai-widget');
        Livewire::test('pages::my-dashboard')->call('toggleDone', $mine->id);
        $this->assertNotNull($mine->fresh()->completed_at);
        Livewire::test('pages::my-dashboard')->call('toggleDone', $theirs->id)->assertNotFound();

        // My tasks: only the tasks assigned to them.
        Livewire::test('pages::my-tasks')->assertDontSee('Fix the website')
            ->set('tab', 'done')->assertSee(['Write Dashain caption', 'Momo House'])
            ->assertSee(['Urgent', 'High', 'Medium', 'Low', 'Write Dashain caption'])
            ->call('newTask', 'urgent')->assertSet('fPriority', 'urgent')
            ->call('setStatus', $mine->id, 'in_progress')->set('tab', 'today')->assertSee('Write Dashain caption')
            ->call('setStatus', $theirs->id, 'done')->assertNotFound();
        $this->assertSame('in_progress', $mine->fresh()->status);
        Livewire::test('pages::my-tasks')->call('setPriority', $mine->id, 'low');
        $this->assertSame('low', $mine->fresh()->priority);
        Livewire::test('pages::my-tasks')->call('editTask', $theirs->id)->assertNotFound();

        // Adding work always keeps them on it.
        Livewire::test('pages::my-tasks')->call('newTask')->set('fTitle', 'Tidy the drive')->set('fClient', '')->set('fAssignees', [])->call('saveTask')->assertHasNoErrors();
        $this->assertTrue(Task::where('title', 'Tidy the drive')->first()->assignees->contains($sita));
        Livewire::test('pages::my-tasks')->call('editTask', $mine->id)->set('fPriority', 'urgent')->set('fAssignees', [$other->id])->call('saveTask')->assertHasNoErrors();
        $this->assertSame('urgent', $mine->fresh()->priority);
        $this->assertTrue($mine->fresh()->assignees->contains($sita));

        // Clients: the work, no money.
        $this->get(route('my.clients'))->assertOk()->assertSee(['Momo House', 'for you'])->assertDontSee('45,000');

        // Attendance: only their own days, read only.
        [$day] = \App\Support\NepaliDate::monthRange(today());
        while ($day->isHoliday()) {
            $day = $day->copy()->addDay();
        }
        $sita->attendances()->create(['date' => $day, 'status' => 'present', 'check_in' => '09:05', 'check_out' => '18:02', 'note' => 'Shoot at Thamel']);
        $other->attendances()->create(['date' => $day, 'status' => 'absent', 'note' => 'Ram was sick']);
        $this->get(route('my.attendance'))->assertOk()->assertSee(['My attendance', 'Shoot at Thamel', '9:05 AM', '8h 57m'])->assertDontSee('Ram was sick');
        $client->payments()->create(['amount' => 7700, 'due_date' => today()]);
        $this->get(route('clients.show', $client))->assertOk()
            ->assertSee(['Momo House', 'Write Dashain caption', 'Scripts', 'Videos', 'Add work', 'Notes'])
            ->assertDontSee(['45,000', '7,700', 'Outstanding', 'Record payment', 'Price not set', 'payment-form', 'Repeat last month']);
        foreach (['newPayment', 'editAgreement', 'newDocument', 'editNotes', 'repeatLastMonth'] as $action) {
            Livewire::test('pages::client-profile', ['client' => $client])->call($action)->assertForbidden();
        }
        Livewire::test('pages::client-profile', ['client' => $client])->call('setTab', 'payments')->assertSet('tab', 'overview');
        Livewire::test('pages::client-profile', ['client' => $client])->call('toggleTask', $theirs->id)->assertOk();
        Livewire::test('pages::client-profile', ['client' => $client])->call('toggleTask', $mine->id)->assertOk();
        Livewire::test('pages::client-profile', ['client' => $client])->call('openTask', $mine->id)->call('editTask', $mine->id)->assertSet('taskId', $mine->id)->assertSet('taskTitle', 'Write Dashain caption')->set('taskTitle', 'Write Tihar caption')->call('addTask')->assertHasNoErrors();
        $this->assertSame('Write Tihar caption', $mine->fresh()->title);
        // Any task of the client can be edited; editing does not put them on it, and only admins delete.
        Livewire::test('pages::client-profile', ['client' => $client])->call('openTask', $theirs->id)->call('editTask', $theirs->id)->set('taskTitle', 'Fix the site menu')->call('addTask')->assertHasNoErrors();
        $this->assertSame('Fix the site menu', $theirs->fresh()->title);
        $this->assertFalse($theirs->fresh()->assignees->contains($sita));
        Livewire::test('pages::client-profile', ['client' => $client])->call('deleteTask', $theirs->id)->assertForbidden();

        // Calendar: their own tasks only; they cannot delete tasks.
        $this->get(route('calendar.index'))->assertOk()->assertSee('Write Tihar caption')->assertDontSee('Fix the website');
        Livewire::test('pages::calendar')->call('editTask', $theirs->id)->assertNotFound();
        Livewire::test('pages::calendar')->call('confirmDelete', $mine->id)->assertForbidden();

        // Scripts are for Content Creators; videos for admins only.
        $this->get(route('scripts.index'))->assertRedirect(route('my.dashboard'));
        $this->get(route('videos.index'))->assertRedirect(route('my.dashboard'));
        Livewire::test('pages::my-dashboard')->call('newScript')->assertForbidden();
        Livewire::test('pages::ai-widget')->assertForbidden();

        // A Content Creator: scripts and every client's work on the calendar, including deleting it. Still no videos.
        $sita->update(['department' => 'content-creator']);
        $user->unsetRelation('employee');
        $this->get(route('scripts.index'))->assertOk();
        $this->get(route('videos.index'))->assertRedirect(route('my.dashboard'));
        $this->get(route('my.dashboard'))->assertOk()->assertSee('New script')->assertDontSee('Add video');
        $this->get(route('calendar.index'))->assertOk()->assertSee(['Write Tihar caption', 'Fix the site menu']);
        Livewire::test('pages::calendar')->call('editTask', $theirs->id)->assertOk();
        Livewire::test('pages::calendar')->call('confirmDelete', $theirs->id)->call('deleteTask')->assertOk();
        $this->assertNull($theirs->fresh());
        Livewire::test('pages::my-dashboard')->call('newScript')->assertRedirect();

        // Admins are unchanged.
        $this->actingAs(User::factory()->create());
        $this->get(route('dashboard'))->assertOk();
        $this->get(route('finance.index'))->assertOk();
    }
}
