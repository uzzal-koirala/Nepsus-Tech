<?php

namespace Tests\Feature;

use App\Mail\DailyReminders;
use App\Models\Client;
use App\Models\Script;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;
use Tests\TestCase;

class RemindersTest extends TestCase
{
    use RefreshDatabase;

    public function test_morning_email_lists_what_is_due_and_goes_to_the_saved_addresses(): void
    {
        Mail::fake();
        $client = Client::factory()->create(['company' => 'Momo House']);
        Script::create(['title' => 'Dashain reel', 'client_id' => $client->id, 'type' => 'reel', 'status' => 'approved', 'shoot_date' => today()->addDay()]);
        Script::create(['title' => 'Far away shoot', 'client_id' => $client->id, 'type' => 'reel', 'status' => 'idea', 'shoot_date' => today()->addDays(9)]);
        $client->payments()->create(['amount' => 7500, 'due_date' => today()->subDays(2)]);

        $sections = collect(DailyReminders::gather())->keyBy('title');
        $this->assertSame(['Dashain reel'], array_column($sections['Shoots']['items'], 'title'));
        $this->assertSame('Tomorrow', $sections['Shoots']['items'][0]['when']);
        $this->assertSame('2 days late', $sections['Payments due']['items'][0]['when']);
        $html = (new DailyReminders($sections->values()->all()))->render();
        $this->assertStringContainsString('Dashain reel', $html);
        $this->assertStringNotContainsString('Far away shoot', $html);

        // Settings: a bad address is refused; good ones are saved and the test email goes out.
        $this->actingAs(User::factory()->create());
        Livewire::test('pages::settings.preferences')
            ->set('reminderEmails', 'me@gmail.com, nope')->call('save')->assertHasErrors('reminderEmails')
            ->set('reminderEmails', 'me@gmail.com, partner@gmail.com')->call('sendTest')->assertHasNoErrors();
        Mail::assertSent(DailyReminders::class, fn ($m) => $m->hasTo('me@gmail.com') && $m->hasTo('partner@gmail.com'));

        $this->artisan('reminders:send')->expectsOutputToContain('Reminders sent to me@gmail.com, partner@gmail.com')->assertOk();
        Setting::put(['reminder_emails' => '']);
        $this->artisan('reminders:send')->expectsOutputToContain('No reminder email set')->assertOk();
    }
}
