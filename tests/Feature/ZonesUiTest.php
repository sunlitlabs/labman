<?php

namespace Tests\Feature;

use App\Models\ProxyLog;
use App\Models\Server;
use App\Models\Service;
use App\Models\Setting;
use App\Models\Zone;
use App\Npm\Connection;
use App\Npm\NpmAuto;
use App\Npm\NpmClient;
use App\Npm\NpmException;
use App\Npm\NpmSync;
use App\Npm\PlanText;
use App\Support\Secrets;
use App\Support\Settings;
use App\Support\ZoneStatus;
use App\Support\Zones;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\FakeNpm;
use Tests\TestCase;

/** Step 6 of zones: the overview, a zone's page, the setup wizard, the change log, the review screen and the service form. */
class ZonesUiTest extends TestCase
{
    use RefreshDatabase;

    private const URL_OUTER = 'http://npm-external.test:81';

    private FakeNpm $inner;

    private FakeNpm $outer;

    private Zone $internal;

    private Zone $external;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs($this->admin(['name' => 'Ada Admin', 'email' => 'root@example.com']));
        $this->inner = FakeNpm::install();
        $this->outer = FakeNpm::install(self::URL_OUTER);
        NpmAuto::setEnabled(false);

        Settings::set(['local_domains' => 'home.example.com']);
        $this->internal = Zones::default();
        $this->internal->update(['name' => 'Internal', 'level' => 60]);
        $this->external = Zone::create(['name' => 'External', 'level' => 10, 'domains' => ['public' => ['example.dev']], 'cidrs' => ['198.51.100.0/24']]);
    }

    private function connect(Zone $zone, string $url): void
    {
        Connection::save($url, 'npm@example.com', 'npm-secret', true, $zone);
    }

    // ----------------------------------------------------------------------------- overview

    public function test_a_single_zone_lab_is_offered_the_wizard(): void
    {
        $this->external->delete();

        $this->get(route('zones.index'))->assertOk()->assertSee('One zone so far')->assertSee('Start the setup wizard')->assertSee(route('zones.wizard'), false);
    }

    public function test_the_overview_has_a_card_per_zone_most_trusted_first_with_its_proxy_status(): void
    {
        $this->connect($this->internal, FakeNpm::URL);
        $this->inner->addHost(['domain_names' => ['wiki.home.example.com'], 'forward_host' => '192.0.2.50', 'forward_port' => 80]);

        $this->get(route('zones.index'))->assertOk()->assertSeeInOrder(['Internal', 'External'])
            ->assertSee('not checked')->assertSee('no proxy')->assertSee('home.example.com')->assertSee('example.dev');

        $this->post(route('zones.refresh'))->assertRedirect();

        $this->get(route('zones.index'))->assertOk()->assertSee('connected')->assertSee('NPM 2.17.0')->assertSee('1 host(s)')->assertSee('Certificates:')->assertSee('Drift:');
        $this->assertSame('ok', ZoneStatus::cached($this->internal)['state']);
        $this->assertSame('none', ZoneStatus::cached($this->external)['state'], 'a zone with no proxy has nothing to check');
    }

    public function test_every_proxy_can_be_edited_and_disconnected_from_where_it_is_listed(): void
    {
        $this->connect($this->internal, FakeNpm::URL);
        $this->connect($this->external, self::URL_OUTER);
        $edit = route('npm.index', ['zone' => $this->external->id]).'#connect';
        $disconnect = route('npm.disconnect', ['zone' => $this->external->id]);

        foreach ([route('npm.index'), route('zones.index'), route('zones.show', $this->external)] as $page) {
            $this->get($page)->assertOk()->assertSee('Edit', false)->assertSee(str_replace('&', '&amp;', $edit), false);
        }
        foreach ([route('npm.index'), route('zones.show', $this->external)] as $page) {
            $this->get($page)->assertSee($disconnect, false);
        }

        $this->delete($disconnect)->assertRedirect();
        $this->assertFalse(Connection::exists($this->external));
        $this->assertTrue(Connection::exists($this->internal));
        $this->get(route('npm.index'))->assertSee('Connect', false);
    }

    public function test_an_unreachable_proxy_degrades_only_its_own_card(): void
    {
        $this->connect($this->internal, FakeNpm::URL);
        $this->connect($this->external, self::URL_OUTER);
        $this->outer->password = 'changed-elsewhere';

        $this->post(route('zones.refresh'))->assertRedirect();

        $this->get(route('zones.index'))->assertOk()->assertSee('unreachable')->assertSee('connected')->assertSee('The proxy could not be reached')->assertSee('Check the connection');
    }

    public function test_each_problem_comes_with_its_fix(): void
    {
        $this->external->update(['domains' => null]);

        $page = $this->get(route('zones.index'))->assertOk()
            ->assertSee('This zone has no proxy yet.')->assertSee('Connect one')
            ->assertSee('No domain is set for this zone')->assertSee('Set a domain')
            ->getContent();

        $this->assertStringContainsString(route('zones.edit', $this->external), $page);
        $this->assertStringContainsString(route('npm.index', ['zone' => $this->external->id]).'#connect', $page);
    }

    // --------------------------------------------------------------------------- a zone's page

    public function test_a_zones_page_lists_its_servers_with_the_cidr_fix_and_a_move_control(): void
    {
        $moved = Server::create(['name' => 'edge', 'address' => '198.51.100.7']); // lands in the default zone, but its address fits External
        Server::create(['name' => 'web1', 'address' => '192.0.2.11']);

        $this->get(route('zones.show', $this->internal))->assertOk()
            ->assertSee('web1')->assertSee('edge')->assertSee('fits the')->assertSee('Move to External')
            ->assertSee('This zone has no proxy yet')->assertSee('Connect a proxy');

        $this->post(route('zones.assign'), ['server_id' => $moved->id, 'zone_id' => $this->external->id])->assertRedirect();
        $this->assertSame($this->external->id, $moved->fresh()->zone_id);

        $this->get(route('zones.show', $this->external))->assertOk()->assertSee('edge');
        $this->post(route('zones.assign'), ['server_id' => 999, 'zone_id' => $this->external->id])->assertSessionHasErrors('server_id');
    }

    public function test_an_empty_zone_says_what_to_do_next(): void
    {
        $this->get(route('zones.show', $this->external))->assertOk()->assertSee('No servers in this zone yet')->assertSee('No services in this zone')
            ->assertSee('Nothing has been applied to this zone');
    }

    public function test_a_zone_page_offers_the_mirror_fix_for_zones_that_do_not_mirror_it_yet(): void
    {
        $this->get(route('zones.show', $this->external))->assertOk()->assertSee("Let Internal mirror External's hosts", false);

        $this->post(route('dns.propose'), ['kind' => 'zone', 'target_zone' => $this->internal->id, 'source_zone' => $this->external->id])->assertRedirect();

        $this->get(route('zones.show', $this->external))->assertOk()->assertDontSee("Let Internal mirror External's hosts", false);
    }

    // -------------------------------------------------------------------- the change log

    private function wiki(): Service
    {
        return Service::create(['name' => 'Wiki', 'address' => '192.0.2.50', 'domain' => 'wiki.home.example.com', 'scheme' => 'http', 'path' => '/']);
    }

    public function test_applying_a_preview_is_logged_with_who_when_and_how_many(): void
    {
        $this->connect($this->internal, FakeNpm::URL);
        $this->wiki();
        $sync = new NpmSync(NpmClient::fromStored($this->internal), $this->internal);
        $plan = $sync->pushPlan();

        $this->post(route('npm.push.apply'), ['apply' => [$plan[0]['key']], 'fp' => [$plan[0]['key'] => $plan[0]['fingerprint']]])->assertRedirect();

        $entry = ProxyLog::first();
        $this->assertSame($this->internal->id, $entry->zone_id);
        $this->assertSame('preview', $entry->source);
        $this->assertSame('Ada Admin', $entry->who);
        $this->assertSame(1, $entry->applied);
        $this->assertSame(0, $entry->failed);
        $this->assertStringContainsString('Proxy host created', $entry->results[0]['message']);

        $this->get(route('zones.show', $this->internal))->assertOk()->assertSee('What changed')->assertSee('1 applied')->assertSee('from a preview by Ada Admin')->assertSee('Proxy host created');
    }

    public function test_automatic_updates_are_logged_without_a_person_and_nothing_is_logged_when_nothing_happened(): void
    {
        $this->connect($this->internal, FakeNpm::URL);
        NpmAuto::setEnabled(true);
        $svc = $this->wiki();
        NpmAuto::flush();

        $entry = ProxyLog::first();
        $this->assertSame('automatic', $entry->source);
        $this->assertNull($entry->who);

        ProxyLog::record($this->internal, 'preview', []);
        $this->assertSame(1, ProxyLog::count());
    }

    public function test_a_new_service_gets_its_proxy_host_and_nothing_is_remembered_while_updates_are_off(): void
    {
        $this->connect($this->internal, FakeNpm::URL);

        // Off: creating a service queues nothing, so switching on later does not send it.
        $this->wiki();
        NpmAuto::setEnabled(true);
        NpmAuto::flush();
        $this->assertSame([], $this->inner->hosts);

        // On: a new service with a name gets its host.
        Service::create(['name' => 'Blog', 'address' => '192.0.2.51', 'domain' => 'blog.home.example.com', 'scheme' => 'http', 'path' => '/']);
        NpmAuto::flush();
        $this->assertSame(['blog.home.example.com'], array_values($this->inner->hosts)[0]['domain_names']);
        $this->assertCount(1, $this->inner->hosts);

        // A service with no name has nothing to put in a proxy.
        Service::create(['name' => 'Plain', 'address' => '192.0.2.52', 'scheme' => 'http', 'path' => '/']);
        NpmAuto::flush();
        $this->assertCount(1, $this->inner->hosts);
    }

    public function test_the_grouped_apply_logs_each_zone_separately(): void
    {
        $this->connect($this->internal, FakeNpm::URL);
        $this->connect($this->external, self::URL_OUTER);
        $this->wiki();
        Service::create(['name' => 'Blog', 'address' => '198.51.100.7', 'zone_id' => $this->external->id, 'public_domain' => 'blog.example.dev', 'scheme' => 'http', 'path' => '/']);
        $in = (new NpmSync(NpmClient::fromStored($this->internal), $this->internal))->pushPlan();
        $out = (new NpmSync(NpmClient::fromStored($this->external), $this->external))->pushPlan();

        $this->post(route('npm.all.apply'), [
            'mode' => 'push', 'zones' => [$this->internal->id, $this->external->id],
            'apply' => [$this->internal->id => [$in[0]['key']], $this->external->id => [$out[0]['key']]],
            'fp' => [$this->internal->id => [$in[0]['key'] => $in[0]['fingerprint']], $this->external->id => [$out[0]['key'] => $out[0]['fingerprint']]],
        ])->assertRedirect();

        $this->assertEqualsCanonicalizing([$this->internal->id, $this->external->id], ProxyLog::pluck('zone_id')->all());
    }

    // ------------------------------------------------------------------------------- wizard

    public function test_every_wizard_step_opens_and_the_current_one_is_marked(): void
    {
        foreach (['zones', 'servers', 'proxies', 'domains', 'review'] as $step) {
            $this->get(route('zones.wizard', ['step' => $step]))->assertOk()->assertSee('Zones setup')->assertSee('aria-current="step"', false);
        }
        $this->get(route('zones.wizard', ['step' => 'nonsense']))->assertOk()->assertSee('1. Name the zones');
    }

    public function test_the_zones_step_starts_from_a_template_and_saves_new_and_renamed_zones(): void
    {
        $this->get(route('zones.wizard', ['step' => 'zones', 'template' => 'four']))->assertOk()
            ->assertSee('Management')->assertSee('value="90"', false)->assertSee('LAN')->assertSee('Skip this step');

        $this->post(route('zones.wizard.save', 'zones'), [
            'zones' => [$this->internal->id => ['name' => 'Home', 'level' => 70, 'cidrs' => '192.0.2.0/24'], $this->external->id => ['name' => 'External', 'level' => 10, 'cidrs' => '198.51.100.0/24']],
            'new' => [['name' => 'Management', 'level' => 90, 'cidrs' => ''], ['name' => '', 'level' => 50, 'cidrs' => '']],
        ])->assertRedirect(route('zones.wizard', ['step' => 'servers']));

        $this->assertSame('Home', $this->internal->fresh()->name);
        $this->assertSame(['192.0.2.0/24'], $this->internal->fresh()->cidrs);
        $this->assertSame(90, Zone::where('name', 'Management')->value('level'));
        $this->assertSame(3, Zone::count(), 'an empty row adds nothing');
    }

    public function test_the_zones_step_refuses_bad_input_and_changes_nothing(): void
    {
        $before = Zone::pluck('name', 'id')->all();

        foreach ([
            ['zones' => [$this->internal->id => ['name' => 'Same', 'level' => 70], $this->external->id => ['name' => 'same', 'level' => 10]]],
            ['zones' => [$this->internal->id => ['name' => 'Internal', 'level' => 500], $this->external->id => ['name' => 'External', 'level' => 10]]],
            ['zones' => [$this->internal->id => ['name' => '', 'level' => 70], $this->external->id => ['name' => 'External', 'level' => 10]]],
            ['zones' => [$this->internal->id => ['name' => 'Internal', 'level' => 70, 'cidrs' => '192.0.2.0/40'], $this->external->id => ['name' => 'External', 'level' => 10]]],
        ] as $body) {
            $this->post(route('zones.wizard.save', 'zones'), $body)->assertSessionHasErrors();
        }

        $this->assertSame($before, Zone::pluck('name', 'id')->all());
    }

    public function test_the_servers_step_places_servers_and_can_apply_the_suggestions(): void
    {
        $a = Server::create(['name' => 'edge', 'address' => '198.51.100.7']);
        $b = Server::create(['name' => 'web1', 'address' => '192.0.2.11']);

        $this->get(route('zones.wizard', ['step' => 'servers']))->assertOk()->assertSee('fits External')->assertSee('Use the suggestions');

        $this->post(route('zones.wizard.save', 'servers'), ['assign' => [$a->id => $this->internal->id, $b->id => $this->external->id]])->assertRedirect(route('zones.wizard', ['step' => 'proxies']));
        $this->assertSame($this->external->id, $b->fresh()->zone_id);

        $this->post(route('zones.wizard.save', 'servers'), ['assign' => [$a->id => $this->internal->id, $b->id => $this->external->id], 'suggest' => 1]);
        $this->assertSame($this->external->id, $a->fresh()->zone_id, 'its address matches the External network block');
        $this->assertSame($this->external->id, $b->fresh()->zone_id, 'no suggestion for it: the choice stands');
    }

    public function test_the_proxies_step_connects_a_zone_and_returns_to_the_wizard(): void
    {
        $this->get(route('zones.wizard', ['step' => 'proxies']))->assertOk()->assertSee('no proxy yet')->assertSee('Test and save');

        $this->post(route('npm.connect', ['zone' => $this->external->id]), ['url' => self::URL_OUTER, 'email' => 'npm@example.com', 'password' => 'npm-secret', 'verify_tls' => 1, 'return' => 'wizard'])
            ->assertRedirect(route('zones.wizard', ['step' => 'proxies']));

        $this->assertNotNull(Connection::get($this->external));
        $this->get(route('zones.wizard', ['step' => 'proxies']))->assertOk()->assertSee('connected');

        // A wrong login is reported on the same page, and nothing is saved.
        $this->post(route('npm.connect', ['zone' => $this->internal->id]), ['url' => FakeNpm::URL, 'email' => 'npm@example.com', 'password' => 'wrong', 'return' => 'wizard'])->assertSessionHasErrors('url');
        $this->assertNull(Connection::get($this->internal));
    }

    public function test_the_domains_step_saves_domains_the_public_address_and_the_certificate_choice(): void
    {
        $this->post(route('zones.wizard.save', 'domains'), ['z' => [
            $this->internal->id => ['local_domains' => 'lab.example.com, home.example.com', 'public_domains' => '', 'public_address' => '', 'cert_strategy' => 'existing', 'cert_email' => ''],
            $this->external->id => ['local_domains' => '', 'public_domains' => 'example.dev, example.me', 'public_address' => '203.0.113.10', 'cert_strategy' => 'letsencrypt_http', 'cert_email' => 'admin@example.com'],
        ]])->assertRedirect(route('zones.wizard', ['step' => 'review']));

        $this->assertSame('lab.example.com, home.example.com', Settings::get('local_domains'), "the default zone's domains stay in the settings");
        $ext = $this->external->fresh();
        $this->assertSame(['local' => [], 'public' => ['example.dev', 'example.me']], $ext->domains());
        $this->assertSame('203.0.113.10', $ext->dnsSettings()['public_address']);
        $this->assertSame('letsencrypt_http', $ext->certificatePolicy()->strategy);
        $this->assertSame('admin@example.com', $ext->certificatePolicy()->email);
    }

    public function test_the_domains_step_keeps_the_fine_tuning_it_does_not_show_and_refuses_bad_input(): void
    {
        $this->external->update(['certificate_policy' => ['pins' => [['domain' => 'example.dev', 'certificate_id' => 7]], 'origin_certificates' => [7], 'edge_managed' => true]]);

        $this->post(route('zones.wizard.save', 'domains'), ['z' => [$this->external->id => ['public_domains' => 'example.dev', 'cert_strategy' => 'existing']]])->assertSessionHasNoErrors();
        $policy = $this->external->fresh()->certificatePolicy();
        $this->assertSame([7], $policy->originIds);
        $this->assertTrue($policy->edgeManaged);
        $this->assertSame(7, $policy->pins[0]['certificate_id']);

        $before = $this->external->fresh()->toArray();
        foreach ([
            ['public_domains' => 'bad_domain!.com'],
            ['public_address' => 'not an address!'],
            ['cert_strategy' => 'letsencrypt_http'],   // a CDN zone cannot request certificates
            ['cert_email' => 'nope'],
        ] as $bad) {
            $this->post(route('zones.wizard.save', 'domains'), ['z' => [$this->external->id => $bad + ['public_domains' => 'example.dev']]])->assertSessionHasErrors();
        }
        $this->assertSame($before, $this->external->fresh()->toArray());
    }

    public function test_finishing_the_wizard_is_remembered_and_lands_on_the_overview(): void
    {
        $this->get(route('zones.wizard', ['step' => 'review']))->assertOk()->assertSee('Finish')->assertSee('Internal')->assertSee('External');
        $this->assertNull(Secrets::plain('zones.wizard_done'));

        $this->post(route('zones.wizard.save', 'review'))->assertRedirect(route('zones.index'));

        $this->assertNotNull(Secrets::plain('zones.wizard_done'));
    }

    public function test_only_admins_reach_the_wizard_and_the_zone_pages(): void
    {
        $this->actingAs($this->user());

        foreach ([route('zones.wizard'), route('zones.show', $this->internal), route('zones.index')] as $url) {
            $this->get($url)->assertForbidden();
        }
        $this->post(route('zones.wizard.save', 'zones'), [])->assertForbidden();
        $this->post(route('zones.assign'), [])->assertForbidden();
    }

    // ----------------------------------------------------------------------- review screen

    public function test_the_review_says_in_plain_words_what_will_happen_and_hides_the_raw_diff_behind_a_toggle(): void
    {
        $this->connect($this->internal, FakeNpm::URL);
        $this->wiki();

        $this->get(route('npm.push'))->assertOk()
            ->assertSee('Add wiki.home.example.com to the Internal proxy, forwarding to http://192.0.2.50:80')
            ->assertSee('Raw changes')->assertSee('&quot;forward_host&quot;: &quot;192.0.2.50&quot;', false);

        $this->get(route('npm.all', ['mode' => 'push']))->assertOk()->assertSee('Add wiki.home.example.com to the Internal proxy');
    }

    public function test_the_plain_sentence_names_the_certificate_and_the_kind_of_change(): void
    {
        $item = ['key' => 'svc:1', 'title' => 'Wiki → wiki.home.example.com', 'action' => 'create', 'errors' => [], 'data' => ['payload' => ['domain_names' => ['wiki.home.example.com'], 'forward_scheme' => 'https', 'forward_host' => '192.0.2.50', 'forward_port' => 443]],
            'changes' => [['field' => 'certificate', 'from' => null, 'to' => '#3 Wildcard (already covers wiki.home.example.com)']]];

        $this->assertSame('Add wiki.home.example.com to the LAN proxy, forwarding to https://192.0.2.50:443 using #3 Wildcard (already covers wiki.home.example.com).', PlanText::describe($item, 'LAN', false));

        $update = ['key' => 'svc:1', 'title' => 'Wiki → wiki.home.example.com', 'action' => 'update', 'errors' => [], 'data' => ['payload' => []], 'changes' => [['field' => 'forward_port', 'from' => 80, 'to' => 8080]]];
        $this->assertSame('Change wiki.home.example.com on the LAN proxy: forward_port from 80 to 8080.', PlanText::describe($update, 'LAN', false));

        $this->assertStringContainsString('Remove it in NPM yourself', PlanText::describe(['title' => 'x', 'action' => 'orphan'], 'LAN', false));
    }

    public function test_a_new_host_that_would_be_broken_is_an_error_that_blocks_its_apply(): void
    {
        $this->connect($this->internal, FakeNpm::URL);
        $svc = $this->wiki();
        $this->internal->update(['certificate_policy' => ['pins' => [['domain' => 'home.example.com', 'certificate_id' => 99]]]]); // a certificate this proxy does not have
        $sync = new NpmSync(NpmClient::fromStored($this->internal), $this->internal);

        $item = $sync->pushPlan()[0];
        $this->assertSame('create', $item['action']);
        $this->assertStringContainsString('#99 pinned for home.example.com does not exist', $item['errors'][0]);

        $this->get(route('npm.push'))->assertOk()->assertSee('blocked')->assertSee('Error:')->assertSee('cannot be applied yet')->assertSee('Nothing to apply');

        try {
            $sync->applyPush([$item['key'] => $item['fingerprint']]);
            $this->fail('a blocked item must not apply');
        } catch (NpmException $e) {
            $this->assertStringContainsString('has an error and cannot be applied', $e->getMessage());
        }
        $this->assertSame([], $this->inner->hosts);
    }

    // ------------------------------------------------------------------------ service form

    public function test_the_service_form_carries_the_zone_data_for_its_live_address_and_zone_hints(): void
    {
        $server = Server::create(['name' => 'edge', 'address' => '198.51.100.7', 'zone_id' => $this->external->id]);
        $this->internal->update(['mirror_sources' => [['zone_id' => $this->external->id, 'upstream' => 'auto']]]);

        $page = $this->get(route('services.create'))->assertOk()->assertSee('data-service-preview', false)->assertSee('Also publish in')->getContent();

        preg_match('/data-zone-info="([^"]*)"/', $page, $m);
        $info = json_decode(html_entity_decode($m[1]), true);

        $this->assertTrue($info['multiple']);
        $this->assertSame('External', $info['zones'][$this->external->id]['name']);
        $this->assertSame('example.dev', $info['zones'][$this->external->id]['public']);
        $this->assertSame(['Internal'], $info['zones'][$this->external->id]['mirrors_to'], 'the zones that will also publish its services');
        $this->assertSame(['zone' => $this->external->id, 'address' => '198.51.100.7'], $info['servers'][$server->id]);
        $this->assertSame($this->internal->id, $info['default']);

        $this->assertStringContainsString('data-zone-info', $this->get(route('services.edit', $this->wiki()))->getContent());
        $js = file_get_contents(public_path('js/labman.js'));
        $this->assertStringContainsString('Resulting address:', $js);
        $this->assertStringContainsString('Also published in:', $js);
    }
}
