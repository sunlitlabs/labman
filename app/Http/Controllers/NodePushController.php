<?php

namespace App\Http\Controllers;

use App\Node\Mode;
use App\Node\NodeReceiver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Node side: the main LabMan posts a snapshot here (bearer token = LABMAN_NODE_TOKEN). */
class NodePushController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        abort_unless(Mode::isNode(), 404);

        if (Mode::token() === '') {
            return response()->json(['ok' => false, 'message' => 'This node has no LABMAN_NODE_TOKEN, so it refuses every push.'], 503);
        }

        $given = (string) $request->bearerToken();
        if ($given === '' || ! hash_equals(hash('sha256', Mode::token()), hash('sha256', $given))) {
            return response()->json(['ok' => false, 'message' => 'Wrong token.'], 401);
        }

        try {
            $counts = NodeReceiver::apply((array) $request->json()->all());
        } catch (\InvalidArgumentException $e) {
            return response()->json(['ok' => false, 'message' => $e->getMessage()], 422);
        }

        return response()->json(['ok' => true] + $counts);
    }
}
