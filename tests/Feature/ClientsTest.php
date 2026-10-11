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

    public function test_cards_remind_about_payments_due_within_a_week_or_overdue(): void
    {
        $this->actingAs(User::factory()->create());

        $soon = Client::factory()->create(['name' => 'Soon Client']);
        $soon->payments()->create(['amount' => 25000, 'due_date' => today()->addDays(3)]);
        $soon->payments()->create(['amount' => 9000, 'due_date' => today()->addDays(30)]);

        $late = Client::factory()->create(['name' => 'Late Client']);
        $late->payments()->create(['amount' => 8000, 'due_date' => today()->subDays(2)]);

        $later = Client::factory()->create(['name' => 'Later Client']);
        $later->payments()->create(['amount' => 5000, 'due_date' => today()->addDays(20)]);

        $paid = Client::factory()->create(['name' => 'Paid Client']);
        $paid->payments()->create(['amount' => 5000, 'due_date' => today()->addDays(2), 'paid_at' => now()]);

        $this->assertSame(['tone' => 'soon', 'days' => 3], array_intersect_key(Client::withNextPayment()->find($soon->id)->paymentAlert(), ['tone' => 0, 'days' => 0]));
        $this->assertSame('overdue', Client::withNextPayment()->find($late->id)->paymentAlert()['tone']);
        $this->assertNull(Client::withNextPayment()->find($later->id)->paymentAlert());
        $this->assertNull(Client::withNextPayment()->find($paid->id)->paymentAlert());

        Livewire::test('pages::clients')
            ->assertSee('Due in 3 days')
            ->assertSee('NPR 25,000')
            ->assertSee('Overdue by 2 days')
            ->assertDontSee('Due in 20 days');
    }

    public function test_form_jumps_to_the_step_with_the_first_error(): void
    {
        $this->actingAs(User::factory()->create());

        Livewire::test('pages::clients')
            ->call('create')
            ->assertSet('formTab', 'details')
            ->set('name', 'Sita Gurung')
            ->set('formTab', 'details')
            ->set('instagram_url', 'not a url')
            ->call('save')
            ->assertHasErrors(['instagram_url'])
            ->assertSet('formTab', 'social')
            ->set('instagram_url', '')
            ->set('name', '')
            ->call('save')
            ->assertSet('formTab', 'details');
    }

    public function test_dragging_a_card_changes_its_priority(): void
    {
        $this->actingAs(User::factory()->create());

        $a = Client::factory()->create(['name' => 'Alpha', 'position' => 0]);
        $b = Client::factory()->create(['name' => 'Bravo', 'position' => 1]);
        $c = Client::factory()->create(['name' => 'Charlie', 'position' => 2]);

        $page = Livewire::test('pages::clients')->assertSeeInOrder(['Alpha', 'Bravo', 'Charlie']);

        // Drag Charlie to the top, then Alpha to the end.
        $page->call('sortClient', $c->id, 0)->assertSeeInOrder(['Charlie', 'Alpha', 'Bravo']);
        $page->call('sortClient', $a->id, 2)->assertSeeInOrder(['Charlie', 'Bravo', 'Alpha']);

        $this->assertSame([$c->id, $b->id, $a->id], Client::orderBy('position')->pluck('id')->all());

        // New clients start at the top.
        $page->set('name', 'Delta')->call('save');
        $this->assertSame('Delta', Client::orderBy('position')->value('name'));
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
            ->set('package', 'gold')
            ->call('save')
            ->assertHasErrors(['package'])
            ->set('package', 'premium')
            ->set('price', '25000')
            ->set('billing_cycle', 'monthly')
            ->set('progress', 140)
            ->call('save')
            ->assertHasErrors(['progress'])
            ->set('progress', 60)
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
        $this->assertSame(60, $sita->progress);
        $this->assertSame('premium', $sita->package);
        $this->assertSame(['instagram' => 'https://instagram.com/sita'], $sita->socialLinks());

        Livewire::test('pages::clients')
            ->call('confirmDelete', $sita->id)
            ->call('delete');

        $this->assertModelMissing($sita);
    }
}
