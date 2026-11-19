<?php

namespace Tests\Feature;

use App\Config\YamlConfig;
use App\Domain\SettingsInput;
use App\Models\Server;
use App\Models\Service;
use App\Monitoring\HealthChecker;
use App\Monitoring\Verdict;
use App\Npm\Connection;
use App\Support\DomainSuggestions;
use App\Support\Domains;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Support\FakeNpm;
use Tests\TestCase;

/** Several domains per item, the add/remove lists, opt-in suggested names, and the form pieces around them. */
class DomainListsTest extends TestCase
{
    use RefreshDatabase;

    private function configure(): void
    {
        SettingsInput::update(['local_domains' => 'home.example.com', 'public_domains' => 'example.org']);
    }

    private function admin1()
    {
        return \App\Models\User::where('role', 'admin')->first() ?? $this->admin();
    }

    // ------------------------------------------------------------- settings lists

    public function test_the_settings_form_posts_lists_with_a_default_and_the_default_goes_first(): void
    {
        $this->actingAs($this->admin())->put('/settings', [
            'section' => 'general', 'title' => 'LabMan', 'check_interval' => 30, 'timeout' => 4, 'ssh_target' => 'address', 'icons' => 1,
            'local_domains' => ['a.example.com', 'B.example.com', '', 'a.example.com'], 'local_default' => 'b.example.com',
            'public_domains' => ['example.org'], 'public_default' => 'example.org',
        ])->assertSessionHasNoErrors();

        $this->assertSame(['b.example.com', 'a.example.com'], Domains::local());
        $this->assertSame('b.example.com', Domains::defaultFor('local'));
        $this->assertSame('example.org', Domains::defaultFor('public'));
    }

    public function test_the_settings_page_renders_the_lists_with_add_and_remove(): void
    {
        $this->configure();

        $html = $this->actingAs($this->admin1())->get('/settings')->assertOk()->assertSee('Add local domain')->assertSee('Add public domain')->getContent();

        $this->assertStringContainsString('name="local_domains[]" value="home.example.com"', $html);
        $this->assertMatchesRegularExpression('#name="local_default" value="home.example.com"\s+checked#', $html);
    }

    // ----------------------------------------------------- several names per item

    public function test_the_service_form_stores_a_primary_name_and_extras_per_kind(): void
    {
        $admin = $this->admin();
        $this->actingAs($admin)->post('/services', [
            'name' => 'Wiki', 'address' => '192.0.2.5', 'scheme' => 'http', 'path' => '/', 'expect' => '200-499',
            'local_domains' => ['wiki.home.example.com', 'docs.home.example.com'], 'public_domains' => ['wiki.example.org', 'wiki2.example.org', ''],
        ])->assertSessionHasNoErrors();

        $s = Service::where('name', 'Wiki')->first();
        $this->assertSame('wiki.home.example.com', $s->domain);
        $this->assertSame('wiki.example.org', $s->public_domain);
        $this->assertSame(['local' => ['docs.home.example.com'], 'public' => ['wiki2.example.org']], $s->extra_domains);
        $this->assertSame(['local' => ['wiki.home.example.com', 'docs.home.example.com'], 'public' => ['wiki.example.org', 'wiki2.example.org']], Domains::namesOf($s));

        $this->get(route('services.edit', $s))->assertSee('value="docs.home.example.com"', false)->assertSee('value="wiki2.example.org"', false);

        // Clearing the lists clears the names.
        $this->put(route('services.update', $s), [
            'name' => 'Wiki', 'address' => '192.0.2.5', 'scheme' => 'http', 'path' => '/', 'expect' => '200-499', 'local_domains' => [''], 'public_domains' => [''],
        ])->assertSessionHasNoErrors();
        $this->assertNull($s->fresh()->domain);
        $this->assertNull($s->fresh()->extra_domains);
    }

    public function test_invalid_extra_names_are_rejected(): void
    {
        $this->actingAs($this->admin())->post('/services', [
            'name' => 'Bad', 'address' => '192.0.2.5', 'scheme' => 'http', 'path' => '/', 'expect' => '200-499', 'local_domains' => ['ok.example.com', 'not a host!'],
        ])->assertSessionHasErrors();
        $this->assertSame(0, Service::count());
    }

    public function test_a_server_keeps_several_names(): void
    {
        $this->actingAs($this->admin())->post('/servers', [
            'name' => 'nas', 'address' => '192.0.2.10', 'ssh_port' => 22, 'local_domains' => ['nas.home.example.com', 'files.home.example.com'],
        ])->assertSessionHasNoErrors();

        $s = Server::first();
        $this->assertSame('nas.home.example.com', $s->domain);
        $this->assertSame(['files.home.example.com'], Domains::namesOf($s)['local'][1] ? [Domains::namesOf($s)['local'][1]] : []);
    }

    public function test_every_name_is_health_checked(): void
    {
        Http::fake([
            'https://a.home.example.com/*' => Http::response('ok'), 'https://b.home.example.com/*' => Http::response('', 502), 'https://c.example.org/*' => Http::response('ok'),
        ]);
        $service = Service::create([
            'name' => 'S', 'domain' => 'a.home.example.com', 'public_domain' => 'c.example.org', 'extra_domains' => ['local' => ['b.home.example.com'], 'public' => []],
            'address' => '127.0.0.1', 'port' => 1, 'scheme' => 'http', 'path' => '/', 'public_check' => true, 'check_tcp' => false,
        ]);

        $result = (new HealthChecker)->checkServices([$service], 2)[$service->id];

        $this->assertSame(['backend', 'local', 'local 2', 'public'], array_map(fn ($c) => $c->label, $result['checks']));
        $this->assertSame(Verdict::UP, $result['state'], 'names answer, so it is up even though the backend is unreachable');
    }

    public function test_yaml_round_trips_extra_names_and_validates_them(): void
    {
        $yaml = "servers: []\nservices:\n  - {name: Wiki, address: 192.0.2.5, domain: w.home.example.com, public_domain: w.example.org, extra_domains: [x.home.example.com, y.example.org]}\n";

        $this->configure(); // plain lists are sorted by the configured domains
        YamlConfig::import(YamlConfig::parse($yaml));

        $s = Service::first();
        $this->assertSame(['x.home.example.com'], Domains::extras($s->extra_domains)['local']);
        $this->assertSame(['y.example.org'], Domains::extras($s->extra_domains)['public']);
        $this->assertStringContainsString('extra_domains', YamlConfig::export());

        $this->expectException(\App\Config\ConfigException::class);
        YamlConfig::parse("servers: []\nservices:\n  - {name: B, address: 192.0.2.5, extra_domains: [not valid!]}\n");
    }

    // ------------------------------------------------------------- suggestions

    public function test_suggestions_come_from_names_under_the_default_local_domain_and_never_collide(): void
    {
        $this->assertSame(['services' => [], 'servers' => []], DomainSuggestions::all(), 'nothing without a local domain');

        $this->configure();
        $wiki = Service::create(['name' => 'Home Assistant', 'address' => '192.0.2.5', 'scheme' => 'http', 'path' => '/']);
        $has = Service::create(['name' => 'Has name', 'domain' => 'x.home.example.com', 'address' => '192.0.2.6', 'scheme' => 'http', 'path' => '/']);
        $dup = Service::create(['name' => 'home-assistant', 'address' => '192.0.2.7', 'scheme' => 'http', 'path' => '/']);
        $nas = Server::create(['name' => 'Proxmox 1', 'address' => '192.0.2.10']);

        $all = DomainSuggestions::all();

        $this->assertSame('home-assistant.home.example.com', $all['services'][$wiki->id]);
        $this->assertArrayNotHasKey($has->id, $all['services']);
        $this->assertArrayNotHasKey($dup->id, $all['services'], 'the same name is not suggested twice');
        $this->assertSame('proxmox-1.home.example.com', $all['servers'][$nas->id]);
    }

    public function test_the_export_only_suggests_when_asked(): void
    {
        $this->configure();
        Service::create(['name' => 'Wiki', 'address' => '192.0.2.5', 'scheme' => 'http', 'path' => '/']);

        $this->assertStringNotContainsString('wiki.home.example.com', YamlConfig::export());
        $this->assertStringContainsString('wiki.home.example.com', YamlConfig::export(true));
        $this->assertNull(Service::first()->domain, 'exporting saves nothing');

        $this->actingAs($this->admin())->get('/settings/export?suggest_domains=1')->assertOk()->assertSee('wiki.home.example.com');
        $this->get('/settings/export')->assertDontSee('wiki.home.example.com');
    }

    public function test_the_wizard_offers_suggestions_only_when_switched_on_and_applies_the_kept_ones(): void
    {
        $npm = FakeNpm::install();
        Connection::save(FakeNpm::URL, 'npm@example.com', 'npm-secret', true);
        $this->configure();
        $wiki = Service::create(['name' => 'Wiki', 'address' => '192.0.2.5', 'scheme' => 'http', 'path' => '/']);
        $other = Service::create(['name' => 'Other', 'address' => '192.0.2.6', 'scheme' => 'http', 'path' => '/']);
        $admin = $this->admin();

        $this->actingAs($admin)->get('/npm/wizard')->assertDontSee('Suggested local names');

        $page = $this->get('/npm/wizard?suggest=1')->assertOk()->assertSee('Suggested local names')->assertSee('wiki.home.example.com')->getContent();
        $this->assertMatchesRegularExpression('#name="choice\[suggest:svc:'.$wiki->id.'\]" value="apply"\s+checked#', $page);

        $this->post('/npm/wizard', ['choice' => ["suggest:svc:{$wiki->id}" => 'apply', "suggest:svc:{$other->id}" => 'skip']])->assertRedirect(route('npm.index'));

        $this->assertSame('wiki.home.example.com', $wiki->fresh()->domain);
        $this->assertNull($other->fresh()->domain);
        $this->assertSame([], $npm->writes(), 'a suggestion is saved in LabMan only');
    }

    public function test_a_suggested_name_can_be_edited_before_applying(): void
    {
        FakeNpm::install();
        Connection::save(FakeNpm::URL, 'npm@example.com', 'npm-secret', true);
        $this->configure();
        $wiki = Service::create(['name' => 'Wiki', 'address' => '192.0.2.5', 'scheme' => 'http', 'path' => '/']);
        $blog = Service::create(['name' => 'Blog', 'address' => '192.0.2.6', 'scheme' => 'http', 'path' => '/']);
        $admin = $this->admin();

        $this->actingAs($admin)->get('/npm/wizard?suggest=1')->assertSee('name="domain_edit[suggest:svc:'.$wiki->id.']"', false);

        $this->post('/npm/wizard', [
            'choice' => ["suggest:svc:{$wiki->id}" => 'apply', "suggest:svc:{$blog->id}" => 'apply'],
            'domain_edit' => ["suggest:svc:{$wiki->id}" => 'Docs.Home.Example.com', "suggest:svc:{$blog->id}" => 'not valid!'],
        ]);

        $this->assertSame('docs.home.example.com', $wiki->fresh()->domain);
        $this->assertNull($blog->fresh()->domain, 'an invalid edit is refused, not saved');
    }

    public function test_the_wizard_switch_is_unavailable_without_a_local_domain(): void
    {
        $npm = FakeNpm::install();
        Connection::save(FakeNpm::URL, 'npm@example.com', 'npm-secret', true);

        $this->actingAs($this->admin())->get('/npm/wizard?suggest=1')->assertOk()->assertDontSee('Suggested local names')->assertSee('Set a local domain');
    }

    // ------------------------------------------------------------- form pieces

    public function test_forms_use_a_sticky_save_bar_the_icon_picker_and_the_add_lists(): void
    {
        $this->configure();
        $service = Service::create(['name' => 'Jellyfin', 'address' => '192.0.2.5', 'scheme' => 'http', 'path' => '/']);
        $admin = $this->admin();

        foreach ([route('services.create'), route('services.edit', $service), route('servers.create')] as $url) {
            $this->actingAs($admin)->get($url)->assertOk()->assertSee('class="savebar"', false)->assertSee('data-iconpick', false)->assertSee('data-listedit', false);
        }
        $this->get(route('services.create'))->assertSee('data-suffix="home.example.com"', false)->assertSee('data-slug-from="name"', false);
    }

    public function test_the_icon_value_still_saves_through_the_picker(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)->post('/services', ['name' => 'X', 'address' => '192.0.2.9', 'scheme' => 'http', 'path' => '/', 'expect' => '200-499', 'icon' => 'none'])->assertSessionHasNoErrors();
        $this->assertSame('none', Service::where('name', 'X')->value('icon'));
    }
}
