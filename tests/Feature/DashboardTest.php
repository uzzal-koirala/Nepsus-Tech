<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DashboardTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_are_redirected_to_the_login_page(): void
    {
        $response = $this->get(route('dashboard'));
        $response->assertRedirect(route('login'));
    }

    public function test_authenticated_users_can_visit_the_dashboard(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $response = $this->get(route('dashboard'));
        $response->assertOk();
    }

    public function test_overview_shows_live_numbers(): void
    {
        $this->actingAs(User::factory()->create());
        $client = Client::factory()->create(['status' => 'active', 'company' => 'Everest Gym']);
        $client->payments()->create(['amount' => 7500, 'due_date' => today()->subDays(3)]);
        $client->payments()->create(['amount' => 4200, 'paid_at' => now()]);

        $this->get(route('dashboard'))->assertOk()
            ->assertSee(['Active clients', 'Cash flow', 'Payments due', '3 days late', '1 payment overdue', 'Recent transactions', 'Everest Gym', '+ NPR 4,200'])
            ->assertDontSee(['Team today', 'Open tasks', 'Agreements ending soon']);
    }
}
