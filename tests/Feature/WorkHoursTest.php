<?php

namespace Tests\Feature;

use App\Models\Attendance;
use App\Models\Employee;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class WorkHoursTest extends TestCase
{
    use RefreshDatabase;

    /** A working day in this Nepali month, so it shows on both calendars. */
    private function workday(): CarbonInterface
    {
        [$day] = \App\Support\NepaliDate::monthRange(today());
        while ($day->isHoliday()) {
            $day = $day->addDay();
        }

        return $day;
    }

    public function test_late_means_after_start_plus_grace(): void
    {
        $e = new Employee(['work_start' => '10:00', 'work_end' => '18:00', 'late_after' => 15]);

        $this->assertNull($e->minutesLate('09:50'));
        $this->assertNull($e->minutesLate('10:15'));
        $this->assertSame(16, $e->minutesLate('10:16'));
        $this->assertSame(75, $e->minutesLate('11:15'));
        $this->assertSame('10:16 AM', $e->lateFrom());
        $this->assertSame('10:00 AM – 6:00 PM', $e->workHours());
        $this->assertNull((new Employee)->minutesLate('11:00'));
        $this->assertSame('1h 15m', Attendance::duration(75));
    }

    public function test_admin_sets_hours_and_late_check_ins_are_labelled_for_both(): void
    {
        $this->actingAs(User::factory()->create());
        $user = User::factory()->staff()->create();
        $sita = Employee::factory()->create(['name' => 'Sita Rai', 'status' => 'active']);
        Employee::whereKey($sita->id)->update(['user_id' => $user->id]);
        $day = $this->workday();
        $sita->attendances()->create(['date' => $day, 'status' => 'present', 'check_in' => '10:40', 'check_out' => '18:05']);

        // No hours yet: nobody is late.
        $this->get(route('employees.show', $sita))->assertOk()->assertSee('No hours set')->assertDontSee('Late 40m');

        $page = Livewire::test('pages::employee-profile', ['employee' => $sita])->call('editHours')
            ->set('workStart', '10:00')->set('workEnd', '09:00')->set('lateAfter', '15')->call('saveHours')->assertHasErrors('workEnd')
            ->set('workEnd', '18:00')->set('lateAfter', '-5')->call('saveHours')->assertHasErrors('lateAfter')
            ->set('lateAfter', '15')->call('saveHours')->assertHasNoErrors();
        $this->assertSame(['10:00', '18:00', 15], [$sita->fresh()->work_start, $sita->fresh()->work_end, $sita->fresh()->late_after]);
        $page->assertSee(['Late from 10:16 AM', 'Late 40m', '1 late day in']);

        // The employee sees their hours and the same label.
        $this->actingAs($user);
        $this->get(route('my.attendance'))->assertOk()->assertSee(['Your hours: 10:00 AM – 6:00 PM', 'late from 10:16 AM', 'Late 40m', '1 late']);

        // Removing the hours clears the labels.
        $this->actingAs(User::factory()->create());
        Livewire::test('pages::employee-profile', ['employee' => $sita->fresh()])->call('clearHours')->assertDontSee('Late 40m');
        $this->assertNull($sita->fresh()->work_start);
    }
}
