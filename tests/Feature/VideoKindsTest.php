<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\User;
use App\Models\Video;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class VideoKindsTest extends TestCase
{
    use RefreshDatabase;

    public function test_raw_footage_and_b_roll_and_editing_steps_after_adding(): void
    {
        $this->actingAs(User::factory()->create());

        // Raw footage: starts as To edit, keeps its dates.
        Livewire::test('pages::videos')->call('openNew')->assertSet('videoKind', 'raw')
            ->set('url', 'https://drive.google.com/file/d/a/view')->set('title', 'Kitchen clips')
            ->set('shotOn', today()->toDateString())->set('editBy', today()->addDays(3)->toDateString())
            ->call('save')->assertHasNoErrors();
        $raw = Video::firstWhere('title', 'Kitchen clips');
        $this->assertSame(['raw', 'raw'], [$raw->kind, $raw->status]);
        $this->assertNotNull($raw->edit_by);

        // B-roll: no shoot or edit-by date, even if they were filled in.
        Livewire::test('pages::videos')->call('openNew')
            ->set('url', 'https://drive.google.com/file/d/b/view')->set('title', 'Thamel street shots')->set('videoKind', 'broll')
            ->set('shotOn', today()->toDateString())->set('editBy', today()->addDays(3)->toDateString())
            ->call('save')->assertHasNoErrors();
        $broll = Video::firstWhere('title', 'Thamel street shots');
        $this->assertSame('broll', $broll->kind);
        $this->assertNull($broll->shot_on);
        $this->assertNull($broll->edit_by);
        $this->assertNull($broll->nextStep());
        Livewire::test('pages::videos')->set('videoKind', 'clip')->call('save')->assertHasErrors('videoKind');

        // No All filter: the list opens on To edit, then Editing, Edited, B-roll, Completed.
        $this->get(route('videos.index'))->assertOk()->assertSeeInOrder(['To edit', 'Editing', 'Edited', 'B-roll', 'Completed'])->assertSee(['Start editing', 'Type']);
        Livewire::test('pages::videos')->assertSet('status', 'raw')->call('filter', '')->assertSet('status', 'raw');

        // B-roll never keeps a script.
        $script = \App\Models\Script::create(['title' => 'Momo reel', 'type' => 'reel', 'status' => 'approved']);
        Livewire::test('pages::videos')->call('edit', $broll->id)->set('scriptId', (string) $script->id)->call('save')->assertHasNoErrors();
        $this->assertNull($broll->fresh()->script_id);
        Livewire::test('pages::videos')->call('filter', 'broll')->assertSee('Thamel street shots')->assertDontSee('Kitchen clips')
            ->call('filter', 'raw')->assertSee('Kitchen clips')->assertDontSee('Thamel street shots');

        // A Video Editor moves raw footage along after it is added; B-roll cannot be.
        $user = User::factory()->staff()->create();
        $e = Employee::factory()->create(['department' => 'video-editor', 'status' => 'active']);
        Employee::whereKey($e->id)->update(['user_id' => $user->id]);
        $this->actingAs($user);
        Livewire::test('pages::videos')->call('setStatus', $raw->id, 'editing')->call('filter', 'editing')->assertSee('Mark edited');
        $this->assertSame('editing', $raw->fresh()->status);
        Livewire::test('pages::videos')->call('setStatus', $raw->id, 'done')->call('filter', 'done')->assertSee('Mark completed');
        $this->assertSame('done', $raw->fresh()->status);
        Livewire::test('pages::videos')->call('setStatus', $raw->id, 'completed')->call('filter', 'completed')->assertSee('Kitchen clips');
        $this->assertSame('completed', $raw->fresh()->status);
        $this->assertNull($raw->fresh()->nextStep());
        Livewire::test('pages::videos')->call('setStatus', $raw->id, 'done');
        Livewire::test('pages::videos')->call('setStatus', $broll->id, 'editing')->assertStatus(422);

        // The editor's queue never shows B-roll.
        $broll->update(['status' => 'raw']);
        $raw->update(['status' => 'raw']);
        $this->get(route('my.dashboard'))->assertSee('Kitchen clips')->assertDontSee('Thamel street shots');
    }
}
