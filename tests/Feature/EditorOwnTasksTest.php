<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Employee;
use App\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class EditorOwnTasksTest extends TestCase
{
    use RefreshDatabase;

    private function login(string $department): array
    {
        $user = User::factory()->staff()->create();
        $e = Employee::factory()->create(['department' => $department, 'status' => 'active']);
        Employee::whereKey($e->id)->update(['user_id' => $user->id]);

        return [$user, $e];
    }

    public function test_a_video_editor_adds_tasks_for_themselves_only_and_sees_the_warning(): void
    {
        [$user, $me] = $this->login('video-editor');
        $other = Employee::factory()->create(['status' => 'active']);
        $client = Client::factory()->create(['status' => 'active']);
        $this->actingAs($user);

        Livewire::test('pages::my-tasks')->call('newTask')->assertSee('salary deduction')
            ->set('fTitle', 'Colour grade reel')->set('fAssignees', [$me->id, $other->id])->call('saveTask')->assertHasNoErrors();
        Livewire::test('pages::calendar')->call('newTask', today()->toDateString())->assertSee('salary deduction')
            ->set('task.title', 'Cut teaser')->set('task.client_id', (string) $client->id)->set('task.assignees', [(string) $other->id])
            ->call('saveTask')->assertHasNoErrors();

        foreach (Task::with('assignees')->get() as $t) {
            $this->assertSame([$me->id], $t->assignees->pluck('id')->all(), $t->title);
        }

        // A task given to them keeps its people when they edit it.
        $given = Task::factory()->create(['title' => 'Given task']);
        $given->assignees()->sync([$me->id, $other->id]);
        Livewire::test('pages::my-tasks')->call('editTask', $given->id)->assertDontSee('salary deduction')
            ->set('fTitle', 'Given task, renamed')->set('fAssignees', [$me->id])->call('saveTask')->assertHasNoErrors();
        $this->assertEqualsCanonicalizing([$me->id, $other->id], $given->fresh()->assignees->pluck('id')->all());
    }

    public function test_content_creators_still_assign_anyone_without_the_warning(): void
    {
        [$user] = $this->login('content-creator');
        $other = Employee::factory()->create(['status' => 'active']);
        $this->actingAs($user);

        Livewire::test('pages::calendar')->call('newTask', today()->toDateString())->assertDontSee('salary deduction')
            ->set('task.title', 'Shoot')->set('task.client_id', (string) Client::factory()->create()->id)->set('task.assignees', [(string) $other->id])
            ->call('saveTask')->assertHasNoErrors();
        $this->assertSame([$other->id], Task::sole()->assignees->pluck('id')->all());
    }
}
