<?php

namespace Tests\Unit;

use App\Support\NepaliDate;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class NepaliDateTest extends TestCase
{
    public function test_ad_dates_convert_to_bikram_sambat(): void
    {
        // Expected values from nepali-date-converter 3.4.0.
        $cases = [
            '1943-04-14' => [2000, 1, 1],
            '2025-01-01' => [2081, 9, 17],
            '2026-04-14' => [2083, 1, 1],
            '2026-10-12' => [2083, 6, 26],
            '2027-04-13' => [2083, 12, 30],
            '2030-12-31' => [2087, 9, 15],
        ];

        foreach ($cases as $ad => $bs) {
            $this->assertSame($bs, NepaliDate::fromAd(Carbon::parse($ad)), $ad);
        }
    }

    public function test_bs_dates_convert_back_to_ad_and_give_month_ranges(): void
    {
        $this->assertSame('2026-10-12', NepaliDate::toAd(2083, 6, 26)->toDateString());
        $this->assertSame('1943-04-14', NepaliDate::toAd(2000, 1, 1)->toDateString());

        // Asoj 2083 (31 days) runs 2026-09-17 to 2026-10-17.
        [$start, $end] = NepaliDate::monthRange(Carbon::parse('2026-10-12'));
        $this->assertSame('2026-09-17 00:00:00', $start->toDateTimeString());
        $this->assertSame('2026-10-17 23:59:59', $end->toDateTimeString());
    }

    public function test_formats_with_english_month_names(): void
    {
        $this->assertSame('26 Ashoj 2083', NepaliDate::format(Carbon::parse('2026-10-12')));
        $this->assertSame('26 Ashoj', NepaliDate::format(Carbon::parse('2026-10-12'), withYear: false));
        $this->assertSame('Ashoj 2083', NepaliDate::monthYear(Carbon::parse('2026-10-12')));
        $this->assertSame('', NepaliDate::format(null));
        $this->assertSame('1 Jan 1900', NepaliDate::format(Carbon::parse('1900-01-01')));
    }
}
