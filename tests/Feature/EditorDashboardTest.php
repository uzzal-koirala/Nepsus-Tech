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

class EditorDashboardTest extends TestCase
{
    use RefreshDatabase;

    private function login(string $department): array
    {
        $user = User::factory()->staff()->create(['name' => 'Bikash Thapa']);
        $e = Employee::factory()->create(['name' => 'Bikash Thapa', 'department' => $department, 'status' => 'active']);
        Employee::whereKey($e->id)->update(['user_id' => $user->id]);

        return [$user, $e];
    }

    public function test_video_editors_get_an_editing_dashboard_and_the_videos_page(): void
    {
        $client = Client::factory()->create(['company' => 'Momo House', 'status' => 'active']);
        $raw = Video::create(['title' => 'Kitchen clips', 'url' => 'https://drive.google.com/file/d/x/view', 'client_id' => $client->id, 'status' => 'raw']);
        $editing = Video::create(['title' => 'Chef interview', 'url' => 'https://drive.google.com/file/d/y/view', 'client_id' => $client->id, 'status' => 'editing']);
        Video::create(['title' => 'Old promo', 'url' => 'https://drive.google.com/file/d/z/view', 'status' => 'done']);
        Script::create(['title' => 'Momo reel', 'client_id' => $client->id, 'type' => 'reel', 'status' => 'draft', 'shoot_date' => today()->addDays(2)]);
        [$user, $bikash] = $this->login('video-editor');
        $task = Task::create(['title' => 'Cut the Tihar reel', 'type' => WorkType::first()?->slug ?? 'task', 'priority' => 'high', 'status' => 'todo', 'due_date' => today()]);
        $task->assignees()->sync([$bikash->id]);
        $this->actingAs($user);

        $this->assertTrue($user->isVideoEditor());
        $this->get(route('my.dashboard'))->assertOk()
            ->assertSee(['Footage queue', 'Kitchen clips', 'Chef interview', 'Start editing', 'Mark edited', 'Cut the Tihar reel', 'Shoots coming up', 'Momo reel', 'Recently edited', 'Old promo', 'Waiting to edit'])
            ->assertDontSee(['Tasks finished and tasks due', 'Recently finished']);
        $this->get(route('videos.index'))->assertOk();

        // raw -> editing -> done, from the dashboard.
        Livewire::test('pages::editor-dashboard')->call('moveVideo', $raw->id, 'editing')->call('moveVideo', $editing->id, 'done');
        $this->assertSame(['editing', 'done'], [$raw->fresh()->status, $editing->fresh()->status]);
        Livewire::test('pages::editor-dashboard')->call('moveVideo', $raw->id, 'published')->assertForbidden();

        Livewire::test('pages::editor-dashboard')->call('toggleDone', $task->id);
        $this->assertNotNull($task->fresh()->completed_at);
    }

    public function test_other_employees_keep_the_normal_dashboard_and_no_videos(): void
    {
        [$user] = $this->login('content');
        $this->actingAs($user);

        $this->assertFalse($user->isVideoEditor());
        $this->get(route('my.dashboard'))->assertOk()->assertSee('Tasks finished and tasks due')->assertDontSee('Footage queue');
        $this->get(route('videos.index'))->assertRedirect(route('my.dashboard'));
        Livewire::test('pages::editor-dashboard')->assertForbidden();
    }
}
