<?php

namespace App\Http\Controllers;

use App\Models\Node;
use App\Node\NodePush;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/** Admin: the nodes (zone dashboards in node mode) this LabMan pushes to. */
class NodeController extends Controller
{
    public function index()
    {
        return view('nodes.index', ['nodes' => Node::query()->with('zone')->orderBy('name')->get()]);
    }

    public function create()
    {
        // A fresh secret to copy into the node's LABMAN_NODE_TOKEN.
        return view('nodes.form', ['node' => new Node(['enabled' => true]), 'suggested' => Str::random(40)]);
    }

    public function edit(Node $node)
    {
        return view('nodes.form', ['node' => $node, 'suggested' => null]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validated($request);
        $node = Node::create($data);

        return $this->pushAndReturn($node, 'Node added. ');
    }

    public function update(Request $request, Node $node): RedirectResponse
    {
        $data = $this->validated($request, $node);
        if (blank($data['token'] ?? null)) {
            unset($data['token']); // blank keeps the saved secret
        }
        $node->update($data);

        return $this->pushAndReturn($node, 'Node saved. ');
    }

    public function push(Node $node): RedirectResponse
    {
        return $this->pushAndReturn($node, '');
    }

    public function destroy(Node $node): RedirectResponse
    {
        $node->delete();

        return redirect()->route('nodes.index')->with('status', "Node '{$node->name}' removed. Its dashboard keeps showing the last push; stop or reconfigure it on its own host.");
    }

    private function pushAndReturn(Node $node, string $prefix): RedirectResponse
    {
        $result = NodePush::push($node);

        return redirect()->route('nodes.index')->with($result['ok'] ? 'status' : 'node_error', $prefix.$result['message']);
    }

    /** @return array<string, mixed> */
    private function validated(Request $request, ?Node $node = null): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:100', Rule::unique('nodes', 'name')->ignore($node)],
            'url' => ['required', 'string', 'max:255', 'url:http,https'],
            'token' => [$node ? 'nullable' : 'required', 'string', 'min:16', 'max:200'],
            'zone_id' => ['nullable', 'integer', 'exists:zones,id'],
        ]);
        $data['enabled'] = $request->boolean('enabled');
        $data['zone_id'] = filled($data['zone_id'] ?? null) ? (int) $data['zone_id'] : null;
        $data['url'] = rtrim($data['url'], '/');

        return $data;
    }
}
