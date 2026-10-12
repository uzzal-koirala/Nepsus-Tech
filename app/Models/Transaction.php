<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;

/** Income or an expense entered on the Finance page. */
#[Fillable(['kind', 'category', 'title', 'amount', 'date', 'method', 'note', 'receipt_path'])]
class Transaction extends Model
{
    public const INCOME_CATEGORIES = [
        'project' => ['label' => 'Project income', 'icon' => 'briefcase'],
        'consulting' => ['label' => 'Consulting', 'icon' => 'chat-bubble-left-right'],
        'commission' => ['label' => 'Commission', 'icon' => 'arrows-right-left'],
        'refund' => ['label' => 'Refund', 'icon' => 'arrow-uturn-left'],
        'other' => ['label' => 'Other income', 'icon' => 'plus-circle'],
    ];

    public const EXPENSE_CATEGORIES = [
        'rent' => ['label' => 'Rent', 'icon' => 'building-office'],
        'utilities' => ['label' => 'Electricity & internet', 'icon' => 'bolt'],
        'software' => ['label' => 'Software & tools', 'icon' => 'computer-desktop'],
        'ads' => ['label' => 'Ads & boosting', 'icon' => 'megaphone'],
        'equipment' => ['label' => 'Equipment', 'icon' => 'camera'],
        'travel' => ['label' => 'Travel', 'icon' => 'truck'],
        'food' => ['label' => 'Food & refreshments', 'icon' => 'cake'],
        'office' => ['label' => 'Office supplies', 'icon' => 'paper-clip'],
        'tax' => ['label' => 'Tax & fees', 'icon' => 'building-library'],
        'other' => ['label' => 'Other expense', 'icon' => 'ellipsis-horizontal-circle'],
    ];

    protected function casts(): array
    {
        return ['amount' => 'decimal:2'];
    }

    protected static function booted(): void
    {
        static::deleted(fn (Transaction $t) => $t->receipt_path && Storage::disk('local')->delete($t->receipt_path));
    }

    /** Stored as a plain Y-m-d date so month ranges compare the same on every database. */
    protected function date(): Attribute
    {
        return Attribute::make(
            get: fn ($value) => Carbon::parse($value)->startOfDay(),
            set: fn ($value) => Carbon::parse($value)->toDateString(),
        );
    }

    /** @return array<string, array{label: string, icon: string}> */
    public static function categories(string $kind): array
    {
        return $kind === 'income' ? self::INCOME_CATEGORIES : self::EXPENSE_CATEGORIES;
    }

    public function categoryLabel(): string
    {
        return __(self::categories($this->kind)[$this->category]['label'] ?? 'Other');
    }
}
