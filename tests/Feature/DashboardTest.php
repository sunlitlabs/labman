<?php

namespace Tests\Feature;

use App\Config\YamlConfig;
use App\Models\Link;
use App\Models\Server;
use App\Models\Service;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** The dashboard: items can be kept off it, links have their own tab, and the page offers tabs and a search. */
class DashboardTest extends TestCase
{
    use RefreshDatabase;

    private function lab(): void
    {
        Server::create(['name' => 'Nas', 'address' => '192.0.2.20']);
        Server::create(['name' => 'Hidden box', 'address' => '192.0.2.21', 'on_dashboard' => false]);
        Service::create(['name' => 'Wiki', 'address' => '192.0.2.50', 'domain' => 'wiki.example.com', 'scheme' => 'http', 'path' => '/']);
        Service::create(['name' => 'LabMan itself', 'address' => '192.0.2.51', 'domain' => 'labman.example.com', 'scheme' => 'http', 'path' => '/', 'on_dashboard' => false]);
        Link::create(['name' => 'Controller', 'url' => 'https://controller.example.com']);
        Link::create(['name' => 'Private bookmark', 'url' => 'https://private.example.com', 'on_dashboard' => false]);
    }

    public function test_an_item_switched_off_is_not_on_the_dashboard_but_is_still_everywhere_else(): void
    {
        $this->actingAs($this->admin());
        $this->lab();

        $this->get('/')->assertOk()
            ->assertSee('Wiki')->assertSee('Nas')->assertSee('Controller')
            ->assertDontSee('LabMan itself')->assertDontSee('Hidden box')->assertDontSee('Private bookmark');

        $this->get(route('services.index'))->assertOk()->assertSee('LabMan itself');
        $this->get(route('servers.index'))->assertOk()->assertSee('Hidden box');
        $this->get(route('links.index'))->assertOk()->assertSee('Private bookmark')->assertSee('not on dashboard');
    }

    public function test_a_hidden_item_does_not_count_in_the_dashboard_summary_but_is_still_monitored(): void
    {
        $this->actingAs($this->admin());
        $this->lab();
        Server::where('name', 'Hidden box')->update(['state' => 'down', 'checked_at' => now()]);

        $dash = $this->getJson('/status?scope=dashboard')->assertOk()->json();
        $all = $this->getJson('/status')->assertOk()->json();

        $this->assertCount(1, $dash['servers']);
        $this->assertCount(2, $all['servers']);
        $this->assertSame(1, array_sum($dash['summary']['servers']));
        $this->assertSame(2, array_sum($all['summary']['servers']));
    }

    public function test_the_forms_save_the_switch(): void
    {
        $this->actingAs($this->admin());

        $this->post('/services', ['name' => 'LabMan', 'address' => '192.0.2.51', 'scheme' => 'http', 'path' => '/', 'expect' => '200-499', 'on_dashboard' => '0', 'monitor' => '1', 'check_tcp' => '1', 'verify_tls' => '1', 'public_check' => '1'])->assertSessionHasNoErrors();
        $service = Service::where('name', 'LabMan')->firstOrFail();
        $this->assertFalse($service->on_dashboard);

        $this->get(route('services.edit', $service))->assertOk()->assertSee('Show on the dashboard');
        $this->post('/servers', ['name' => 'Box', 'address' => '192.0.2.30', 'ssh_port' => 22, 'on_dashboard' => '0', 'monitor' => '1', 'check_ssh' => '1'])->assertSessionHasNoErrors();
        $this->assertFalse(Server::where('name', 'Box')->firstOrFail()->on_dashboard);
        $this->post('/links', ['name' => 'Cloud', 'url' => 'https://cloud.example.org', 'on_dashboard' => '0'])->assertSessionHasNoErrors();
        $this->assertFalse(Link::where('name', 'Cloud')->firstOrFail()->on_dashboard);

        $this->post('/links', ['name' => 'Default on', 'url' => 'https://on.example.org'])->assertSessionHasNoErrors();
        $this->assertTrue(Link::where('name', 'Default on')->firstOrFail()->on_dashboard);
    }

    public function test_the_switch_survives_a_yaml_export_and_import(): void
    {
        $this->actingAs($this->admin());
        $this->lab();

        $yaml = YamlConfig::export();
        $this->assertStringContainsString('on_dashboard: false', $yaml);

        Server::query()->delete();
        Service::query()->delete();
        YamlConfig::import(YamlConfig::parse($yaml));

        $this->assertFalse(Server::where('name', 'Hidden box')->firstOrFail()->on_dashboard);
        $this->assertTrue(Server::where('name', 'Nas')->firstOrFail()->on_dashboard);
        $this->assertFalse(Service::where('name', 'LabMan itself')->firstOrFail()->on_dashboard);
    }

    public function test_the_dashboard_has_tabs_with_counts_and_a_search(): void
    {
        $this->actingAs($this->admin());
        $this->lab();

        $html = $this->get('/')->assertOk()->assertSee('role="tablist"', false)->getContent();

        foreach (['all' => 3, 'services' => 1, 'servers' => 1, 'links' => 1] as $tab => $n) {
            $this->assertMatchesRegularExpression('/data-tab-count="'.$tab.'">'.$n.'</', $html, $tab);
        }
        $this->assertStringContainsString('id="filter"', $html);
        $this->assertStringContainsString('data-section="links"', $html);
        $this->assertStringContainsString('data-status-url="'.route('status', ['scope' => 'dashboard']).'"', $html);
    }

    public function test_a_single_kind_needs_no_tabs(): void
    {
        $this->actingAs($this->admin());
        Link::create(['name' => 'Controller', 'url' => 'https://controller.example.com']);

        $this->get('/')->assertOk()->assertDontSee('role="tablist"', false)->assertSee('Controller');
    }

    public function test_links_are_a_main_tab_that_dashboard_viewers_can_read_but_only_admins_change(): void
    {
        $this->actingAs($admin = $this->admin());
        $link = Link::create(['name' => 'Controller', 'url' => 'https://controller.example.com']);

        $this->get('/')->assertSee('href="'.route('links.index').'"', false);
        $this->get(route('links.index'))->assertOk()->assertSee('Add link');

        $this->actingAs($this->user());
        $this->get(route('links.index'))->assertOk()->assertSee('Controller')->assertDontSee('Add link');
        $this->put(route('links.update', $link), ['name' => 'X', 'url' => 'https://example.com'])->assertForbidden();
    }
}
