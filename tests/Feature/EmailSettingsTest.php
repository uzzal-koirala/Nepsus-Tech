<?php

namespace Tests\Feature;

use App\Mail\LeaveRequested;
use App\Models\Employee;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;
use Tests\TestCase;

class EmailSettingsTest extends TestCase
{
    use RefreshDatabase;

    public function test_admins_choose_where_system_emails_go_and_which_are_sent(): void
    {
        Mail::fake();
        $this->actingAs(User::factory()->create());

        $this->get(route('settings.email'))->assertOk()
            ->assertSee(['Send system emails to', 'info@nepdude.com', 'Morning reminders', 'Leave requests', 'Emails are not leaving the server yet']);
        $this->get(route('settings.preferences'))->assertOk()->assertSee('Settings → Email');

        Livewire::test('pages::settings.email')
            ->call('removeEmail', 'info@nepdude.com')->assertHasErrors('newEmail')
            ->set('newEmail', 'office@nepsustech.com')->call('addEmail')->call('removeEmail', 'info@nepdude.com')->assertHasNoErrors()
            ->set('leaveOn', false);
        $this->assertSame(['office@nepsustech.com'], Setting::systemEmails());
        $this->assertSame('0', Setting::get('leave_emails_on'));

        // Employees cannot open it.
        $this->actingAs(User::factory()->staff()->create());
        $this->get(route('settings.email'))->assertRedirect(route('my.dashboard'));
    }

    public function test_a_leave_request_emails_the_office_when_turned_on(): void
    {
        Mail::fake();
        Setting::put(['reminder_emails' => 'boss@nepsustech.com, hr@nepsustech.com', 'leave_emails_on' => '1']);
        $user = User::factory()->staff()->create();
        $sita = Employee::factory()->create(['name' => 'Sita Rai', 'status' => 'active']);
        Employee::whereKey($sita->id)->update(['user_id' => $user->id]);
        $this->actingAs($user);
        $day = today()->addDays(3);
        while ($day->isHoliday()) {
            $day = $day->addDay();
        }

        Livewire::test('pages::my-attendance')->set('leaveFrom', $day->toDateString())->set('leaveTo', $day->toDateString())->set('leaveReason', 'Family function')->call('requestLeave')->assertHasNoErrors();
        Mail::assertSent(LeaveRequested::class, function ($m) {
            $html = $m->render();

            return $m->hasTo('boss@nepsustech.com') && $m->hasTo('hr@nepsustech.com')
                && str_contains($html, 'Sita Rai asked for leave') && str_contains($html, 'Family function');
        });

        // Turned off: no email, the request still goes in.
        Setting::put(['leave_emails_on' => '0']);
        Mail::fake();
        $next = $day->addDays(7);
        while ($next->isHoliday()) {
            $next = $next->addDay();
        }
        Livewire::test('pages::my-attendance')->set('leaveFrom', $next->toDateString())->set('leaveTo', $next->toDateString())->set('leaveReason', 'Dentist')->call('requestLeave')->assertHasNoErrors();
        Mail::assertNothingSent();
        $this->assertSame(2, $sita->leaveRequests()->count());
    }
}
