<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Employee;
use App\Models\Task;
use App\Models\TaskGroup;
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
        $this->get(route('tasks.index'))->assertOk()->assertSee(['Tasks', 'No boxes yet'])->assertDontSee(['Projects', 'Activity log']);

        $type = WorkType::firstOrFail()->slug;
        $client = Client::factory()->create(['company' => 'Everest Gym']);
        [$asha, $bikash] = Employee::factory()->count(2)->create();

        // Internal task without a client or date.
        $page = Livewire::test('pages::tasks')
            ->set('view', 'board')
            ->call('newTaskFor', 'in_progress')
            ->assertSet('task.status', 'in_progress')
            ->call('saveTask')
            ->assertHasErrors(['task.title'])
            ->set('task.title', 'Update office wifi')
            ->set('task.assignees', [(string) $asha->id])
            ->call('saveTask')
            ->assertHasNoErrors();

        $page->call('newTaskFor', 'todo')
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
        $page->set('clientFilter', 'none')->assertSee('Update office wifi')->assertDontSee('Shoot gym reel')
            ->set('clientFilter', '')->set('search', 'wifi')->assertDontSee('Shoot gym reel')
            ->call('clearFilters')->set('view', 'list')->assertSee(['Shoot gym reel', 'Update office wifi']);

        // Move to done, then delete.
        $page->call('moveTask', $reel->id, 'done');
        $this->assertNotNull($reel->fresh()->completed_at);
        $page->call('confirmDelete', $wifi->id)->call('deleteTask');
        $this->assertModelMissing($wifi);
    }

    public function test_boxes_group_tasks_and_show_progress_and_people(): void
    {
        $this->actingAs(User::factory()->create());
        $client = Client::factory()->create(['company' => 'Everest Gym']);
        $asha = Employee::factory()->create(['name' => 'Asha Gurung']);

        $page = Livewire::test('pages::tasks')
            ->assertSet('view', 'boxes')
            ->call('newBox')
            ->call('saveBox')
            ->assertHasErrors(['box.name'])
            ->set('box.name', 'Dashain campaign')
            ->set('box.client_id', (string) $client->id)
            ->set('box.priority', 'high')
            ->set('box.due_date', today()->addDays(5)->toDateString())
            ->call('saveBox')
            ->assertHasNoErrors();

        $box = TaskGroup::sole();
        $this->assertSame(['Dashain campaign', $client->id, 'high'], [$box->name, $box->client_id, $box->priority]);
        $this->assertTrue($box->due_date->isSameDay(today()->addDays(5)));

        // Quick add and a detailed task with a person.
        $page->call('openQuick', $box->id)
            ->call('saveQuick')->assertHasErrors(['quick.title'])
            ->set('quick.title', 'Write captions')
            ->set('quick.assignees', [(string) $asha->id])
            ->call('saveQuick')->assertHasNoErrors()
            ->call('newTaskIn', $box->id)
            ->assertSet('task.task_group_id', (string) $box->id)
            ->set('task.title', 'Shoot greeting video')
            ->set('task.assignees', [(string) $asha->id])
            ->call('saveTask')
            ->assertHasNoErrors();

        $this->assertSame(2, $box->tasks()->count());
        $this->assertSame([$client->id], $box->tasks()->pluck('client_id')->unique()->values()->all()); // tasks follow the box's client

        $first = $box->tasks()->where('title', 'Write captions')->sole();
        $this->assertSame([$asha->id], $first->assignees->pluck('id')->all());
        $page->call('toggleDone', $first->id)
            ->assertSee(['Dashain campaign', 'Everest Gym', '1 / 2 done', 'Asha Gurung', 'Shoot greeting video']);

        $page->call('confirmDeleteBox', $box->id)->call('deleteBox');
        $this->assertModelMissing($box);
        $this->assertSame(0, Task::count());
    }
}
