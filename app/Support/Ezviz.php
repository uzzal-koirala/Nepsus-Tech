<?php

namespace App\Support;

use App\Models\Setting;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * EZVIZ Open Platform (cloud API): lists the cameras on the EZVIZ account,
 * gives live stream links (HLS) and takes snapshots. Works from any host.
 */
class Ezviz
{
    public const REGIONS = [
        'https://open.ezvizlife.com' => 'International (Asia, Europe, America)',
        'https://open.ys7.com' => 'China',
    ];

    public function __construct(
        public readonly string $appKey,
        public readonly string $secret,
        public readonly string $base = 'https://open.ezvizlife.com',
    ) {}

    /** The account saved in Settings → Cameras, or null when not set up. */
    public static function fromSettings(): ?self
    {
        $key = trim((string) Setting::get('ezviz_app_key'));
        $secret = (string) Setting::get('ezviz_secret');

        if ($key === '' || $secret === '') {
            return null;
        }

        return new self($key, Crypt::decryptString($secret), (string) Setting::get('ezviz_region', 'https://open.ezvizlife.com'));
    }

    /**
     * Access token plus the server to use for this account (cached until shortly before it expires).
     *
     * @return array{token: string, domain: string}
     */
    public function session(): array
    {
        return Cache::remember('ezviz.session.'.md5($this->appKey.$this->secret.$this->base), now()->addDays(6), function () {
            $data = $this->call($this->base, 'token/get', ['appKey' => $this->appKey, 'appSecret' => $this->secret]);

            return ['token' => $data['accessToken'], 'domain' => rtrim($data['areaDomain'] ?? $this->base, '/')];
        });
    }

    /**
     * Every camera (channel) on the account.
     *
     * @return list<array{serial: string, channel: int, name: string, online: bool, picture: ?string, encrypted: bool}>
     */
    public function cameras(): array
    {
        $cameras = [];
        $page = 0;

        do {
            $rows = $this->api('camera/list', ['pageStart' => $page, 'pageSize' => 50]);
            foreach ($rows as $c) {
                $cameras[] = [
                    'serial' => (string) $c['deviceSerial'],
                    'channel' => (int) ($c['channelNo'] ?? 1),
                    'name' => (string) ($c['channelName'] ?? $c['deviceSerial']),
                    'online' => (int) ($c['status'] ?? 0) === 1,
                    'picture' => ($c['picUrl'] ?? '') ?: null,
                    'encrypted' => (int) ($c['isEncrypt'] ?? 0) === 1,
                ];
            }
            $page++;
        } while (count($rows) === 50 && $page < 20);

        return $cameras;
    }

    /** HLS link for live view; quality 1 = HD, 2 = smooth. */
    public function liveUrl(string $serial, int $channel = 1, int $quality = 2, ?string $code = null): string
    {
        $data = $this->api('v2/live/address/get', array_filter([
            'deviceSerial' => $serial,
            'channelNo' => $channel,
            'protocol' => 2, // HLS
            'quality' => $quality,
            'expireTime' => 3600,
            'code' => $code,
        ], fn ($v) => $v !== null && $v !== ''));

        return (string) ($data['url'] ?? throw new RuntimeException(__('EZVIZ did not return a live link.')));
    }

    /** Takes a picture now and returns its link. */
    public function snapshot(string $serial, int $channel = 1): string
    {
        $data = $this->api('device/capture', ['deviceSerial' => $serial, 'channelNo' => $channel]);

        return (string) ($data['picUrl'] ?? throw new RuntimeException(__('EZVIZ did not return a picture.')));
    }

    private function api(string $path, array $params): mixed
    {
        $session = $this->session();

        try {
            return $this->call($session['domain'], $path, $params + ['accessToken' => $session['token']]);
        } catch (RuntimeException $e) {
            // 10002 = token expired or invalid: log in again once.
            if ($e->getCode() !== 10002) {
                throw $e;
            }
            Cache::forget('ezviz.session.'.md5($this->appKey.$this->secret.$this->base));
            $session = $this->session();

            return $this->call($session['domain'], $path, $params + ['accessToken' => $session['token']]);
        }
    }

    private function call(string $base, string $path, array $params): mixed
    {
        $response = Http::asForm()->timeout(20)->post(rtrim($base, '/').'/api/lapp/'.$path, $params)->throw()->json();
        $code = (string) ($response['code'] ?? '');

        if ($code !== '200') {
            throw new RuntimeException(self::explain($code, (string) ($response['msg'] ?? '')), (int) $code);
        }

        return $response['data'] ?? [];
    }

    /** Plain-language messages for the common EZVIZ error codes. */
    private static function explain(string $code, string $msg): string
    {
        return match ($code) {
            '10001', '10005', '10017', '10030' => __('The AppKey or Secret is wrong.'),
            '10002' => __('The EZVIZ login expired. Try again.'),
            '20007' => __('This camera is offline.'),
            '20008' => __('The camera did not answer in time. Try again.'),
            '20018' => __('This camera is not on your EZVIZ account.'),
            '60019' => __('Video encryption is on for this camera. Turn it off in the EZVIZ app, or enter its verification code.'),
            default => __('EZVIZ error :code: :msg', ['code' => $code, 'msg' => $msg]),
        };
    }
}
