<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\Holiday;
use App\Models\LeaveRequest;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class HolidayTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_marks_a_holiday_on_the_calendar_and_it_counts_everywhere(): void
    {
        $this->actingAs(User::factory()->create());
        // A working day two days out, and the one after it.
        $day = today()->addDays(2);
        while ($day->isHoliday() || $day->addDay()->isHoliday()) {
            $day = $day->addDay();
        }
        $next = $day->addDay();
        $this->assertFalse($day->isHoliday());

        $page = Livewire::test('pages::calendar')->call('pick', $day->toDateString())
            ->set('holidayName', '')->call('markHoliday')->assertHasErrors('holidayName')
            ->set('holidayName', 'Dashain')->call('markHoliday')->assertHasNoErrors()
            ->assertSee(['Dashain', 'Holiday: Dashain', 'Remove']);

        $this->assertSame('Dashain', Holiday::nameOn($day));
        $this->assertTrue($day->isHoliday());
        // Leave over the holiday and the next day counts only the working day.
        $this->assertCount(1, (new LeaveRequest(['from_date' => $day, 'to_date' => $next]))->workingDays());

        // Employees see it but cannot change it.
        $staff = User::factory()->staff()->create();
        Employee::whereKey(Employee::factory()->create()->id)->update(['user_id' => $staff->id]);
        $this->actingAs($staff);
        Livewire::test('pages::calendar')->call('pick', $day->toDateString())->assertSee('Dashain')->assertDontSee('Mark as holiday')
            ->call('removeHoliday')->assertForbidden();
        Livewire::test('pages::calendar')->set('holidayName', 'Party')->call('markHoliday')->assertForbidden();

        // The admin removes it again.
        $this->actingAs(User::factory()->create());
        $page->call('removeHoliday')->assertSee('Mark as holiday');
        $this->assertNull(Holiday::nameOn($day));
        $this->assertFalse($day->isHoliday());
    }
}
