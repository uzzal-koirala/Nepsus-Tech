<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Script;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class ScriptsTest extends TestCase
{
    use RefreshDatabase;

    public function test_scripts_are_written_saved_as_you_type_filtered_copied_and_deleted(): void
    {
        $this->get(route('scripts.index'))->assertRedirect(route('login'));
        $this->actingAs(User::factory()->create());
        $this->get(route('scripts.index'))->assertOk()->assertSee(['Scripts', 'No scripts yet']);
        $client = Client::factory()->create(['company' => 'Momo House']);

        Livewire::test('pages::scripts')->call('create')->assertRedirect(route('scripts.edit', Script::sole()));
        $script = Script::sole();
        $this->get(route('scripts.edit', $script))->assertOk()->assertSee('Saves as you type');

        $body = 'Stop scrolling if you love momo. Steam, sauce, the first bite. Visit us in Thamel today.';
        Livewire::test('pages::script-editor', ['script' => $script])
            ->set('title', 'Momo reel')
            ->set('body', $body)
            ->set('status', 'review')
            ->set('clientId', (string) $client->id)
            ->set('type', 'tiktok')
            ->assertNotSet('savedAt', null)
            ->set('title', '')->assertHasErrors('title'); // a title is required

        $script->refresh();
        $this->assertSame(['Momo reel', $body, 'review', $client->id, 'tiktok'], [$script->title, $script->body, $script->status, $script->client_id, $script->type]);
        $this->assertSame(16, Script::wordCount($body));
        $this->assertSame('6 sec', Script::speakingTime($body));

        Livewire::test('pages::scripts')
            ->assertSee(['Momo reel', 'Momo House', 'In review'])
            ->set('status', 'approved')->assertDontSee('Momo reel')
            ->set('status', '')->set('search', 'thamel')->assertSee('Momo reel')
            ->set('search', '')->set('clientFilter', 'none')->assertDontSee('Momo reel');

        Livewire::test('pages::script-editor', ['script' => $script])->call('duplicate');
        $this->assertSame(['Momo reel', 'Momo reel (copy)'], Script::orderBy('id')->pluck('title')->all());

        Livewire::test('pages::script-editor', ['script' => $script])->call('delete')->assertRedirect(route('scripts.index'));
        $this->assertModelMissing($script);
    }
}
