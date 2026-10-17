<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Invoice;
use App\Models\Package;
use App\Models\Payment;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class InvoicesTest extends TestCase
{
    use RefreshDatabase;

    private function invoice(array $attributes = [], array $items = [['Social media management', 1, 25000], ['Reel editing', 4, 2500]]): Invoice
    {
        $invoice = Invoice::create($attributes + ['type' => 'invoice', 'number' => Invoice::nextNumber($attributes['type'] ?? 'invoice'), 'bill_name' => 'Ram Sharma', 'bill_company' => 'Himalayan Cafe', 'issue_date' => today(), 'due_date' => today()->addDays(15), 'tax_rate' => 13, 'discount' => 5000, 'template' => 'classic', 'accent' => 'green']);
        foreach ($items as $pos => [$desc, $qty, $price]) {
            $invoice->items()->create(['description' => $desc, 'quantity' => $qty, 'price' => $price, 'position' => $pos]);
        }

        return $invoice->fresh('items');
    }

    public function test_totals_numbers_and_statuses(): void
    {
        $this->assertSame(['subtotal' => 35000.0, 'discount' => 5000.0, 'tax' => 3900.0, 'total' => 33900.0], $this->invoice()->totals());
        $this->assertSame(['subtotal' => 100.0, 'discount' => 100.0, 'tax' => 0.0, 'total' => 0.0], Invoice::totalsFor([['quantity' => 1, 'price' => 100]], 500, 13)); // discount never goes below zero

        $this->assertSame('INV-0002', Invoice::nextNumber('invoice'));
        $this->assertSame('QT-0001', Invoice::nextNumber('quotation'));

        $this->assertSame('overdue', $this->invoice(['due_date' => today()->subDay(), 'status' => 'sent'])->displayStatus());
        $this->assertSame('expired', $this->invoice(['type' => 'quotation', 'due_date' => today()->subDay()])->displayStatus());
    }

    public function test_pages_render_every_template(): void
    {
        $this->get(route('invoices.index'))->assertRedirect(route('login'));
        $this->actingAs(User::factory()->create());
        Setting::put(['business_name' => 'Nepsus Tech']);

        $this->get(route('invoices.index'))->assertOk()->assertSee(['Invoices &amp; Quotations', 'No invoices yet'], false);
        $this->get(route('invoices.index', ['type' => 'quotation']))->assertOk()->assertSee('No quotations yet');

        foreach (['classic', 'minimal', 'bold'] as $tpl) {
            $inv = $this->invoice(['template' => $tpl]);
            $this->get(route('invoices.show', $inv))->assertOk()->assertSee([$inv->number, 'INVOICE', 'Himalayan Cafe', 'Social media management', 'NPR 33,900', 'Nepsus Tech']);
            $this->get(route('invoices.print', $inv))->assertOk()->assertSee(['Save as PDF', 'NPR 33,900']);
        }
        $this->get(route('invoices.edit', $inv))->assertOk()->assertSee('Live preview');
        $this->get(route('invoices.index'))->assertSee(['INV-0001', 'NPR 33,900']);
    }

    public function test_invoice_is_created_in_the_editor_with_a_client_and_package(): void
    {
        $this->actingAs(User::factory()->create());
        $client = Client::factory()->create(['name' => 'Sita Rai', 'company' => 'Everest Gym', 'phone' => '9811111111']);
        $package = Package::create(['name' => 'Growth plan', 'description' => '12 posts + 4 reels', 'price' => 30000, 'billing_cycle' => 'monthly', 'is_active' => true]);

        $page = Livewire::test('pages::invoice-editor')
            ->assertSet('type', 'invoice')
            ->set('clientId', (string) $client->id)
            ->assertSet('billCompany', 'Everest Gym')
            ->call('addPackage', $package->id)
            ->assertSet('items.0.description', 'Growth plan')
            ->call('addItem')
            ->call('save')
            ->assertHasErrors(['items.1.description', 'items.1.price'])
            ->call('removeItem', 1)
            ->set('taxRate', '13')
            ->set('template', 'bold')
            ->set('accent', 'blue')
            ->set('paymentInfo', 'NIC Asia Bank 0000')
            ->assertSee('NPR 33,900') // live preview total
            ->call('save')
            ->assertHasNoErrors();

        $inv = Invoice::sole();
        $this->assertSame(['INV-0001', 'invoice', 'draft', $client->id, 'bold', 'blue'], [$inv->number, $inv->type, $inv->status, $inv->client_id, $inv->template, $inv->accent]);
        $this->assertSame(33900.0, $inv->totals()['total']);
        $page->assertRedirect(route('invoices.show', $inv));

        // The next document remembers the look and payment details.
        Livewire::test('pages::invoice-editor')->assertSet('template', 'bold')->assertSet('accent', 'blue')->assertSet('paymentInfo', 'NIC Asia Bank 0000');
    }

    public function test_paid_invoice_records_a_client_payment(): void
    {
        $this->actingAs(User::factory()->create());
        $client = Client::factory()->create();
        $inv = $this->invoice(['client_id' => $client->id]);

        Livewire::test('pages::invoice-show', ['invoice' => $inv])
            ->call('setStatus', 'sent')
            ->call('openPaid')
            ->set('method', 'eSewa')
            ->call('markPaid')
            ->assertHasNoErrors();

        $inv->refresh();
        $payment = Payment::sole();
        $this->assertSame(['paid', $payment->id], [$inv->status, $inv->payment_id]);
        $this->assertSame([33900.0, 'eSewa', $client->id, 'INV-0001'], [(float) $payment->amount, $payment->method, $payment->client_id, $payment->reference]);
        $this->assertNotNull($payment->paid_at);
    }

    public function test_quotation_converts_to_invoice_and_documents_can_be_duplicated(): void
    {
        $this->actingAs(User::factory()->create());
        $quote = $this->invoice(['type' => 'quotation', 'number' => 'QT-0001']);
        $this->get(route('invoices.show', $quote))->assertSee(['QUOTATION', 'Convert to invoice']);

        Livewire::test('pages::invoice-show', ['invoice' => $quote])->call('convert');

        $invoice = Invoice::where('type', 'invoice')->sole();
        $quote->refresh();
        $this->assertSame(['accepted', $invoice->id], [$quote->status, $quote->converted_to_id]);
        $this->assertSame($quote->totals(), $invoice->fresh('items')->totals());
        $this->assertSame('INV-0001', $invoice->number);

        Livewire::test('pages::invoice-show', ['invoice' => $invoice])->call('duplicate');
        $this->assertSame(['INV-0001', 'INV-0002'], Invoice::where('type', 'invoice')->orderBy('id')->pluck('number')->all());

        Livewire::test('pages::invoice-show', ['invoice' => $invoice])->call('delete')->assertRedirect(route('invoices.index'));
        $this->assertModelMissing($invoice);
    }
}
