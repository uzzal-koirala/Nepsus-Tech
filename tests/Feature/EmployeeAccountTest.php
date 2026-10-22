<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;
use Tests\TestCase;

class EmployeeAccountTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_login_is_created_changed_and_removed_from_the_employee_profile(): void
    {
        $admin = User::factory()->create(['email' => 'admin@example.com']);
        $this->actingAs($admin);
        $employee = Employee::factory()->create(['name' => 'Sita Rai', 'email' => 'sita@example.com', 'status' => 'active']);

        $page = Livewire::test('pages::employee-profile', ['employee' => $employee])->call('setTab', 'account')
            ->assertSee(['No login yet', 'Create login'])->assertSet('accEmail', 'sita@example.com');

        // Taken email and short password are refused.
        $page->set('accEmail', 'admin@example.com')->set('accPassword', 'short')->call('createAccount')
            ->assertHasErrors(['accEmail', 'accPassword']);

        $page->set('accEmail', 'Sita@Example.com')->set('accPassword', 'secret-pass-1')->call('createAccount')->assertHasNoErrors();
        $user = $employee->fresh()->user;
        $this->assertSame(['Sita Rai', 'sita@example.com'], [$user->name, $user->email]);
        $this->assertNotNull($user->email_verified_at);
        $this->assertTrue(Auth::validate(['email' => 'sita@example.com', 'password' => 'secret-pass-1']));

        // New password; empty password keeps the old one.
        Livewire::test('pages::employee-profile', ['employee' => $employee->fresh()])->call('setTab', 'account')
            ->assertSee('Can sign in')
            ->call('generatePassword')->assertNotSet('accPassword', '')
            ->set('accPassword', 'brand-new-pass')->call('updateAccount')->assertHasNoErrors()
            ->set('accEmail', 'sita.rai@example.com')->set('accPassword', '')->call('updateAccount')->assertHasNoErrors();
        $user->refresh();
        $this->assertSame('sita.rai@example.com', $user->email);
        $this->assertTrue(Hash::check('brand-new-pass', $user->password));

        Livewire::test('pages::employee-profile', ['employee' => $employee->fresh()])->call('removeAccount');
        $this->assertModelMissing($user);
        $this->assertNull($employee->fresh()->user_id);

        // You cannot remove your own login, and deleting an employee removes theirs.
        Employee::whereKey($employee->id)->update(['user_id' => $admin->id]);
        Livewire::test('pages::employee-profile', ['employee' => $employee->fresh()])->call('removeAccount')->assertForbidden();
        $other = Employee::factory()->create();
        $login = User::factory()->create();
        $other->forceFill(['user_id' => $login->id])->save();
        $other->delete();
        $this->assertModelMissing($login);
    }
}
