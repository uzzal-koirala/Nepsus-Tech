<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Employee;
use App\Models\Script;
use App\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class ScriptShootTaskTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_shoot_date_shows_only_after_approval_and_then_becomes_the_writers_shoot_task(): void
    {
        $client = Client::factory()->create(['status' => 'active']);
        $writer = User::factory()->staff()->create();
        $e = Employee::factory()->create(['department' => 'content-creator', 'status' => 'active']);
        Employee::whereKey($e->id)->update(['user_id' => $writer->id]);
        $script = Script::create(['title' => 'Momo reel', 'client_id' => $client->id, 'type' => 'reel', 'status' => 'draft', 'body' => 'Hook', 'user_id' => $writer->id]);
        $day = today()->addDays(4)->toDateString();

        // Before approval the writer can pick the date, but it stays off the calendar and makes no task.
        $this->actingAs($writer);
        Livewire::test('pages::script-editor', ['script' => $script])->set('shootDate', $day)->assertSee('On the calendar once approved');
        $this->assertSame($day, $script->fresh()->shoot_date->toDateString());
        Livewire::test('pages::calendar')->assertDontSee('Shoot: Momo reel');
        $this->assertSame(0, Task::count());

        // Approved: the date is kept, the calendar shows the shoot and the writer gets a video shoot task.
        $script->update(['status' => 'approved', 'shoot_date' => null]);
        Livewire::test('pages::script-editor', ['script' => $script->fresh()])->set('shootDate', $day)->assertDontSee('On the calendar once approved');
        $task = Task::sole();
        $this->assertSame(['Shoot: Momo reel', 'video', $day, $client->id, [$e->id]], [$task->title, $task->type, $task->due_date->toDateString(), $task->client_id, $task->assignees->pluck('id')->all()]);
        Livewire::test('pages::calendar')->assertSee('Shoot: Momo reel');
        Livewire::test('pages::my-tasks')->assertSee('Shoot: Momo reel');

        // A new date moves it, shooting ticks it off, clearing the date removes it.
        $later = today()->addDays(6)->toDateString();
        $script->fresh()->update(['shoot_date' => $later]);
        $this->assertSame($later, Task::sole()->due_date->toDateString());
        $script->fresh()->update(['status' => 'shot']);
        $this->assertNotNull(Task::sole()->completed_at);
        // Ticking the task off in My tasks completes the script; unticking takes it back to Approved.
        $script->fresh()->update(['status' => 'approved']);
        $id = Task::sole()->id;
        Livewire::test('pages::my-tasks')->call('setStatus', $id, 'done');
        $this->assertSame('shot', $script->fresh()->status);
        Livewire::test('pages::my-tasks')->call('setStatus', $id, 'todo');
        $this->assertSame('approved', $script->fresh()->status);
        Livewire::test('pages::calendar')->call('toggleDone', $id);
        $this->assertSame('shot', $script->fresh()->status);

        $script->fresh()->update(['status' => 'approved', 'shoot_date' => null]);
        $this->assertSame(0, Task::count());
    }
}
