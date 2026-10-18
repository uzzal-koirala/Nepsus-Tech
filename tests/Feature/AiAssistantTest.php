<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Employee;
use App\Models\Setting;
use App\Models\Task;
use App\Models\TaskGroup;
use App\Models\User;
use App\Support\AiAssistant;
use App\Support\AiTools;
use App\Support\NepaliDate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\TestCase;

class AiAssistantTest extends TestCase
{
    use RefreshDatabase;

    public function test_tools_find_create_update_and_report(): void
    {
        $this->actingAs(User::factory()->create());
        $client = Client::factory()->create(['name' => 'Sita Rai', 'company' => 'Everest Gym']);
        $ram = Employee::factory()->create(['name' => 'Ram Thapa', 'status' => 'active']);
        $tools = new AiTools;

        $found = json_decode($tools->run('find_clients', ['query' => 'everest']), true);
        $this->assertSame($client->id, $found[0]['id']);
        $this->assertSame($ram->id, json_decode($tools->run('find_people', ['query' => 'ram']), true)[0]['id']);

        // A Nepali due date and a box that carries its client.
        $box = json_decode($tools->run('create_box', ['name' => 'Dashain campaign', 'client_id' => $client->id]), true)['created_box'];
        [$y, $m] = NepaliDate::fromAd(today());
        $created = json_decode($tools->run('create_task', [
            'title' => 'Design 4 posts', 'box_id' => $box['id'], 'work_type' => 'post', 'priority' => 'high',
            'due_date' => sprintf('%d-%02d-15', $y, $m), 'calendar' => 'bs', 'assignee_ids' => [$ram->id],
        ]), true)['created'];

        $task = Task::findOrFail($created['id']);
        $this->assertSame([$client->id, $box['id'], 'post', 'high', [$ram->id]], [$task->client_id, $task->task_group_id, $task->type, $task->priority, $task->assignees->pluck('id')->all()]);
        $this->assertTrue($task->due_date->isSameDay(NepaliDate::toAd($y, $m, 15)));
        $this->assertCount(2, $tools->actions);

        $tools->run('update_task', ['task_id' => $task->id, 'status' => 'done']);
        $this->assertNotNull($task->fresh()->completed_at);
        $this->assertSame(1, json_decode($tools->run('list_tasks', ['status' => 'done']), true)['count']);

        // The calendar groups tasks by day, for a Nepali month or a range.
        Task::create(['title' => 'Later shoot', 'type' => 'post', 'due_date' => NepaliDate::toAd($y, $m, 15)]);
        $month = json_decode($tools->run('calendar', ['month' => sprintf('%d-%02d', $y, $m)]), true);
        $this->assertSame([2, 1], [$month['total'], $month['done']]);
        $this->assertCount(1, $month['days']);
        $this->assertSame(['Design 4 posts', 'Later shoot'], array_column($month['days'][0]['tasks'], 'title'));
        $this->assertSame(1, json_decode($tools->run('calendar', ['month' => sprintf('%d-%02d', $y, $m), 'open_only' => true]), true)['total']);
        // A Nepali date sent without calendar=bs is still read as Nepali.
        $this->assertSame(2, json_decode($tools->run('calendar', ['from' => sprintf('%d-%02d-15', $y, $m), 'to' => sprintf('%d-%02d-15', $y, $m)]), true)['total']);
        $this->assertSame(0, json_decode($tools->run('calendar', ['from' => sprintf('%d-%02d-16', $y, $m), 'to' => sprintf('%d-%02d-20', $y, $m), 'calendar' => 'bs']), true)['total']);

        $overview = json_decode($tools->run('business_overview', []), true);
        $this->assertSame(1, $overview['clients_total']);

        // Bad input never reaches the database.
        foreach ([['create_task', ['title' => '']], ['create_task', ['title' => 'x', 'client_id' => 999]], ['create_task', ['title' => 'x', 'due_date' => '2083-13-40', 'calendar' => 'bs']], ['calendar', ['month' => '2083-13']], ['nope', []]] as [$name, $input]) {
            try {
                $tools->run($name, $input);
                $this->fail("{$name} should have been rejected");
            } catch (ValidationException) {
            }
        }
        $this->assertSame(2, Task::count());
        $this->assertSame(1, TaskGroup::count());
    }

    public function test_floating_chat_connects_and_answers_without_saving(): void
    {
        $user = User::factory()->create(['name' => 'Uzzal Koirala']);
        $this->actingAs($user);
        config(['services.anthropic.key' => null, 'services.openrouter.key' => null]);

        $this->get(route('dashboard'))->assertOk()->assertSee('data-test="ai-toggle"', false);

        Livewire::test('pages::ai-widget')
            ->assertSee('Connect the AI')
            ->set('apiKey', 'wrong')->call('saveKey')->assertHasErrors(['apiKey'])
            ->set('apiKey', 'sk-or-test-123')->call('saveKey')->assertHasNoErrors();
        $this->assertSame('sk-or-test-123', AiAssistant::key('openrouter'));
        $this->assertSame('openrouter', AiAssistant::provider());
        $this->assertNotSame('sk-or-test-123', Setting::get('openrouter_api_key')); // stored encrypted

        // A fake assistant: no real API calls in tests.
        $this->app->instance(AiAssistant::class, new class extends AiAssistant {
            public array $seen = [];

            public function reply(array $history, string $userName): array
            {
                $this->seen = $history;

                return ['text' => 'Done. Added **Design posts**.', 'actions' => [['icon' => 'plus-circle', 'text' => 'Created task “Design posts”', 'url' => '/tasks']], 'model' => 'vendor/some-model:free'];
            }
        });

        $chat = Livewire::test('pages::ai-widget')
            ->assertSee('Hi Uzzal')
            ->call('send', 'Add a task to design posts')
            ->assertSee(['Add a task to design posts', 'Created task “Design posts”'])
            ->assertSeeHtml('<strong>Design posts</strong>')
            ->call('send', 'And another');

        // Follow-ups send the earlier turns, newest last.
        $seen = app(AiAssistant::class)->seen;
        $this->assertSame(['user', 'assistant', 'user'], array_column($seen, 'role'));
        $this->assertSame('And another', end($seen)['content']);

        // Clearing starts fresh; nothing is stored anywhere.
        $chat->call('clear')->assertSet('messages', [])->assertSee('Hi Uzzal');
    }

    public function test_openrouter_runs_tools_and_reports_the_model(): void
    {
        $this->actingAs(User::factory()->create());
        config(['services.openrouter.key' => 'sk-or-test', 'services.openrouter.model' => 'nvidia/test-model:free']);

        Http::fake(['openrouter.ai/*' => Http::sequence()
            ->push(['model' => 'nvidia/test-model:free', 'choices' => [['finish_reason' => 'tool_calls', 'message' => ['role' => 'assistant', 'content' => '', 'tool_calls' => [
                ['id' => 'call_1', 'type' => 'function', 'function' => ['name' => 'create_task', 'arguments' => json_encode(['title' => 'Edit reel'])]],
            ]]]]])
            ->push(['model' => 'nvidia/test-model:free', 'choices' => [['finish_reason' => 'stop', 'message' => ['role' => 'assistant', 'content' => 'Added **Edit reel**.']]]])
            ->push([], 429)]);

        $this->assertSame('openrouter', AiAssistant::provider());
        $reply = (new AiAssistant)->reply([['role' => 'user', 'content' => 'Add a task to edit the reel']], 'Uzzal');

        $this->assertSame(['Added **Edit reel**.', 'nvidia/test-model:free'], [$reply['text'], $reply['model']]);
        $this->assertSame('Edit reel', Task::sole()->title);
        $this->assertCount(1, $reply['actions']);

        // The tool result went back with the call id, and fallback models were offered.
        Http::assertSent(fn ($r) => $r->hasHeader('Authorization', 'Bearer sk-or-test') && in_array('nvidia/test-model:free', $r['models'] ?? [], true));
        Http::assertSent(fn ($r) => collect($r['messages'] ?? [])->contains(fn ($m) => ($m['role'] ?? null) === 'tool' && $m['tool_call_id'] === 'call_1'));

        // Busy free models give a friendly message.
        $busy = (new AiAssistant)->reply([['role' => 'user', 'content' => 'hi']], 'Uzzal');
        $this->assertStringContainsString('busy', $busy['text']);
        $this->assertNull($busy['model']);
    }
}
