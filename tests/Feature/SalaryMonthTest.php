<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\SalaryPayment;
use App\Models\User;
use App\Support\NepaliDate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class SalaryMonthTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_salary_is_an_expense_of_the_month_it_is_for(): void
    {
        $this->actingAs(User::factory()->create());
        $e = Employee::factory()->create(['name' => 'Suyog Dahal', 'salary' => 38000, 'status' => 'active']);
        $now = NepaliDate::period(today());
        [$y, $m] = array_map('intval', explode('-', $now));
        $first = NepaliDate::toAd($y, $m, 1);

        // This month's salary paid a few days before the month began.
        $e->salaryPayments()->create(['period' => $now, 'amount' => 38000, 'paid_on' => $first->copy()->subDays(3)]);
        // Last month's salary paid today counts in last month, not this one.
        $e->salaryPayments()->create(['period' => NepaliDate::period($first->copy()->subDay()), 'amount' => 5000, 'paid_on' => today()]);

        $finance = Livewire::test('pages::finance');
        $this->assertSame(38000.0, $finance->instance()->summary['salaries']);
        $finance->assertSee(['Suyog Dahal', 'Salary · '.NepaliDate::MONTHS[$m - 1].' '.$y]);
        $this->assertSame([38000.0], collect($finance->instance()->entries)->where('source', 'salary')->pluck('amount')->all());

        // The same on the Overview.
        $this->get(route('dashboard'))->assertOk();
        $this->assertSame(38000.0, collect(Livewire::test('pages::dashboard')->instance()->flow)->last()['spent']);

        // Scope: every month in the range.
        $this->assertSame(2, SalaryPayment::forMonthsIn($first->copy()->subDay(), today())->count());
    }
}
