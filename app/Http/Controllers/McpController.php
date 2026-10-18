<?php

namespace App\Http\Controllers;

use App\Support\Mcp;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/** The MCP endpoint behind Settings > AI connections. */
class McpController
{
    public function __invoke(Request $request, string $token, Mcp $mcp): JsonResponse|Response
    {
        // A wrong or switched-off link looks like any missing page.
        abort_unless(Mcp::check($token), 404);

        // No server-to-client stream: clients fall back to plain POST replies.
        if (! $request->isMethod('post')) {
            return response('', 405, ['Allow' => 'POST']);
        }

        $body = json_decode($request->getContent(), true);
        if ($body === null) {
            return response()->json(['jsonrpc' => '2.0', 'id' => null, 'error' => ['code' => -32700, 'message' => 'Parse error']], 400);
        }

        $isBatch = is_array($body) && array_is_list($body) && $body !== [];
        $replies = array_values(array_filter(array_map(fn ($m) => $mcp->handle($m), $isBatch ? $body : [$body])));

        if ($replies === []) {
            return response('', 202);
        }

        return response()->json($isBatch ? $replies : $replies[0], 200, [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
