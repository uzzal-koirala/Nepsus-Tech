<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class VehiclesTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_cannot_see_vehicles(): void
    {
        $this->get(route('vehicles.index'))->assertRedirect(route('login'));
    }

    public function test_vehicle_can_be_added_and_shown(): void
    {
        $this->actingAs(User::factory()->create());
        $rider = Employee::factory()->create(['name' => 'Hari Rider', 'status' => 'active']);

        $this->get(route('vehicles.index'))->assertOk()->assertSee('No vehicles yet');

        Livewire::test('pages::vehicles')
            ->call('create')
            ->assertSet('form.type', 'scooter')
            ->call('save')
            ->assertHasErrors(['form.name'])
            ->set('form.type', 'jet')
            ->set('form.name', 'Office scooter')
            ->call('save')
            ->assertHasErrors(['form.type'])
            ->set('form.type', 'scooter')
            ->set('form.plate_no', 'Ba 85 Pa 1234')
            ->set('form.brand', 'Honda')
            ->set('form.model', 'Dio')
            ->set('form.employee_id', (string) $rider->id)
            ->set('form.bluebook_expiry', today()->addDays(10)->toDateString())
            ->call('save')
            ->assertHasNoErrors();

        $vehicle = Vehicle::sole();
        $this->assertSame('Honda Dio', $vehicle->subtitle());
        $this->assertSame($rider->id, $vehicle->employee_id);
        $this->assertCount(1, $vehicle->renewalsDue());

        $this->get(route('vehicles.index'))->assertOk()->assertSee(['Office scooter', 'Ba 85 Pa 1234', 'Renewal due', 'images/vehicles/scooter.png']);
        $this->get(route('vehicles.show', $vehicle))->assertOk()->assertSee(['Office scooter', 'Hari Rider', 'Bluebook renewal']);
    }

    public function test_expenses_are_recorded_and_count_in_finance(): void
    {
        $this->actingAs(User::factory()->create());
        $vehicle = Vehicle::create(['name' => 'Pulsar', 'type' => 'bike']);

        $page = Livewire::test('pages::vehicle-profile', ['vehicle' => $vehicle])
            ->call('openExpense', 'fuel')
            ->assertSet('expCategory', 'fuel')
            ->call('saveExpense')
            ->assertHasErrors(['expAmount'])
            ->set('expAmount', '1500')
            ->set('expLiters', '9.5')
            ->set('expOdometer', '12000')
            ->set('expMethod', 'eSewa')
            ->call('saveExpense')
            ->assertHasNoErrors()
            ->call('openExpense', 'servicing')
            ->set('expAmount', '2500')
            ->set('expTitle', 'Full service')
            ->set('expLiters', '3')
            ->call('saveExpense')
            ->assertHasNoErrors()
            ->assertSee(['Full service', '9.5 litres', '12,000 km', 'Where the money goes']);

        $this->assertSame(4000.0, $page->instance()->stats['total']);
        $this->assertSame(1500.0, $page->instance()->stats['fuel']);
        $this->assertNull($vehicle->expenses()->where('category', 'servicing')->value('liters'));

        // Vehicle costs are money out on the finance page.
        $finance = Livewire::test('pages::finance');
        $this->assertSame(4000.0, $finance->instance()->summary['vehicles']);
        $this->assertSame(4000.0, $finance->instance()->summary['spent']);
        $finance->assertSee(['Pulsar', 'Petrol / fuel']);

        // Deleting from finance removes it from the vehicle too.
        $fuel = $vehicle->expenses()->where('category', 'fuel')->sole();
        $finance->call('deleteEntry', 'v'.$fuel->id);
        $this->assertModelMissing($fuel);

        // Deleting from the profile, then the vehicle itself.
        $service = $vehicle->expenses()->sole();
        $page->call('confirmDeleteExpense', $service->id)->assertSee('Delete this expense?')->call('deleteExpense', $service->id);
        $this->assertSame(0, $vehicle->expenses()->count());

        $vehicle->expenses()->create(['category' => 'wash', 'amount' => 200, 'date' => today()]);
        $page->call('delete')->assertRedirect(route('vehicles.index'));
        $this->assertModelMissing($vehicle);
        $this->assertSame(0, \App\Models\VehicleExpense::count());
    }

    public function test_vehicle_can_be_edited(): void
    {
        $this->actingAs(User::factory()->create());
        $vehicle = Vehicle::create(['name' => 'Old name', 'type' => 'car', 'year' => 2019]);

        Livewire::test('pages::vehicle-profile', ['vehicle' => $vehicle])
            ->call('edit')
            ->assertSet('form.name', 'Old name')
            ->assertSet('form.year', '2019')
            ->set('form.name', 'Office car')
            ->set('form.type', 'suv')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame(['Office car', 'suv'], [$vehicle->fresh()->name, $vehicle->fresh()->type]);
    }
}
