<?php

namespace Tests\Feature;

use App\Models\AiConversation;
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

        $overview = json_decode($tools->run('business_overview', []), true);
        $this->assertSame(1, $overview['clients_total']);

        // Bad input never reaches the database.
        foreach ([['create_task', ['title' => '']], ['create_task', ['title' => 'x', 'client_id' => 999]], ['create_task', ['title' => 'x', 'due_date' => '2083-13-40', 'calendar' => 'bs']], ['nope', []]] as [$name, $input]) {
            try {
                $tools->run($name, $input);
                $this->fail("{$name} should have been rejected");
            } catch (ValidationException) {
            }
        }
        $this->assertSame(1, Task::count());
        $this->assertSame(1, TaskGroup::count());
    }

    public function test_chat_page_connects_sends_and_keeps_chats_private(): void
    {
        $this->get(route('ai.index'))->assertRedirect(route('login'));
        $user = User::factory()->create(['name' => 'Uzzal Koirala']);
        $this->actingAs($user);
        config(['services.anthropic.key' => null]);

        Livewire::test('pages::ai')
            ->assertSee('Connect the AI')
            ->set('apiKey', 'wrong')->call('saveKey')->assertHasErrors(['apiKey'])
            ->set('apiKey', 'sk-ant-test-123')->call('saveKey')->assertHasNoErrors();
        $this->assertSame('sk-ant-test-123', AiAssistant::apiKey());
        $this->assertNotSame('sk-ant-test-123', Setting::get('anthropic_api_key')); // stored encrypted

        // A fake assistant: no real API calls in tests.
        $this->app->instance(AiAssistant::class, new class extends AiAssistant {
            public array $seen = [];

            public function reply(array $history, string $userName): array
            {
                $this->seen = $history;

                return ['text' => "Done. Added **Design posts**.", 'actions' => [['icon' => 'plus-circle', 'text' => 'Created task “Design posts”', 'url' => '/tasks']]];
            }
        });

        $page = Livewire::test('pages::ai')
            ->assertSee('Hi Uzzal')
            ->call('send', 'Add a task to design posts')
            ->assertSee(['Add a task to design posts', 'Created task “Design posts”'])
            ->assertSeeHtml('<strong>Design posts</strong>');

        $chat = AiConversation::sole();
        $this->assertSame(['Add a task to design posts', $user->id], [$chat->title, $chat->user_id]);
        $this->assertSame(['user', 'assistant'], $chat->messages->pluck('role')->all());
        $page->assertSet('conversationId', $chat->id);

        // Follow-ups send the earlier turns.
        $page->call('send', 'And another');
        $this->assertSame(['user', 'assistant', 'user'], array_column(app(AiAssistant::class)->seen, 'role'));

        // Someone else cannot open or delete it.
        $this->actingAs(User::factory()->create());
        Livewire::test('pages::ai', ['conversationId' => $chat->id])->assertSet('conversationId', null)->call('deleteChat', $chat->id);
        $this->assertModelExists($chat);
    }
}
