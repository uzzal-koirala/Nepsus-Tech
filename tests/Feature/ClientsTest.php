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

    public function test_profile_picture_can_be_uploaded_replaced_and_removed_without_touching_notes(): void
    {
        \Illuminate\Support\Facades\Storage::fake('public');
        $this->actingAs(User::factory()->create());

        $client = Client::factory()->create(['name' => 'Sita Gurung', 'notes' => 'Prefers WhatsApp', 'package_id' => \App\Models\Package::first()->id]);

        $page = Livewire::test('pages::clients')
            ->call('edit', $client->id)
            ->set('photo', \Illuminate\Http\UploadedFile::fake()->create('notes.pdf', 10, 'application/pdf'))
            ->assertHasErrors(['photo'])
            ->set('photo', \Illuminate\Http\UploadedFile::fake()->image('logo.png', 200, 200))
            ->call('save')
            ->assertHasNoErrors();

        $first = $client->fresh()->avatar_path;
        \Illuminate\Support\Facades\Storage::disk('public')->assertExists($first);
        $this->assertSame('Prefers WhatsApp', $client->fresh()->notes);
        $page->assertSee($client->fresh()->avatarUrl());

        // Replacing deletes the old file.
        $page->call('edit', $client->id)->set('photo', \Illuminate\Http\UploadedFile::fake()->image('new.png'))->call('save');
        \Illuminate\Support\Facades\Storage::disk('public')->assertMissing($first);

        // Removing clears it.
        $second = $client->fresh()->avatar_path;
        $page->call('edit', $client->id)->call('clearPhoto')->call('save');
        $this->assertNull($client->fresh()->avatar_path);
        \Illuminate\Support\Facades\Storage::disk('public')->assertMissing($second);
    }

    public function test_billing_period_end_follows_the_nepali_calendar(): void
    {
        $this->travelTo('2026-10-12'); // 26 Ashoj 2083
        $bs = fn (int $y, int $m, int $d) => \App\Support\NepaliDate::toAd($y, $m, $d);

        // Started 15 Bhadra: months run 15th to 14th, so this month ends 14 Kartik.
        $monthly = Client::factory()->make(['status' => 'active', 'billing_cycle' => 'monthly', 'contract_start' => $bs(2083, 5, 15)]);
        $this->assertSame('14 Kartik 2083', $monthly->periodEnd()->toNepali());

        // Started on the 32nd of Asar: Ashoj only has 31 days, so it renews on the 31st and ends on the 30th.
        $clamped = Client::factory()->make(['status' => 'active', 'billing_cycle' => 'monthly', 'contract_start' => $bs(2083, 3, 32)]);
        $this->assertSame('30 Ashoj 2083', $clamped->periodEnd()->toNepali());

        // Yearly from 10 Asar 2082: this year ends 9 Asar 2084.
        $yearly = Client::factory()->make(['status' => 'active', 'billing_cycle' => 'yearly', 'contract_start' => $bs(2082, 3, 10)]);
        $this->assertSame('9 Asar 2084', $yearly->periodEnd()->toNepali());

        $this->assertNull(Client::factory()->make(['status' => 'active', 'billing_cycle' => 'one_time'])->periodEnd());
        $this->assertNull(Client::factory()->make(['status' => 'inactive', 'billing_cycle' => 'monthly', 'contract_start' => $bs(2083, 5, 15)])->periodEnd());

        $this->actingAs(User::factory()->create());
        Client::factory()->create(['name' => 'Monthly Client', 'status' => 'active', 'billing_cycle' => 'monthly', 'contract_start' => $bs(2083, 5, 15)]);
        Livewire::test('pages::clients')->assertSee('Month ends')->assertSee('14 Kartik')->assertSee('19 days left');
    }

    public function test_a_package_must_be_chosen_when_packages_exist(): void
    {
        $this->actingAs(User::factory()->create());

        Livewire::test('pages::clients')
            ->call('create')
            ->set('name', 'Ram Thapa')
            ->call('save')
            ->assertHasErrors(['package' => 'required'])
            ->assertSet('formTab', 'package');
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
            ->assertSet('formTab', 'package')
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
        $page->set('name', 'Delta')->set('package', (string) \App\Models\Package::first()->id)->call('save');
        $this->assertSame('Delta', Client::orderBy('position')->value('name'));
    }

    public function test_clients_can_be_created_searched_filtered_and_deleted(): void
    {
        $this->actingAs(User::factory()->create());

        $premium = \App\Models\Package::factory()->create(['name' => 'Premium', 'price' => 40000]);
        Client::factory()->create(['name' => 'Asha Koirala', 'status' => 'inactive']);
        Client::factory()->create(['name' => 'Bikram Thapa', 'status' => 'active']);

        $this->get(route('clients.index'))->assertOk()->assertSee('Asha Koirala');

        Livewire::test('pages::clients')
            ->set('name', 'Sita Gurung')
            ->set('email', 'not-an-email')
            ->call('save')
            ->assertHasErrors(['email'])
            ->set('email', 'sita@example.com')
            ->set('package', '999')
            ->call('save')
            ->assertHasErrors(['package'])
            ->set('package', (string) $premium->id)
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
            ->set('clientStatus', 'lead')
            ->call('save')
            ->assertHasErrors(['clientStatus'])
            ->set('clientStatus', 'active')
            ->call('save')
            ->assertHasNoErrors()
            ->assertSee('NPR 25,000')
            ->set('search', 'Sita')
            ->assertSee('Sita Gurung')
            ->assertDontSee('Bikram Thapa')
            ->set('search', '')
            ->call('setStatus', 'inactive')
            ->assertSee('Asha Koirala')
            ->assertDontSee('Bikram Thapa');

        $sita = Client::where('email', 'sita@example.com')->firstOrFail();
        $this->assertSame('active', $sita->status);
        $this->assertSame(60, $sita->progress);
        $this->assertSame($premium->id, $sita->package_id);
        $this->assertSame('Premium', $sita->package->name);
        $this->assertSame(['instagram' => 'https://instagram.com/sita'], $sita->socialLinks());

        Livewire::test('pages::clients')
            ->call('confirmDelete', $sita->id)
            ->call('delete');

        $this->assertModelMissing($sita);
    }
}
