<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Employee;
use App\Models\Transaction;
use App\Models\User;
use App\Support\NepaliDate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

class FinanceTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_are_redirected_and_users_see_the_page(): void
    {
        $this->get(route('finance.index'))->assertRedirect(route('login'));

        $this->actingAs(User::factory()->create())
            ->get(route('finance.index'))
            ->assertOk()
            ->assertSee(['Finance', 'Money in', 'Money out', 'Transactions']);
    }

    public function test_money_in_and_out_come_from_payments_salaries_and_entries(): void
    {
        $this->actingAs(User::factory()->create());
        [$y, $m] = NepaliDate::fromAd(today());
        $day = NepaliDate::toAd($y, $m, 2);
        $lastDay = NepaliDate::toAd($y, $m, NepaliDate::monthLength($y, $m));

        $client = Client::factory()->create(['company' => 'Mandala Paradise']);
        $client->payments()->create(['amount' => 40000, 'type' => 'monthly', 'due_date' => $day, 'paid_at' => $day->copy()->setTime(11, 0), 'method' => 'Bank']);
        $client->payments()->create(['amount' => 9999, 'due_date' => $day]); // not received: not money in
        Employee::factory()->create(['name' => 'Bina Rai'])->salaryPayments()->create(['period' => NepaliDate::period(today()), 'amount' => 15000, 'paid_on' => $lastDay]);

        $page = Livewire::test('pages::finance')
            ->call('newEntry', 'expense')
            ->assertSet('category', 'rent')
            ->set('title', '')
            ->call('saveEntry')
            ->assertHasErrors(['title', 'amount'])
            ->set('title', 'Office rent')
            ->set('amount', '12000')
            ->set('date', $day->toDateString())
            ->set('method', 'Cash')
            ->call('saveEntry')
            ->assertHasNoErrors()
            ->call('newEntry', 'income')
            ->assertSet('category', 'project')
            ->set('title', 'Logo for a friend')
            ->set('amount', '5000')
            ->set('date', $lastDay->toDateString())
            ->call('saveEntry')
            ->assertHasNoErrors();

        $sum = $page->instance()->summary;
        $this->assertSame(45000.0, $sum['income']);    // 40,000 client + 5,000 other
        $this->assertSame(27000.0, $sum['spent']);     // 12,000 rent + 15,000 salary (paid on the last day)
        $this->assertSame(18000.0, $sum['net']);
        $this->assertSame(9999.0, $sum['outstanding']);

        $page->assertSee(['Mandala Paradise', 'Bina Rai', 'Office rent', 'Logo for a friend', 'Income', 'Expense'])
            ->set('show', 'expense')
            ->assertSee('Office rent')
            ->assertDontSee('Logo for a friend');

        $rent = Transaction::firstWhere('title', 'Office rent');
        $page->call('deleteEntry', $rent->id);
        $this->assertModelMissing($rent);
    }

    public function test_an_entry_can_have_a_private_receipt(): void
    {
        Storage::fake('local');
        $this->actingAs(User::factory()->create());

        Livewire::test('pages::finance')
            ->call('newEntry', 'expense')
            ->set('title', 'Camera lens')
            ->set('category', 'equipment')
            ->set('amount', '35000')
            ->set('receipt', UploadedFile::fake()->image('bill.png'))
            ->call('saveEntry')
            ->assertHasNoErrors();

        $entry = Transaction::sole();
        Storage::disk('local')->assertExists($entry->receipt_path);
        $this->get(route('transactions.receipt', $entry))->assertOk();

        auth()->logout();
        $this->get(route('transactions.receipt', $entry))->assertRedirect(route('login'));
    }

    public function test_date_filters_and_export(): void
    {
        $this->actingAs(User::factory()->create());
        [$y, $m] = NepaliDate::fromAd(today());
        $thisMonth = NepaliDate::toAd($y, $m, 1);
        $lastMonth = NepaliDate::addMonths($thisMonth, -1);
        Transaction::create(['kind' => 'expense', 'category' => 'rent', 'title' => 'Rent now', 'amount' => 1000, 'date' => $thisMonth]);
        Transaction::create(['kind' => 'expense', 'category' => 'rent', 'title' => 'Rent before', 'amount' => 2000, 'date' => $lastMonth]);

        $page = Livewire::test('pages::finance')
            ->assertSee('Rent now')->assertDontSee('Rent before')
            ->set('from', $lastMonth->toDateString())
            ->set('to', $lastMonth->toDateString())
            ->assertSee('Rent before')->assertDontSee('Rent now');
        $this->assertSame(2000.0, $page->instance()->summary['expenses']);

        // A reversed range is swapped, so both months show.
        // Range is last month only; moving From past To swaps them.
        $page->set('from', $thisMonth->toDateString())
            ->assertSet('from', $lastMonth->toDateString())->assertSet('to', $thisMonth->toDateString())
            ->assertSee(['Rent now', 'Rent before']);

        $page->call('export')->assertFileDownloaded();
    }
}
