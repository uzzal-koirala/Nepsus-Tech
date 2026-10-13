<?php

namespace App\Support;

use App\Models\CctvCamera;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Short-lived signed tokens (RS256 JWT) that let one user watch one camera on the
 * MediaMTX gateway. The gateway checks them with the public key from /api/cctv/jwks,
 * so it needs no password and Nepsus never handles the video itself.
 */
class CctvToken
{
    public const TTL_MINUTES = 30;

    public static function issue(CctvCamera $camera, User $user): string
    {
        [$private, $kid] = self::key();
        $now = time();

        $header = ['alg' => 'RS256', 'typ' => 'JWT', 'kid' => $kid];
        $payload = [
            'iss' => self::issuer(),
            'sub' => (string) $user->id,
            'iat' => $now,
            'exp' => $now + self::TTL_MINUTES * 60,
            'mediamtx_permissions' => [['action' => 'read', 'path' => $camera->stream_path]],
        ];

        $unsigned = self::b64(json_encode($header)).'.'.self::b64(json_encode($payload));
        openssl_sign($unsigned, $signature, $private, OPENSSL_ALGO_SHA256);

        return $unsigned.'.'.self::b64($signature);
    }

    /** Public key set for the gateway (safe to publish). */
    public static function jwks(): array
    {
        [$private, $kid] = self::key();
        $rsa = openssl_pkey_get_details(openssl_pkey_get_private($private))['rsa'];

        return ['keys' => [[
            'kty' => 'RSA', 'use' => 'sig', 'alg' => 'RS256', 'kid' => $kid,
            'n' => self::b64($rsa['n']), 'e' => self::b64($rsa['e']),
        ]]];
    }

    /** Must match authJWTIssuer in the gateway's mediamtx.yml, so it comes from APP_URL (not the address typed in the browser). */
    public static function issuer(): string
    {
        return rtrim((string) config('app.url'), '/');
    }

    /** @return array{0: string, 1: string} private key PEM and key id (created once). */
    private static function key(): array
    {
        if ($stored = Setting::get('cctv_jwt_private')) {
            return [Crypt::decryptString($stored), (string) Setting::get('cctv_jwt_kid')];
        }

        $options = ['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA];
        $key = openssl_pkey_new($options);
        if (! $key) {
            // Windows PHP builds often lack an OpenSSL config file; Linux hosting does not need this.
            $options += self::windowsConfig();
            $key = openssl_pkey_new($options) ?: throw new RuntimeException('Could not create the CCTV signing key: '.openssl_error_string());
        }
        openssl_pkey_export($key, $pem, null, $options);

        $kid = Str::lower(Str::random(12));
        Setting::put(['cctv_jwt_private' => Crypt::encryptString($pem), 'cctv_jwt_kid' => $kid]);

        return [$pem, $kid];
    }

    private static function windowsConfig(): array
    {
        foreach ([getenv('OPENSSL_CONF') ?: '', 'C:/Program Files/Git/usr/ssl/openssl.cnf', 'C:/Program Files/Common Files/SSL/openssl.cnf'] as $path) {
            if ($path !== '' && is_file($path)) {
                return ['config' => $path];
            }
        }

        return [];
    }

    private static function b64(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }
}
