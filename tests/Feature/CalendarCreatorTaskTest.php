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

class CalendarCreatorTaskTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_content_creator_adds_a_client_task_on_the_calendar(): void
    {
        $client = Client::factory()->create(['company' => 'Narayani Hardware', 'status' => 'active']);
        $user = User::factory()->staff()->create();
        $e = Employee::factory()->create(['department' => 'content-creator', 'status' => 'active']);
        Employee::whereKey($e->id)->update(['user_id' => $user->id]);
        $this->actingAs($user);
        $type = WorkType::first()->slug;

        // The form's dropdowns bind to these the moment the page loads; an empty task meant a picked client was never sent.
        Livewire::test('pages::calendar')->assertSet('task.client_id', '')->assertSet('task.type', array_key_first(Task::types()))->assertSet('task.priority', 'medium');
        $this->actingAs(User::factory()->create());
        Livewire::test('pages::tasks')->assertSet('task.client_id', '')->assertSet('task.status', 'todo');
        $this->actingAs($user);

        Livewire::test('pages::calendar')->call('newTask', today()->toDateString())
            ->set('task.title', 'Cycling video 01 shoot')->set('task.client_id', (string) $client->id)->set('task.type', $type)
            ->call('saveTask')->assertHasNoErrors();
        $this->assertSame($client->id, Task::sole()->client_id);
    }
}
