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

        // Admin pages send them to their own version, or to My day.
        $this->get(route('dashboard'))->assertRedirect(route('my.dashboard'));
        $this->get(route('clients.index'))->assertRedirect(route('my.clients'));
        $this->get(route('clients.show', $client))->assertRedirect(route('my.clients.show', $client));
        $this->get(route('tasks.index'))->assertRedirect(route('my.tasks'));
        foreach (['finance.index', 'packages.index', 'employees.index', 'invoices.index', 'sales.index', 'library.index', 'settings.business', 'settings.ai-connections'] as $page) {
            $this->get(route($page))->assertRedirect(route('my.dashboard'));
        }
        $this->get('/settings')->assertRedirect(route('profile.edit'));

        // My day: their task, shoots and footage; never someone else's task.
        $this->get(route('my.dashboard'))->assertOk()
            ->assertSee(['Sita', 'Write Dashain caption', 'Momo reel', 'Kitchen clips', 'My day', 'My tasks'])
            ->assertDontSee(['Fix the website', 'Finance', 'Invoices'])
            ->assertDontSee('pages::ai-widget');
        Livewire::test('pages::my-dashboard')->call('toggleDone', $mine->id);
        $this->assertNotNull($mine->fresh()->completed_at);
        Livewire::test('pages::my-dashboard')->call('toggleDone', $theirs->id)->assertNotFound();

        Livewire::test('pages::my-tasks')->assertSee('Done')->set('show', 'done')->assertSee('Write Dashain caption')
            ->call('setStatus', $mine->id, 'in_progress')->call('setStatus', $theirs->id, 'done')->assertNotFound();
        $this->assertSame('in_progress', $mine->fresh()->status);

        // Clients: the work, no money.
        $this->get(route('my.clients'))->assertOk()->assertSee(['Momo House', 'for you'])->assertDontSee('45,000');
        $this->get(route('my.clients.show', $client))->assertOk()
            ->assertSee(['Momo House', 'Write Dashain caption', 'Fix the website', 'Momo reel', 'Kitchen clips'])
            ->assertDontSee(['45,000', 'Payments', 'Outstanding']);

        // Calendar: their own tasks only; they cannot delete tasks.
        $this->get(route('calendar.index'))->assertOk()->assertSee('Write Dashain caption')->assertDontSee('Fix the website');
        Livewire::test('pages::calendar')->call('editTask', $theirs->id)->assertNotFound();
        Livewire::test('pages::calendar')->call('confirmDelete', $mine->id)->assertForbidden();

        $this->get(route('scripts.index'))->assertOk();
        $this->get(route('videos.index'))->assertOk();
        Livewire::test('pages::ai-widget')->assertForbidden();

        // Admins are unchanged.
        $this->actingAs(User::factory()->create());
        $this->get(route('dashboard'))->assertOk();
        $this->get(route('finance.index'))->assertOk();
    }
}
