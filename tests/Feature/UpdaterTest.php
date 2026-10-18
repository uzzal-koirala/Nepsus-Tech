<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

class UpdaterTest extends TestCase
{
    use RefreshDatabase;

    private string $file;

    protected function setUp(): void
    {
        parent::setUp();
        $this->file = storage_path('app/deploy.json');
        @unlink($this->file);
    }

    protected function tearDown(): void
    {
        @unlink($this->file);
        parent::tearDown();
    }

    public function test_updates_page_asks_for_a_token_first(): void
    {
        $this->actingAs(User::factory()->create());
        config(['services.github.token' => null]);

        $this->get(route('settings.updates'))->assertOk()->assertSee('Updates');
        Livewire::test('pages::settings.updates')->assertSee('Connect GitHub to turn on updates');
    }

    public function test_shows_new_versions_and_starts_the_deploy_on_github(): void
    {
        config(['services.github.token' => 'github_pat_test', 'services.github.repo' => 'acme/app']);
        $this->actingAs(User::factory()->create());
        file_put_contents($this->file, json_encode(['sha' => 'aaa1111', 'message' => 'Old version', 'at' => now()->subDay()->toIso8601String()]));

        $commit = fn ($sha, $msg) => ['sha' => $sha, 'commit' => ['message' => $msg."\n\nbody", 'author' => ['name' => 'Uzzal', 'date' => now()->toIso8601String()]]];
        Http::fake([
            'api.github.com/repos/acme/app/commits/main' => Http::response($commit('ccc3333', 'Calendar access for AI')),
            'api.github.com/repos/acme/app/compare/aaa1111...ccc3333' => Http::response(['ahead_by' => 2, 'commits' => [$commit('bbb2222', 'Tasks boxes'), $commit('ccc3333', 'Calendar access for AI')]]),
            'api.github.com/repos/acme/app/actions/workflows/deploy.yml/runs*' => Http::response(['workflow_runs' => [['status' => 'completed', 'conclusion' => 'success', 'run_started_at' => now()->subDay()->toIso8601String(), 'created_at' => now()->toIso8601String(), 'html_url' => 'https://github.com/acme/app/actions/runs/1', 'head_sha' => 'aaa1111']]]),
            'api.github.com/repos/acme/app/actions/workflows/deploy.yml/dispatches' => Http::response(null, 204),
        ]);

        Livewire::test('pages::settings.updates')
            ->assertSee(['Old version', 'Calendar access for AI', '2 updates ready', 'Tasks boxes', 'Last update finished'])
            ->call('update')
            ->assertSet('starting', true)
            ->assertSee('Starting the update');

        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/dispatches') && $r['ref'] === 'main' && $r->hasHeader('Authorization', 'Bearer github_pat_test'));
    }

    public function test_chatgpt_can_check_and_put_the_update_live(): void
    {
        $tools = new \App\Support\AiTools;

        // Not set up: a clear message instead of a crash.
        config(['services.github.token' => null]);
        try {
            $tools->run('put_update_live', []);
            $this->fail('should explain that updates are not set up');
        } catch (\Illuminate\Validation\ValidationException $e) {
            $this->assertStringContainsString('GitHub token', $e->getMessage());
        }

        config(['services.github.token' => 'github_pat_test', 'services.github.repo' => 'acme/app']);
        file_put_contents($this->file, json_encode(['sha' => 'aaa1111', 'message' => 'Old', 'at' => now()->toIso8601String()]));
        $commit = fn ($sha, $msg) => ['sha' => $sha, 'commit' => ['message' => $msg, 'author' => ['name' => 'U', 'date' => now()->toIso8601String()]]];
        Http::fake([
            'api.github.com/repos/acme/app/commits/main' => Http::response($commit('ccc3333', 'AI connections')),
            'api.github.com/repos/acme/app/compare/*' => Http::response(['ahead_by' => 1, 'commits' => [$commit('ccc3333', 'AI connections')]]),
            'api.github.com/repos/acme/app/actions/workflows/deploy.yml/runs*' => Http::response(['workflow_runs' => [['status' => 'completed', 'conclusion' => 'success', 'run_started_at' => now()->toIso8601String(), 'created_at' => now()->toIso8601String(), 'html_url' => 'https://github.com/x', 'head_sha' => 'aaa1111']]]),
            'api.github.com/repos/acme/app/actions/workflows/deploy.yml/dispatches' => Http::response(null, 204),
        ]);

        $check = json_decode($tools->run('check_for_updates', []), true);
        $this->assertSame([1, ['AI connections'], 'finished'], [$check['updates_waiting'], $check['whats_new'], $check['last_update']['state']]);

        $started = json_decode($tools->run('put_update_live', []), true);
        $this->assertTrue($started['started']);
        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/dispatches'));
        $this->assertCount(1, $tools->actions);
    }

    public function test_up_to_date_and_clear_errors(): void
    {
        config(['services.github.token' => 'github_pat_test', 'services.github.repo' => 'acme/app']);
        $this->actingAs(User::factory()->create());
        file_put_contents($this->file, json_encode(['sha' => 'ccc3333', 'message' => 'Live', 'at' => now()->toIso8601String()]));

        Http::fake([
            'api.github.com/repos/acme/app/commits/main' => Http::response(['sha' => 'ccc3333', 'commit' => ['message' => 'Live', 'author' => ['name' => 'U', 'date' => now()->toIso8601String()]]]),
            'api.github.com/repos/acme/app/actions/*/runs*' => Http::response(['workflow_runs' => []]),
            'api.github.com/repos/acme/app/actions/workflows/deploy.yml/dispatches' => Http::response(['message' => 'Bad credentials'], 401),
        ]);

        Livewire::test('pages::settings.updates')
            ->assertSee('Up to date')
            ->call('update')
            ->assertSet('starting', false);
    }
}
