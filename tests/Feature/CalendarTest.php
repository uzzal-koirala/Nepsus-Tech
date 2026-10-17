<?php

namespace Tests\Feature;

use App\Models\CalendarEvent;
use App\Models\Client;
use App\Models\User;
use App\Support\NepaliDate;
use Livewire\Livewire;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CalendarTest extends TestCase
{
    use RefreshDatabase;

    public function test_calendar_shows_client_dates_and_events_can_be_added(): void
    {
        $this->get(route('calendar.index'))->assertRedirect(route('login'));
        $this->actingAs(User::factory()->create());

        $client = Client::factory()->create(['name' => 'Sita Rai', 'company' => 'Everest Gym', 'status' => 'active', 'billing_months' => 1, 'contract_start' => NepaliDate::addMonths(today(), -2)]);
        $client->payments()->create(['amount' => 15000, 'due_date' => today()]);
        $client->tasks()->create(['title' => 'Design March posts', 'due_date' => today()]);

        $page = Livewire::test('pages::calendar')
            ->assertSee(['Everest Gym', 'Payment due', 'Design March posts', 'Renews'])
            ->call('toggle', 'due')
            ->assertDontSee('Payment due')
            ->call('newEvent', today()->toDateString())
            ->call('saveEvent')
            ->assertHasErrors(['event.title'])
            ->set('event.title', 'Content shoot')
            ->set('event.kind', 'shoot')
            ->set('event.time', '14:30')
            ->set('event.client_id', (string) $client->id)
            ->call('saveEvent')
            ->assertHasNoErrors()
            ->assertSee(['Content shoot', '2:30 PM']);

        $event = CalendarEvent::sole();
        $this->assertSame(['shoot', '14:30', $client->id], [$event->kind, $event->time, $event->client_id]);

        $page->call('confirmDelete', $event->id)->call('deleteEvent');
        $this->assertModelMissing($event);

        // Moving months keeps the title in step.
        [$y, $m] = NepaliDate::fromAd(today());
        Livewire::test('pages::calendar')->call('shift', 1)->assertSet('month', sprintf('%d-%02d', $m === 12 ? $y + 1 : $y, $m % 12 + 1));
    }
}
