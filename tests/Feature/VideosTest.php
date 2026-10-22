<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\User;
use App\Models\Video;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class VideosTest extends TestCase
{
    use RefreshDatabase;

    public function test_footage_links_are_added_filtered_marked_and_removed(): void
    {
        $this->get(route('videos.index'))->assertRedirect(route('login'));
        $this->actingAs(User::factory()->create());
        $this->get(route('videos.index'))->assertOk()->assertSee(['Videos', 'No videos yet']);
        $client = Client::factory()->create(['company' => 'Momo House']);

        Livewire::test('pages::videos')->call('openNew')
            ->set('url', 'not a link')->call('save')->assertHasErrors('url')
            ->set('url', 'https://drive.google.com/drive/folders/abc123')->set('title', 'Dashain reel clips')
            ->set('clientId', (string) $client->id)->set('notes', 'Use the steam shots')->call('save')->assertHasNoErrors();
        $video = Video::sole();
        $this->assertSame(['Dashain reel clips', $client->id, 'raw', 'Google Drive', true], [$video->title, $video->client_id, $video->status, $video->source(), $video->isFolder()]);

        // No title: named by date.
        Livewire::test('pages::videos')->call('openNew')->set('url', 'https://drive.google.com/file/d/xyz/view')->call('save');
        $this->assertStringStartsWith('Footage ', Video::latest('id')->first()->title);

        Livewire::test('pages::videos')
            ->assertSee(['Dashain reel clips', 'Momo House', 'Google Drive', 'Use the steam shots'])
            ->set('clientFilter', 'none')->assertDontSee('Dashain reel clips')
            ->set('clientFilter', (string) $client->id)->assertSee('Dashain reel clips')
            ->call('setStatus', $video->id, 'editing')
            ->set('status', 'raw')->assertDontSee('Dashain reel clips')
            ->set('status', 'editing')->assertSee('Dashain reel clips');
        $this->assertSame('editing', $video->fresh()->status);

        Livewire::test('pages::videos')->call('edit', $video->id)->assertSet('title', 'Dashain reel clips')
            ->set('title', 'Dashain final clips')->call('save');
        $this->assertSame('Dashain final clips', $video->fresh()->title);

        Livewire::test('pages::videos')->call('confirmDelete', $video->id)->call('delete');
        $this->assertModelMissing($video);
    }
}
