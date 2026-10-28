<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Employee;
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
        // The list starts on Approved; the submitted script waits in the approval panel and under In review.
        Livewire::test('pages::scripts')
            ->assertSet('status', 'approved')->assertSee(['Waiting for your approval', 'Momo reel'])
            ->set('status', 'review')->assertSee(['Momo reel', 'Momo House', '1 script'])->assertDontSee('Other scripts')
            ->set('status', '')->assertSet('status', 'approved')
            ->set('status', 'review')->set('search', 'thamel')->assertSee('Momo reel')->assertDontSee('Waiting for your approval')
            ->set('search', '')->call('addTo', $client->id);
        $this->assertSame(2, Script::where('client_id', $client->id)->count());
        Livewire::test('pages::scripts')->call('addTo', 0);
        $this->assertSame(1, Script::whereNull('client_id')->where('title', 'Untitled script')->count());
        Script::where('title', 'Untitled script')->delete();

        // Shoot date: picked once approved, saved from the editor, shown on the client's calendar, upcoming shoots first.
        $script->update(['status' => 'approved']);
        Livewire::test('pages::script-editor', ['script' => $script])->set('shootDate', today()->addDays(2)->toDateString())->assertHasNoErrors();
        $this->assertTrue($script->fresh()->shoot_date->isSameDay(today()->addDays(2)));
        $this->assertSame('Shoot in 2 days', $script->fresh()->shootLabel());
        // Within a filter: upcoming shoots first, then no date, then past shoots.
        $later = Script::create(['title' => 'Later idea', 'client_id' => $client->id, 'status' => 'approved', 'type' => 'reel']);
        $past = Script::create(['title' => 'Old shoot', 'client_id' => $client->id, 'status' => 'approved', 'type' => 'reel', 'shoot_date' => today()->subDays(5)]);
        $order = Livewire::test('pages::scripts')->set('status', 'approved')->instance()->boxes->first()['scripts']->pluck('title')->all();
        $this->assertSame(['Momo reel', 'Later idea', 'Old shoot'], $order);
        Livewire::test('pages::client-profile', ['client' => $client])->assertSee('Shoot: Momo reel');
        Livewire::test('pages::calendar')->call('pick', today()->addDays(2)->toDateString())->assertSee('Shoot: Momo reel');
        $later->delete();
        $past->delete();

        Livewire::test('pages::script-editor', ['script' => $script])->call('duplicate');
        $this->assertSame(['Momo reel', 'Momo reel (copy)'], Script::orderBy('id')->pluck('title')->all());

        Livewire::test('pages::script-editor', ['script' => $script])->call('delete')->assertRedirect(route('scripts.index'));
        $this->assertModelMissing($script);
    }

    public function test_content_creators_submit_scripts_and_admins_approve_or_send_them_back(): void
    {
        $user = User::factory()->staff()->create();
        $e = Employee::factory()->create(['department' => 'content-creator', 'status' => 'active']);
        Employee::whereKey($e->id)->update(['user_id' => $user->id]);
        $script = Script::create(['title' => 'Momo reel', 'type' => 'reel', 'status' => 'draft']);
        $this->actingAs($user);

        // The writer's panel: no status, a Submit button, no copy or delete.
        $this->get(route('scripts.edit', $script))->assertOk()
            ->assertSee(['Shoot date', 'Client', 'Submit for approval'])
            ->assertDontSee(['Copy script', 'Make a copy', 'Delete script', 'Save script', 'Podcast']);
        // They cannot set the status themselves.
        Livewire::test('pages::script-editor', ['script' => $script])->set('status', 'approved');
        $this->assertSame('draft', $script->fresh()->status);

        // An empty script cannot be submitted; a written one goes to review.
        Livewire::test('pages::script-editor', ['script' => $script])->set('body', '')->call('submitScript')->assertHasErrors('body');
        Livewire::test('pages::script-editor', ['script' => $script])
            ->set('title', 'Momo reel v2')->set('body', 'Stop scrolling if you love momo')->set('type', 'tiktok')
            ->call('submitScript')->assertHasNoErrors()->assertRedirect(route('scripts.index'));
        $script->refresh();
        $this->assertSame(['Momo reel v2', 'tiktok', 'review'], [$script->title, $script->type, $script->status]);
        $this->get(route('scripts.edit', $script))->assertSee('Waiting for approval')->assertDontSee('Submit for approval');
        Livewire::test('pages::script-editor', ['script' => $script])->call('submitScript')->assertStatus(422);
        Livewire::test('pages::script-editor', ['script' => $script])->set('reviewNote', 'ok')->call('approveScript')->assertForbidden();

        // The list only filters by In review and Approved for writers.
        // Writers: no All chip; they start on Approved.
        $this->get(route('scripts.index'))->assertSeeInOrder(['Approved', 'In review', 'Completed'])->assertDontSee(['Idea', 'Draft', 'Shot']);
        Livewire::test('pages::scripts')->assertSet('status', 'approved')->assertDontSee('Momo reel v2')
            ->set('status', 'review')->assertSee('Momo reel v2')
            ->set('status', '')->assertSet('status', 'approved');

        // The admin sends it back with a reason, which the writer sees.
        $admin = User::factory()->create();
        $this->actingAs($admin);
        $this->get(route('scripts.edit', $script))->assertSee(['Waiting for your approval', 'Approve', 'Not approved', 'Copy script']);
        Livewire::test('pages::script-editor', ['script' => $script])->call('rejectScript')->assertHasErrors('reviewNote')
            ->set('reviewNote', 'Make the hook shorter')->call('rejectScript')->assertHasNoErrors();
        $this->assertSame(['rejected', 'Make the hook shorter'], [$script->fresh()->status, $script->fresh()->review_note]);

        $this->actingAs($user);
        $this->get(route('scripts.edit', $script))->assertSee(['Not approved', 'Make the hook shorter', 'Submit again']);
        Livewire::test('pages::script-editor', ['script' => $script->fresh()])->set('body', 'Momo! Stop scrolling.')->call('submitScript')->assertHasNoErrors();
        $this->assertSame(['review', null], [$script->fresh()->status, $script->fresh()->review_note]);

        // Approved: the writer sees it is ready.
        $this->actingAs($admin);
        Livewire::test('pages::script-editor', ['script' => $script->fresh()])->set('reviewNote', 'Great hook')->call('approveScript')->assertHasNoErrors();
        $this->assertSame('approved', $script->fresh()->status);
        $this->actingAs($user);
        $this->get(route('scripts.edit', $script))->assertSee(['Approved', 'Great hook'])->assertDontSee(['Submit for approval', 'Submit again']);

        // After approval the writer marks it as shot; only from Approved.
        $this->get(route('scripts.edit', $script))->assertSee('Mark as shot');
        Livewire::test('pages::script-editor', ['script' => $script->fresh()])->call('markShot')->assertHasNoErrors();
        $this->assertSame('shot', $script->fresh()->status);
        $this->get(route('scripts.edit', $script))->assertSee('Approved and shot')->assertDontSee('Mark as shot');
        Livewire::test('pages::script-editor', ['script' => $script->fresh()])->call('markShot')->assertStatus(422);

        // Admins can mark an approved script as shot too.
        $other = Script::create(['title' => 'Chef intro', 'type' => 'reel', 'status' => 'approved', 'body' => 'Meet our chef']);
        $this->actingAs($admin);
        $this->get(route('scripts.edit', $other))->assertSee(['Approved, ready to shoot', 'Mark as shot']);
        Livewire::test('pages::script-editor', ['script' => $other])->call('markShot')->assertHasNoErrors();
        $this->assertSame('shot', $other->fresh()->status);
        $this->get(route('scripts.edit', $other))->assertDontSee('Mark as shot');
    }

    public function test_admins_see_submitted_scripts_counted_and_approve_them_from_the_list(): void
    {
        $writer = User::factory()->staff()->create(['name' => 'Sita Rai']);
        $waiting = Script::create(['title' => 'Momo reel', 'type' => 'reel', 'status' => 'review', 'body' => 'Stop scrolling', 'user_id' => $writer->id]);
        Script::create(['title' => 'Chef intro', 'type' => 'reel', 'status' => 'review', 'body' => 'Meet the chef']);
        Script::create(['title' => 'Old one', 'type' => 'reel', 'status' => 'approved']);
        $this->actingAs(User::factory()->create());

        $this->assertSame(2, Script::waitingCount());
        $this->get(route('scripts.index'))->assertOk()
            ->assertSee(['Waiting for your approval', '2 scripts submitted by the team', 'Momo reel', 'By Sita Rai', 'View &amp; edit', 'Approve'], false)
            ->assertSeeInOrder(['Scripts', '2'], false);

        Livewire::test('pages::scripts')->call('approve', $waiting->id)->assertSee('1 script submitted by the team');
        $this->assertSame('approved', $waiting->fresh()->status);
        Livewire::test('pages::scripts')->call('approve', $waiting->id)->assertNotFound();

        // Writers see neither the count nor the panel, and cannot approve.
        $e = Employee::factory()->create(['department' => 'content-creator', 'status' => 'active']);
        Employee::whereKey($e->id)->update(['user_id' => $writer->id]);
        $this->actingAs($writer);
        $this->get(route('scripts.index'))->assertOk()->assertDontSee('Waiting for your approval');
        Livewire::test('pages::scripts')->call('approve', $waiting->id)->assertForbidden();
    }
}
