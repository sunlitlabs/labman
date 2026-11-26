<?php

namespace Tests\Feature;

use App\Models\ApiToken;
use App\Models\Link;
use App\Models\Node;
use App\Models\Server;
use App\Models\Service;
use App\Models\Zone;
use App\Node\NodePush;
use App\Node\NodeReceiver;
use App\Node\NodeSnapshot;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** Node mode: the main LabMan pushes a zone's dashboard, the node serves it and nothing else. */
class NodeTest extends TestCase
{
    use RefreshDatabase;

    private const TOKEN = 'a-long-shared-node-secret';

    private function lab(): Zone
    {
        $secure = Zone::create(['name' => 'Secure', 'level' => 90, 'cidrs' => ['127.0.0.0/8'], 'dashboard_indicator' => true]);
        $family = Zone::create(['name' => 'Family', 'level' => 10]);
        $nas = Server::create(['name' => 'Vault', 'address' => '192.0.2.8', 'zone_id' => $secure->id, 'ssh_user' => 'root']);
        $nas->forceFill(['state' => 'up', 'checked_at' => now(), 'checks' => [['label' => 'ssh :22', 'ok' => true, 'ms' => 2, 'detail' => 'connected']]])->save();
        Server::create(['name' => 'Tv box', 'address' => '192.0.2.9', 'zone_id' => $family->id]);
        Service::create(['name' => 'Wiki', 'server_id' => $nas->id, 'scheme' => 'http', 'path' => '/', 'domain' => 'wiki.example.com', 'group' => 'Docs']);
        Service::create(['name' => 'Cartoons', 'address' => '192.0.2.9', 'port' => 80, 'scheme' => 'http', 'path' => '/', 'zone_id' => $family->id]);
        Link::create(['name' => 'Router', 'url' => 'https://router.example.com']);

        return $secure;
    }

    public function test_a_snapshot_holds_the_zones_items_with_their_health_and_no_secrets(): void
    {
        $secure = $this->lab();
        \App\Support\Secrets::put('npm.password', 'hunter2-secret');

        $snap = NodeSnapshot::build($secure);

        $this->assertSame(['Vault'], array_column($snap['servers'], 'name'));
        $this->assertSame(['Wiki'], array_column($snap['services'], 'name'));
        $this->assertSame(['Router'], array_column($snap['links'], 'name'));
        $this->assertSame('up', $snap['servers'][0]['state']);
        $this->assertCount(3, $snap['zones'], 'every zone, so a node can place and badge items');
        $this->assertStringNotContainsString('hunter2', json_encode($snap));
        $this->assertArrayNotHasKey('inventory_host', $snap['servers'][0]);
        $this->assertCount(2, NodeSnapshot::build()['servers'], 'no zone means everything');
    }

    public function test_a_service_on_a_server_outside_the_zone_brings_that_server_along_hidden(): void
    {
        $this->lab();
        $family = Zone::where('name', 'Family')->first();
        $vault = Server::where('name', 'Vault')->first();
        Service::create(['name' => 'Remote thing', 'server_id' => $vault->id, 'scheme' => 'http', 'path' => '/', 'zone_id' => $family->id]);

        $snap = NodeSnapshot::build($family);
        $byName = collect($snap['servers'])->keyBy('name');

        $this->assertFalse($byName['Vault']['on_dashboard'], 'needed for the address, not shown');
        $this->assertTrue($byName['Tv box']['on_dashboard']);
    }

    public function test_the_receiver_replaces_everything_and_keeps_ids(): void
    {
        $secure = $this->lab();
        $snap = NodeSnapshot::build($secure);
        $ids = ['server' => $snap['servers'][0]['id'], 'service' => $snap['services'][0]['id']];

        Server::query()->delete();
        Service::query()->delete();
        Link::query()->delete();
        Zone::query()->delete();
        Server::create(['name' => 'Leftover', 'address' => '192.0.2.99']);

        $counts = NodeReceiver::apply($snap);

        $this->assertSame(['servers' => 1, 'services' => 1, 'links' => 1], $counts);
        $this->assertSame($ids['server'], Server::first()->id);
        $this->assertSame($ids['service'], Service::first()->id);
        $this->assertSame('up', Server::first()->state);
        $this->assertSame('Secure', Zone::where('dashboard_indicator', true)->first()->name);
        $this->assertNull(Server::where('name', 'Leftover')->first());

        $this->expectException(\InvalidArgumentException::class);
        NodeReceiver::apply(['version' => 2]);
    }

    public function test_the_receiver_ignores_columns_it_was_not_meant_to_store(): void
    {
        $snap = ['version' => 1, 'zones' => [], 'links' => [], 'services' => [], 'servers' => [
            ['id' => 5, 'name' => 'Box', 'address' => '192.0.2.5', 'inventory_host' => 'sneaky', 'npm_host_id' => 7],
        ]];

        NodeReceiver::apply($snap);

        $this->assertNull(Server::find(5)->inventory_host);
    }

    // ---------------------------------------------------------------- node mode

    private function asNode(): void
    {
        config(['labman.mode' => 'node', 'labman.node_token' => self::TOKEN]);
    }

    public function test_a_node_accepts_a_push_only_with_the_token(): void
    {
        $this->asNode();
        $snap = ['version' => 1, 'zones' => [], 'servers' => [], 'services' => [], 'links' => [['id' => 1, 'name' => 'Router', 'url' => 'https://router.example.com']]];

        $this->postJson('/node/push', $snap)->assertStatus(401);
        $this->postJson('/node/push', $snap, ['Authorization' => 'Bearer wrong-token-wrong-token'])->assertStatus(401);
        $this->assertSame(0, Link::count());

        $this->postJson('/node/push', $snap, ['Authorization' => 'Bearer '.self::TOKEN])->assertOk()->assertJson(['ok' => true, 'links' => 1]);
        $this->assertSame(1, Link::count());

        $this->postJson('/node/push', ['version' => 9], ['Authorization' => 'Bearer '.self::TOKEN])->assertStatus(422);
    }

    public function test_a_node_without_a_token_refuses_every_push_and_a_main_install_has_no_endpoint(): void
    {
        config(['labman.mode' => 'node', 'labman.node_token' => '']);
        $this->postJson('/node/push', ['version' => 1], ['Authorization' => 'Bearer anything-at-all-123'])->assertStatus(503);

        config(['labman.mode' => 'main']);
        $this->postJson('/node/push', ['version' => 1], ['Authorization' => 'Bearer '.self::TOKEN])->assertNotFound();
    }

    public function test_a_node_serves_the_dashboard_to_anyone_and_nothing_else(): void
    {
        $this->asNode();
        NodeReceiver::apply(NodeSnapshot::build($this->lab()));

        $this->get('/')->assertOk()->assertSee('Wiki')->assertSee('Vault')->assertSee('Router')->assertDontSee('Sign in')->assertDontSee('Add service');
        $this->getJson('/status?scope=dashboard')->assertOk()->assertJsonPath('servers.0.state', 'up');
        $this->get('/manifest.webmanifest')->assertOk();

        foreach (['/login', '/register', '/setup', '/admin', '/services', '/servers', '/links', '/settings', '/users', '/mcp'] as $path) {
            $this->get($path)->assertNotFound();
        }
        $this->postJson('/mcp', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list'])->assertNotFound();
    }

    public function test_a_node_shows_zone_badges_to_a_zone_that_asks_for_them(): void
    {
        $this->asNode();
        NodeReceiver::apply(NodeSnapshot::build($this->lab()));

        // The test client is 127.0.0.1, inside Secure's network block, and Secure asks for badges.
        $this->get('/')->assertOk()->assertSee('zone-chip', false);
    }

    // ----------------------------------------------------------------- main side

    public function test_pushing_posts_the_snapshot_with_the_token_and_records_the_outcome(): void
    {
        $secure = $this->lab();
        $node = Node::create(['name' => 'Secure dash', 'url' => 'http://192.0.2.60:8080', 'token' => self::TOKEN, 'zone_id' => $secure->id]);
        Http::fake(['192.0.2.60:8080/node/push' => Http::response(['ok' => true, 'servers' => 1, 'services' => 1, 'links' => 1])]);

        $result = NodePush::push($node);

        $this->assertTrue($result['ok']);
        Http::assertSent(fn ($r) => $r->url() === 'http://192.0.2.60:8080/node/push' && $r->hasHeader('Authorization', 'Bearer '.self::TOKEN) && $r['zone'] === 'Secure' && count($r['services']) === 1);
        $this->assertTrue($node->fresh()->push_ok);
        $this->assertNotNull($node->fresh()->pushed_at);
        $this->assertStringNotContainsString(self::TOKEN, (string) \DB::table('nodes')->value('token'), 'stored encrypted');
    }

    public function test_push_failures_are_explained_and_one_node_never_stops_the_others(): void
    {
        $this->lab();
        Node::create(['name' => 'a bad token', 'url' => 'http://192.0.2.61', 'token' => self::TOKEN.'-1']);
        Node::create(['name' => 'b not a node', 'url' => 'http://192.0.2.62', 'token' => self::TOKEN.'-2']);
        Node::create(['name' => 'c unreachable', 'url' => 'http://192.0.2.63', 'token' => self::TOKEN.'-3']);
        Node::create(['name' => 'd good', 'url' => 'http://192.0.2.64', 'token' => self::TOKEN.'-4']);
        Node::create(['name' => 'e paused', 'url' => 'http://192.0.2.65', 'token' => self::TOKEN.'-5', 'enabled' => false]);
        Http::fake([
            '192.0.2.61/*' => Http::response(['ok' => false, 'message' => 'Wrong token.'], 401),
            '192.0.2.62/*' => Http::response('', 404),
            '192.0.2.63/*' => fn () => throw new \Illuminate\Http\Client\ConnectionException('cURL error 7: connection refused'),
            '192.0.2.64/*' => Http::response(['ok' => true, 'servers' => 2, 'services' => 2, 'links' => 1]),
        ]);

        $results = NodePush::all();

        $this->assertSame(['a bad token', 'b not a node', 'c unreachable', 'd good'], array_keys($results));
        $this->assertStringContainsString('refused the token', $results['a bad token']['message']);
        $this->assertStringContainsString('LABMAN_MODE=node', $results['b not a node']['message']);
        $this->assertStringContainsString('Could not reach', $results['c unreachable']['message']);
        $this->assertTrue($results['d good']['ok']);
        $this->assertStringNotContainsString(self::TOKEN, json_encode($results));
        $this->assertNull(Node::where('name', 'e paused')->first()->pushed_at);
    }

    public function test_the_admin_pages_manage_nodes_and_never_show_the_token(): void
    {
        $this->actingAs($this->admin());
        $this->lab();
        Http::fake(['*' => Http::response(['ok' => true, 'servers' => 1, 'services' => 1, 'links' => 1])]);

        $this->get(route('nodes.index'))->assertOk()->assertSee('No nodes yet');
        $this->get(route('nodes.create'))->assertOk()->assertSee('LABMAN_NODE_TOKEN');
        $this->post(route('nodes.store'), ['name' => 'Short', 'url' => 'http://192.0.2.60', 'token' => 'short'])->assertSessionHasErrors('token');
        $this->post(route('nodes.store'), ['name' => 'Secure dash', 'url' => 'ftp://192.0.2.60', 'token' => self::TOKEN])->assertSessionHasErrors('url');

        $this->post(route('nodes.store'), ['name' => 'Secure dash', 'url' => 'http://192.0.2.60:8080/', 'token' => self::TOKEN, 'enabled' => '1'])->assertRedirect(route('nodes.index'));
        $node = Node::firstOrFail();
        $this->assertSame('http://192.0.2.60:8080', $node->url);

        $this->get(route('nodes.index'))->assertOk()->assertSee('Secure dash')->assertSee('in step')->assertDontSee(self::TOKEN);
        $this->get(route('nodes.edit', $node))->assertOk()->assertDontSee(self::TOKEN);

        $this->put(route('nodes.update', $node), ['name' => 'Secure dash', 'url' => 'http://192.0.2.60:8080', 'token' => '', 'enabled' => '1'])->assertSessionHasNoErrors();
        $this->assertSame(self::TOKEN, $node->fresh()->token, 'a blank token keeps the saved one');

        $this->post(route('nodes.push', $node))->assertRedirect(route('nodes.index'));
        $this->delete(route('nodes.destroy', $node))->assertRedirect();
        $this->assertSame(0, Node::count());

        $this->actingAs($this->user());
        $this->get(route('nodes.index'))->assertForbidden();
    }

    public function test_mcp_manages_nodes_without_returning_the_token(): void
    {
        $admin = $this->admin();
        [, $token] = ApiToken::issue($admin, 'test', 'admin');
        $this->lab();
        Http::fake(['*' => Http::response(['ok' => true, 'servers' => 1, 'services' => 1, 'links' => 1])]);
        $call = fn (string $tool, array $args = []) => $this->postJson('/mcp', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call', 'params' => ['name' => $tool, 'arguments' => (object) $args]], ['Authorization' => 'Bearer '.$token])->assertOk()->json('result');

        $saved = $call('node_save', ['name' => 'Secure dash', 'url' => 'http://192.0.2.60:8080', 'token' => self::TOKEN, 'zone' => 'Secure']);
        $this->assertFalse($saved['isError']);
        $this->assertSame('Secure', $saved['structuredContent']['node']['zone']);
        $this->assertTrue($saved['structuredContent']['push']['ok']);
        $this->assertStringNotContainsString(self::TOKEN, json_encode($saved));

        $this->assertTrue($call('node_save', ['name' => 'Bad', 'url' => 'http://192.0.2.61'])['isError'], 'a new node needs a token');
        $this->assertTrue($call('node_save', ['name' => 'Bad', 'url' => 'nope', 'token' => self::TOKEN])['isError']);

        $moved = $call('node_save', ['name' => 'Secure dash', 'zone' => '']);
        $this->assertNull($moved['structuredContent']['node']['zone']);
        $this->assertStringNotContainsString(self::TOKEN, json_encode($call('node_list')));
        $this->assertFalse($call('node_push')['isError']);
        $this->assertFalse($call('node_remove', ['name' => 'Secure dash'])['isError']);
        $this->assertSame(0, Node::count());
    }
}
