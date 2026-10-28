<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Employee;
use App\Models\Script;
use App\Models\User;
use App\Models\Video;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class VideoScriptTest extends TestCase
{
    use RefreshDatabase;

    public function test_footage_links_to_its_script_and_editors_can_read_it(): void
    {
        $client = Client::factory()->create(['company' => 'Momo House']);
        $script = Script::create(['title' => 'Dashain momo reel', 'type' => 'reel', 'status' => 'approved', 'client_id' => $client->id, 'body' => 'HOOK: Stop scrolling if you love momo.', 'notes' => 'Shoot in the kitchen']);
        $this->actingAs(User::factory()->create());

        Livewire::test('pages::videos')->call('openNew')->assertSee(['Script', 'Dashain momo reel'])
            ->set('scriptId', '999')->set('url', 'https://drive.google.com/file/d/a/view')->call('save')->assertHasErrors('scriptId')
            // No title and no client: both come from the script.
            ->set('scriptId', (string) $script->id)->call('save')->assertHasNoErrors();
        $video = Video::sole();
        $this->assertSame([$script->id, 'Dashain momo reel', $client->id], [$video->script_id, $video->title, $video->client_id]);
        Livewire::test('pages::videos')->call('edit', $video->id)->assertSet('scriptId', (string) $script->id);
        $this->get(route('videos.index'))->assertSee('Script: Dashain momo reel');

        // A Video Editor reads the script in a popup (they cannot open Scripts).
        $user = User::factory()->staff()->create();
        $e = Employee::factory()->create(['department' => 'video-editor', 'status' => 'active']);
        Employee::whereKey($e->id)->update(['user_id' => $user->id]);
        $this->actingAs($user);
        $this->get(route('scripts.edit', $script))->assertRedirect(route('my.dashboard'));
        Livewire::test('pages::videos')->call('viewScript', $video->id)
            ->assertSee(['HOOK: Stop scrolling if you love momo.', 'Shoot in the kitchen'])->assertDontSee('Open script');
        Livewire::test('pages::videos')->assertSee('View script')->assertDontSee('Script: ')
            ->call('viewScript', $video->id)->call('downloadScript')->assertFileDownloaded(\Illuminate\Support\Str::slug($script->title).'.txt');

        // Deleting the script keeps the video, just unlinked.
        $script->delete();
        $this->assertNull($video->fresh()->script_id);
    }
}
