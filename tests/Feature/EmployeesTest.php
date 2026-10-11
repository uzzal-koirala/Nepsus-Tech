<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class EmployeesTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_are_redirected_to_login(): void
    {
        $this->get(route('employees.index'))->assertRedirect(route('login'));
    }

    public function test_employees_can_be_added_filtered_edited_and_removed(): void
    {
        $this->actingAs(User::factory()->create());

        Employee::factory()->create(['name' => 'Bina Rai', 'department' => 'design', 'status' => 'on_leave']);
        Employee::factory()->create(['name' => 'Kiran Shrestha', 'department' => 'development', 'status' => 'active']);

        $this->get(route('employees.index'))->assertOk()->assertSee('Bina Rai')->assertSee('Kiran Shrestha');

        $page = Livewire::test('pages::employees')
            ->set('name', 'Sita Gurung')
            ->set('dept', 'finance')
            ->call('save')
            ->assertHasErrors(['dept'])
            ->set('dept', 'marketing')
            ->set('designation', 'Social Media Manager')
            ->set('phone', '9812345678')
            ->set('joinedOn', '2026-04-14')
            ->call('save')
            ->assertHasNoErrors()
            ->assertSee('Sita Gurung')
            ->assertSee('1 Baisakh 2083');

        $sita = Employee::where('name', 'Sita Gurung')->sole();
        $this->assertSame('marketing', $sita->department);
        $this->assertSame('9779812345678', $sita->whatsappNumber());

        $page->set('department', 'design')->assertSee('Bina Rai')->assertDontSee('Kiran Shrestha')
            ->set('department', '')->call('setStatus', 'on_leave')->assertSee('Bina Rai')->assertDontSee('Sita Gurung');

        $page->call('edit', $sita->id)->assertSet('dept', 'marketing')->set('employeeStatus', 'inactive')->call('save');
        $this->assertSame('inactive', $sita->fresh()->status);

        $page->call('confirmDelete', $sita->id)->call('delete');
        $this->assertModelMissing($sita);
    }

    public function test_salary_can_be_paid_in_parts_and_shows_progress(): void
    {
        $this->actingAs(User::factory()->create());
        $period = \App\Support\NepaliDate::period(today());

        $ram = Employee::factory()->create(['name' => 'Ram Thapa', 'salary' => 40000, 'status' => 'active']);

        $page = Livewire::test('pages::employees')
            ->call('openPay', $ram->id)
            ->assertSet('payAmount', '40000')
            ->assertSet('payPeriod', $period)
            ->set('payAmount', '15000')
            ->call('savePay')
            ->assertHasNoErrors()
            ->assertSee('38%');

        $this->assertSame(['paid' => 15000.0, 'salary' => 40000.0, 'left' => 25000.0, 'percent' => 38], Employee::withSalaryPaid()->find($ram->id)->salaryProgress());

        $page->call('openPay', $ram->id)->assertSet('payAmount', '25000')->call('savePay');
        $this->assertSame(0.0, Employee::withSalaryPaid()->find($ram->id)->salaryProgress()['left']);

        $page->set('payPeriod', '1990-01')->set('payAmount', '100')->call('savePay')->assertHasErrors(['payPeriod']);

        $payment = $ram->salaryPayments()->first();
        $page->call('openHistory', $ram->id)->call('deleteSalaryPayment', $payment->id);
        $this->assertModelMissing($payment);
    }

    public function test_tenure_reads_naturally(): void
    {
        $this->travelTo('2026-10-12');

        $this->assertSame('2y 3m', Employee::factory()->make(['joined_on' => '2024-07-01'])->tenure());
        $this->assertSame('1y', Employee::factory()->make(['joined_on' => '2025-10-01'])->tenure());
        $this->assertSame('5m', Employee::factory()->make(['joined_on' => '2026-05-10'])->tenure());
        $this->assertSame('New', Employee::factory()->make(['joined_on' => '2026-10-05'])->tenure());
    }
}
