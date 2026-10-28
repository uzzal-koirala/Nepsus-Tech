<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Employee;
use App\Models\Script;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class ScriptRowMenuTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_script_menu_views_copies_edits_completes_and_prints(): void
    {
        $this->actingAs(User::factory()->create());
        $client = Client::factory()->create(['company' => 'Mandala Paradise']);
        $s = Script::create(['title' => 'Celing Video 01', 'client_id' => $client->id, 'type' => 'reel', 'status' => 'approved', 'body' => 'HOOK: Look up.', 'notes' => 'Bring the ladder']);

        Livewire::test('pages::scripts')
            ->assertSee(['View', 'Copy script', 'Completed'])->assertSeeHtml('data-test="script-menu-'.$s->id.'"')
            ->call('viewScript', $s->id)->assertSee(['HOOK: Look up.', 'Bring the ladder', 'Download PDF'])->assertSeeHtml(route('scripts.pdf', $s))
            ->call('complete', $s->id);
        $this->assertSame('shot', $s->fresh()->status);

        // Completed only for approved scripts.
        Livewire::test('pages::scripts')->call('complete', $s->id)->assertNotFound();

        $this->get(route('scripts.pdf', $s))->assertOk()->assertSee(['Celing Video 01', 'Mandala Paradise', 'HOOK: Look up.', 'Save as PDF']);
    }

    public function test_video_editors_cannot_open_the_script_pdf(): void
    {
        $user = User::factory()->staff()->create();
        $e = Employee::factory()->create(['department' => 'video-editor', 'status' => 'active']);
        Employee::whereKey($e->id)->update(['user_id' => $user->id]);
        $s = Script::create(['title' => 'X', 'type' => 'reel', 'status' => 'approved']);
        $this->actingAs($user)->get(route('scripts.pdf', $s))->assertRedirect();
    }
}
