<?php

namespace App\Http\Controllers;

use App\Models\Setting;
use App\Support\AttendancePunches;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * Receives punches from the office PC helper (when the app is hosted online and
 * cannot reach the attendance device on the office network itself).
 */
class AttendancePushController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $key = (string) Setting::get('device_push_token');
        abort_unless($key !== '' && hash_equals($key, (string) $request->bearerToken()), 401, 'Wrong or missing key.');

        $data = $request->validate([
            'computer' => ['nullable', 'string', 'max:64'],
            'punches' => ['present', 'array', 'max:5000'],
            'punches.*.user' => ['required', 'string', 'max:32'],
            'punches.*.time' => ['required', 'date_format:Y-m-d H:i:s'],
        ]);

        $result = AttendancePunches::apply(array_map(fn ($p) => [
            'user' => $p['user'],
            'time' => Carbon::createFromFormat('Y-m-d H:i:s', $p['time'], config('app.timezone')),
        ], $data['punches']));

        Setting::put([
            'device_last_sync' => now()->toIso8601String(),
            'device_last_result' => json_encode($result),
            'device_last_error' => '',
            'device_helper_seen' => now()->toIso8601String(),
            'device_helper_computer' => $data['computer'] ?? '',
        ]);

        return response()->json($result);
    }
}
