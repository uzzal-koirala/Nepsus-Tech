<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class MyTasksListViewTest extends TestCase
{
    use RefreshDatabase;

    public function test_creators_and_editors_switch_my_tasks_to_a_list(): void
    {
        foreach (['content-creator', 'video-editor'] as $dept) {
            $user = User::factory()->staff()->create();
            $e = Employee::factory()->create(['department' => $dept, 'status' => 'active']);
            Employee::whereKey($e->id)->update(['user_id' => $user->id]);
            $t = Task::factory()->create(['title' => "List task {$dept}", 'priority' => 'high', 'status' => 'todo', 'completed_at' => null]);
            $t->assignees()->sync([$e->id]);
            $this->actingAs($user);

            // List by default, Board one click away.
            Livewire::test('pages::my-tasks')->assertSet('view', 'list')->assertSeeHtml(['data-test="task-list"', 'data-test="row-'.$t->id.'"'])->assertDontSeeHtml('data-test="priority-boxes"')
                ->assertSee(["List task {$dept}", 'Assigned to', 'You', 'Any priority', 'All clients'])
                ->set('view', 'board')->assertSeeHtml('data-test="priority-boxes"')->set('view', 'list')
                // Filters: priority, and "No client".
                ->set('priority', 'low')->assertDontSee("List task {$dept}")->set('priority', 'high')->assertSee("List task {$dept}")
                ->set('client', 'none')->assertSee("List task {$dept}")
                ->call('setStatus', $t->id, 'done');
            $this->assertNotNull($t->fresh()->completed_at);
        }

        // Shared tasks name the others.
        $niksan = Employee::factory()->create(['name' => 'Niksan Adhikari', 'status' => 'active']);
        $ram = Employee::factory()->create(['name' => 'Ram Thapa', 'status' => 'active']);
        $shared = Task::factory()->create(['title' => 'Shared task', 'status' => 'todo', 'completed_at' => null, 'client_id' => null]);
        $shared->assignees()->sync([$e->id, $niksan->id]);
        Livewire::test('pages::my-tasks')->assertSee('You and Niksan');
        $shared->assignees()->sync([$e->id, $niksan->id, $ram->id]);
        Livewire::test('pages::my-tasks')->assertSee('You + 2 others');
    }
}
