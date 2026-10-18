<?php

namespace App\Support;

use App\Models\Setting;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * A Model Context Protocol server (tools only, Streamable HTTP without streaming), so
 * ChatGPT, Claude and other assistants can use the app through the same validated
 * tools as the in-app assistant. The secret link is the password: whoever has it can
 * read, change and delete data (deletes ask first and keep a recovery copy).
 */
class Mcp
{
    private const VERSIONS = ['2025-06-18', '2025-03-26', '2024-11-05'];

    /** Tools that only read; assistants can run these without asking. */
    private const READ_ONLY = [
        'find_clients', 'find_people', 'list_boxes', 'list_tasks', 'calendar', 'get_client', 'list_payments',
        'list_transactions', 'list_invoices', 'list_leads', 'team_today', 'get_employee', 'business_overview', 'check_for_updates', 'list_packages',
    ];

    public static function token(): ?string
    {
        $stored = Setting::get('mcp_token');

        try {
            return $stored ? Crypt::decryptString($stored) : null;
        } catch (\Throwable) {
            return null;
        }
    }

    public static function enabled(): bool
    {
        return static::token() !== null;
    }

    /** A new link; the old one stops working at once. */
    public static function regenerate(): string
    {
        $token = Str::random(48);
        Setting::put(['mcp_token' => Crypt::encryptString($token)]);

        return $token;
    }

    public static function disable(): void
    {
        Setting::put(['mcp_token' => null]);
    }

    public static function check(string $given): bool
    {
        $token = static::token();

        return $token !== null && hash_equals($token, $given);
    }

    public static function url(): ?string
    {
        return ($token = static::token()) ? route('mcp', $token) : null;
    }

    /** One JSON-RPC message in, its reply out (null for notifications). */
    public function handle(mixed $message): ?array
    {
        if (! is_array($message) || ($message['jsonrpc'] ?? null) !== '2.0' || ! is_string($message['method'] ?? null)) {
            return $this->error($message['id'] ?? null, -32600, 'Invalid request');
        }
        if (! array_key_exists('id', $message)) {
            return null; // notifications/initialized and friends need no answer
        }

        $id = $message['id'];
        $params = is_array($message['params'] ?? null) ? $message['params'] : [];

        return match ($message['method']) {
            'initialize' => $this->result($id, $this->initialize($params)),
            'ping' => $this->result($id, new \stdClass),
            'tools/list' => $this->result($id, ['tools' => $this->tools()]),
            'tools/call' => $this->result($id, $this->call($params)),
            default => $this->error($id, -32601, 'Method not found: '.$message['method']),
        };
    }

    private function initialize(array $params): array
    {
        Setting::put(['mcp_last_used' => now()->toIso8601String(), 'mcp_last_client' => Str::limit((string) ($params['clientInfo']['name'] ?? 'Unknown'), 60)]);
        $asked = $params['protocolVersion'] ?? null;

        return [
            'protocolVersion' => in_array($asked, self::VERSIONS, true) ? $asked : self::VERSIONS[0],
            'capabilities' => ['tools' => ['listChanged' => false]],
            'serverInfo' => ['name' => (Setting::get('business_name') ?: config('app.name')).' management', 'version' => '1.0.0'],
            'instructions' => AiAssistant::guide(),
        ];
    }

    private function tools(): array
    {
        return array_map(function ($t) {
            $read = in_array($t['name'], self::READ_ONLY, true);

            return [
                'name' => $t['name'],
                'title' => Str::headline($t['name']),
                'description' => $t['description'],
                'inputSchema' => $t['inputSchema'],
                'annotations' => ['readOnlyHint' => $read, 'destructiveHint' => $t['name'] === 'delete', 'idempotentHint' => $read, 'openWorldHint' => false],
            ];
        }, AiTools::definitions());
    }

    private function call(array $params): array
    {
        $name = (string) ($params['name'] ?? '');
        $input = is_array($params['arguments'] ?? null) ? $params['arguments'] : [];
        $text = fn (string $s, bool $error = false) => ['content' => [['type' => 'text', 'text' => $s]], 'isError' => $error];

        try {
            return $text((new AiTools)->run($name, $input));
        } catch (ValidationException $e) {
            return $text(implode(' ', $e->validator->errors()->all()), true);
        } catch (\Throwable $e) {
            Log::error('MCP tool failed', ['tool' => $name, 'error' => $e->getMessage()]);

            return $text('That did not work because of an error in the app.', true);
        }
    }

    private function result(mixed $id, mixed $result): array
    {
        return ['jsonrpc' => '2.0', 'id' => $id, 'result' => $result];
    }

    private function error(mixed $id, int $code, string $message): array
    {
        return ['jsonrpc' => '2.0', 'id' => $id, 'error' => ['code' => $code, 'message' => $message]];
    }
}
