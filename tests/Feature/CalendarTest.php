<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Employee;
use App\Models\Task;
use App\Models\User;
use App\Models\WorkType;
use App\Support\NepaliDate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class CalendarTest extends TestCase
{
    use RefreshDatabase;

    public function test_calendar_shows_only_client_tasks_and_tasks_can_be_added(): void
    {
        $this->get(route('calendar.index'))->assertRedirect(route('login'));
        $this->actingAs(User::factory()->create());

        $type = WorkType::firstOrFail()->slug;
        $client = Client::factory()->create(['name' => 'Sita Rai', 'company' => 'Everest Gym']);
        $client->payments()->create(['amount' => 15000, 'due_date' => today()]);
        $client->tasks()->create(['title' => 'Design Dashain posts', 'type' => $type, 'due_date' => today()]);
        Task::create(['title' => 'Office cleanup', 'type' => $type, 'due_date' => today()]); // no client
        $employee = Employee::factory()->create();

        $page = Livewire::test('pages::calendar')
            ->assertSee(['Design Dashain posts', 'Everest Gym'])
            ->assertDontSee(['Office cleanup', 'Payment due'])
            ->call('toggleType', $type)
            ->assertDontSee('Design Dashain posts')
            ->call('toggleType', $type)
            ->call('newTask', today()->addDays(2)->toDateString())
            ->call('saveTask')
            ->assertHasErrors(['task.client_id', 'task.title'])
            ->set('task.client_id', (string) $client->id)
            ->set('task.title', 'Shoot reel at the gym')
            ->set('task.priority', 'high')
            ->set('task.assignees', [(string) $employee->id])
            ->call('saveTask')
            ->assertHasNoErrors()
            ->assertSet('day', today()->addDays(2)->toDateString())
            ->assertSee('Shoot reel at the gym');

        $task = Task::where('title', 'Shoot reel at the gym')->sole();
        $this->assertSame([$client->id, 'high', 'todo', [$employee->id]], [$task->client_id, $task->priority, $task->status, $task->assignees->pluck('id')->all()]);
        $this->assertTrue($task->due_date->isSameDay(today()->addDays(2)));

        $page->call('toggleDone', $task->id);
        $this->assertNotNull($task->fresh()->completed_at);

        $page->call('confirmDelete', $task->id)->call('deleteTask');
        $this->assertModelMissing($task);

        [$y, $m] = NepaliDate::fromAd(today());
        Livewire::test('pages::calendar')->call('shift', 1)->assertSet('month', sprintf('%d-%02d', $m === 12 ? $y + 1 : $y, $m % 12 + 1));
    }
}
