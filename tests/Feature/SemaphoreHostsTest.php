<?php

namespace Tests\Feature;

use App\Models\Notice;
use App\Models\Server;
use App\Semaphore\Connection;
use App\Semaphore\HostSync;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\FakeSemaphore;
use Tests\TestCase;

/** The workbooks page, aiming runs at servers, the host list kept in Semaphore, matching on the server list and the new-host notices. */
class SemaphoreHostsTest extends TestCase
{
    use RefreshDatabase;

    private FakeSemaphore $sem;

    protected function setUp(): void
    {
        parent::setUp();

        $this->sem = FakeSemaphore::install();
        Connection::save(FakeSemaphore::URL, 'sem-token', true);
    }

    private function choose(bool $auto = false): void
    {
        $this->actingAs($this->admin())->post('/semaphore/inventory', ['inventory' => '1:3'] + ($auto ? ['auto' => 1] : []))->assertSessionHasNoErrors();
    }

    private function puts(): array
    {
        return array_values(array_filter($this->sem->requests, fn ($r) => $r['method'] === 'PUT'));
    }

    // ----------------------------------------------------------- workbooks page

    public function test_workbooks_have_their_own_page_and_carry_chosen_servers_to_the_run_form(): void
    {
        Server::create(['name' => 'web1', 'address' => '192.0.2.11', 'group' => 'lab']);

        $this->actingAs($this->admin())->get('/workbooks?hosts[]=web1&groups[]=lab')->assertOk()
            ->assertSee('Update packages')->assertSee('Aimed at')->assertSee('web1')->assertSee('group lab')
            ->assertSee('hosts%5B0%5D=web1', false);

        $page = $this->get('/semaphore/run?project=1&template=7&hosts[]=web1&groups[]=lab')->assertOk()->getContent();
        $this->assertMatchesRegularExpression('#name="hosts\[\]" value="web1"\s+checked#', $page);
        $this->assertMatchesRegularExpression('#name="groups\[\]" value="lab"\s+checked#', $page);
    }

    public function test_the_run_form_lists_groups_and_servers_and_flags_those_missing_from_semaphore(): void
    {
        Server::create(['name' => 'web1', 'address' => '192.0.2.11', 'group' => 'Web']);
        Server::create(['name' => 'lonely', 'address' => '192.0.2.50', 'group' => 'Web']);
        $this->choose();

        $page = $this->get('/semaphore/run?project=1&template=7')->assertOk()->assertSee('value="Web"', false)->assertSee('2 server(s)')->getContent();

        $this->assertSame(1, substr_count($page, 'not in Semaphore</span>'));
    }

    // ------------------------------------------------------- choosing the inventory

    public function test_only_static_inventories_can_be_chosen(): void
    {
        $this->actingAs($this->admin())->get('/semaphore')->assertOk()->assertSee('Static hosts')->assertSee('not supported');

        $this->post('/semaphore/inventory', ['inventory' => '1:4'])->assertSessionHasErrors('inventory');
        $this->assertNull(HostSync::target());

        $this->post('/semaphore/inventory', ['inventory' => '1:3', 'auto' => 1])->assertRedirect(route('semaphore.index'));
        $this->assertSame(['project' => 1, 'inventory' => 3, 'auto' => true], HostSync::target());
        $this->assertNotNull(HostSync::known(), 'the host list is read when it is chosen');
    }

    // ------------------------------------------------------------------- syncing

    public function test_sync_adds_missing_servers_fixes_known_ones_and_never_removes_other_hosts(): void
    {
        $web = Server::create(['name' => 'web1', 'address' => '192.0.2.12', 'ssh_user' => 'deploy', 'group' => 'Web']);
        $new = Server::create(['name' => 'New Box', 'address' => '192.0.2.60', 'ssh_port' => 2222]);
        $this->choose();

        $this->post('/semaphore/inventory/sync')->assertRedirect(route('semaphore.index'));

        $yaml = $this->sem->inventories[3]['inventory'];
        $this->assertStringContainsString('192.0.2.12', $yaml);
        $this->assertStringContainsString('ansible_user: deploy', $yaml);
        $this->assertStringContainsString('New-Box', $yaml);
        $this->assertStringContainsString('ansible_port: 2222', $yaml);
        $this->assertStringContainsString('stranger', $yaml, 'hosts only Semaphore has are kept');
        $this->assertStringNotContainsString('192.0.2.11', $yaml);
        $this->assertSame('web1', $web->fresh()->inventory_host);
        $this->assertSame('New-Box', $new->fresh()->inventory_host);

        $count = count($this->puts());
        $this->post('/semaphore/inventory/sync');
        $this->assertCount($count, $this->puts(), 'nothing to change, nothing written');
    }

    public function test_a_static_ini_inventory_becomes_yaml_when_written(): void
    {
        $this->sem->inventories[3]['type'] = 'static';
        $this->sem->inventories[3]['inventory'] = "[web]\nweb1 ansible_host=192.0.2.11\n";
        Server::create(['name' => 'extra', 'address' => '192.0.2.70']);
        $this->choose();

        $this->post('/semaphore/inventory/sync');

        $this->assertSame('static-yaml', $this->sem->inventories[3]['type']);
        $this->assertStringContainsString('extra', $this->sem->inventories[3]['inventory']);
        $this->assertStringContainsString('web1', $this->sem->inventories[3]['inventory']);
    }

    public function test_server_changes_update_semaphore_when_switched_on_and_not_otherwise(): void
    {
        $this->choose(auto: false);
        $this->post('/servers', ['name' => 'quiet', 'address' => '192.0.2.71', 'ssh_port' => 22])->assertSessionHasNoErrors();
        $this->assertSame([], $this->puts(), 'automatic updates are off');

        $this->choose(auto: true);
        $this->post('/servers', ['name' => 'loud', 'address' => '192.0.2.72', 'ssh_port' => 22])->assertSessionHasNoErrors();
        $this->assertStringContainsString('loud', $this->sem->inventories[3]['inventory']);
        $this->assertStringContainsString('quiet', $this->sem->inventories[3]['inventory'], 'one update brings everything in line');

        $loud = Server::where('name', 'loud')->first();
        $this->delete(route('servers.destroy', $loud))->assertRedirect();
        $this->assertStringNotContainsString('loud', $this->sem->inventories[3]['inventory'], 'a deleted server is taken out');
        $this->assertStringContainsString('stranger', $this->sem->inventories[3]['inventory']);
    }

    public function test_a_failed_automatic_update_is_recorded_and_raised(): void
    {
        $this->choose(auto: true);
        $this->sem->token = 'rotated';

        $this->post('/servers', ['name' => 'oops', 'address' => '192.0.2.73', 'ssh_port' => 22])->assertSessionHasNoErrors();

        $this->assertFalse(HostSync::lastSync()['ok']);
        $this->assertSame('Semaphore\'s host list was not updated', Notice::where('key', 'semaphore-sync')->first()->title);
        $this->assertNotNull(Server::where('name', 'oops')->first(), 'the server is saved either way');
    }

    // ------------------------------------------------------------- the server list

    public function test_the_server_list_shows_matches_and_offers_to_run_workbooks(): void
    {
        Server::create(['name' => 'web1', 'address' => '192.0.2.11']);
        Server::create(['name' => 'lonely', 'address' => '192.0.2.50']);

        $this->actingAs($this->admin())->get('/servers')->assertOk()->assertDontSee('Not in Semaphore');
        $this->choose();

        $this->get('/servers')->assertOk()->assertSee('Semaphore: ')->assertSee('Not in Semaphore')
            ->assertSee('data-pick-server', false)->assertSee('Run a workbook on the selected servers')
            ->assertSee(e(route('workbooks.index', ['hosts' => ['web1']])), false);
    }

    public function test_non_admins_see_the_plain_server_list(): void
    {
        Server::create(['name' => 'web1', 'address' => '192.0.2.11']);
        $this->choose();
        auth()->logout();

        $this->actingAs($this->user())->get('/servers')->assertOk()->assertDontSee('Semaphore:')->assertDontSee('data-pick-server', false);
    }

    // --------------------------------------------------- noticing new hosts

    public function test_new_hosts_in_semaphore_raise_one_notice_for_admins(): void
    {
        Server::create(['name' => 'web1', 'address' => '192.0.2.11']);
        $this->choose();

        $this->assertSame(['stranger'], array_keys(HostSync::strangers()));
        $notice = Notice::where('key', 'semaphore-hosts')->first();
        $this->assertStringContainsString('stranger', $notice->body);

        $this->get('/servers')->assertSee('that LabMan does not have');

        // Looking again without anything new does not nag again once dismissed.
        $this->post(route('notices.dismiss', $notice))->assertRedirect();
        $this->assertSame([], HostSync::watch(true));
        $this->assertNotNull($notice->fresh()->dismissed_at);

        // A further new host brings it back.
        $this->sem->inventories[3]['inventory'] .= "    another:\n      ansible_host: 192.0.2.98\n";
        $this->assertSame(['another'], HostSync::watch(true));
        $this->assertNull($notice->fresh()->dismissed_at);

        auth()->logout();
        $this->actingAs($this->user())->get('/servers')->assertDontSee('that LabMan does not have');
    }

    public function test_the_watch_is_throttled(): void
    {
        $this->choose();
        $this->assertNull(HostSync::watch(), 'looked a moment ago');
    }

    public function test_new_hosts_can_be_added_or_ignored_and_the_notice_goes_away(): void
    {
        Server::create(['name' => 'web1', 'address' => '192.0.2.11']);
        $this->sem->inventories[3]['inventory'] .= "    other:\n      ansible_host: 192.0.2.97\n";
        $this->choose();

        $this->get('/semaphore/hosts')->assertOk()->assertSee('stranger')->assertSee('192.0.2.99');

        $this->post('/semaphore/hosts', ['choice' => ['stranger' => 'add', 'other' => 'ignore'], 'name' => ['stranger' => 'Stranger Box']])->assertRedirect(route('semaphore.index'));

        $server = Server::where('name', 'Stranger Box')->first();
        $this->assertSame('192.0.2.99', $server->address);
        $this->assertSame('stranger', $server->inventory_host);
        $this->assertSame([], HostSync::strangers());
        $this->assertNull(Notice::where('key', 'semaphore-hosts')->first(), 'nothing left to tell anyone about');
    }

    public function test_disconnecting_forgets_the_host_list_settings(): void
    {
        $this->choose();
        $this->delete('/semaphore/connection');

        $this->assertNull(HostSync::target());
        $this->assertNull(HostSync::known());
        $this->assertSame(0, Notice::count());
    }
}
