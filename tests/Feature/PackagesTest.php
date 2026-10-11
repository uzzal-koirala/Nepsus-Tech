<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Package;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class PackagesTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_are_redirected_to_login(): void
    {
        $this->get(route('packages.index'))->assertRedirect(route('login'));
    }

    public function test_starter_packages_exist_after_migrating(): void
    {
        $this->assertSame(['Basic', 'Standard', 'Premium', 'Enterprise'], Package::orderBy('position')->pluck('name')->all());
        $this->assertSame('crown', Package::firstWhere('name', 'Premium')->icon);
        $this->assertSame('gold', Package::firstWhere('name', 'Premium')->color);
    }

    public function test_packages_can_be_created_edited_reordered_and_deleted(): void
    {
        $this->actingAs(User::factory()->create());

        $page = Livewire::test('pages::packages')
            ->call('create')
            ->set('name', 'Growth')
            ->set('icon', 'not-an-icon')
            ->call('save')
            ->assertHasErrors(['icon'])
            ->set('icon', 'rocket-launch')
            ->set('color', 'violet')
            ->set('price', '30000')
            ->set('billingCycle', 'monthly')
            ->set('features', "16 posts a month\n- 4 reels\n\n• Monthly report")
            ->call('save')
            ->assertHasNoErrors()
            ->assertSee('Growth')
            ->assertSee('NPR 30,000');

        $growth = Package::firstWhere('name', 'Growth');
        $this->assertSame(['16 posts a month', '4 reels', 'Monthly report'], $growth->features);
        $this->assertSame('violet', $growth->color);
        $this->assertSame(Package::max('position'), $growth->position);

        $page->call('edit', $growth->id)->assertSet('features', "16 posts a month\n4 reels\nMonthly report")
            ->set('isActive', false)->call('save');
        $this->assertFalse($growth->fresh()->is_active);

        // Move Growth to the front.
        $page->call('sortPackage', $growth->id, 0);
        $this->assertSame('Growth', Package::orderBy('position')->value('name'));

        // Deleting keeps the client, without a package.
        $client = Client::factory()->create(['package_id' => $growth->id]);
        $page->call('confirmDelete', $growth->id)->call('delete');
        $this->assertModelMissing($growth);
        $this->assertNull($client->fresh()->package_id);
    }

    public function test_switched_off_packages_are_hidden_from_the_client_form_unless_already_used(): void
    {
        $this->actingAs(User::factory()->create());

        $retired = Package::factory()->create(['name' => 'Retired Plan', 'is_active' => false]);

        Livewire::test('pages::clients')->call('create')->assertDontSee('Retired Plan');

        $client = Client::factory()->create(['package_id' => $retired->id]);
        Livewire::test('pages::clients')->call('edit', $client->id)->assertSee('Retired Plan');
    }
}
