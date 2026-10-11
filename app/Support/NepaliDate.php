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

    public const MONTHS = ['Baisakh', 'Jestha', 'Asar', 'Shrawan', 'Bhadra', 'Ashoj', 'Kartik', 'Mangsir', 'Poush', 'Magh', 'Falgun', 'Chaitra'];

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
     * BS year, month (1-12), day to the AD date.
     */
    public static function toAd(int $year, int $month, int $day): Carbon
    {
        $days = $day - 1;

        foreach (self::calendar() as $y => $months) {
            if ((int) $y === $year) {
                $days += array_sum(array_slice($months, 0, $month - 1));
                break;
            }

            $days += array_sum($months);
        }

        return Carbon::parse(self::EPOCH_AD)->addDays($days);
    }

    /**
     * First and last AD day of the BS month containing $date.
     *
     * @return array{0: Carbon, 1: Carbon}
     */
    public static function monthRange(CarbonInterface $date): array
    {
        [$year, $month] = self::fromAd($date);
        $length = self::calendar()[(string) $year][$month - 1];

        return [self::toAd($year, $month, 1)->startOfDay(), self::toAd($year, $month, $length)->endOfDay()];
    }

    /**
     * BS payroll month key for a date, e.g. "2083-06".
     */
    public static function period(CarbonInterface $date): string
    {
        [$year, $month] = self::fromAd($date);

        return sprintf('%d-%02d', $year, $month);
    }

    /**
     * "2083-06" => "Ashoj 2083".
     */
    public static function periodLabel(string $period): string
    {
        [$year, $month] = array_map('intval', explode('-', $period));

        return self::MONTHS[$month - 1].' '.$year;
    }

    /**
     * The current BS month and the ones before it, newest first.
     *
     * @return list<string>
     */
    public static function recentPeriods(int $count = 3, ?CarbonInterface $from = null): array
    {
        [$year, $month] = self::fromAd($from ?? today());
        $periods = [];

        for ($i = 0; $i < $count; $i++) {
            $periods[] = sprintf('%d-%02d', $year, $month);
            [$year, $month] = $month === 1 ? [$year - 1, 12] : [$year, $month - 1];
        }

        return $periods;
    }

    /**
     * e.g. "26 Ashoj 2083"; falls back to the AD date outside the supported range.
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

        return $day.' '.self::MONTHS[$month - 1].($withYear ? ' '.$year : '');
    }

    /**
     * e.g. "Ashoj 2083".
     */
    public static function monthYear(?CarbonInterface $date): string
    {
        if ($date === null || ! $bs = self::fromAd($date)) {
            return (string) $date?->format('M Y');
        }

        return self::MONTHS[$bs[1] - 1].' '.$bs[0];
    }
}
