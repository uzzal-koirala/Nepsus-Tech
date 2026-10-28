<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\User;
use App\Models\Video;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class VideoEditByTest extends TestCase
{
    use RefreshDatabase;

    public function test_videos_get_an_edit_by_date_that_stands_out_for_editors(): void
    {
        $this->actingAs(User::factory()->create());

        // Added with an edit-by date.
        Livewire::test('pages::videos')->call('openNew')
            ->set('url', 'https://drive.google.com/file/d/x/view')->set('title', 'Kitchen clips')->set('editBy', today()->addDays(5)->toDateString())
            ->call('save')->assertHasNoErrors();
        $video = Video::sole();
        $this->assertSame(today()->addDays(5)->toDateString(), $video->edit_by->toDateString());
        $this->get(route('videos.index'))->assertSee(['Edit by', 'Edit by '.today()->addDays(5)->toNepali(false)]);
        Livewire::test('pages::videos')->call('edit', $video->id)->assertSet('editBy', today()->addDays(5)->toDateString());

        // The tag says how urgent it is.
        $tag = fn (string $date, string $status = 'raw') => (new Video(['edit_by' => $date, 'status' => $status]))->deadline();
        $this->assertSame('Edit 2 days late', $tag(today()->subDays(2)->toDateString())['label']);
        $this->assertTrue($tag(today()->subDays(2)->toDateString())['late']);
        $this->assertSame('Edit by today', $tag(today()->toDateString())['label']);
        $this->assertSame('Edit by tomorrow', $tag(today()->addDay()->toDateString())['label']);
        $this->assertStringStartsWith('Was due', $tag(today()->subDay()->toDateString(), 'done')['label']);
        $this->assertNull((new Video)->deadline());

        // Late footage counts as due on the editor's dashboard.
        Video::create(['title' => 'Late promo', 'url' => 'https://drive.google.com/file/d/y/view', 'status' => 'editing', 'edit_by' => today()->subDay()]);
        Video::create(['title' => 'No date yet', 'url' => 'https://drive.google.com/file/d/z/view', 'status' => 'raw']);
        $user = User::factory()->staff()->create();
        $e = Employee::factory()->create(['department' => 'video-editor', 'status' => 'active']);
        Employee::whereKey($e->id)->update(['user_id' => $user->id]);
        $this->actingAs($user);
        $this->get(route('my.dashboard'))->assertOk()->assertSee('Footage and tasks, today or late');
    }
}
