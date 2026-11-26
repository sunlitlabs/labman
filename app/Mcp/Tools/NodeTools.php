<?php

namespace App\Mcp\Tools;

use App\Mcp\Tool;
use App\Mcp\ToolException;
use App\Models\Node;
use App\Models\Zone;
use App\Node\NodePush;

/** Nodes: other LabMan installs in node mode that this one pushes a zone dashboard to. The token is never returned. */
final class NodeTools
{
    private const IDENT = ['name' => ['type' => 'string', 'description' => 'Node name.']];

    /** @return list<Tool> */
    public static function all(): array
    {
        return [
            new Tool('node_list', 'List nodes', 'The nodes this LabMan keeps updated, with the zone each shows and how the last push went. Tokens are never shown.',
                [], [], 'admin', true, false, fn () => ['nodes' => Node::query()->with('zone')->orderBy('name')->get()->map(fn (Node $n) => self::present($n))->all()]),

            new Tool('node_save', 'Add or change a node', 'Create a node, or change the one with this name, then push to it once. A node is another LabMan started with LABMAN_MODE=node and a LABMAN_NODE_TOKEN; the token given here must equal it (stored encrypted, never returned). Omitted fields keep their value when changing.',
                [
                    'name' => Tool::str('Node name (unique).'),
                    'url' => Tool::str('Address this LabMan reaches the node at, e.g. http://192.0.2.60:8080.'),
                    'token' => Tool::str('The node\'s LABMAN_NODE_TOKEN (at least 16 characters). Required when creating.'),
                    'zone' => Tool::str('Zone whose items the node shows; empty string for everything.'),
                    'enabled' => Tool::bool('Keep the node updated (default true).'),
                ], ['name'], 'admin', false, false, function (array $a) {
                    $node = Node::query()->where('name', $a['name'])->first();
                    $data = [];

                    if (array_key_exists('url', $a)) {
                        $url = rtrim((string) $a['url'], '/');
                        if (! preg_match('#^https?://[^\s/]+#i', $url) || filter_var($url, FILTER_VALIDATE_URL) === false) {
                            throw new ToolException('url must be an http:// or https:// address.');
                        }
                        $data['url'] = $url;
                    }
                    if (array_key_exists('token', $a) && $a['token'] !== '') {
                        if (mb_strlen((string) $a['token']) < 16) {
                            throw new ToolException('token must be at least 16 characters.');
                        }
                        $data['token'] = (string) $a['token'];
                    }
                    if (array_key_exists('zone', $a)) {
                        if ($a['zone'] === '' || $a['zone'] === null) {
                            $data['zone_id'] = null;
                        } else {
                            $zone = Zone::query()->whereRaw('lower(name) = ?', [mb_strtolower((string) $a['zone'])])->first()
                                ?? throw new ToolException("There is no zone '{$a['zone']}'.");
                            $data['zone_id'] = $zone->id;
                        }
                    }
                    if (array_key_exists('enabled', $a)) {
                        $data['enabled'] = (bool) $a['enabled'];
                    }

                    if ($node === null) {
                        foreach (['url', 'token'] as $required) {
                            if (! isset($data[$required])) {
                                throw new ToolException("A new node needs a {$required}.");
                            }
                        }
                        $node = Node::create(['name' => $a['name']] + $data + ['enabled' => true]);
                    } else {
                        $node->update($data);
                    }

                    return ['node' => self::present($node->fresh('zone')), 'push' => NodePush::push($node->load('zone'))];
                }),

            new Tool('node_push', 'Push to nodes now', 'Send the current dashboard to one node (by name) or to every enabled node. Nodes are also updated after every health-check round.',
                ['name' => ['type' => 'string', 'description' => 'Node name; omit for all enabled nodes.']], [], 'admin', false, false, function (array $a) {
                    if (filled($a['name'] ?? null)) {
                        $node = Node::query()->where('name', $a['name'])->with('zone')->first() ?? throw new ToolException("There is no node '{$a['name']}'. Use node_list.");

                        return ['results' => [$node->name => NodePush::push($node)]];
                    }

                    return ['results' => NodePush::all()];
                }),

            new Tool('node_remove', 'Remove a node', 'Stop updating a node and forget it. The node itself keeps showing its last dashboard until you stop or reconfigure it.',
                self::IDENT, ['name'], 'admin', false, true, function (array $a) {
                    $node = Node::query()->where('name', $a['name'])->first() ?? throw new ToolException("There is no node '{$a['name']}'. Use node_list.");
                    $node->delete();

                    return ['removed' => $node->name];
                }),
        ];
    }

    /** @return array<string, mixed> */
    private static function present(Node $n): array
    {
        return [
            'name' => $n->name, 'url' => $n->url, 'zone' => $n->zone?->name, 'enabled' => (bool) $n->enabled,
            'last_push' => $n->pushed_at?->toIso8601String(), 'last_push_ok' => $n->push_ok, 'last_push_message' => $n->push_message,
        ];
    }
}
