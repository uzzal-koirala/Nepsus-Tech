<?php

namespace Tests\Feature;

use App\Models\Attendance;
use App\Models\Employee;
use App\Models\LeaveRequest;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Carbon\CarbonInterface;
use Livewire\Livewire;
use Tests\TestCase;

class LeaveRequestTest extends TestCase
{
    use RefreshDatabase;

    /** The next working day on or after $from. */
    private function workday(CarbonInterface $from): CarbonInterface
    {
        $day = $from;
        while ($day->isHoliday()) {
            $day = $day->addDay();
        }

        return $day;
    }

    public function test_employee_requests_leave_and_an_admin_approves_it(): void
    {
        $user = User::factory()->staff()->create();
        $sita = Employee::factory()->create(['name' => 'Sita Rai', 'status' => 'active']);
        Employee::whereKey($sita->id)->update(['user_id' => $user->id]);
        $from = $this->workday(today()->addDays(3));
        // Three days in a row, one of them may be the weekly holiday.
        $to = $from->copy()->addDays(2);
        $this->actingAs($user);

        $this->get(route('my.attendance'))->assertOk()->assertSee('Request leave');
        Livewire::test('pages::my-attendance')->call('newLeave')
            ->set('leaveFrom', $from->toDateString())->set('leaveTo', $to->toDateString())->set('leaveReason', 'Family function in Pokhara')
            ->call('requestLeave')->assertHasNoErrors()
            ->assertSee(['My leave requests', 'Family function in Pokhara', 'Waiting']);

        // No asking twice for the same days, no reason, or a range that ends first.
        Livewire::test('pages::my-attendance')->set('leaveFrom', $from->toDateString())->set('leaveTo', $from->toDateString())->set('leaveReason', 'Again')
            ->call('requestLeave')->assertHasErrors('leaveFrom');
        Livewire::test('pages::my-attendance')->set('leaveFrom', $to->toDateString())->set('leaveTo', $from->toDateString())->set('leaveReason', '')
            ->call('requestLeave')->assertHasErrors(['leaveTo', 'leaveReason']);

        // Employees cannot decide.
        $request = LeaveRequest::firstOrFail();
        Livewire::test('pages::leave-requests')->assertForbidden();

        // The admin sees it on Employees, with a count in the sidebar, and approves it.
        $this->actingAs(User::factory()->create());
        $this->get(route('employees.index'))->assertOk()->assertSee(['Leave requests', 'Sita Rai', 'Family function in Pokhara']);
        Livewire::test('pages::leave-requests')->call('decide', $request->id, 'approved')->set('reply', '')->call('saveDecision')->assertHasErrors('reply');
        $this->assertSame('pending', $request->fresh()->status);
        Livewire::test('pages::leave-requests')->call('decide', $request->id, 'approved')->set('reply', 'Enjoy, no shoots that week')->call('saveDecision')->assertHasNoErrors();

        $request->refresh();
        $this->assertSame('approved', $request->status);
        $this->assertSame('Enjoy, no shoots that week', $request->reply);
        $marked = Attendance::where('employee_id', $sita->id)->where('status', 'leave')->get();
        $this->assertCount(count($request->workingDays()), $marked);
        $this->assertTrue($marked->every(fn ($a) => ! $a->date->isHoliday() && $a->note === 'Family function in Pokhara'));

        // Decided requests cannot be decided again.
        Livewire::test('pages::leave-requests')->call('decide', $request->id, 'rejected')->assertNotFound();

        // Back as the employee: approved, with the reply, and leave on the calendar.
        $this->actingAs($user);
        Livewire::test('pages::my-attendance')->assertSee(['Approved', 'Admin', 'Enjoy, no shoots that week']);
    }

    public function test_employee_can_cancel_a_waiting_request_and_rejections_mark_nothing(): void
    {
        $user = User::factory()->staff()->create();
        $ram = Employee::factory()->create(['status' => 'active']);
        Employee::whereKey($ram->id)->update(['user_id' => $user->id]);
        $other = Employee::factory()->create(['status' => 'active']);
        $day = $this->workday(today()->addDay());
        $theirs = $other->leaveRequests()->create(['from_date' => $day, 'to_date' => $day, 'reason' => 'Not mine']);
        $this->actingAs($user);

        Livewire::test('pages::my-attendance')->set('leaveFrom', $day->toDateString())->set('leaveTo', $day->toDateString())->set('leaveReason', 'Dentist')->call('requestLeave');
        $mine = LeaveRequest::where('employee_id', $ram->id)->firstOrFail();
        Livewire::test('pages::my-attendance')->assertDontSee('Not mine')->call('cancelLeave', $theirs->id)->assertNotFound();
        Livewire::test('pages::my-attendance')->call('cancelLeave', $mine->id);
        $this->assertNull($mine->fresh());

        $this->actingAs(User::factory()->create());
        Livewire::test('pages::leave-requests')->call('decide', $theirs->id, 'rejected')->call('saveDecision')->assertHasErrors('reply');
        Livewire::test('pages::leave-requests')->call('decide', $theirs->id, 'rejected')->set('reply', 'Shoot planned that day')->call('saveDecision')->assertHasNoErrors();
        $this->assertSame('rejected', $theirs->fresh()->status);
        $this->assertSame(0, Attendance::where('employee_id', $other->id)->count());
    }
}
