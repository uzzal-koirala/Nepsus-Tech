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

        $this->get(route('videos.index'))->assertOk()->assertSee(['To edit', 'B-roll', 'Start editing', 'Type']);
        Livewire::test('pages::videos')->call('filter', 'broll')->assertSee('Thamel street shots')->assertDontSee('Kitchen clips')
            ->call('filter', 'raw')->assertSee('Kitchen clips')->assertDontSee('Thamel street shots');

        // A Video Editor moves raw footage along after it is added; B-roll cannot be.
        $user = User::factory()->staff()->create();
        $e = Employee::factory()->create(['department' => 'video-editor', 'status' => 'active']);
        Employee::whereKey($e->id)->update(['user_id' => $user->id]);
        $this->actingAs($user);
        Livewire::test('pages::videos')->call('setStatus', $raw->id, 'editing')->assertSee('Mark edited');
        $this->assertSame('editing', $raw->fresh()->status);
        Livewire::test('pages::videos')->call('setStatus', $raw->id, 'done');
        $this->assertSame('done', $raw->fresh()->status);
        Livewire::test('pages::videos')->call('setStatus', $broll->id, 'editing')->assertStatus(422);

        // The editor's queue never shows B-roll.
        $broll->update(['status' => 'raw']);
        $raw->update(['status' => 'raw']);
        $this->get(route('my.dashboard'))->assertSee('Kitchen clips')->assertDontSee('Thamel street shots');
    }
}
