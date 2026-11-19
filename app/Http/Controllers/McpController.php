<?php

namespace App\Http\Controllers;

use App\Mcp\McpContext;
use App\Mcp\McpServer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/** POST /mcp: the Model Context Protocol endpoint (stateless, JSON responses). */
class McpController extends Controller
{
    public function __invoke(Request $request, McpServer $server): JsonResponse|Response
    {
        $context = new McpContext($request->attributes->get('api_token'));
        $message = json_decode($request->getContent(), true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            return response()->json(McpServer::error(null, -32700, 'Parse error'), 400);
        }

        // A batch is an array of messages; answer with an array of the non-empty responses.
        if (is_array($message) && $message !== [] && array_is_list($message)) {
            $responses = array_values(array_filter(array_map(fn ($m) => $server->handle($m, $context), $message)));

            return $responses === [] ? response('', 202) : response()->json($responses);
        }

        $response = $server->handle($message, $context);

        return $response === null ? response('', 202) : response()->json($response);
    }

    /** No server-initiated stream and no sessions: GET/DELETE are not supported. */
    public function unsupported(): Response
    {
        return response('', 405, ['Allow' => 'POST']);
    }
}
