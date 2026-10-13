<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\Task;
use App\Support\NepaliDate;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
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

        $page->call('setStatus', 'on_leave')->assertSee('Bina Rai')->assertDontSee('Sita Gurung');

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

        // A part-paid month takes another payment, but never more than what is left.
        $page->call('openPay', $ram->id)->assertSet('payPeriod', $period)->assertSet('payAmount', '25000');
        $page->set('payAmount', '25001')->call('savePay')->assertHasErrors(['payAmount'])->assertSee('Only NPR 25,000 is left for this month.');
        $page->set('payAmount', '25000')->call('savePay')->assertHasNoErrors();
        $this->assertSame(2, $ram->salaryPayments()->count());

        // Once fully paid the month is locked, and the popup moves on to next month.
        $page->call('openPay', $ram->id)->assertNotSet('payPeriod', $period)->assertSet('payAmount', '40000');
        $page->set('payPeriod', $period)->set('payAmount', '100')->call('savePay')->assertHasErrors(['payPeriod'])->assertSee('Salary for this month is already fully paid.');
        $this->assertSame(2, $ram->salaryPayments()->count());

        // Future months (up to 2 ahead) can be paid in advance.
        $ahead = \App\Support\NepaliDate::period(\App\Support\NepaliDate::addMonths(today(), 2));
        $page->call('openPay', $ram->id)->set('payPeriod', $ahead)->call('savePay')->assertHasNoErrors();
        $tooFar = \App\Support\NepaliDate::period(\App\Support\NepaliDate::addMonths(today(), 3));
        $page->call('openPay', $ram->id)->set('payPeriod', $tooFar)->call('savePay')->assertHasErrors(['payPeriod']);
        $lastMonth = \App\Support\NepaliDate::recentPeriods(2)[1];
        $page->call('openPay', $ram->id)->set('payPeriod', $lastMonth)->call('savePay')->assertHasErrors(['payPeriod']);

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

    public function test_profile_shows_salary_and_records_payments(): void
    {
        $employee = Employee::factory()->create(['name' => 'Bina Rai', 'status' => 'active', 'salary' => 40000, 'joined_on' => today()->subYear()]);

        $this->get(route('employees.show', $employee))->assertRedirect(route('login'));
        $this->actingAs(User::factory()->create());
        $this->get(route('employees.show', $employee))->assertOk()->assertSee(['Bina Rai', 'Attendance', 'Next salary']);
        $this->get(route('employees.show', ['employee' => $employee, 'tab' => 'salary']))->assertOk()->assertSee('Salary by month');

        $page = Livewire::test('pages::employee-profile', ['employee' => $employee])
            ->call('openPay')
            ->assertSet('payAmount', '40000')
            ->set('payAmount', '15000')
            ->call('savePay')
            ->assertHasNoErrors()
            ->assertSee('Pay NPR 25,000')
            ->assertSee(['To pay this month', 'NPR 15,000 paid of NPR 40,000']);

        $payment = $employee->salaryPayments()->sole();
        $page->call('confirmDeleteSalaryPayment', $payment->id)->assertSee('Delete this salary payment?')->call('deleteSalaryPayment', $payment->id);
        $this->assertSame(0, $employee->salaryPayments()->count());

        $page->call('edit')->set('salary', '45000')->call('save')->assertHasNoErrors();
        $this->assertSame('45000.00', $employee->fresh()->salary);

        $page->call('delete')->assertRedirect(route('employees.index'));
        $this->assertModelMissing($employee);
    }

    public function test_attendance_can_be_marked_on_the_calendar(): void
    {
        $this->actingAs(User::factory()->create());
        $employee = Employee::factory()->create(['status' => 'active']);
        [$y, $m] = NepaliDate::fromAd(today());
        $day = NepaliDate::toAd($y, $m, 1)->toDateString();

        $page = Livewire::test('pages::employee-profile', ['employee' => $employee])
            ->call('markDay', $day, 'leave')
            ->assertSet('leaveDate', $day);
        $this->assertSame(0, $employee->attendances()->count()); // nothing saved without a reason

        $page->set('leaveReason', '')
            ->call('saveLeave')
            ->assertHasErrors(['leaveReason'])
            ->set('leaveReason', 'Sick with fever')
            ->call('saveLeave')
            ->assertHasNoErrors()
            ->assertSee('Sick with fever');
        $this->assertSame(['leave', 'Sick with fever'], [$employee->attendances()->sole()->status, $employee->attendances()->sole()->note]);

        $page->call('markDay', $day, 'present');
        $this->assertSame('present', $employee->attendances()->sole()->status);
        $this->assertSame(1, $page->instance()->calendar['counts']['present']);
        $page->assertSee(['To pay this month', 'Advance paid', 'No salary paid ahead yet', 'Total absent'])->assertDontSee(['Total present', 'Total leave']);

        $page->call('markDay', $day, '');
        $this->assertSame(0, $employee->attendances()->count());

        // Fills every past working day of the month that is not marked yet (Saturdays are off).
        $page->call('markWorkdaysPresent');
        $expected = collect(range(1, NepaliDate::fromAd(today())[2]))
            ->map(fn ($d) => NepaliDate::toAd($y, $m, $d))
            ->reject->isSaturday()->count();
        $this->assertSame($expected, $employee->attendances()->where('status', 'present')->count());
    }

    public function test_salary_day_agreement_work_and_documents(): void
    {
        Storage::fake('local');
        $this->actingAs(User::factory()->create());
        $employee = Employee::factory()->create(['status' => 'active', 'salary' => 30000]);
        $task = Task::factory()->for(\App\Models\Client::factory())->create(['title' => 'Edit Dashain reel', 'completed_at' => null]);
        $task->assignees()->attach($employee);

        $page = Livewire::test('pages::employee-profile', ['employee' => $employee])
            ->call('edit')
            ->set('salaryDay', '40')
            ->call('save')
            ->assertHasErrors(['salaryDay'])
            ->set('salaryDay', '1')
            ->set('contractStart', '2026-04-14')
            ->set('contractEnd', '2027-04-13')
            ->call('save')
            ->assertHasNoErrors();

        $employee->refresh();
        $this->assertSame(1, $employee->salary_day);
        $this->assertSame(1, NepaliDate::fromAd($employee->nextSalaryDate())[2]);
        $this->assertFalse($employee->nextSalaryDate()->isBefore(today()));

        $page->call('setTab', 'work')->assertSee('Edit Dashain reel');

        $page->call('setTab', 'documents')
            ->set('docType', 'agreement')
            ->set('docUpload', UploadedFile::fake()->create('contract.pdf', 120, 'application/pdf'))
            ->call('uploadDocument')
            ->assertHasNoErrors()
            ->assertSee('contract.pdf');

        $doc = $employee->documents()->sole();
        Storage::disk('local')->assertExists($doc->path);
        $this->get(route('employee-documents.download', $doc))->assertOk()->assertDownload('contract.pdf');

        $page->call('deleteDocument', $doc->id);
        Storage::disk('local')->assertMissing($doc->path);
    }

    public function test_saturday_is_a_holiday(): void
    {
        $this->actingAs(User::factory()->create());
        $employee = Employee::factory()->create(['status' => 'active']);
        $saturday = today()->next(\Carbon\CarbonInterface::SATURDAY)->toDateString();

        Livewire::test('pages::employee-profile', ['employee' => $employee])
            ->assertSee('Holiday')
            ->call('markDay', $saturday, 'absent')
            ->assertStatus(422);
        $this->assertSame(0, $employee->attendances()->count());
    }

    public function test_departments_can_be_added_and_deleted_from_the_form(): void
    {
        $this->actingAs(User::factory()->create());
        Employee::factory()->create(['department' => 'design']);

        $page = Livewire::test('pages::employees')
            ->call('create')
            ->set('newDepartment', 'Video Production')
            ->call('addDepartment')
            ->assertSet('dept', 'video-production')
            ->set('name', 'Sujan Thapa')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame('video-production', Employee::firstWhere('name', 'Sujan Thapa')->department);
        $this->assertSame('Video Production', Employee::firstWhere('name', 'Sujan Thapa')->departmentMeta()['label']);

        // A department with people in it is kept; an empty one is deleted.
        $page->call('deleteDepartment', 'design');
        $this->assertTrue(\App\Models\Department::where('slug', 'design')->exists());
        $page->call('deleteDepartment', 'sales');
        $this->assertFalse(\App\Models\Department::where('slug', 'sales')->exists());
    }

    public function test_profile_shows_this_months_salary_status(): void
    {
        $this->actingAs(User::factory()->create());
        $employee = Employee::factory()->create(['status' => 'active', 'salary' => 30000, 'salary_day' => 1]);

        $page = Livewire::test('pages::employee-profile', ['employee' => $employee]);
        $page->assertSee(NepaliDate::periodLabel(NepaliDate::period(today())).' salary');
        NepaliDate::fromAd(today())[2] > 1 ? $page->assertSee('Overdue') : $page->assertSee('Unpaid');

        // Salary day 1 has passed unless today is the 1st, so the calendar marks it unpaid.
        $page->assertSee(NepaliDate::fromAd(today())[2] > 1 ? 'Salary unpaid' : 'Salary day');
        $page->call('openPay')->set('payAmount', '10000')->call('savePay')->assertSee(['Part paid', 'Salary part', 'Paid NPR 10,000']);
        $page->call('openPay')->assertSet('payPeriod', NepaliDate::period(today()))->assertSet('payAmount', '20000')->call('savePay')->assertHasNoErrors()->assertSee('Paid on');

        // The salary history lists only the payments made.
        $page->assertSee(['Salary history', 'Total paid', 'Salary for'])->assertDontSee('Months fully paid');

        // A payment can carry its own title.
        $next = \App\Models\SalaryPayment::firstOpenPeriod($employee->fullyPaidPeriods());
        $page->call('openPay')->assertSet('payTitle', '')->set('payPeriod', $next)->set('payTitle', 'Dashain bonus')->set('payAmount', '5000')->call('savePay')->assertHasNoErrors()->assertSee('Dashain bonus')
            ->assertSee('For '.str(NepaliDate::periodLabel($next))->before(' '))->assertDontSee('No salary paid ahead yet');
        $this->assertSame('Dashain bonus', $employee->salaryPayments()->where('period', $next)->value('title'));
    }

    public function test_personal_details_can_be_saved(): void
    {
        $this->actingAs(User::factory()->create());
        $employee = Employee::factory()->create();

        Livewire::test('pages::employee-profile', ['employee' => $employee])
            ->call('setTab', 'details')
            ->assertSee(['Personal', 'Family', 'Emergency contact', 'Bank'])
            ->call('openDetails')
            ->set('info.blood_group', 'Z+')
            ->call('saveDetails')
            ->assertHasErrors(['info.blood_group'])
            ->set('info.blood_group', 'O+')
            ->set('info.father_name', 'Hari Dahal')
            ->set('info.mother_name', 'Sita Dahal')
            ->set('info.district', 'Chitwan')
            ->set('info.date_of_birth', '1998-05-12')
            ->set('info.emergency_name', 'Ram Dahal')
            ->set('info.emergency_phone', '9800000000')
            ->call('saveDetails')
            ->assertHasNoErrors()
            ->assertSee(['Hari Dahal', 'Sita Dahal', 'Chitwan', 'Ram Dahal', '9800000000']);

        $employee->refresh();
        $this->assertSame('O+', $employee->blood_group);
        $this->assertSame('1998-05-12', $employee->date_of_birth->toDateString());
        $this->assertNull($employee->spouse_name);
    }

    public function test_employee_photo_can_be_uploaded_and_removed(): void
    {
        Storage::fake('public');
        $this->actingAs(User::factory()->create());
        $employee = Employee::factory()->create();

        $page = Livewire::test('pages::employee-profile', ['employee' => $employee])
            ->set('photo', UploadedFile::fake()->create('notes.pdf', 10, 'application/pdf'))
            ->assertHasErrors('photo')
            ->set('photo', UploadedFile::fake()->image('me.png'))
            ->assertHasNoErrors();

        $path = $employee->refresh()->avatar_path;
        $this->assertNotNull($path);
        Storage::disk('public')->assertExists($path);
        $page->assertSee($employee->avatarUrl());

        $page->call('removePhoto');
        $this->assertNull($employee->refresh()->avatar_path);
        Storage::disk('public')->assertMissing($path);
    }
}
