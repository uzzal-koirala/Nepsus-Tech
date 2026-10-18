<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Employee;
use App\Models\Task;
use App\Models\User;
use App\Models\WorkType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class TasksTest extends TestCase
{
    use RefreshDatabase;

    public function test_tasks_are_created_assigned_moved_and_filtered(): void
    {
        $this->get(route('tasks.index'))->assertRedirect(route('login'));
        $this->actingAs(User::factory()->create());
        $this->get(route('tasks.index'))->assertOk()->assertSee(['Tasks', 'No tasks yet'])->assertDontSee(['Projects', 'Activity log']);

        $type = WorkType::firstOrFail()->slug;
        $client = Client::factory()->create(['company' => 'Everest Gym']);
        [$asha, $bikash] = Employee::factory()->count(2)->create();

        // Internal task without a client or date, picked person is pre-assigned.
        $page = Livewire::test('pages::tasks')
            ->set('person', (string) $asha->id)
            ->call('newTaskFor', 'in_progress')
            ->assertSet('task.status', 'in_progress')
            ->assertSet('task.assignees', [(string) $asha->id])
            ->call('saveTask')
            ->assertHasErrors(['task.title'])
            ->set('task.title', 'Update office wifi')
            ->call('saveTask')
            ->assertHasNoErrors();

        $page->set('person', '')
            ->call('newTaskFor', 'todo')
            ->set('task.title', 'Shoot gym reel')
            ->set('task.client_id', (string) $client->id)
            ->set('task.type', $type)
            ->set('task.priority', 'urgent')
            ->set('task.due_date', today()->toDateString())
            ->set('task.assignees', [(string) $bikash->id])
            ->call('saveTask')
            ->assertHasNoErrors()
            ->assertSee(['Update office wifi', 'Shoot gym reel', 'Everest Gym']);

        $wifi = Task::where('title', 'Update office wifi')->sole();
        $reel = Task::where('title', 'Shoot gym reel')->sole();
        $this->assertSame([null, 'in_progress', [$asha->id]], [$wifi->client_id, $wifi->status, $wifi->assignees->pluck('id')->all()]);
        $this->assertSame([$client->id, 'urgent', [$bikash->id]], [$reel->client_id, $reel->priority, $reel->assignees->pluck('id')->all()]);

        // Filters
        $page->set('person', (string) $bikash->id)->assertSee('Shoot gym reel')->assertDontSee('Update office wifi')
            ->set('person', '')->set('clientFilter', 'none')->assertSee('Update office wifi')->assertDontSee('Shoot gym reel')
            ->set('clientFilter', '')->set('search', 'wifi')->assertDontSee('Shoot gym reel')
            ->call('clearFilters')->set('view', 'list')->assertSee(['Shoot gym reel', 'Update office wifi']);

        // Move to done, then delete.
        $page->call('moveTask', $reel->id, 'done');
        $this->assertNotNull($reel->fresh()->completed_at);
        $page->call('confirmDelete', $wifi->id)->call('deleteTask');
        $this->assertModelMissing($wifi);
    }
}
