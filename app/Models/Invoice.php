<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/** An invoice or a quotation (type), with its line items. */
#[Fillable(['type', 'number', 'client_id', 'bill_name', 'bill_company', 'bill_phone', 'bill_email', 'bill_address', 'issue_date', 'due_date', 'status', 'discount', 'tax_rate', 'notes', 'terms', 'payment_info', 'template', 'accent', 'payment_id', 'converted_to_id'])]
class Invoice extends Model
{
    public const TEMPLATES = [
        'classic' => ['label' => 'Classic', 'hint' => 'Dark header with angled stripes'],
        'minimal' => ['label' => 'Minimal', 'hint' => 'Clean page with a side stripe'],
        'bold' => ['label' => 'Bold', 'hint' => 'Full dark header and accent bands'],
    ];

    /** Accent colours: hex for the paper, plus a Tailwind swatch for pickers. */
    public const ACCENTS = [
        'green' => ['hex' => '#8bc34a', 'label' => 'Green'],
        'blue' => ['hex' => '#3151fe', 'label' => 'Nepsus blue'],
        'red' => ['hex' => '#e5393b', 'label' => 'Red'],
        'orange' => ['hex' => '#f59e0b', 'label' => 'Orange'],
        'violet' => ['hex' => '#7c3aed', 'label' => 'Violet'],
        'teal' => ['hex' => '#0d9488', 'label' => 'Teal'],
    ];

    public const STATUSES = [
        'invoice' => ['draft' => 'Draft', 'sent' => 'Sent', 'paid' => 'Paid'],
        'quotation' => ['draft' => 'Draft', 'sent' => 'Sent', 'accepted' => 'Accepted', 'declined' => 'Declined'],
    ];

    protected function casts(): array
    {
        return [
            'issue_date' => 'date',
            'due_date' => 'date',
            'discount' => 'decimal:2',
            'tax_rate' => 'decimal:2',
        ];
    }

    public function items(): HasMany
    {
        return $this->hasMany(InvoiceItem::class)->orderBy('position');
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }

    public function convertedTo(): BelongsTo
    {
        return $this->belongsTo(self::class, 'converted_to_id');
    }

    public function isQuote(): bool
    {
        return $this->type === 'quotation';
    }

    /** Next free number: INV-0001, QT-0001, ... */
    public static function nextNumber(string $type): string
    {
        $prefix = $type === 'quotation' ? 'QT-' : 'INV-';
        $n = (int) static::where('type', $type)->count() + 1;
        while (static::where('number', $prefix.str_pad((string) $n, 4, '0', STR_PAD_LEFT))->exists()) {
            $n++;
        }

        return $prefix.str_pad((string) $n, 4, '0', STR_PAD_LEFT);
    }

    /**
     * Subtotal, discount, tax and total for a list of items.
     *
     * @param  iterable<array{quantity: mixed, price: mixed}>  $items
     * @return array{subtotal: float, discount: float, tax: float, total: float}
     */
    public static function totalsFor(iterable $items, float $discount, float $taxRate): array
    {
        $subtotal = 0.0;
        foreach ($items as $item) {
            $subtotal += (float) ($item['quantity'] ?? 0) * (float) ($item['price'] ?? 0);
        }
        $discount = min(max(0, $discount), $subtotal);
        $tax = round(($subtotal - $discount) * max(0, $taxRate) / 100, 2);

        return ['subtotal' => round($subtotal, 2), 'discount' => round($discount, 2), 'tax' => $tax, 'total' => round($subtotal - $discount + $tax, 2)];
    }

    public function totals(): array
    {
        return self::totalsFor($this->items->map(fn ($i) => ['quantity' => $i->quantity, 'price' => $i->price]), (float) $this->discount, (float) $this->tax_rate);
    }

    /** Status shown to people: overdue / expired are worked out from the date. */
    public function displayStatus(): string
    {
        if ($this->due_date && $this->due_date->lt(today())) {
            if (! $this->isQuote() && $this->status !== 'paid') {
                return 'overdue';
            }
            if ($this->isQuote() && in_array($this->status, ['draft', 'sent'], true)) {
                return 'expired';
            }
        }

        return $this->status;
    }

    public static function statusTone(string $status): string
    {
        return match ($status) {
            'paid', 'accepted' => 'bg-emerald-50 text-emerald-700 ring-emerald-200 dark:bg-emerald-500/10 dark:text-emerald-300 dark:ring-emerald-500/20',
            'sent' => 'bg-sky-50 text-sky-700 ring-sky-200 dark:bg-sky-500/10 dark:text-sky-300 dark:ring-sky-500/20',
            'overdue', 'declined' => 'bg-rose-50 text-rose-700 ring-rose-200 dark:bg-rose-500/10 dark:text-rose-300 dark:ring-rose-500/20',
            'expired' => 'bg-amber-50 text-amber-700 ring-amber-200 dark:bg-amber-500/10 dark:text-amber-300 dark:ring-amber-500/20',
            default => 'bg-zinc-100 text-zinc-600 ring-zinc-200 dark:bg-white/10 dark:text-zinc-300 dark:ring-white/10',
        };
    }

    /**
     * Everything a template needs to draw the paper. The editor builds the same shape
     * from the form, so the live preview and the saved document look identical.
     */
    public function toPaper(): array
    {
        return self::paper([
            'type' => $this->type, 'number' => $this->number, 'status' => $this->displayStatus(),
            'issue_date' => $this->issue_date, 'due_date' => $this->due_date,
            'bill_name' => $this->bill_name, 'bill_company' => $this->bill_company, 'bill_phone' => $this->bill_phone,
            'bill_email' => $this->bill_email, 'bill_address' => $this->bill_address,
            'items' => $this->items->map(fn ($i) => ['description' => $i->description, 'details' => $i->details, 'quantity' => (float) $i->quantity, 'price' => (float) $i->price])->all(),
            'discount' => (float) $this->discount, 'tax_rate' => (float) $this->tax_rate,
            'notes' => $this->notes, 'terms' => $this->terms, 'payment_info' => $this->payment_info,
            'template' => $this->template, 'accent' => $this->accent,
        ]);
    }

    /** Fills in totals, labels and the business details for a paper array. */
    public static function paper(array $doc): array
    {
        $quote = ($doc['type'] ?? 'invoice') === 'quotation';
        $parse = fn ($d) => $d instanceof Carbon ? $d : ($d ? Carbon::parse($d) : null);

        return $doc + [
            'title' => $quote ? 'QUOTATION' : 'INVOICE',
            'to_label' => $quote ? 'Quotation for' : 'Invoice to',
            'due_label' => $quote ? 'Valid until' : 'Due date',
            'accent_hex' => self::ACCENTS[$doc['accent'] ?? 'green']['hex'] ?? self::ACCENTS['green']['hex'],
            'issue' => $parse($doc['issue_date'] ?? null),
            'due' => $parse($doc['due_date'] ?? null),
            'totals' => self::totalsFor($doc['items'] ?? [], (float) ($doc['discount'] ?? 0), (float) ($doc['tax_rate'] ?? 0)),
            'business' => [
                'name' => (string) Setting::get('business_name'),
                'tagline' => (string) Setting::get('business_tagline'),
                'phone' => (string) Setting::get('business_phone'),
                'email' => (string) Setting::get('business_email'),
                'address' => (string) Setting::get('business_address'),
                'website' => (string) Setting::get('business_website'),
                'pan' => (string) Setting::get('business_pan'),
                'logo' => Setting::logoUrl(),
            ],
        ];
    }
}
