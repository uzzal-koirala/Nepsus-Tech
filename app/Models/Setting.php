<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

/** Workspace settings (business details and preferences), read with Setting::get(). */
#[Fillable(['key', 'value'])]
class Setting extends Model
{
    protected $primaryKey = 'key';

    public $incrementing = false;

    protected $keyType = 'string';

    public const DEFAULTS = [
        'business_name' => 'Nepsus Tech',
        'business_tagline' => '',
        'business_email' => '',
        'business_phone' => '',
        'business_address' => '',
        'business_website' => '',
        'business_pan' => '',
        'business_logo' => '',
        'currency' => 'NPR',
        'remind_days' => '7',
        'weekly_holiday' => '6', // 0 = Sunday ... 6 = Saturday
        'reminder_emails' => 'info@nepdude.com', // comma separated
        'reminder_emails_on' => '1',
    ];

    /** @var array<string, string|null>|null */
    private static ?array $cache = null;

    public static function get(string $key, mixed $default = null): mixed
    {
        if (self::$cache === null) {
            self::$cache = Schema::hasTable('settings') ? static::pluck('value', 'key')->all() : [];
        }

        return self::$cache[$key] ?? $default ?? (self::DEFAULTS[$key] ?? null);
    }

    /** @param array<string, mixed> $values */
    public static function put(array $values): void
    {
        foreach ($values as $key => $value) {
            static::updateOrCreate(['key' => $key], ['value' => $value === null ? null : (string) $value]);
        }

        self::$cache = null;
    }

    public static function forget(): void
    {
        self::$cache = null;
    }

    public static function logoUrl(): string
    {
        $logo = static::get('business_logo');

        return $logo ? Storage::disk('public')->url($logo) : asset('images/nepsus-logo.png');
    }

    public static function weeklyHoliday(): int
    {
        return (int) static::get('weekly_holiday');
    }
}
