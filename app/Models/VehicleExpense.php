<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

#[Fillable(['category', 'title', 'amount', 'date', 'odometer', 'liters', 'method', 'note'])]
class VehicleExpense extends Model
{
    /** What the money went on, each with a 3D picture in public/images/vehicles. */
    public const CATEGORIES = [
        'fuel' => ['label' => 'Petrol / fuel', 'image' => 'fuel', 'tone' => 'bg-rose-50 text-rose-700 dark:bg-rose-500/10 dark:text-rose-300', 'bar' => 'bg-rose-500'],
        'servicing' => ['label' => 'Servicing', 'image' => 'servicing', 'tone' => 'bg-sky-50 text-sky-700 dark:bg-sky-500/10 dark:text-sky-300', 'bar' => 'bg-sky-500'],
        'maintenance' => ['label' => 'Maintenance / repair', 'image' => 'maintenance', 'tone' => 'bg-zinc-100 text-zinc-700 dark:bg-white/10 dark:text-zinc-200', 'bar' => 'bg-zinc-500'],
        'oil' => ['label' => 'Engine oil', 'image' => 'oil', 'tone' => 'bg-amber-50 text-amber-700 dark:bg-amber-500/10 dark:text-amber-300', 'bar' => 'bg-amber-500'],
        'tyre' => ['label' => 'Tyres', 'image' => 'tyre', 'tone' => 'bg-stone-100 text-stone-700 dark:bg-stone-500/10 dark:text-stone-300', 'bar' => 'bg-stone-500'],
        'insurance' => ['label' => 'Insurance', 'image' => 'insurance', 'tone' => 'bg-indigo-50 text-indigo-700 dark:bg-indigo-500/10 dark:text-indigo-300', 'bar' => 'bg-indigo-500'],
        'tax' => ['label' => 'Bluebook & tax', 'image' => 'tax', 'tone' => 'bg-violet-50 text-violet-700 dark:bg-violet-500/10 dark:text-violet-300', 'bar' => 'bg-violet-500'],
        'parking' => ['label' => 'Parking & toll', 'image' => 'parking', 'tone' => 'bg-blue-50 text-blue-700 dark:bg-blue-500/10 dark:text-blue-300', 'bar' => 'bg-blue-500'],
        'wash' => ['label' => 'Washing', 'image' => 'wash', 'tone' => 'bg-cyan-50 text-cyan-700 dark:bg-cyan-500/10 dark:text-cyan-300', 'bar' => 'bg-cyan-500'],
        'other' => ['label' => 'Other', 'image' => 'other', 'tone' => 'bg-zinc-100 text-zinc-700 dark:bg-white/10 dark:text-zinc-200', 'bar' => 'bg-zinc-400'],
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'liters' => 'decimal:2',
            'odometer' => 'integer',
        ];
    }

    /** Stored as a plain Y-m-d date. */
    protected function date(): Attribute
    {
        return Attribute::make(
            get: fn ($value) => Carbon::parse($value)->startOfDay(),
            set: fn ($value) => Carbon::parse($value)->toDateString(),
        );
    }

    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class);
    }

    public function categoryMeta(): array
    {
        return self::CATEGORIES[$this->category] ?? self::CATEGORIES['other'];
    }

    public static function imageUrl(string $category): string
    {
        return asset('images/vehicles/'.(self::CATEGORIES[$category]['image'] ?? 'other').'.png');
    }

    /** The title given, or the category name. */
    public function label(): string
    {
        return $this->title ?: __($this->categoryMeta()['label']);
    }
}
