<?php

namespace Tests\Feature;

use App\Http\Middleware\RedirectToSetup;
use App\Models\Server;
use App\Models\User;
use App\Support\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SetupTest extends TestCase
{
    use RefreshDatabase;

    private function payload(array $overrides = []): array
    {
        return array_replace_recursive([
            'name' => 'First Admin',
            'email' => 'admin@example.com',
            'password' => 'correct horse battery',
            'password_confirmation' => 'correct horse battery',
            'title' => 'My Lab',
            'registration_mode' => 'approval',
            'visibility' => ['services' => 'users', 'servers' => 'users', 'settings' => 'admins', 'users' => 'admins'],
        ], $overrides);
    }

    public function test_every_request_redirects_to_setup_until_an_admin_exists(): void
    {
        foreach (['/', '/login', '/services', '/servers', '/settings', '/status', '/register'] as $path) {
            $this->get($path)->assertRedirect('/setup');
        }

        $this->post('/login', ['email' => 'a@example.com', 'password' => 'x'])->assertRedirect('/setup');
        $this->get('/setup')->assertOk()->assertSee('Set up LabMan');
    }

    public function test_the_container_health_route_is_exempt(): void
    {
        $this->get('/up')->assertOk();
    }

    public function test_the_wizard_creates_the_admin_applies_settings_and_signs_in(): void
    {
        $this->post('/setup', $this->payload(['registration_mode' => 'off', 'visibility' => ['servers' => 'admins']]))
            ->assertRedirect('/');

        $admin = User::where('email', 'admin@example.com')->firstOrFail();
        $this->assertSame('admin', $admin->role);
        $this->assertSame('active', $admin->status);
        $this->assertAuthenticatedAs($admin);

        Settings::flush();
        $this->assertSame('My Lab', Settings::title());
        $this->assertSame('off', Settings::registrationMode());
        $this->assertSame('admins', Settings::visibility('servers'));
        $this->assertSame('users', Settings::visibility('services'));
    }

    public function test_setup_locks_permanently_once_an_admin_exists(): void
    {
        $this->post('/setup', $this->payload())->assertRedirect('/');
        $this->post('/logout');

        $this->get('/setup')->assertNotFound();
        $this->post('/setup', $this->payload(['email' => 'attacker@example.com']))->assertNotFound();

        $this->assertSame(1, User::count());
        $this->assertDatabaseMissing('users', ['email' => 'attacker@example.com']);
    }

    public function test_the_lock_is_rechecked_inside_the_transaction(): void
    {
        // Simulate losing the race: an admin appears after the middleware check would have passed.
        $this->admin(['email' => 'winner@example.com']);

        $this->withoutMiddleware(RedirectToSetup::class)
            ->post('/setup', $this->payload(['email' => 'late@example.com']))
            ->assertNotFound();

        $this->assertSame(1, User::count());
        $this->assertDatabaseMissing('users', ['email' => 'late@example.com']);
    }

    public function test_there_are_no_default_credentials(): void
    {
        $this->assertSame(0, User::count());
        $this->post('/login', ['email' => 'admin@example.com', 'password' => 'password'])->assertRedirect('/setup');
    }

    public function test_the_setup_token_is_required_when_configured(): void
    {
        config(['labman.setup_token' => 's3cret-token']);

        $this->get('/setup')->assertSee('Setup token');

        $this->post('/setup', $this->payload())->assertSessionHasErrors('setup_token');
        $this->post('/setup', $this->payload(['setup_token' => 'wrong']))->assertSessionHasErrors('setup_token');
        $this->assertSame(0, User::count());

        $this->post('/setup', $this->payload(['setup_token' => 's3cret-token']))->assertRedirect('/');
        $this->assertSame(1, User::count());
    }

    public function test_validation_failures_create_nothing(): void
    {
        $this->post('/setup', $this->payload(['password_confirmation' => 'different']))->assertSessionHasErrors('password');
        $this->post('/setup', $this->payload(['registration_mode' => 'wild']))->assertSessionHasErrors('registration_mode');
        $this->post('/setup', $this->payload(['visibility' => ['services' => 'martians']]))->assertSessionHasErrors('visibility.services');

        $this->assertSame(0, User::count());
    }

    public function test_optional_yaml_is_imported_and_invalid_yaml_blocks_setup(): void
    {
        $this->post('/setup', $this->payload(['yaml' => "servers:\n  - {name: x}\nservices: []\n"]))
            ->assertSessionHasErrors('yaml');
        $this->assertSame(0, User::count(), 'a bad YAML file must not half-complete setup');

        $yaml = "servers:\n  - {name: nas, address: 192.0.2.10}\nservices: []\n";
        $this->post('/setup', $this->payload(['yaml' => $yaml]))->assertRedirect('/');

        $this->assertSame('192.0.2.10', Server::where('name', 'nas')->value('address'));
    }
}
