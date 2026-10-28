<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class SalaryMethodTest extends TestCase
{
    use RefreshDatabase;

    public function test_paying_a_salary_asks_how_it_was_paid(): void
    {
        $this->actingAs(User::factory()->create());
        $e = Employee::factory()->create(['name' => 'Niksan Adhikari', 'salary' => 20000, 'status' => 'active']);

        // From the profile: no method, no payment.
        $page = Livewire::test('pages::employee-profile', ['employee' => $e])->call('openPay')
            ->assertSee(['Payment method', 'Bank transfer', 'Cheque', 'Cash', 'Other'])
            ->call('savePay')->assertHasErrors('payMethod')->assertSee('Pick how the salary was paid.')
            ->set('payMethod', 'Crypto')->call('savePay')->assertHasErrors('payMethod')
            ->set('payMethod', 'Cheque')->call('savePay')->assertHasNoErrors();
        $this->assertSame('Cheque', $e->salaryPayments()->sole()->method);
        $page->call('openPay')->assertSet('payMethod', '');

        // From the Employees list too.
        $e2 = Employee::factory()->create(['salary' => 15000, 'status' => 'active']);
        Livewire::test('pages::employees')->call('openPay', $e2->id)->set('payMethod', 'Cash')->call('savePay')->assertHasNoErrors();
        $this->assertSame('Cash', $e2->salaryPayments()->sole()->method);

        // Finance shows the method, the profile shows its label.
        Livewire::test('pages::finance')->assertSee(['Niksan Adhikari', 'Cheque']);
        $this->assertSame('Bank transfer', (new \App\Models\SalaryPayment(['method' => 'Bank']))->methodLabel());
    }
}
