<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class ClientsTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_are_redirected_to_login(): void
    {
        $this->get(route('clients.index'))->assertRedirect(route('login'));
    }

    public function test_clients_can_be_created_searched_filtered_and_deleted(): void
    {
        $this->actingAs(User::factory()->create());

        Client::factory()->create(['name' => 'Asha Koirala', 'status' => 'lead']);
        Client::factory()->create(['name' => 'Bikram Thapa', 'status' => 'active']);

        $this->get(route('clients.index'))->assertOk()->assertSee('Asha Koirala');

        Livewire::test('pages::clients')
            ->set('name', 'Sita Gurung')
            ->set('email', 'not-an-email')
            ->call('save')
            ->assertHasErrors(['email'])
            ->set('email', 'sita@example.com')
            ->set('package', 'Social Media Growth')
            ->set('price', '25000')
            ->set('billing_cycle', 'monthly')
            ->set('instagram_url', 'not a url')
            ->call('save')
            ->assertHasErrors(['instagram_url'])
            ->set('instagram_url', 'https://instagram.com/sita')
            ->call('save')
            ->assertHasNoErrors()
            ->assertSee('NPR 25,000')
            ->set('search', 'Sita')
            ->assertSee('Sita Gurung')
            ->assertDontSee('Bikram Thapa')
            ->set('search', '')
            ->call('setStatus', 'lead')
            ->assertSee('Asha Koirala')
            ->assertDontSee('Bikram Thapa');

        $sita = Client::where('email', 'sita@example.com')->firstOrFail();
        $this->assertSame('active', $sita->status);
        $this->assertSame(['instagram' => 'https://instagram.com/sita'], $sita->socialLinks());

        Livewire::test('pages::clients')
            ->call('confirmDelete', $sita->id)
            ->call('delete');

        $this->assertModelMissing($sita);
    }
}
