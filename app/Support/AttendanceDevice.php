<?php

namespace App\Support;

use App\Models\Setting;
use Carbon\CarbonInterface;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * Talks to a Hikvision attendance terminal on the office network over ISAPI
 * (HTTP with digest login), e.g. the DS-K1A8603EF-B.
 */
class AttendanceDevice
{
    public function __construct(
        public readonly string $host,
        public readonly string $username,
        public readonly string $password,
    ) {}

    /** The device saved in Settings → Attendance device, or null when not set up. */
    public static function fromSettings(): ?self
    {
        $host = trim((string) Setting::get('device_host'));
        $secret = (string) Setting::get('device_password');

        if ($host === '' || $secret === '') {
            return null;
        }

        return new self($host, (string) Setting::get('device_username', 'admin'), Crypt::decryptString($secret));
    }

    /**
     * Name, model and serial number; throws when the device cannot be reached or the login is wrong.
     *
     * @return array{name: string, model: string, serial: string}
     */
    public function info(): array
    {
        $xml = simplexml_load_string($this->http()->get('/ISAPI/System/deviceInfo')->throw()->body());

        return [
            'name' => (string) ($xml->deviceName ?? ''),
            'model' => (string) ($xml->model ?? ''),
            'serial' => (string) ($xml->serialNumber ?? ''),
        ];
    }

    /**
     * Every punch between two moments, read page by page.
     *
     * @return list<array{user: string, time: Carbon}>
     */
    public function punches(CarbonInterface $from, CarbonInterface $to): array
    {
        $searchId = (string) Str::uuid();
        $position = 0;
        $punches = [];

        do {
            $page = $this->http()->post('/ISAPI/AccessControl/AcsEvent?format=json', [
                'AcsEventCond' => [
                    'searchID' => $searchId,
                    'searchResultPosition' => $position,
                    'maxResults' => 30,
                    'major' => 0, // all event kinds; only ones with an employee number are kept
                    'minor' => 0,
                    'startTime' => $from->toIso8601String(),
                    'endTime' => $to->toIso8601String(),
                ],
            ])->throw()->json('AcsEvent') ?? [];

            $events = $page['InfoList'] ?? [];
            foreach ($events as $event) {
                $user = trim((string) ($event['employeeNoString'] ?? $event['employeeNo'] ?? ''));
                if ($user !== '' && ! empty($event['time'])) {
                    $punches[] = ['user' => $user, 'time' => Carbon::parse($event['time'])->setTimezone(config('app.timezone'))];
                }
            }
            $position += count($events);
        } while (($page['responseStatusStrg'] ?? '') === 'MORE' && $events !== [] && $position < 20000);

        return $punches;
    }

    private function http(): PendingRequest
    {
        $base = Str::startsWith($this->host, ['http://', 'https://']) ? $this->host : 'http://'.$this->host;

        return Http::baseUrl(rtrim($base, '/'))
            ->withDigestAuth($this->username, $this->password)
            ->connectTimeout(5)
            ->timeout(20)
            ->withoutVerifying(); // devices use self-signed certificates
    }
}
