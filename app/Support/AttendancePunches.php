<?php

namespace App\Support;

use App\Models\Attendance;
use App\Models\Employee;
use App\Models\Setting;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use RuntimeException;
use ZipArchive;

/**
 * Turns punches from the attendance device (live or from an exported file) into attendance marks.
 * A day with a punch becomes Present with the first punch as check-in. Days marked by hand are never changed.
 */
class AttendancePunches
{
    /**
     * @param  iterable<array{user: string, time: \Carbon\CarbonInterface}>  $punches
     * @return array{marked: int, kept: int, punches: int, unknown: list<string>}
     */
    public static function apply(iterable $punches): array
    {
        $employees = Employee::whereNotNull('device_user_id')->pluck('id', 'device_user_id')
            ->mapWithKeys(fn ($id, $deviceId) => [self::normalise((string) $deviceId) => $id]);

        // Every punch time (HH:MM) per person per day.
        $times = [];
        $unknown = [];
        $count = 0;
        foreach ($punches as $punch) {
            $count++;
            $user = self::normalise($punch['user']);
            if (! isset($employees[$user])) {
                $unknown[$user] = true;

                continue;
            }
            $times[$employees[$user].'|'.$punch['time']->toDateString()][] = $punch['time']->format('H:i');
        }

        $marked = $kept = 0;
        foreach ($times as $key => $dayTimes) {
            [$employeeId, $day] = explode('|', $key);
            $mark = Attendance::where('employee_id', $employeeId)->whereDate('date', $day)->first();

            if ($mark && $mark->source !== 'device') {
                $kept++; // marked by hand: leave it alone

                continue;
            }

            // First punch is check-in, last is check-out; earlier syncs of the same day are merged in.
            $all = array_filter([...$dayTimes, $mark?->check_in, $mark?->check_out]);
            [$in, $out] = [min($all), max($all)];
            Attendance::updateOrCreate(
                ['employee_id' => $employeeId, 'date' => $day],
                ['status' => 'present', 'check_in' => $in, 'check_out' => self::minutesBetween($in, $out) >= self::MIN_SHIFT_MINUTES ? $out : null, 'source' => 'device', 'note' => null],
            );
            $marked++;
        }

        return ['marked' => $marked, 'kept' => $kept, 'punches' => $count, 'unknown' => array_map('strval', array_keys($unknown))];
    }

    /**
     * Pulls the last $days days of punches from the device in Settings and marks attendance.
     * The outcome is saved for the settings page; errors are saved too, then rethrown.
     *
     * @return array{marked: int, kept: int, punches: int, unknown: list<string>}
     */
    public static function syncFromDevice(int $days = 2): array
    {
        $device = AttendanceDevice::fromSettings() ?? throw new RuntimeException(__('The attendance device is not set up yet.'));

        try {
            $result = self::apply($device->punches(today()->subDays($days - 1), now()));
            Setting::put(['device_last_sync' => now()->toIso8601String(), 'device_last_result' => json_encode($result), 'device_last_error' => '']);

            return $result;
        } catch (\Throwable $e) {
            Setting::put(['device_last_sync' => now()->toIso8601String(), 'device_last_error' => Str::limit($e->getMessage(), 250)]);

            throw $e;
        }
    }

    /** A second punch this soon after check-in is a double tap, not leaving. */
    public const MIN_SHIFT_MINUTES = 30;

    public static function minutesBetween(string $from, string $to): int
    {
        [$fh, $fm] = array_map('intval', explode(':', $from));
        [$th, $tm] = array_map('intval', explode(':', $to));

        return ($th * 60 + $tm) - ($fh * 60 + $fm);
    }

    /** Device IDs are compared without leading zeros, so "0007" and "7" are the same person. */
    public static function normalise(string $id): string
    {
        $id = trim($id);

        return ltrim($id, '0') === '' ? $id : ltrim($id, '0');
    }

    /**
     * Reads punches from an attendance file exported from the device or iVMS-4200 (.csv or .xlsx).
     * Finds the ID column and the time column (or separate date and time columns) by their headings.
     *
     * @return list<array{user: string, time: Carbon}>
     */
    public static function fromFile(string $path, string $extension): array
    {
        $rows = strtolower($extension) === 'xlsx' ? self::xlsxRows($path) : self::csvRows($path);

        foreach ($rows as $i => $row) {
            $cols = self::columns($row);
            if ($cols !== null) {
                return self::rowsToPunches(array_slice($rows, $i + 1), $cols);
            }
        }

        throw new RuntimeException(__('Could not find the ID and time columns in this file.'));
    }

    /** @return array{id: int, datetime: ?int, date: ?int, time: ?int}|null */
    private static function columns(array $row): ?array
    {
        $find = fn (string $pattern) => collect($row)->search(fn ($cell) => preg_match($pattern, trim((string) $cell)) === 1);

        $id = $find('/^(employee|person|user|staff|emp)?\s*\.?\s*(id|no\.?|number|code)$/i');
        $datetime = $find('/^(attendance\s*|punch\s*|check\s*|access\s*|event\s*)?(date\s*time|time\s*stamp|datetime)$/i');
        $date = $find('/^(attendance\s*|punch\s*)?date$/i');
        $time = $find('/^(attendance\s*|punch\s*|check\s*(in)?\s*)?time$/i');

        return match (true) {
            $id === false => null,
            $datetime !== false => ['id' => $id, 'datetime' => $datetime, 'date' => null, 'time' => null],
            $date !== false && $time !== false => ['id' => $id, 'datetime' => null, 'date' => $date, 'time' => $time],
            $time !== false => ['id' => $id, 'datetime' => $time, 'date' => null, 'time' => null], // a "Time" column holding date and time
            default => null,
        };
    }

    private static function rowsToPunches(array $rows, array $cols): array
    {
        $punches = [];
        foreach ($rows as $row) {
            $user = trim((string) ($row[$cols['id']] ?? ''));
            $raw = $cols['datetime'] !== null
                ? self::cellToString($row[$cols['datetime']] ?? '')
                : trim(self::cellToString($row[$cols['date']] ?? '', 'Y-m-d').' '.self::cellToString($row[$cols['time']] ?? '', 'H:i:s'));

            if ($user === '' || trim($raw) === '') {
                continue;
            }
            try {
                $punches[] = ['user' => $user, 'time' => Carbon::parse($raw, config('app.timezone'))];
            } catch (\Throwable) {
                // not a time: skip the row
            }
        }

        return $punches;
    }

    /** Excel stores dates as day numbers since 1899-12-30; turn those back into text. */
    private static function cellToString(mixed $value, string $format = 'Y-m-d H:i:s'): string
    {
        if (is_numeric($value) && (float) $value > 0 && (float) $value < 100000) {
            $days = (float) $value;
            $base = Carbon::create(1899, 12, 30, 0, 0, 0, config('app.timezone'));

            return $base->addSeconds((int) round($days * 86400))->format($days < 1 ? 'H:i:s' : $format);
        }

        return trim((string) $value);
    }

    private static function csvRows(string $path): array
    {
        $text = (string) file_get_contents($path);
        $text = preg_replace('/^\xEF\xBB\xBF/', '', $text); // UTF-8 BOM
        if (str_starts_with($text, "\xFF\xFE") || str_starts_with($text, "\xFE\xFF")) {
            $text = mb_convert_encoding($text, 'UTF-8', 'UTF-16');
        }
        $lines = preg_split('/\r\n|\r|\n/', $text);
        $delimiter = collect([',', ';', "\t"])->sortByDesc(fn ($d) => substr_count($lines[0] ?? '', $d))->first();

        return array_values(array_filter(array_map(fn ($line) => str_getcsv($line, $delimiter), $lines), fn ($r) => array_filter($r, 'strlen') !== []));
    }

    /** Minimal reader for the first sheet of an .xlsx file. */
    private static function xlsxRows(string $path): array
    {
        $zip = new ZipArchive;
        if ($zip->open($path) !== true) {
            throw new RuntimeException(__('This Excel file could not be opened.'));
        }

        $shared = [];
        if (($xml = $zip->getFromName('xl/sharedStrings.xml')) !== false) {
            foreach (simplexml_load_string($xml)->si as $si) {
                $shared[] = isset($si->t) ? (string) $si->t : implode('', array_map('strval', $si->xpath('.//*[local-name()="t"]')));
            }
        }

        $sheet = $zip->getFromName('xl/worksheets/sheet1.xml');
        $zip->close();
        if ($sheet === false) {
            throw new RuntimeException(__('This Excel file has no sheet.'));
        }

        $rows = [];
        foreach (simplexml_load_string($sheet)->sheetData->row as $row) {
            $cells = [];
            foreach ($row->c as $c) {
                $col = 0;
                foreach (str_split(preg_replace('/\d/', '', (string) $c['r'])) as $letter) {
                    $col = $col * 26 + (ord($letter) - 64);
                }
                $type = (string) $c['t'];
                $cells[$col - 1] = match ($type) {
                    's' => $shared[(int) $c->v] ?? '',
                    'inlineStr' => (string) $c->is->t,
                    default => (string) $c->v,
                };
            }
            if ($cells !== []) {
                $rows[] = array_replace(array_fill(0, max(array_keys($cells)) + 1, ''), $cells);
            }
        }

        return $rows;
    }
}
