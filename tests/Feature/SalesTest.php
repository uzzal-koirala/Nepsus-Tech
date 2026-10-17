<?php

namespace Tests\Feature;

use App\Models\Campaign;
use App\Models\Client;
use App\Models\Employee;
use App\Models\Lead;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class SalesTest extends TestCase
{
    use RefreshDatabase;

    private function lead(array $attributes = []): Lead
    {
        return Lead::create($attributes + ['name' => 'Ram Sharma', 'company' => 'Himalayan Cafe', 'phone' => '9800000000', 'source' => 'facebook', 'service_type' => 'social_media', 'value' => 30000, 'stage' => 'new']);
    }

    public function test_sales_pages_need_a_login_and_show_every_tab(): void
    {
        $this->get(route('sales.index'))->assertRedirect(route('login'));

        $this->actingAs(User::factory()->create());
        $this->get(route('sales.index'))->assertOk()->assertSee(['Sales & Marketing', 'Open pipeline', 'Sales funnel', 'Follow up today']);
        foreach (['pipeline' => 'Proposal sent', 'leads' => 'No leads yet', 'campaigns' => 'No campaigns yet'] as $tab => $text) {
            $this->get(route('sales.index', ['tab' => $tab]))->assertOk()->assertSee($text);
        }
        $this->get(route('dashboard'))->assertSee('Sales &amp; Marketing', false);
    }

    public function test_lead_can_be_added_with_validation(): void
    {
        $this->actingAs(User::factory()->create());
        $owner = Employee::factory()->create(['status' => 'active']);

        Livewire::test('pages::sales')
            ->call('newLead')
            ->set('lead.name', '')
            ->set('lead.email', 'not-an-email')
            ->call('saveLead')
            ->assertHasErrors(['lead.name', 'lead.email'])
            ->set('lead.name', 'Sita Rai')
            ->set('lead.company', 'Everest Gym')
            ->set('lead.email', 'sita@example.com')
            ->set('lead.source', 'instagram')
            ->set('lead.value', '45000')
            ->set('lead.employee_id', (string) $owner->id)
            ->call('saveLead')
            ->assertHasNoErrors();

        $lead = Lead::sole();
        $this->assertSame(['Everest Gym', 'instagram', 'new', $owner->id], [$lead->company, $lead->source, $lead->stage, $lead->employee_id]);
        $this->assertSame(1, $lead->activities()->count());
    }

    public function test_pipeline_moves_and_lost_needs_a_reason(): void
    {
        $this->actingAs(User::factory()->create());
        $lead = $this->lead();

        $page = Livewire::test('pages::sales', ['tab' => 'pipeline'])
            ->call('moveLead', $lead->id, 'proposal');
        $this->assertSame('proposal', $lead->fresh()->stage);
        $this->assertStringContainsString('Moved from New to Proposal sent', $lead->activities()->first()->body);

        $page->call('moveLead', $lead->id, 'lost')->call('confirmLost')->assertHasErrors(['lostReason']);
        $this->assertSame('proposal', $lead->fresh()->stage);

        $page->set('lostReason', 'Price too high')->call('confirmLost')->assertHasNoErrors();
        $lead->refresh();
        $this->assertSame(['lost', 'Price too high'], [$lead->stage, $lead->lost_reason]);
        $this->assertNotNull($lead->closed_at);
    }

    public function test_overview_numbers(): void
    {
        $this->actingAs(User::factory()->create());
        $this->lead(['value' => 40000, 'stage' => 'proposal', 'next_follow_up' => today()->subDay()]);
        $this->lead(['value' => 20000, 'stage' => 'new']);
        $this->lead(['value' => 50000, 'stage' => 'won', 'closed_at' => now()]);
        $this->lead(['value' => 10000, 'stage' => 'lost', 'closed_at' => now()]);

        $s = Livewire::test('pages::sales')->instance()->stats;

        $this->assertSame(60000.0, $s['pipeline']);                 // open deals only
        $this->assertSame(40000 * 0.5 + 20000 * 0.1, $s['forecast']); // weighted by stage
        $this->assertSame(50, $s['conversion']);                    // 1 won of 2 closed
        $this->assertSame(50000.0, $s['wonThisMonth']);
        $this->assertSame(1, $s['followUps']);
    }

    public function test_lead_profile_logs_activity_and_converts_to_client(): void
    {
        $this->actingAs(User::factory()->create());
        $lead = $this->lead(['notes' => 'Wants Growth package']);

        $this->get(route('sales.leads.show', $lead))->assertOk()->assertSee(['Himalayan Cafe', 'Log what happened', 'Convert to client']);

        Livewire::test('pages::lead-profile', ['lead' => $lead])
            ->call('logActivity')
            ->assertHasErrors(['activityBody'])
            ->set('activityType', 'call')
            ->set('activityBody', 'Called, wants a quote')
            ->set('nextFollowUp', today()->addDays(3)->toDateString())
            ->call('logActivity')
            ->assertHasNoErrors()
            ->assertSee('Called, wants a quote');

        $lead->refresh();
        $this->assertSame('contacted', $lead->stage); // first call moves a new lead on
        $this->assertSame(today()->addDays(3)->toDateString(), $lead->next_follow_up->toDateString());

        Livewire::test('pages::lead-profile', ['lead' => $lead])->call('convert');

        $client = Client::sole();
        $this->assertSame(['Ram Sharma', 'Himalayan Cafe', '9800000000', 'social_media', 'active'], [$client->name, $client->company, $client->phone, $client->service_type, $client->status]);
        $this->assertSame('30000.00', (string) $client->price);
        $lead->refresh();
        $this->assertSame(['won', $client->id], [$lead->stage, $lead->client_id]);
    }

    public function test_campaigns_track_spend_and_leads(): void
    {
        $this->actingAs(User::factory()->create());

        Livewire::test('pages::sales', ['tab' => 'campaigns'])
            ->call('newCampaign')
            ->set('campaign.name', 'Dashain boost')
            ->set('campaign.channel', 'facebook_ads')
            ->set('campaign.budget', '10000')
            ->set('campaign.spent', '6000')
            ->set('campaign.starts_on', today()->toDateString())
            ->set('campaign.ends_on', today()->subDay()->toDateString())
            ->call('saveCampaign')
            ->assertHasErrors(['campaign.ends_on'])
            ->set('campaign.ends_on', today()->addWeek()->toDateString())
            ->call('saveCampaign')
            ->assertHasNoErrors();

        $campaign = Campaign::sole();
        $this->lead(['campaign_id' => $campaign->id, 'stage' => 'won', 'value' => 30000, 'closed_at' => now()]);
        $this->lead(['campaign_id' => $campaign->id]);

        Livewire::test('pages::sales', ['tab' => 'campaigns'])
            ->assertSee(['Dashain boost', 'Running', 'NPR 3,000', 'Return +400%']);

        Livewire::test('pages::sales', ['tab' => 'campaigns'])->call('confirmDeleteCampaign', $campaign->id)->call('deleteCampaign', $campaign->id);
        $this->assertModelMissing($campaign);
        $this->assertSame(2, Lead::whereNull('campaign_id')->count());
    }
}
