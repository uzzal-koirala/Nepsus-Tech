<?php

namespace App\Support;

use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

/**
 * Bikram Sambat (BS) date conversion and formatting.
 *
 * Month lengths in resources/data/bs-calendar.json come from the
 * nepali-date-converter package (MIT) and cover BS 2000-2090 (AD 1943-2034).
 * BS 2000/01/01 is AD 1943-04-14. Dates are stored in AD; this is display only.
 */
final class NepaliDate
{
    public const EPOCH_AD = '1943-04-14';

    public const MONTHS = ['बैशाख', 'जेठ', 'असार', 'साउन', 'भदौ', 'असोज', 'कात्तिक', 'मंसिर', 'पुस', 'माघ', 'फागुन', 'चैत'];

    private static ?array $calendar = null;

    /**
     * @return array<string, list<int>> BS year => 12 month lengths
     */
    public static function calendar(): array
    {
        return self::$calendar ??= json_decode(file_get_contents(resource_path('data/bs-calendar.json')), true);
    }

    /**
     * @return array{0: int, 1: int, 2: int}|null BS [year, month 1-12, day], or null when out of range
     */
    public static function fromAd(CarbonInterface $date): ?array
    {
        $days = (int) Carbon::parse(self::EPOCH_AD)->diffInDays($date->copy()->startOfDay(), false);

        if ($days < 0) {
            return null;
        }

        foreach (self::calendar() as $year => $months) {
            foreach ($months as $index => $length) {
                if ($days < $length) {
                    return [(int) $year, $index + 1, $days + 1];
                }

                $days -= $length;
            }
        }

        return null;
    }

    /**
     * e.g. "२६ असोज २०८३"; falls back to the AD date outside the supported range.
     */
    public static function format(?CarbonInterface $date, bool $withYear = true): string
    {
        if ($date === null) {
            return '';
        }

        if (! $bs = self::fromAd($date)) {
            return $date->format($withYear ? 'j M Y' : 'j M');
        }

        [$year, $month, $day] = $bs;

        return self::digits($day).' '.self::MONTHS[$month - 1].($withYear ? ' '.self::digits($year) : '');
    }

    /**
     * e.g. "असोज २०८३".
     */
    public static function monthYear(?CarbonInterface $date): string
    {
        if ($date === null || ! $bs = self::fromAd($date)) {
            return (string) $date?->format('M Y');
        }

        return self::MONTHS[$bs[1] - 1].' '.self::digits($bs[0]);
    }

    public static function digits(int|string $value): string
    {
        return strtr((string) $value, ['०', '१', '२', '३', '४', '५', '६', '७', '८', '९']);
    }
}
