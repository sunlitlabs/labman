<?php

namespace Tests\Feature;

use App\Models\Server;
use App\Models\Service;
use App\Support\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

/** Admin CRUD, input validation, rendering and the SSH command. */
class ManagementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs($this->admin());
    }

    // ----------------------------------------------------------------- escaping

    public function test_html_in_names_is_escaped_everywhere(): void
    {
        $evil = '<script>alert(1)</script>';
        $server = Server::create(['name' => $evil, 'address' => '192.0.2.10', 'group' => $evil, 'notes' => $evil]);
        Service::create(['name' => $evil, 'domain' => 'app.example.com', 'address' => '192.0.2.20', 'scheme' => 'http', 'path' => '/', 'group' => $evil, 'notes' => $evil]);
        Settings::set(['title' => '<b>Lab</b>']);

        foreach (['/services', '/servers'] as $path) {
            $html = $this->get($path)->assertOk()->getContent();

            $this->assertStringNotContainsString('<script>alert(1)</script>', $html, $path);
            $this->assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $html, $path);
            $this->assertStringNotContainsString('<b>Lab</b>', $html);
        }

        $this->get("/servers/{$server->id}/edit")->assertOk()->assertDontSee('<script>alert(1)</script>', false);
    }

    public function test_html_in_check_details_is_escaped(): void
    {
        $service = Service::create(['name' => 'ok', 'address' => '192.0.2.20', 'scheme' => 'http', 'path' => '/']);
        $service->forceFill([
            'state' => 'down', 'checked_at' => now(),
            'checks' => [['label' => 'backend', 'ok' => false, 'ms' => 3, 'detail' => '<img src=x onerror=alert(1)>']],
        ])->save();

        $this->get('/services')->assertDontSee('<img src=x', false)->assertSee('&lt;img src=x', false);
    }

    // ---------------------------------------------------------------- servers

    public function test_admin_creates_updates_and_deletes_a_server(): void
    {
        $this->post('/servers', [
            'name' => 'nas', 'address' => '192.0.2.10', 'domain' => 'nas.example.com', 'group' => 'Storage',
            'ports' => '445, 8080', 'ssh_user' => 'deploy', 'ssh_port' => 2222, 'notes' => 'files',
        ])->assertRedirect(route('servers.index'));

        $server = Server::where('name', 'nas')->firstOrFail();
        $this->assertSame([445, 8080], $server->ports);
        $this->assertSame([2222, 445, 8080], $server->checkPorts());

        $this->put("/servers/{$server->id}", ['name' => 'nas', 'address' => '2001:db8::10', 'ssh_port' => 22])
            ->assertRedirect(route('servers.index'));
        $this->assertSame('2001:db8::10', $server->fresh()->address);

        $this->delete("/servers/{$server->id}")->assertRedirect(route('servers.index'));
        $this->assertSame(0, Server::count());
    }

    public function test_server_input_is_validated(): void
    {
        $base = ['name' => 'nas', 'address' => '192.0.2.10', 'ssh_port' => 22];

        $bad = [
            'address' => ['192.0.2.10; reboot', 'bad host', '-oProxyCommand=x', '999.1.1.1', 'http://example.com'],
            'domain' => ['not a domain', 'a_b.example.com', 'https://x.example.com'],
            'ssh_user' => ['-oProxyCommand=x', 'bob; rm -rf /', 'a b', '$(id)', 'x`y`'],
            'ssh_port' => [0, 65536, 'abc'],
            'ports' => ['70000', '0', 'abc'],
        ];

        foreach ($bad as $field => $values) {
            foreach ($values as $value) {
                $this->post('/servers', [$field => $value] + $base)
                    ->assertSessionHasErrors($field === 'ports' ? 'ports.0' : $field);
            }
        }

        $this->assertSame(0, Server::count());
    }

    public function test_server_names_must_be_unique(): void
    {
        Server::create(['name' => 'nas', 'address' => '192.0.2.10']);

        $this->post('/servers', ['name' => 'nas', 'address' => '192.0.2.11', 'ssh_port' => 22])->assertSessionHasErrors('name');
    }

    public function test_valid_hosts_are_accepted(): void
    {
        foreach (['192.0.2.10', '2001:db8::1', 'nas', 'nas.example.com', 'xn--bcher-kva.example'] as $i => $address) {
            $this->post('/servers', ['name' => "s{$i}", 'address' => $address, 'ssh_port' => 22])->assertSessionHasNoErrors();
        }

        $this->assertSame(5, Server::count());
    }

    // -------------------------------------------------------------- ssh command

    public function test_ssh_command_formats(): void
    {
        Settings::set(['ssh_user' => 'admin', 'ssh_target' => 'address']);

        $plain = Server::create(['name' => 'a', 'address' => '192.0.2.5', 'domain' => 'a.example.com']);
        $this->assertSame('ssh admin@192.0.2.5', $plain->sshCommand());

        $port = Server::create(['name' => 'b', 'address' => '192.0.2.6', 'ssh_port' => 2222, 'ssh_user' => 'deploy']);
        $this->assertSame('ssh -p 2222 deploy@192.0.2.6', $port->sshCommand());

        Settings::set(['ssh_target' => 'domain']);
        $this->assertSame('ssh admin@a.example.com', $plain->fresh()->sshCommand());
        $this->assertSame('ssh -p 2222 deploy@192.0.2.6', $port->fresh()->sshCommand(), 'no domain: falls back to the address');

        Settings::set(['ssh_user' => '']);
        $this->assertSame('ssh a.example.com', $plain->fresh()->sshCommand(), 'no user anywhere: bare host');

        $v6 = Server::create(['name' => 'c', 'address' => '2001:db8::1', 'ssh_user' => 'root']);
        Settings::set(['ssh_target' => 'address']);
        $this->assertSame('ssh root@2001:db8::1', $v6->fresh()->sshCommand());
    }

    public function test_servers_page_exposes_the_copy_command_and_filter(): void
    {
        Settings::set(['ssh_user' => 'admin']);
        Server::create(['name' => 'nas', 'address' => '192.0.2.5']);

        $this->get('/servers')
            ->assertOk()
            ->assertSee('data-ssh="ssh admin@192.0.2.5"', false)
            // The polling script reads these; a regression once HTML-escaped the quotes and silently stopped live updates.
            ->assertSee('data-status-url="'.route('status').'" data-interval="30"', false)
            ->assertSee('id="filter"', false)
            ->assertSee('data-count="servers:unknown"', false);
    }

    // ----------------------------------------------------------------- services

    public function test_admin_creates_a_service_on_a_server_or_with_its_own_address(): void
    {
        $server = Server::create(['name' => 'docker-1', 'address' => '192.0.2.20']);

        $this->post('/services', [
            'name' => 'Wiki', 'domain' => 'wiki.example.com', 'server_id' => $server->id, 'port' => 3000,
            'scheme' => 'http', 'path' => '/', 'expect' => '200-399', 'verify_tls' => 1, 'public_check' => 1,
        ])->assertRedirect(route('services.index'));

        $this->post('/services', [
            'name' => 'Router', 'address' => '192.0.2.1', 'port' => 443, 'scheme' => 'https', 'path' => '/', 'expect' => '200-499', 'public_check' => 0,
        ])->assertRedirect(route('services.index'));

        $wiki = Service::where('name', 'Wiki')->firstOrFail();
        $this->assertSame('http://192.0.2.20:3000/', $wiki->backendUrl());
        $this->assertSame('https://wiki.example.com/', $wiki->openUrl(), 'the public URL wins');

        $router = Service::where('name', 'Router')->firstOrFail();
        $this->assertSame('https://192.0.2.1:443/', $router->openUrl(), 'no domain: the backend URL');
        $this->assertFalse($router->public_check);
    }

    public function test_ipv6_addresses_are_bracketed_in_urls(): void
    {
        $service = Service::create(['name' => 'v6', 'address' => '2001:db8::1', 'port' => 8080, 'scheme' => 'http', 'path' => '/x']);

        $this->assertSame('http://[2001:db8::1]:8080/x', $service->backendUrl());
    }

    public function test_service_needs_exactly_one_of_server_or_address(): void
    {
        $server = Server::create(['name' => 'docker-1', 'address' => '192.0.2.20']);
        $base = ['name' => 'X', 'scheme' => 'http', 'path' => '/', 'expect' => '200-399'];

        $this->post('/services', $base)->assertSessionHasErrors('server_id');
        $this->post('/services', $base + ['server_id' => $server->id, 'address' => '192.0.2.5'])->assertSessionHasErrors('server_id');

        $this->assertSame(0, Service::count());
    }

    public function test_service_input_is_validated(): void
    {
        $base = ['name' => 'X', 'address' => '192.0.2.5', 'scheme' => 'http', 'path' => '/', 'expect' => '200-399'];

        $cases = [
            ['path', 'no-slash'], ['path', '/has space'], ['path', '/<script>'],
            ['expect', '399-200'], ['expect', 'abc'], ['expect', '99'],
            ['scheme', 'ftp'], ['port', 0], ['port', 70000],
            ['address', 'bad host'], ['domain', 'bad domain'],
        ];

        foreach ($cases as [$field, $value]) {
            $this->post('/services', [$field => $value] + $base)->assertSessionHasErrors($field);
        }

        $this->assertSame(0, Service::count());
    }

    public function test_blank_path_and_expect_fall_back_to_defaults(): void
    {
        $this->post('/services', ['name' => 'X', 'address' => '192.0.2.5', 'scheme' => 'http', 'path' => '', 'expect' => ''])
            ->assertSessionHasNoErrors();

        $service = Service::firstOrFail();
        $this->assertSame('/', $service->path);
        $this->assertSame('200-499', $service->expect);
    }

    public function test_services_page_renders_open_links(): void
    {
        Service::create(['name' => 'Wiki', 'domain' => 'wiki.example.com', 'address' => '192.0.2.20', 'port' => 3000, 'scheme' => 'http', 'path' => '/']);
        Service::create(['name' => 'Lonely', 'scheme' => 'http', 'path' => '/']);

        $this->get('/services')->assertOk()
            ->assertSee('data-status-url="'.route('status').'" data-interval="30"', false)
            ->assertSee('data-href="https://wiki.example.com/"', false)
            ->assertSee('no address')
            ->assertSee('data-count="services:unknown"', false);
    }

    // --------------------------------------------------------------- settings

    public function test_admin_saves_settings_with_limits_enforced(): void
    {
        $valid = [
            'title' => 'Home Lab', 'check_interval' => 15, 'timeout' => 2.5, 'ssh_user' => 'admin', 'ssh_target' => 'domain',
            'registration_mode' => 'open',
            'visibility' => ['services' => 'everyone', 'servers' => 'users', 'settings' => 'admins', 'users' => 'admins'],
        ];

        $this->put('/settings', $valid)->assertRedirect(route('settings.index'));

        $this->assertSame('Home Lab', Settings::title());
        $this->assertSame(15, Settings::get('check_interval'));
        $this->assertSame(2.5, Settings::timeout());
        $this->assertSame('everyone', Settings::visibility('services'));
        $this->assertSame('open', Settings::registrationMode());

        $this->put('/settings', ['check_interval' => 4] + $valid)->assertSessionHasErrors('check_interval');
        $this->put('/settings', ['timeout' => 0.1] + $valid)->assertSessionHasErrors('timeout');
        $this->put('/settings', ['timeout' => 61] + $valid)->assertSessionHasErrors('timeout');
        $this->put('/settings', ['ssh_user' => '-oFoo'] + $valid)->assertSessionHasErrors('ssh_user');
        $this->put('/settings', ['ssh_target' => 'both'] + $valid)->assertSessionHasErrors('ssh_target');
    }

    public function test_yaml_import_through_the_settings_page(): void
    {
        $this->post('/settings/import', ['yaml' => (string) file_get_contents(base_path('labman.example.yml'))])
            ->assertRedirect(route('settings.data'));
        $this->assertSame(2, Server::count());

        $this->post('/settings/import', ['yaml' => "servers:\n  - {name: x}\nservices: []\n"])
            ->assertSessionHasErrors('yaml');

        $file = UploadedFile::fake()->createWithContent('lab.yml', "servers:\n  - {name: only, address: 192.0.2.77}\nservices: []\n");
        $this->post('/settings/import', ['yaml_file' => $file, 'replace' => 1])->assertRedirect(route('settings.data'));
        $this->assertSame(['only'], Server::pluck('name')->all());
    }

    public function test_yaml_export_downloads_a_file(): void
    {
        Server::create(['name' => 'nas', 'address' => '192.0.2.10']);

        $this->get('/settings/export')
            ->assertOk()
            ->assertHeader('Content-Disposition', 'attachment; filename="labman.yml"')
            ->assertSee('name: nas', false);
    }

    public function test_pages_send_security_headers(): void
    {
        $this->get('/services')
            ->assertHeader('X-Frame-Options', 'DENY')
            ->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeaderMissing('X-Powered-By');
    }
}
