<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\Secrets;
use App\Support\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** The admin panel: one place for everything that configures LabMan, apart from the day-to-day pages. */
class AdminPanelTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = $this->admin(['email' => 'root@example.com']);
    }

    // ------------------------------------------------------------------ access

    public function test_the_overview_follows_who_may_open_the_admin_panel(): void
    {
        $this->get('/admin')->assertRedirect(route('login'));
        $this->actingAs($this->user())->get('/admin')->assertForbidden();
        $this->actingAs($this->admin)->get('/admin')->assertOk()->assertSee('Integrations and tools')->assertSee('Inventory (Ansible)')->assertSee('Nginx Proxy Manager');
    }

    public function test_viewers_allowed_to_see_settings_get_a_read_only_panel_without_integrations(): void
    {
        Settings::set(['visibility.settings' => 'users', 'visibility.users' => 'admins']);

        $page = $this->actingAs($this->user())->get('/admin')->assertOk()
            ->assertSee('only administrators can change them')->assertSee('General')->assertSee('Access')
            ->assertDontSee('Inventory (Ansible)')->assertDontSee('Semaphore')->assertDontSee('AI access')->assertDontSee('Integrations');

        $this->assertStringNotContainsString(route('users.index'), $page->getContent());
        // Everything else in the panel stays admin-only.
        foreach (['/inventory', '/semaphore', '/npm', '/settings/data', '/settings/mcp'] as $uri) {
            $this->get($uri)->assertForbidden();
        }
    }

    public function test_the_main_navigation_only_shows_an_admin_link_to_those_who_can_enter(): void
    {
        $this->actingAs($this->user())->get('/services')->assertOk()->assertDontSee('>Admin<', false);

        $nav = $this->actingAs($this->admin)->get('/services')->assertOk()->assertSee('>Admin<', false)->getContent();
        // The long list of admin pages is no longer in the main navigation; it lives in the panel's sidebar.
        foreach (['inventory', 'semaphore', 'npm', 'users'] as $name) {
            $this->assertStringNotContainsString('href="'.route($name.'.index').'"', $nav, $name);
        }
    }

    public function test_the_sidebar_groups_pages_and_marks_the_current_one(): void
    {
        $html = $this->actingAs($this->admin)->get('/settings/access')->assertOk()->getContent();

        foreach (['Site', 'Integrations', 'Tools'] as $group) {
            $this->assertStringContainsString("<h3>{$group}</h3>", $html);
        }
        foreach (['Overview', 'General', 'Access', 'Users', 'Inventory (Ansible)', 'Semaphore', 'Nginx Proxy Manager', 'AI access (MCP)', 'Import and export'] as $label) {
            $this->assertStringContainsString("<span>{$label}</span>", $html, $label);
        }
        $this->assertMatchesRegularExpression('#class="adminnav-item\s+is-active\s*"\s+aria-current="page"[^>]*>\s*<span>Access</span>#', $html);
        $this->assertDoesNotMatchRegularExpression('#is-active[^>]*>\s*<span>General</span>#', $html);
    }

    public function test_step_by_step_pages_hide_the_sidebar_and_show_a_breadcrumb(): void
    {
        $html = $this->actingAs($this->admin)->post('/settings/tokens', ['name' => 'x', 'scope' => 'read'])->assertOk()->getContent();

        $this->assertStringNotContainsString('class="adminnav"', $html);
        $this->assertStringContainsString('aria-label="Breadcrumb"', $html);
        $this->assertStringContainsString('AI access (MCP)', $html);
    }

    public function test_the_sidebar_shows_which_integrations_are_connected_and_pending_users(): void
    {
        User::factory()->pending()->count(2)->create();
        Secrets::putPlain('semaphore.url', 'http://semaphore.test:3000');

        $html = $this->actingAs($this->admin)->get('/settings')->assertOk()->getContent();

        $this->assertMatchesRegularExpression('#<span>Semaphore</span>\s*<span class="dot dot-on"#', $html);
        $this->assertMatchesRegularExpression('#<span>Inventory \(Ansible\)</span>\s*<span class="dot dot-off"#', $html);
        $this->assertMatchesRegularExpression('#<span>Users</span>\s*<span class="adminnav-badge"[^>]*>2</span>#', $html);
    }

    public function test_the_overview_alerts_about_pending_accounts_and_reflects_integration_state(): void
    {
        User::factory()->pending()->create();
        Secrets::putPlain('npm.url', 'http://npm.test:81');

        $page = $this->actingAs($this->admin)->get('/admin')->assertOk()
            ->assertSee('1 account waiting for approval')->assertSee('http://npm.test:81');
        $this->assertStringContainsString('connected', $page->getContent());

        $this->assertSame(1, preg_match_all('/statuschip on/', $page->getContent()), 'only NPM is connected');
    }

    // ------------------------------------------------------------ split settings

    public function test_general_and_access_are_separate_pages(): void
    {
        $this->actingAs($this->admin);

        $this->get('/settings')->assertOk()->assertSee('name="title"', false)->assertSee('name="check_interval"', false)->assertSee('Copy SSH')
            ->assertDontSee('name="registration_mode"', false)->assertDontSee('name="visibility[servers]"', false);

        $this->get('/settings/access')->assertOk()->assertSee('name="registration_mode"', false)->assertSee('name="visibility[servers]"', false)
            ->assertDontSee('name="title"', false)->assertDontSee('name="check_interval"', false);
    }

    public function test_each_page_saves_only_its_own_settings(): void
    {
        $this->actingAs($this->admin);
        Settings::set(['title' => 'Before', 'registration_mode' => 'off', 'visibility.servers' => 'admins']);

        $this->put('/settings', ['section' => 'general', 'title' => 'After', 'check_interval' => 20, 'timeout' => 3, 'ssh_user' => 'ops', 'ssh_target' => 'domain'])
            ->assertRedirect(route('settings.index'))->assertSessionHas('status');

        $this->assertSame('After', Settings::title());
        $this->assertSame('ops', Settings::get('ssh_user'));
        $this->assertSame('off', Settings::registrationMode(), 'the general page leaves access settings alone');
        $this->assertSame('admins', Settings::visibility('servers'));

        $this->put('/settings', ['section' => 'access', 'registration_mode' => 'open', 'visibility' => ['servers' => 'everyone', 'services' => 'users', 'settings' => 'admins', 'users' => 'admins']])
            ->assertRedirect(route('settings.access'));

        $this->assertSame('open', Settings::registrationMode());
        $this->assertSame('everyone', Settings::visibility('servers'));
        $this->assertSame('After', Settings::title(), 'the access page leaves general settings alone');
        $this->assertSame(20, Settings::get('check_interval'));
    }

    public function test_each_page_validates_its_own_fields(): void
    {
        $this->actingAs($this->admin);

        $this->put('/settings', ['section' => 'general', 'title' => '', 'check_interval' => 1, 'timeout' => 3, 'ssh_target' => 'address'])->assertSessionHasErrors(['title', 'check_interval']);
        $this->put('/settings', ['section' => 'general', 'title' => 'x', 'check_interval' => 10, 'timeout' => 3, 'ssh_user' => '-oFoo', 'ssh_target' => 'address'])->assertSessionHasErrors('ssh_user');
        $this->put('/settings', ['section' => 'access', 'registration_mode' => 'wild', 'visibility' => ['services' => 'martians']])->assertSessionHasErrors(['registration_mode', 'visibility.services']);
        $this->assertNotSame('x', Settings::title());
    }

    public function test_viewers_see_the_pages_read_only_and_cannot_save(): void
    {
        Settings::set(['visibility.settings' => 'users']);
        $this->actingAs($this->user());

        foreach (['/settings', '/settings/access'] as $uri) {
            $this->get($uri)->assertOk()->assertSee('only administrators can change them')->assertDontSee('Save settings')->assertDontSee('Save access settings')->assertSee('disabled', false);
        }

        $this->put('/settings', ['section' => 'general', 'title' => 'Hacked', 'check_interval' => 10, 'timeout' => 3, 'ssh_target' => 'address'])->assertForbidden();
        $this->assertNotSame('Hacked', Settings::title());
    }

    public function test_import_export_and_mcp_have_their_own_admin_only_pages(): void
    {
        $this->actingAs($this->admin);
        $this->get('/settings/data')->assertOk()->assertSee('Download labman.yml')->assertSee('name="yaml"', false)->assertDontSee('name="title"', false);
        $this->get('/settings/mcp')->assertOk()->assertSee('API tokens')->assertSee('Create token')->assertSee(url('/mcp'));

        Settings::set(['visibility.settings' => 'everyone']);
        auth()->logout();
        foreach (['/settings/data', '/settings/mcp'] as $uri) {
            $this->get($uri)->assertRedirect(route('login'));
        }
        $this->actingAs($this->user());
        foreach (['/settings/data', '/settings/mcp'] as $uri) {
            $this->get($uri)->assertForbidden();
        }
    }

    public function test_the_access_page_offers_every_level_for_every_screen(): void
    {
        $html = $this->actingAs($this->admin)->get('/settings/access')->assertOk()->getContent();

        foreach (['services', 'servers', 'settings', 'users'] as $screen) {
            foreach (['everyone', 'users', 'admins'] as $level) {
                $this->assertStringContainsString('name="visibility['.$screen.']" value="'.$level.'"', $html, "{$screen}/{$level}");
            }
        }
        foreach (['off', 'open', 'approval'] as $mode) {
            $this->assertStringContainsString('name="registration_mode" value="'.$mode.'"', $html);
        }
        // The current values are pre-selected.
        $this->assertMatchesRegularExpression('#name="visibility\[servers\]" value="users"\s+checked#', $html);
        $this->assertMatchesRegularExpression('#name="registration_mode" value="approval"\s+checked#', $html);
    }

    public function test_the_home_page_is_the_dashboard_and_links_to_the_main_pages(): void
    {
        $this->actingAs($this->admin)->get('/')->assertOk()
            ->assertSee('href="'.route('services.index').'"', false)
            ->assertSee('href="'.route('servers.index').'"', false);
    }
}
