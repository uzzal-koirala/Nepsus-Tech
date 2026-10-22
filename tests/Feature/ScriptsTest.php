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

        // The popup asks who it is for: a client must be chosen, or "other".
        Livewire::test('pages::scripts')->call('openNew')->assertSet('newFor', 'client')
            ->call('create')->assertHasErrors('newClient')
            ->set('newClient', (string) $client->id)->set('newTitle', 'Momo reel')->call('create')
            ->assertRedirect(route('scripts.edit', Script::sole()));
        $script = Script::sole();
        $this->assertSame([$client->id, 'Momo reel'], [$script->client_id, $script->title]);
        Livewire::test('pages::scripts')->call('openNew')->set('newFor', 'other')->call('create')->assertHasNoErrors();
        $this->assertNull(Script::latest('id')->first()->client_id);
        Script::latest('id')->first()->delete();
        $this->get(route('scripts.edit', $script))->assertOk()->assertSee('Saves as you type');

        $body = 'Stop scrolling if you love momo. Steam, sauce, the first bite. Visit us in Thamel today.';
        Livewire::test('pages::script-editor', ['script' => $script])
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

        // Boxes: one per client with its scripts; "+ Add script" in a box adds straight to that client.
        Livewire::test('pages::scripts')
            ->assertSee(['Momo reel', 'Momo House', 'In review', '1 script'])
            ->assertDontSee('Other scripts')
            ->set('status', 'approved')->assertDontSee('Momo reel')
            ->set('status', '')->set('search', 'thamel')->assertSee('Momo reel')
            ->set('search', '')->call('addTo', $client->id);
        $this->assertSame(2, Script::where('client_id', $client->id)->count());
        Livewire::test('pages::scripts')->call('addTo', 0);
        Livewire::test('pages::scripts')->assertSee(['Other scripts', '2 scripts']);
        Script::where('title', 'Untitled script')->delete();

        // Shoot date: saved from the editor, shown on the client's calendar, upcoming shoots first.
        Livewire::test('pages::script-editor', ['script' => $script])->set('shootDate', today()->addDays(2)->toDateString())->assertHasNoErrors();
        $this->assertTrue($script->fresh()->shoot_date->isSameDay(today()->addDays(2)));
        $this->assertSame('Shoot in 2 days', $script->fresh()->shootLabel());
        $later = Script::create(['title' => 'Later idea', 'client_id' => $client->id, 'status' => 'idea', 'type' => 'reel']);
        $past = Script::create(['title' => 'Old shoot', 'client_id' => $client->id, 'status' => 'shot', 'type' => 'reel', 'shoot_date' => today()->subDays(5)]);
        $order = Livewire::test('pages::scripts')->instance()->boxes->first()['scripts']->pluck('title')->all();
        $this->assertSame(['Momo reel', 'Later idea', 'Old shoot'], $order);
        Livewire::test('pages::client-profile', ['client' => $client])->assertSee('Shoot: Momo reel');
        $later->delete();
        $past->delete();

        Livewire::test('pages::script-editor', ['script' => $script])->call('duplicate');
        $this->assertSame(['Momo reel', 'Momo reel (copy)'], Script::orderBy('id')->pluck('title')->all());

        Livewire::test('pages::script-editor', ['script' => $script])->call('delete')->assertRedirect(route('scripts.index'));
        $this->assertModelMissing($script);
    }
}
