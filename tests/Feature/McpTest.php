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

        // The record ChatGPT tried to save.
        $made = $run('create_employee', ['name' => 'Uzzal Koirala', 'designation' => 'Founder & CEO', 'department' => 'Video Editor', 'salary' => 25000, 'phone' => '9829399342', 'joined_on' => today()->toDateString()])['created_employee'];
        $boss = Employee::findOrFail($made['id']);
        $this->assertSame(['Founder & CEO', 'video-editor', 'active', '9829399342'], [$boss->designation, $boss->department, $boss->status, $boss->phone]);
        $this->assertSame(25000.0, (float) $boss->salary);
        $run('update_employee', ['employee_id' => $boss->id, 'salary' => 30000, 'status' => 'on_leave']);
        $this->assertSame([30000.0, 'on_leave'], [(float) $boss->fresh()->salary, $boss->fresh()->status]);
        try {
            $run('create_employee', ['name' => 'X', 'department' => 'Spaceships']);
            $this->fail('unknown department should be refused');
        } catch (\Illuminate\Validation\ValidationException $ex) {
            $this->assertStringContainsString('Choose one of', $ex->getMessage());
        }

        // Salary follows the Pay button rules: never more than what is left.
        $boss->update(['salary' => 30000, 'status' => 'active']);
        $paid = $run('pay_salary', ['employee_id' => $boss->id, 'amount' => 10000])['paid'];
        $this->assertSame('NPR 20,000', $paid['left_for_month']);
        try {
            $run('pay_salary', ['employee_id' => $boss->id, 'amount' => 25000]);
            $this->fail('paying more than is left should be refused');
        } catch (\Illuminate\Validation\ValidationException $ex) {
            $this->assertStringContainsString('left', $ex->getMessage());
        }
        $this->assertSame('NPR 0', $run('pay_salary', ['employee_id' => $boss->id])['paid']['left_for_month']); // pays the rest

        $att = $run('mark_attendance', ['employee_id' => $boss->id, 'status' => 'present', 'check_in' => '09:05', 'check_out' => '17:30'])['attendance'];
        $this->assertSame(['present', '9:05 AM'], [$att['status'], $att['check_in']]);
        $this->assertSame('edited', \App\Models\Attendance::where('employee_id', $boss->id)->sole()->source);

        // Invoices: create, mark paid (records the payment), quotation to invoice.
        $inv = $run('create_invoice', ['client_id' => $c['id'], 'items' => [['description' => 'Social media', 'price' => 20000], ['description' => 'Reels', 'quantity' => 2, 'price' => 2500]], 'tax_rate' => 13])['created'];
        $this->assertSame(['INV-0001', 'Everest Gym', 'NPR 28,250'], [$inv['number'], $inv['for'], $inv['total']]);
        $run('set_invoice_status', ['invoice_id' => $inv['id'], 'status' => 'paid', 'method' => 'Khalti']);
        $this->assertSame(28250.0, (float) Payment::where('reference', 'INV-0001')->sole()->amount);
        $quote = $run('create_invoice', ['type' => 'quotation', 'bill_to' => 'Momo House', 'items' => [['description' => 'Website', 'price' => 50000]]])['created'];
        $this->assertSame('INV-0002', $run('convert_quotation', ['invoice_id' => $quote['id']])['invoice']['number']);

        // Delete keeps a recovery copy; attendance deletes stay deleted.
        @array_map('unlink', glob(storage_path('app/ai-trash/*.jsonl')) ?: []);
        $key = collect($run('list_transactions')['entries'])->firstWhere('title', 'Facebook boost')['key'];
        $this->assertSame('deleted', array_key_first($run('delete', ['what' => 'transaction', 'id' => $key])));
        $this->assertSame(0, Transaction::count());
        $run('delete', ['what' => 'attendance', 'id' => $boss->id.':'.today()->toDateString()]);
        $this->assertTrue(\App\Models\Attendance::isForgotten($boss->id, today()->toDateString()));
        $run('delete', ['what' => 'lead', 'id' => (string) $lead['id']]);
        $this->assertNull(Lead::find($lead['id']));
        $trash = file_get_contents(storage_path('app/ai-trash/'.today()->toDateString().'.jsonl'));
        $this->assertStringContainsString('Facebook boost', $trash);
        $this->assertStringContainsString('Momo House', $trash);
        @array_map('unlink', glob(storage_path('app/ai-trash/*.jsonl')) ?: []);

        // Packages: create, change, give to a client, delete (the client keeps its price).
        $pkg = $run('create_package', ['name' => 'Growth', 'price' => 30000, 'billing_cycle' => 'monthly', 'features' => ['12 posts', '4 reels']])['created_package'];
        $run('update_package', ['package_id' => $pkg['id'], 'price' => 35000]);
        $run('update_client', ['client_id' => $c['id'], 'package_id' => $pkg['id']]);
        $client = Client::find($c['id']);
        $this->assertSame([$pkg['id'], 35000.0, 1], [$client->package_id, (float) $client->price, $client->billing_months]);
        $this->assertSame(1, collect($run('list_packages'))->firstWhere('id', $pkg['id'])['clients_using_it']);
        $run('delete', ['what' => 'package', 'id' => (string) $pkg['id']]);
        $this->assertNull(\App\Models\Package::find($pkg['id']));
        $this->assertSame([null, 35000.0], [$client->fresh()->package_id, (float) $client->fresh()->price]);
        @array_map('unlink', glob(storage_path('app/ai-trash/*.jsonl')) ?: []);

        $e = Employee::factory()->create(['name' => 'Asha', 'status' => 'active']);
        $this->assertSame('Asha', $run('get_employee', ['employee_id' => $e->id])['name']);
        $this->assertSame('not marked', collect($run('team_today')['team'])->firstWhere('id', $e->id)['status']);
        $this->assertSame(['paid', 'draft'], array_column($run('list_invoices'), 'status'));
    }
}
