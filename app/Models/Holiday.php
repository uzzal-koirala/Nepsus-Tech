<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/** A day off for everyone (Dashain, Tihar...). Counts as a holiday wherever isHoliday() is asked, like the weekly one. */
#[Fillable(['date', 'name'])]
class Holiday extends Model
{
    /** @var array<string, string>|null date => name, loaded once per request */
    private static ?array $byDate = null;

    protected static function booted(): void
    {
        static::saved(fn () => self::forget());
        static::deleted(fn () => self::forget());
    }

    public static function forget(): void
    {
        self::$byDate = null;
    }

    /** Stored as a plain Y-m-d date so lookups match on every database. */
    protected function date(): Attribute
    {
        return Attribute::make(
            get: fn ($value) => Carbon::parse($value)->startOfDay(),
            set: fn ($value) => Carbon::parse($value)->toDateString(),
        );
    }

    /** The holiday's name on that day, or null. */
    public static function nameOn(CarbonInterface|string $date): ?string
    {
        self::$byDate ??= static::pluck('name', 'date')->mapWithKeys(fn ($name, $d) => [Carbon::parse($d)->toDateString() => $name])->all();

        return self::$byDate[$date instanceof CarbonInterface ? $date->toDateString() : Carbon::parse($date)->toDateString()] ?? null;
    }
}
