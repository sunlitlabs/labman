<?php

namespace Tests\Feature;

use App\Models\Server;
use App\Models\Service;
use App\Support\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/** The four screens x three roles, under the default visibility and each configurable level. */
class ScreenAccessTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin(['email' => 'root@example.com']); // setup must be complete for any of this to be reachable
    }

    /** @return array<string, array{0: string, 1: string, 2: string}> screen, role, expectation */
    public static function defaultMatrix(): array
    {
        $rows = [];
        foreach (['services', 'servers'] as $screen) {
            $rows["$screen / guest"] = [$screen, 'guest', 'login'];
            $rows["$screen / user"] = [$screen, 'user', 'ok'];
            $rows["$screen / admin"] = [$screen, 'admin', 'ok'];
        }
        foreach (['settings', 'users'] as $screen) {
            $rows["$screen / guest"] = [$screen, 'guest', 'login'];
            $rows["$screen / user"] = [$screen, 'user', 'forbidden'];
            $rows["$screen / admin"] = [$screen, 'admin', 'ok'];
        }

        return $rows;
    }

    private function actAs(string $role): void
    {
        match ($role) {
            'user' => $this->actingAs($this->user()),
            'admin' => $this->actingAs($this->admin()),
            default => null,
        };
    }

    private function assertOutcome($response, string $expected): void
    {
        match ($expected) {
            'ok' => $response->assertOk(),
            'login' => $response->assertRedirect(route('login')),
            'forbidden' => $response->assertForbidden(),
        };
    }

    #[DataProvider('defaultMatrix')]
    public function test_default_visibility(string $screen, string $role, string $expected): void
    {
        $this->actAs($role);

        $this->assertOutcome($this->get("/{$screen}"), $expected);
    }

    public function test_everyone_level_lets_guests_in(): void
    {
        Settings::set(['visibility.services' => 'everyone', 'visibility.users' => 'everyone']);

        $this->get('/services')->assertOk();
        $this->get('/users')->assertOk()->assertDontSee('Add user');
        $this->get('/servers')->assertRedirect(route('login'));
    }

    public function test_admins_only_level_shuts_out_users_and_guests(): void
    {
        Settings::set(['visibility.services' => 'admins']);

        $this->get('/services')->assertRedirect(route('login'));
        $this->actingAs($this->user())->get('/services')->assertForbidden();
        $this->actingAs($this->admin())->get('/services')->assertOk();
    }

    public function test_users_level_on_settings_and_users_is_read_only_for_non_admins(): void
    {
        Settings::set(['visibility.settings' => 'users', 'visibility.users' => 'users']);
        $this->actingAs($this->user());

        $settings = $this->get('/settings')->assertOk()->assertSee('only administrators can change');
        $settings->assertDontSee('Save settings')->assertDontSee('YAML import');
        $this->get('/users')->assertOk()->assertSee('only administrators can change')->assertDontSee('Add user');

        // ...and read access never grants write access.
        $this->put('/settings', ['title' => 'Hacked'])->assertForbidden();
        $this->get('/users/create')->assertForbidden();
        $this->post('/settings/import', ['yaml' => 'servers: []'])->assertForbidden();
        $this->get('/settings/export')->assertForbidden();
        $this->assertNotSame('Hacked', Settings::title());
    }

    public function test_navigation_only_lists_screens_the_viewer_may_see(): void
    {
        $this->actingAs($this->user())->get('/services')
            ->assertSee('href="'.route('servers.index').'"', false)
            ->assertDontSee('href="'.route('settings.index').'"', false)
            ->assertDontSee('href="'.route('users.index').'"', false);
    }

    public function test_home_is_the_dashboard_for_anyone_who_may_see_services_or_servers(): void
    {
        Server::create(['name' => 'nas', 'address' => '192.0.2.10']);
        Service::create(['name' => 'Wiki', 'address' => '192.0.2.20', 'scheme' => 'http', 'path' => '/']);

        $this->get('/')->assertRedirect(route('login'));
        $this->actingAs($this->user())->get('/')->assertOk()->assertSee('Wiki')->assertSee('nas');

        // Only what the viewer may see is on it.
        Settings::set(['visibility.services' => 'admins']);
        $this->actingAs($this->user())->get('/')->assertOk()->assertDontSee('Wiki')->assertSee('nas');

        // Nothing visible at all: a plain user is refused, like the pages themselves.
        Settings::set(['visibility.servers' => 'admins']);
        $this->actingAs($this->user())->get('/')->assertForbidden();
    }

    // ------------------------------------------------------------ JSON status

    public function test_status_endpoint_follows_the_same_visibility(): void
    {
        Server::create(['name' => 'nas', 'address' => '192.0.2.10']);
        Service::create(['name' => 'Wiki', 'address' => '192.0.2.20', 'scheme' => 'http', 'path' => '/']);

        // Guest: refused (401 for JSON callers, redirect for browsers).
        $this->getJson('/status')->assertUnauthorized();
        $this->get('/status')->assertRedirect(route('login'));

        // Signed-in user under defaults sees both sections.
        $this->actingAs($this->user())->getJson('/status')
            ->assertOk()
            ->assertJsonStructure(['interval', 'services' => [['id', 'state', 'checks']], 'servers' => [['id', 'state', 'checks']], 'summary'])
            ->assertJsonPath('services.0.state', 'unknown');
    }

    public function test_status_endpoint_only_includes_sections_the_viewer_may_see(): void
    {
        Server::create(['name' => 'nas', 'address' => '192.0.2.10']);
        Service::create(['name' => 'Wiki', 'address' => '192.0.2.20', 'scheme' => 'http', 'path' => '/']);
        Settings::set(['visibility.servers' => 'admins', 'visibility.services' => 'everyone']);

        $this->getJson('/status')->assertOk()->assertJsonMissingPath('servers')->assertJsonStructure(['services']);
        $this->actingAs($this->user())->getJson('/status')->assertOk()->assertJsonMissingPath('servers');
        $this->actingAs($this->admin())->getJson('/status')->assertOk()->assertJsonStructure(['servers', 'services']);
    }

    public function test_status_endpoint_is_forbidden_when_no_section_is_visible(): void
    {
        Settings::set(['visibility.servers' => 'admins', 'visibility.services' => 'admins']);

        $this->getJson('/status')->assertUnauthorized();
        $this->actingAs($this->user())->getJson('/status')->assertForbidden();
        $this->actingAs($this->admin())->getJson('/status')->assertOk();
    }

    // -------------------------------------------------- editing is admin-only

    public function test_editing_is_admin_only_on_every_write_route(): void
    {
        $server = Server::create(['name' => 'nas', 'address' => '192.0.2.10']);
        $service = Service::create(['name' => 'Wiki', 'address' => '192.0.2.20', 'scheme' => 'http', 'path' => '/']);
        $victim = $this->user();

        // Everything visible to everyone: write access must still be refused.
        Settings::set(array_fill_keys(['visibility.services', 'visibility.servers', 'visibility.settings', 'visibility.users'], 'everyone'));

        $writes = [
            ['get', '/servers/create'], ['post', '/servers'], ['get', "/servers/{$server->id}/edit"],
            ['put', "/servers/{$server->id}"], ['delete', "/servers/{$server->id}"],
            ['get', '/services/create'], ['post', '/services'], ['get', "/services/{$service->id}/edit"],
            ['put', "/services/{$service->id}"], ['delete', "/services/{$service->id}"],
            ['get', '/users/create'], ['post', '/users'], ['put', "/users/{$victim->id}"], ['post', "/users/{$victim->id}/approve"],
            ['put', '/settings'], ['post', '/settings/import'], ['get', '/settings/export'],
        ];

        foreach ($writes as [$method, $uri]) {
            $this->{$method}($uri)->assertRedirect(route('login'));
        }

        $this->actingAs($this->user());
        foreach ($writes as [$method, $uri]) {
            $this->{$method}($uri)->assertForbidden();
        }

        $this->assertSame(1, Server::count());
        $this->assertSame(1, Service::count());
    }
}
