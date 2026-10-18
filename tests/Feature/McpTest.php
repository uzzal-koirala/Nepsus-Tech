<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Employee;
use App\Models\Lead;
use App\Models\Payment;
use App\Models\Task;
use App\Models\Transaction;
use App\Models\User;
use App\Support\AiTools;
use App\Support\Mcp;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class McpTest extends TestCase
{
    use RefreshDatabase;

    private function rpc(string $token, string $method, array $params = [], ?int $id = 1)
    {
        return $this->postJson(route('mcp', $token), ['jsonrpc' => '2.0', 'method' => $method, 'params' => $params] + ($id === null ? [] : ['id' => $id]));
    }

    public function test_settings_turn_the_link_on_off_and_renew_it(): void
    {
        $this->actingAs(User::factory()->create());
        $this->get(route('settings.ai-connections'))->assertOk()->assertSee('Turn on and make a link');

        Livewire::test('pages::settings.ai-connections')->call('turnOn')->assertSee(['How to connect', 'ChatGPT', 'Claude']);
        $first = Mcp::token();
        $this->assertNotNull($first);
        $this->rpc($first, 'ping')->assertOk();

        Livewire::test('pages::settings.ai-connections')->call('newLink');
        $this->rpc($first, 'ping')->assertNotFound(); // the old link stops working
        $this->rpc(Mcp::token(), 'ping')->assertOk();

        Livewire::test('pages::settings.ai-connections')->call('turnOff');
        $this->assertFalse(Mcp::enabled());
    }

    public function test_mcp_protocol_lists_and_calls_tools(): void
    {
        $this->rpc('anything', 'ping')->assertNotFound(); // off by default
        $token = Mcp::regenerate();
        $this->rpc('wrong-'.$token, 'ping')->assertNotFound();
        $this->get(route('mcp', $token))->assertStatus(405);

        $this->rpc($token, 'initialize', ['protocolVersion' => '2025-06-18', 'clientInfo' => ['name' => 'ChatGPT'], 'capabilities' => new \stdClass])
            ->assertOk()
            ->assertJsonPath('result.protocolVersion', '2025-06-18')
            ->assertJsonPath('result.capabilities.tools.listChanged', false)
            ->assertJson(fn ($j) => $j->where('jsonrpc', '2.0')->where('id', 1)->has('result.instructions')->etc());
        $this->rpc($token, 'notifications/initialized', [], null)->assertStatus(202);

        $tools = $this->rpc($token, 'tools/list')->assertOk()->json('result.tools');
        $this->assertCount(count(AiTools::definitions()), $tools);
        $byName = collect($tools)->keyBy('name');
        $this->assertTrue($byName['list_tasks']['annotations']['readOnlyHint']);
        $this->assertFalse($byName['create_task']['annotations']['readOnlyHint']);

        $client = Client::factory()->create(['company' => 'Everest Gym']);
        $call = $this->rpc($token, 'tools/call', ['name' => 'create_task', 'arguments' => ['title' => 'Shoot reel', 'client_id' => $client->id]])->assertOk();
        $this->assertFalse($call->json('result.isError'));
        $this->assertStringContainsString('Shoot reel', $call->json('result.content.0.text'));
        $this->assertSame($client->id, Task::sole()->client_id);

        // Bad input comes back as a tool error the assistant can read, not a crash.
        $bad = $this->rpc($token, 'tools/call', ['name' => 'create_task', 'arguments' => ['title' => '']]);
        $this->assertTrue($bad->json('result.isError'));
        $this->rpc($token, 'nope')->assertJsonPath('error.code', -32601);
    }

    public function test_tools_cover_clients_money_sales_and_team(): void
    {
        $tools = new AiTools;
        $run = fn ($name, $input = []) => json_decode($tools->run($name, $input), true);

        $c = $run('create_client', ['name' => 'Sita Rai', 'company' => 'Everest Gym', 'service_type' => 'social_media'])['created_client'];
        $run('update_client', ['client_id' => $c['id'], 'phone' => '9800000000']);
        $this->assertSame('9800000000', Client::find($c['id'])->phone);

        $due = $run('add_payment', ['client_id' => $c['id'], 'amount' => 15000, 'paid' => false, 'date' => today()->subDay()->toDateString()])['payment'];
        $this->assertSame('overdue', $due['status']);
        $this->assertSame(1, $run('list_payments', ['status' => 'overdue'])['count']);
        $run('mark_payment_paid', ['payment_id' => $due['id'], 'method' => 'eSewa']);
        $this->assertNotNull(Payment::find($due['id'])->paid_at);
        $this->assertSame('NPR 15,000', $run('get_client', ['client_id' => $c['id']])['received_total']);

        $run('add_transaction', ['kind' => 'expense', 'category' => 'ads', 'title' => 'Facebook boost', 'amount' => 2000]);
        $this->assertSame('ads', Transaction::sole()->category);
        $money = $run('list_transactions');
        $this->assertSame(['NPR 15,000', 'NPR 2,000'], [$money['money_in'], $money['money_out']]);

        $lead = $run('create_lead', ['name' => 'Ram', 'company' => 'Momo House', 'source' => 'facebook', 'value' => 30000])['created_lead'];
        $run('update_lead', ['lead_id' => $lead['id'], 'stage' => 'proposal']);
        $this->assertSame('proposal', Lead::find($lead['id'])->stage);
        $this->assertCount(1, $run('list_leads'));

        $e = Employee::factory()->create(['name' => 'Asha', 'status' => 'active']);
        $this->assertSame('Asha', $run('get_employee', ['employee_id' => $e->id])['name']);
        $this->assertSame('not marked', collect($run('team_today')['team'])->firstWhere('id', $e->id)['status']);
        $this->assertSame([], $run('list_invoices'));
    }
}
