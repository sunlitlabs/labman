<?php

namespace Tests\Feature;

use App\Inventory\GitRepo;
use App\Inventory\Inventory;
use App\Inventory\InventoryException;
use App\Inventory\InventorySync;
use App\Models\Server;
use App\Support\Secrets;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\TempRepo;
use Tests\TestCase;

class InventorySyncTest extends TestCase
{
    use RefreshDatabase;

    private const YAML = <<<'YAML'
all:
  children:
    web:
      hosts:
        web1:
          ansible_host: 192.0.2.11
          ansible_user: deploy
        web2.example.com:
    db:
      hosts:
        db1:
          ansible_host: 192.0.2.21
          ansible_port: 2222
        node[01:03].example.com:
YAML;

    private const INI = <<<'INI'
[web]
web1 ansible_host=192.0.2.11 ansible_user=deploy
web2.example.com

[db]
db1 ansible_host=192.0.2.21 ansible_port=2222 custom_var=keepme
INI;

    private TempRepo $remote;

    protected function tearDown(): void
    {
        $this->remote->cleanup();

        parent::tearDown();
    }

    private function seedRepo(string $content = self::YAML, string $path = 'inventory/hosts.yml'): InventorySync
    {
        $this->remote = TempRepo::create([$path => $content]);

        return $this->sync($path);
    }

    private function sync(string $path = 'inventory/hosts.yml'): InventorySync
    {
        $config = $this->remote->config($path);

        return new InventorySync($config, new GitRepo($config, $this->remote->workDir()));
    }

    /** LabMan servers matching the seeded inventory in different ways. */
    private function servers(): array
    {
        return [
            'web1' => Server::create(['name' => 'web1', 'address' => '192.0.2.11', 'ssh_user' => 'deploy', 'group' => 'web']),
            'db1' => Server::create(['name' => 'db1', 'address' => '192.0.2.99', 'group' => 'db']),
            'only' => Server::create(['name' => 'Only Here', 'address' => '192.0.2.50', 'group' => 'Lab Group', 'ssh_user' => 'ops', 'ssh_port' => 2200]),
        ];
    }

    private function remoteHosts(string $path = 'inventory/hosts.yml'): array
    {
        return Inventory::parse((string) $this->remote->remoteFile($path), $path)->hosts();
    }

    // ================================================================= compare

    public function test_compare_lines_the_two_sides_up(): void
    {
        $sync = $this->seedRepo();
        $s = $this->servers();

        $plan = $sync->compare();

        $this->assertSame('yaml', $plan['format']);
        $this->assertSame('inventory/hosts.yml', $plan['path']);
        $this->assertSame($this->remote->remoteHead(), $plan['head']);
        $this->assertSame(3, $plan['host_count'], 'range patterns are not hosts LabMan manages');

        $this->assertSame(['web1'], array_column($plan['in_sync'], 'host'));

        $this->assertCount(1, $plan['differences']);
        $db = $plan['differences'][0];
        $this->assertSame('pair:'.$s['db1']->id, $db['key']);
        $this->assertSame(['address', 'ssh_port'], array_column($db['fields'], 'field'));
        $this->assertSame(['labman' => '192.0.2.99', 'inventory' => '192.0.2.21'], ['labman' => $db['fields'][0]['labman'], 'inventory' => $db['fields'][0]['inventory']]);
        $this->assertSame([22, 2222], [$db['fields'][1]['labman'], $db['fields'][1]['inventory']]);
        $this->assertSame(['labman', 'inventory', 'skip'], $db['fields'][0]['options']);

        $this->assertSame(['Only Here'], array_column($plan['only_labman'], 'name'));
        $this->assertSame('Only-Here', $plan['only_labman'][0]['inventory_name']);

        $this->assertSame(['web2.example.com'], array_column($plan['only_inventory'], 'host'));
        $this->assertSame(['web'], $plan['only_inventory'][0]['groups']);
        $this->assertSame('web2.example.com', $plan['only_inventory'][0]['suggested']['name']);
        $this->assertSame('web', $plan['only_inventory'][0]['suggested']['group']);

        $this->assertSame(['node[01:03].example.com'], $plan['ignored_patterns']);
    }

    public function test_compare_never_changes_anything(): void
    {
        $sync = $this->seedRepo();
        $this->servers();
        $head = $this->remote->remoteHead();

        $sync->compare();

        $this->assertSame($head, $this->remote->remoteHead());
        $this->assertSame(3, Server::count());
        $this->assertNull(Server::where('name', 'web1')->value('inventory_host'));
    }

    public function test_servers_are_matched_by_saved_link_name_slug_then_address(): void
    {
        $sync = $this->seedRepo(<<<'YAML'
all:
  hosts:
    linked-host:
      ansible_host: 192.0.2.1
    Docker-Host:
      ansible_host: 192.0.2.2
    by-address:
      ansible_host: 192.0.2.3
    ambiguous-a:
      ansible_host: 192.0.2.4
    ambiguous-b:
      ansible_host: 192.0.2.4
YAML);
        Server::create(['name' => 'Renamed in LabMan', 'address' => '192.0.2.1', 'inventory_host' => 'linked-host']);
        Server::create(['name' => 'Docker Host', 'address' => '192.0.2.2']);
        Server::create(['name' => 'Whatever', 'address' => '192.0.2.3']);
        Server::create(['name' => 'Twin', 'address' => '192.0.2.4']);

        $plan = $sync->compare();

        $pairs = collect($plan['in_sync'])->mapWithKeys(fn ($p) => [$p['server'] => $p['host']])->all();
        $this->assertSame('linked-host', $pairs['Renamed in LabMan']);
        $this->assertSame('Docker-Host', $pairs['Docker Host']);
        $this->assertSame('by-address', $pairs['Whatever']);
        $this->assertArrayNotHasKey('Twin', $pairs, 'two hosts share the address, so it is not guessed');
        $this->assertSame(['Twin'], array_column($plan['only_labman'], 'name'));
        $this->assertEqualsCanonicalizing(['ambiguous-a', 'ambiguous-b'], array_column($plan['only_inventory'], 'host'));
    }

    public function test_a_host_is_never_matched_to_two_servers(): void
    {
        $sync = $this->seedRepo("all:\n  hosts:\n    web1:\n      ansible_host: 192.0.2.11\n");
        Server::create(['name' => 'web1', 'address' => '192.0.2.11']);
        Server::create(['name' => 'Duplicate', 'address' => '192.0.2.11']);

        $plan = $sync->compare();

        $this->assertCount(1, $plan['in_sync']);
        $this->assertSame(['Duplicate'], array_column($plan['only_labman'], 'name'));
    }

    public function test_group_differences_follow_the_rules(): void
    {
        $sync = $this->seedRepo("all:\n  children:\n    web:\n      hosts:\n        h1:\n        h2:\n        h3:\n    other:\n      hosts:\n        h3:\n  hosts:\n    h4:\n");
        $nullGroup = Server::create(['name' => 'h1', 'address' => 'h1']);              // LabMan none, inventory web
        $missing = Server::create(['name' => 'h2', 'address' => 'h2', 'group' => 'DB']); // LabMan DB, inventory web
        $fine = Server::create(['name' => 'h3', 'address' => 'h3', 'group' => 'Other']);  // slug matches 'other'
        $ungrouped = Server::create(['name' => 'h4', 'address' => 'h4', 'group' => 'DB']); // inventory has no group

        $diffs = collect($sync->compare()['differences'])->keyBy('server');

        $this->assertSame(['inventory', 'skip'], $diffs['h1']['fields'][0]['options'], 'LabMan has no group: we never remove group membership from the inventory');
        $this->assertSame('web', $diffs['h1']['fields'][0]['inventory']);
        $this->assertSame(['labman', 'inventory', 'skip'], $diffs['h2']['fields'][0]['options']);
        $this->assertArrayNotHasKey('h3', $diffs->all(), 'the slug of the LabMan group matches an inventory group');
        $this->assertSame(['labman', 'skip'], $diffs['h4']['fields'][0]['options'], 'nothing to copy from an ungrouped host');
    }

    public function test_templated_addresses_and_users_are_not_compared(): void
    {
        $sync = $this->seedRepo("all:\n  hosts:\n    t1:\n      ansible_host: '{{ lookup(\"x\") }}'\n      ansible_user: '{{ u }}'\n");
        Server::create(['name' => 't1', 'address' => '192.0.2.5', 'ssh_user' => 'deploy']);

        $plan = $sync->compare();

        $this->assertCount(1, $plan['in_sync']);
    }

    public function test_a_missing_inventory_file_explains_what_to_do(): void
    {
        $sync = $this->seedRepo();
        $this->remote->commitFromElsewhere(['prod/hosts.ini' => ''], 'x');
        $config = $this->remote->config('wrong/path.yml');

        $this->expectException(InventoryException::class);
        $this->expectExceptionMessage("'wrong/path.yml' does not exist");
        (new InventorySync($config, new GitRepo($config, $this->remote->workDir('other'))))->compare();
    }

    // ================================================================== review

    private function choices(array $s): array
    {
        return [
            'pair:'.$s['db1']->id => ['fields' => ['address' => 'inventory', 'ssh_port' => 'labman']],
            'srv:'.$s['only']->id => ['action' => 'add_inventory'],
            'host:web2.example.com' => ['action' => 'add_labman', 'setup' => ['name' => 'web2']],
        ];
    }

    public function test_review_previews_the_exact_diff_and_the_labman_changes(): void
    {
        $sync = $this->seedRepo();
        $s = $this->servers();
        $head = $this->remote->remoteHead();

        $review = $sync->review($this->choices($s));

        $this->assertTrue($review['changes']);
        $this->assertSame($head, $review['head']);
        $this->assertStringContainsString('Only-Here', $review['diff']);
        $this->assertStringContainsString('ansible_user: ops', $review['diff']);
        $this->assertStringContainsString('ansible_port: 2200', $review['diff']);
        $this->assertStringContainsString('Lab_Group', $review['diff']);
        $this->assertStringContainsString('-          ansible_port: 2222', $review['diff'], 'db1 port back to the default 22');
        $this->assertEqualsCanonicalizing(['Add host Only-Here (192.0.2.50) to group Lab_Group', 'Remove ansible_port from db1 (default 22)'], $review['inventory']);
        $this->assertContains('Set address of db1 to 192.0.2.21 (from the inventory)', $review['labman']);
        $this->assertContains('Create server web2 (web2.example.com) from inventory host web2.example.com', $review['labman']);
        $this->assertStringStartsWith('Update inventory from LabMan', $review['commit_message']);
        $this->assertStringContainsString('- Add host Only-Here', $review['commit_message']);
        $this->assertNull($review['convert']);

        $this->assertSame($head, $this->remote->remoteHead(), 'review pushes nothing');
        $this->assertSame(3, Server::count(), 'review changes nothing in LabMan');
        $this->assertSame($review['fingerprint'], $sync->review($this->choices($s))['fingerprint']);
    }

    public function test_leaving_everything_alone_is_a_no_change_review(): void
    {
        $sync = $this->seedRepo();
        $s = $this->servers();
        // web1 is in sync and gets linked: that alone is not a "change" to push, but LabMan records the link.

        $review = $sync->review([
            'srv:'.$s['only']->id => ['action' => 'leave'],
            'host:web2.example.com' => ['action' => 'leave'],
            'pair:'.$s['db1']->id => ['fields' => ['address' => 'skip', 'ssh_port' => 'skip']],
        ]);

        $this->assertSame('', $review['diff']);
        $this->assertSame([], $review['inventory']);
        $this->assertSame([], $review['labman']);
    }

    public function test_review_rejects_bad_choices(): void
    {
        $sync = $this->seedRepo();
        $s = $this->servers();
        $dbKey = 'pair:'.$s['db1']->id;

        $bad = [
            'unknown item' => [['nope:1' => ['action' => 'explode']], "Unknown item 'nope:1'"],
            'stale server' => [['srv:9999' => ['action' => 'delete_labman']], 'no longer exists'],
            'matched server as only-labman' => [['srv:'.$s['web1']->id => ['action' => 'delete_labman']], 'now matches an inventory host'],
            'bad option' => [[$dbKey => ['fields' => ['address' => 'merge']]], "'merge' is not possible"],
            'field not different' => [[$dbKey => ['fields' => ['ssh_user' => 'labman']]], 'no longer different'],
            'bad action' => [['srv:'.$s['only']->id => ['action' => 'explode']], "'explode' is not possible"],
            'bad host action' => [['host:web2.example.com' => ['action' => 'add_inventory']], "'add_inventory' is not possible"],
            'unmatched host gone' => [['host:ghost' => ['action' => 'remove_inventory']], 'no longer unmatched'],
            'matched host as inventory-only' => [['host:web1' => ['action' => 'remove_inventory']], 'no longer unmatched'],
            'duplicate name' => [['host:web2.example.com' => ['action' => 'add_labman', 'setup' => ['name' => 'WEB1']]], "server named 'WEB1' already exists"],
            'bad address' => [['host:web2.example.com' => ['action' => 'add_labman', 'setup' => ['address' => '192.0.2.1; reboot']]], 'IP address'],
            'unsafe user' => [['host:web2.example.com' => ['action' => 'add_labman', 'setup' => ['ssh_user' => '-oProxyCommand=x']]], 'ssh user'],
        ];

        foreach ($bad as $label => [$choices, $message]) {
            try {
                $sync->review($choices);
                $this->fail("Accepted: {$label}");
            } catch (InventoryException $e) {
                $this->assertStringContainsString($message, $e->getMessage(), $label);
            }
        }

        // The group diff of a server without a LabMan group may not write to the inventory.
        $noGroup = Server::create(['name' => 'web2.example.com', 'address' => 'web2.example.com']);
        $this->expectException(InventoryException::class);
        $this->expectExceptionMessage("'labman' is not possible for group");
        $sync->review(['pair:'.$noGroup->id => ['fields' => ['group' => 'labman']]]);
    }

    public function test_two_new_servers_cannot_get_the_same_name(): void
    {
        $sync = $this->seedRepo("all:\n  hosts:\n    a:\n    b:\n");

        $this->expectException(InventoryException::class);
        $this->expectExceptionMessage("server named 'x' already exists");
        $sync->review([
            'host:a' => ['action' => 'add_labman', 'setup' => ['name' => 'x', 'address' => 'a']],
            'host:b' => ['action' => 'add_labman', 'setup' => ['name' => 'x', 'address' => 'b']],
        ]);
    }

    // =================================================================== apply

    public function test_apply_pushes_the_inventory_then_updates_labman(): void
    {
        $sync = $this->seedRepo();
        $s = $this->servers();
        $choices = $this->choices($s);

        $review = $sync->review($choices);
        $result = $sync->apply($choices, $review['fingerprint']);

        $this->assertTrue($result['pushed']);
        $this->assertSame($this->remote->remoteHead(), $result['commit']);

        // Inventory side
        $hosts = $this->remoteHosts();
        $this->assertSame('192.0.2.50', $hosts['Only-Here']['vars']['ansible_host']);
        $this->assertSame('ops', $hosts['Only-Here']['vars']['ansible_user']);
        $this->assertSame(2200, $hosts['Only-Here']['vars']['ansible_port']);
        $this->assertSame(['Lab_Group'], $hosts['Only-Here']['direct']);
        $this->assertArrayNotHasKey('ansible_port', $hosts['db1']['vars']);
        $this->assertSame('192.0.2.21', $hosts['db1']['vars']['ansible_host'], 'untouched vars survive');
        $this->assertArrayHasKey('node[01:03].example.com', $hosts, 'unmanaged patterns survive');
        $this->assertArrayHasKey('web2.example.com', $hosts);
        $this->assertSame('Update inventory from LabMan', $this->remote->log()[0]);
        $this->assertSame('LabMan <labman@localhost>', $this->remote->lastAuthor());

        // LabMan side
        $this->assertSame('192.0.2.21', $s['db1']->fresh()->address);
        $this->assertSame('Only-Here', $s['only']->fresh()->inventory_host);
        $this->assertSame('db1', $s['db1']->fresh()->inventory_host);
        $this->assertSame('web1', $s['web1']->fresh()->inventory_host);
        $created = Server::where('name', 'web2')->firstOrFail();
        $this->assertSame('web2.example.com', $created->address, 'the address defaults to what the inventory says');
        $this->assertSame('web2.example.com', $created->inventory_host);
        $this->assertSame('web', $created->group, 'the group comes from the inventory');

        // Everything lines up afterwards.
        $after = $sync->compare();
        $this->assertSame([], $after['differences']);
        $this->assertSame([], $after['only_labman']);
        $this->assertSame([], $after['only_inventory']);
        $this->assertCount(4, $after['in_sync']);
    }

    public function test_apply_refuses_when_the_repository_moved_after_the_review(): void
    {
        $sync = $this->seedRepo();
        $s = $this->servers();
        $choices = $this->choices($s);
        $review = $sync->review($choices);

        $moved = $this->remote->commitFromElsewhere(['inventory/hosts.yml' => self::YAML."\n    # touched\n"], 'Someone else');

        try {
            $sync->apply($choices, $review['fingerprint']);
            $this->fail('Expected a refusal');
        } catch (InventoryException $e) {
            $this->assertStringContainsString('changed since you reviewed', $e->getMessage());
        }

        $this->assertSame($moved, $this->remote->remoteHead());
        $this->assertSame('192.0.2.99', $s['db1']->fresh()->address, 'LabMan is untouched');
        $this->assertSame(3, Server::count());
    }

    public function test_apply_refuses_when_labman_changed_after_the_review(): void
    {
        $sync = $this->seedRepo();
        $s = $this->servers();
        $choices = $this->choices($s);
        $review = $sync->review($choices);

        $s['only']->update(['address' => '192.0.2.77']);

        $this->expectException(InventoryException::class);
        $this->expectExceptionMessage('changed since you reviewed');
        $sync->apply($choices, $review['fingerprint']);
    }

    public function test_a_forged_fingerprint_or_changed_choices_are_refused(): void
    {
        $sync = $this->seedRepo();
        $s = $this->servers();
        $choices = $this->choices($s);
        $review = $sync->review($choices);

        foreach ([[$choices, str_repeat('0', 40)], [array_merge($choices, ['srv:'.$s['only']->id => ['action' => 'delete_labman']]), $review['fingerprint']]] as [$c, $fp]) {
            try {
                $sync->apply($c, $fp);
                $this->fail('Expected a refusal');
            } catch (InventoryException) {
                $this->addToAssertionCount(1);
            }
        }

        $this->assertSame(3, Server::count());
    }

    public function test_a_refused_push_leaves_labman_untouched(): void
    {
        $sync = $this->seedRepo();
        $s = $this->servers();
        $choices = $this->choices($s);
        $review = $sync->review($choices);

        // Make the push fail after the fingerprint check: point the sync at a remote that has gone away.
        $config = $this->remote->config('inventory/hosts.yml');
        $broken = new InventorySync($config, new class($config, $this->remote->workDir()) extends GitRepo
        {
            public function commitAndPush(array $writes, array $deletes, string $message): string
            {
                throw new InventoryException('The push was rejected, usually because someone pushed to the branch meanwhile. Compare again.');
            }
        });

        try {
            $broken->apply($choices, $review['fingerprint']);
            $this->fail('Expected a failure');
        } catch (InventoryException $e) {
            $this->assertStringContainsString('push was rejected', $e->getMessage());
        }

        $this->assertSame('192.0.2.99', $s['db1']->fresh()->address);
        $this->assertSame(3, Server::count());
        $this->assertNull($s['only']->fresh()->inventory_host);
    }

    public function test_labman_only_changes_do_not_create_a_commit(): void
    {
        $sync = $this->seedRepo();
        $s = $this->servers();
        $head = $this->remote->remoteHead();
        $choices = ['pair:'.$s['db1']->id => ['fields' => ['address' => 'inventory', 'ssh_port' => 'inventory']]];

        $review = $sync->review($choices);
        $this->assertSame('', $review['diff']);
        $result = $sync->apply($choices, $review['fingerprint']);

        $this->assertFalse($result['pushed']);
        $this->assertSame($head, $this->remote->remoteHead());
        $this->assertSame('192.0.2.21', $s['db1']->fresh()->address);
        $this->assertSame(2222, (int) $s['db1']->fresh()->ssh_port);
    }

    public function test_delete_from_labman_and_remove_from_inventory(): void
    {
        $sync = $this->seedRepo();
        $s = $this->servers();
        $choices = ['srv:'.$s['only']->id => ['action' => 'delete_labman'], 'host:web2.example.com' => ['action' => 'remove_inventory']];

        $review = $sync->review($choices);
        $this->assertContains('Delete server Only Here from LabMan', $review['labman']);
        $this->assertContains('Remove host web2.example.com', $review['inventory']);
        $sync->apply($choices, $review['fingerprint']);

        $this->assertNull(Server::find($s['only']->id));
        $this->assertArrayNotHasKey('web2.example.com', $this->remoteHosts());
        $this->assertArrayHasKey('web1', $this->remoteHosts());
    }

    public function test_labman_values_can_be_written_into_the_inventory(): void
    {
        $sync = $this->seedRepo();
        $s = $this->servers();
        $s['web1']->update(['ssh_user' => 'ops', 'ssh_port' => 2022, 'group' => 'Edge']);
        $s['db1']->update(['address' => '192.0.2.21']); // db1: only the port differs now

        $plan = $sync->compare();
        $web1 = collect($plan['differences'])->firstWhere('server', 'web1');
        $this->assertSame(['ssh_user', 'ssh_port', 'group'], array_column($web1['fields'], 'field'));

        $choices = ['pair:'.$s['web1']->id => ['fields' => ['ssh_user' => 'labman', 'ssh_port' => 'labman', 'group' => 'labman']]];
        $sync->apply($choices, $sync->review($choices)['fingerprint']);

        $web = $this->remoteHosts()['web1'];
        $this->assertSame('ops', $web['vars']['ansible_user']);
        $this->assertSame(2022, $web['vars']['ansible_port']);
        $this->assertContains('Edge', $web['direct']);
        $this->assertContains('web', $web['direct'], 'existing group membership is kept');
    }

    public function test_removing_a_user_from_the_inventory_when_labman_has_none(): void
    {
        $sync = $this->seedRepo();
        $s = $this->servers();
        $s['web1']->update(['ssh_user' => null]);

        $choices = ['pair:'.$s['web1']->id => ['fields' => ['ssh_user' => 'labman']]];
        $review = $sync->review($choices);
        $this->assertSame(['Remove ansible_user from web1'], $review['inventory']);
        $sync->apply($choices, $review['fingerprint']);

        $this->assertArrayNotHasKey('ansible_user', $this->remoteHosts()['web1']['vars']);
    }

    // ============================================================ INI -> YAML

    public function test_an_ini_inventory_is_read_and_converted_to_yaml_when_written(): void
    {
        $sync = $this->seedRepo(self::INI, 'inventory/hosts.ini');
        $s = $this->servers();

        $plan = $sync->compare();
        $this->assertSame('ini', $plan['format']);
        $this->assertSame(['from' => 'inventory/hosts.ini', 'to' => 'inventory/hosts.yml'], $plan['convert']);
        $this->assertSame(['web1'], array_column($plan['in_sync'], 'host'));

        $choices = ['srv:'.$s['only']->id => ['action' => 'add_inventory']];
        $review = $sync->review($choices);

        $this->assertSame(['from' => 'inventory/hosts.ini', 'to' => 'inventory/hosts.yml'], $review['convert']);
        $this->assertStringContainsString('Convert inventory/hosts.ini to YAML as inventory/hosts.yml (the old file is kept)', implode("\n", $review['inventory']));
        $this->assertStringContainsString('inventory/hosts.yml', $review['diff']);

        $sync->apply($choices, $review['fingerprint']);

        $yaml = $this->remoteHosts('inventory/hosts.yml');
        $this->assertSame('keepme', $yaml['db1']['vars']['custom_var'], 'variables LabMan does not manage are carried over');
        $this->assertSame(2222, $yaml['db1']['vars']['ansible_port']);
        $this->assertArrayHasKey('Only-Here', $yaml);
        $this->assertNotNull($this->remote->remoteFile('inventory/hosts.ini'), 'the INI file is kept by default so Semaphore keeps working');
        $this->assertSame('inventory/hosts.yml', Secrets::plain('inventory.path'), 'later syncs use the YAML file');
    }

    public function test_the_old_ini_file_can_be_deleted_in_the_same_commit(): void
    {
        $sync = $this->seedRepo(self::INI, 'inventory/hosts.ini');
        $s = $this->servers();
        $choices = ['srv:'.$s['only']->id => ['action' => 'add_inventory']];

        $review = $sync->review($choices, deleteOld: true);
        $this->assertStringContainsString('and delete the old file', implode("\n", $review['inventory']));
        $this->assertStringContainsString('deleted file', $review['diff']);
        $sync->apply($choices, $review['fingerprint'], deleteOld: true);

        $this->assertNull($this->remote->remoteFile('inventory/hosts.ini'));
        $this->assertNotNull($this->remote->remoteFile('inventory/hosts.yml'));

        // The delete flag is part of the fingerprint: a review made without it cannot be used with it.
        $this->expectException(InventoryException::class);
        $sync->apply($choices, $review['fingerprint'], deleteOld: false);
    }

    public function test_the_yaml_file_can_be_created_without_any_host_change(): void
    {
        $sync = $this->seedRepo(self::INI, 'inventory/hosts.ini');

        $review = $sync->review([], convert: true);
        $this->assertTrue($review['changes']);
        $this->assertSame('inventory/hosts.yml', $review['convert']['to']);
        $sync->apply([], $review['fingerprint'], convert: true);

        $this->assertNotNull($this->remote->remoteFile('inventory/hosts.yml'));
        $this->assertNotNull($this->remote->remoteFile('inventory/hosts.ini'), 'kept unless deletion is chosen');
    }

    public function test_deleting_the_old_file_works_without_any_host_change(): void
    {
        $sync = $this->seedRepo(self::INI, 'inventory/hosts.ini');

        $review = $sync->review([], deleteOld: true);
        $sync->apply([], $review['fingerprint'], deleteOld: true);

        $this->assertNull($this->remote->remoteFile('inventory/hosts.ini'));
        $this->assertNotNull($this->remote->remoteFile('inventory/hosts.yml'));
    }

    public function test_conversion_is_refused_when_the_yaml_file_already_exists(): void
    {
        $sync = $this->seedRepo(self::INI, 'inventory/hosts.ini');
        $this->remote->commitFromElsewhere(['inventory/hosts.yml' => "all:\n"], 'already there');
        $s = $this->servers();

        $this->expectException(InventoryException::class);
        $this->expectExceptionMessage("'inventory/hosts.yml' already exists");
        $sync->review(['srv:'.$s['only']->id => ['action' => 'add_inventory']]);
    }

    public function test_an_ini_inventory_is_not_converted_when_only_labman_changes(): void
    {
        $sync = $this->seedRepo(self::INI, 'inventory/hosts.ini');
        $s = $this->servers();
        $choices = ['pair:'.$s['db1']->id => ['fields' => ['address' => 'inventory']]];

        $review = $sync->review($choices);
        $this->assertNull($review['convert']);
        $sync->apply($choices, $review['fingerprint']);

        $this->assertNull($this->remote->remoteFile('inventory/hosts.yml'));
        $this->assertSame('192.0.2.21', $s['db1']->fresh()->address);
    }

    public function test_an_ini_file_without_an_extension_converts_next_to_itself(): void
    {
        $sync = $this->seedRepo("web1 ansible_host=192.0.2.11\n", 'inventory/hosts');
        $server = Server::create(['name' => 'new', 'address' => '192.0.2.60']);

        $choices = ['srv:'.$server->id => ['action' => 'add_inventory']];
        $review = $sync->review($choices);

        $this->assertSame('inventory/hosts.yml', $review['convert']['to']);
    }

    public function test_yaml_inventories_are_rewritten_in_place(): void
    {
        $sync = $this->seedRepo();
        $s = $this->servers();
        $choices = ['srv:'.$s['only']->id => ['action' => 'add_inventory']];
        $review = $sync->review($choices);
        $sync->apply($choices, $review['fingerprint']);

        $this->assertNull($review['convert']);
        $this->assertCount(2, $this->remote->log(), 'seed + one LabMan commit');
        $this->assertNull($this->remote->remoteFile('inventory/hosts.ini'));
    }
}
