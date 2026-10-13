<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Validation\Rule;

#[Fillable(['name', 'location', 'stream_path', 'camera_ip', 'rtsp_path', 'transcode', 'enabled', 'position', 'last_online_at'])]
class CctvCamera extends Model
{
    protected function casts(): array
    {
        return [
            'enabled' => 'boolean',
            'transcode' => 'boolean',
            'position' => 'integer',
            'last_online_at' => 'datetime',
        ];
    }

    public static function rules(?int $ignoreId = null): array
    {
        return [
            'name' => ['required', 'string', 'max:80'],
            'location' => ['nullable', 'string', 'max:120'],
            'stream_path' => ['required', 'string', 'max:64', 'regex:/^[a-z0-9][a-z0-9_-]*$/', Rule::unique('cctv_cameras', 'stream_path')->ignore($ignoreId)],
            'camera_ip' => ['nullable', 'ip'],
            'rtsp_path' => ['required', 'string', 'max:120', 'regex:/^\/[^\s@]*$/'],
            'transcode' => ['boolean'],
            'enabled' => ['boolean'],
        ];
    }

    public function logs(): HasMany
    {
        return $this->hasMany(CctvAccessLog::class);
    }

    /** Gateway address set in CCTV → Cameras, e.g. https://office-pc.tailnet.ts.net (no trailing slash). */
    public static function gateway(): ?string
    {
        $url = rtrim(trim((string) Setting::get('cctv_gateway_url')), '/');

        return $url !== '' ? $url : null;
    }

    /** Browser-playable HLS address of this camera on the gateway. */
    public function hlsUrl(): ?string
    {
        return ($gateway = self::gateway()) ? $gateway.'/'.$this->stream_path.'/index.m3u8' : null;
    }
}
