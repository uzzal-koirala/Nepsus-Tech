<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\User;
use App\Support\NepaliDate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FirstSalaryTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_first_salary_is_paid_the_month_after_joining(): void
    {
        [$y, $m] = NepaliDate::fromAd(today());
        $nextMonth = fn (int $y, int $m) => $m === 12 ? [$y + 1, 1] : [$y, $m + 1];
        [$jy, $jm] = $nextMonth($y, $m);   // joins on the 1st of next month
        [$py, $pm] = $nextMonth($jy, $jm); // first paid the month after that

        // Joined and agreement on the 1st, salary day 3: first salary on the 3rd of the following month.
        $e = Employee::factory()->create(['salary_day' => 3, 'joined_on' => NepaliDate::toAd($jy, $jm, 1), 'contract_start' => NepaliDate::toAd($jy, $jm, 1), 'status' => 'active']);
        $this->assertTrue($e->firstSalaryDate()->isSameDay(NepaliDate::toAd($py, $pm, 3)));
        $this->assertTrue($e->nextSalaryDate()->isSameDay(NepaliDate::toAd($py, $pm, 3)));

        // No joined date: the agreement start counts.
        $e2 = Employee::factory()->create(['salary_day' => 3, 'joined_on' => null, 'contract_start' => NepaliDate::toAd($jy, $jm, 1)]);
        $this->assertTrue($e2->nextSalaryDate()->isSameDay(NepaliDate::toAd($py, $pm, 3)));

        // Someone who joined long ago keeps the usual next salary day.
        $old = Employee::factory()->create(['salary_day' => 3, 'joined_on' => today()->subYears(2)]);
        $this->assertTrue($old->nextSalaryDate()->gte(today()));
        $this->assertTrue($old->nextSalaryDate()->lte(today()->addDays(33)));

        // The profile shows the first salary date, not this month's.
        $this->actingAs(User::factory()->create());
        $this->get(route('employees.show', $e))->assertOk()->assertSee(NepaliDate::toAd($py, $pm, 3)->toNepali(withYear: false));
    }
}
