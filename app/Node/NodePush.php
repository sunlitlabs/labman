<?php

namespace App\Node;

use App\Models\Node;
use Illuminate\Support\Facades\Http;
use Throwable;

/** Main side: send the current dashboard to the nodes. One node failing never stops the others. */
final class NodePush
{
    /** @return array{ok: bool, message: string} */
    public static function push(Node $node): array
    {
        try {
            $response = Http::withToken((string) $node->token)->acceptJson()->timeout(15)->connectTimeout(5)
                ->post($node->pushUrl(), NodeSnapshot::build($node->zone));

            if ($response->successful() && $response->json('ok') === true) {
                $result = ['ok' => true, 'message' => sprintf('Sent %d service(s), %d server(s), %d link(s).', $response->json('services', 0), $response->json('servers', 0), $response->json('links', 0))];
            } else {
                $result = ['ok' => false, 'message' => self::explain($response->status(), (string) $response->json('message'))];
            }
        } catch (Throwable $e) {
            // The message can name the address but never the token (it travels in a header).
            $result = ['ok' => false, 'message' => 'Could not reach the node: '.mb_substr($e->getMessage(), 0, 200)];
        }

        $node->forceFill(['pushed_at' => now(), 'push_ok' => $result['ok'], 'push_message' => $result['message']])->save();

        return $result;
    }

    /** @return array<string, array{ok: bool, message: string}> by node name */
    public static function all(): array
    {
        $out = [];
        foreach (Node::query()->where('enabled', true)->with('zone')->orderBy('name')->get() as $node) {
            $out[$node->name] = self::push($node);
        }

        return $out;
    }

    private static function explain(int $status, string $message): string
    {
        return match (true) {
            $status === 401, $status === 403 => 'The node refused the token (HTTP '.$status.'). Check LABMAN_NODE_TOKEN on the node.',
            $status === 404 => 'The node answered 404: is it running with LABMAN_MODE=node, and is the address right?',
            default => "The node answered HTTP {$status}".($message !== '' ? ': '.mb_substr($message, 0, 200) : '.'),
        };
    }
}
