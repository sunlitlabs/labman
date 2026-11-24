<?php

namespace Tests\Feature;

use App\Config\ConfigException;
use App\Config\YamlConfig;
use App\Models\Mirror;
use App\Models\Server;
use App\Models\Service;
use App\Models\Zone;
use App\Npm\Connection;
use App\Npm\Mirrors;
use App\Npm\NpmAuto;
use App\Npm\NpmClient;
use App\Npm\NpmSync;
use App\Support\Settings;
use App\Support\Zones;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\FakeNpm;
use Tests\TestCase;

/** Step 4 of zones: mirrors, an entry in one zone's proxy that forwards to another zone's proxy for a host that zone owns. */
class MirrorsTest extends TestCase
{
    use RefreshDatabase;

    private const URL_OUTER = 'http://npm-external.test:81';

    private FakeNpm $inner;

    private FakeNpm $outer;

    private Zone $internal;

    private Zone $external;

    private Service $blog;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs($this->admin(['email' => 'root@example.com']));
        $this->inner = FakeNpm::install();
        $this->outer = FakeNpm::install(self::URL_OUTER);
        NpmAuto::setEnabled(false);

        Settings::set(['local_domains' => 'home.example.com']);
        $this->internal = Zones::default();
        $this->internal->update(['name' => 'Internal', 'level' => 60, 'proxy_address' => '192.0.2.5']);
        $this->external = Zone::create(['name' => 'External', 'level' => 10, 'domains' => ['public' => ['example.dev']], 'proxy_address' => '203.0.113.5']);

        Connection::save(FakeNpm::URL, 'npm@example.com', 'npm-secret', true);
        Connection::save(self::URL_OUTER, 'npm@example.com', 'npm-secret', true, $this->external);

        $this->blog = Service::create(['name' => 'Blog', 'address' => '198.51.100.7', 'zone_id' => $this->external->id, 'public_domain' => 'blog.example.dev', 'scheme' => 'http', 'path' => '/']);
    }

    private function mirrorOn(string $upstream = 'auto'): void
    {
        $this->internal->update(['mirror_sources' => [['zone_id' => $this->external->id, 'upstream' => $upstream]]]);
        $this->internal = $this->internal->fresh();
    }

    private function cert(FakeNpm $npm, int $id, array $domains, int $days = 90): void
    {
        $npm->certificates[] = ['id' => $id, 'nice_name' => 'Cert '.$id, 'provider' => 'other', 'domain_names' => $domains, 'expires_on' => gmdate('Y-m-d H:i:s', time() + $days * 86400), 'meta' => []];
    }

    private function sync(?Zone $zone = null): NpmSync
    {
        $zone ??= $this->internal;

        return new NpmSync(NpmClient::fromStored($zone), $zone);
    }

    /** @return array<string, mixed>|null */
    private function mirror(array $plan): ?array
    {
        foreach ($plan as $item) {
            if (str_starts_with($item['key'], 'mirror:')) {
                return $item;
            }
        }

        return null;
    }

    /** @param list<array<string, mixed>> $plan @return array<string, string> */
    private function pick(array $plan): array
    {
        $out = [];
        foreach ($plan as $item) {
            if (in_array($item['action'], ['create', 'update'], true)) {
                $out[$item['key']] = $item['fingerprint'];
            }
        }

        return $out;
    }

    // ----------------------------------------------------------------- what should exist

    public function test_mirrors_are_off_until_a_zone_rule_or_a_service_setting_asks_for_them(): void
    {
        $this->assertSame([], Mirrors::wanted($this->internal));
        $this->assertSame([], Mirrors::wanted($this->external));

        $this->mirrorOn();
        $wanted = Mirrors::wanted($this->internal);
        $this->assertSame(['blog.example.dev'], array_keys($wanted));
        $this->assertSame($this->external->id, $wanted['blog.example.dev']['source']->id);
        $this->assertSame([], Mirrors::wanted($this->external), 'a mirror of a zone is one-way');
    }

    public function test_a_service_can_publish_itself_into_a_zone_without_a_zone_rule(): void
    {
        $this->blog->update(['publish_zones' => [['zone_id' => $this->internal->id, 'upstream' => 'http']]]);

        $wanted = Mirrors::wanted($this->internal);

        $this->assertSame('http', $wanted['blog.example.dev']['upstream']);
        $this->assertSame([$this->internal->id], array_map(fn ($z) => $z->id, Mirrors::targetsFor($this->blog)));
    }

    public function test_a_service_setting_wins_over_the_zone_rule(): void
    {
        $this->mirrorOn('https');
        $this->blog->update(['publish_zones' => [['zone_id' => $this->internal->id, 'upstream' => 'http']]]);

        $this->assertSame('http', Mirrors::wanted($this->internal)['blog.example.dev']['upstream']);
    }

    // ------------------------------------------------------------------ the mirror entry

    public function test_the_mirror_forwards_to_the_other_proxys_address_and_keeps_the_host_header_and_sni(): void
    {
        $this->mirrorOn();
        $this->cert($this->inner, 1, ['*.example.dev']);
        $this->cert($this->outer, 5, ['*.example.dev']);
        $this->outer->addHost(['domain_names' => ['blog.example.dev'], 'forward_host' => '198.51.100.7', 'forward_port' => 80, 'certificate_id' => 5, 'ssl_forced' => true]);

        $item = $this->mirror($this->sync()->pushPlan());

        $this->assertSame('create', $item['action']);
        $p = $item['data']['payload'];
        $this->assertSame('203.0.113.5', $p['forward_host'], 'the other proxy by address, never the public name');
        $this->assertNotSame('blog.example.dev', $p['forward_host']);
        $this->assertSame('https', $p['forward_scheme']);
        $this->assertSame(443, $p['forward_port']);
        $this->assertSame(['blog.example.dev'], $p['domain_names']);
        foreach (['proxy_set_header Host $host;', 'proxy_ssl_server_name on;', 'proxy_ssl_name $host;', 'proxy_ssl_verify on;'] as $line) {
            $this->assertStringContainsString($line, $p['advanced_config']);
        }
        $this->assertStringContainsString(Mirrors::MARKER.' External', $p['advanced_config']);
        $this->assertSame(1, $p['certificate_id'], "its own certificate on this proxy: the wildcard that covers the name");
        $this->assertTrue($p['ssl_forced']);
        $this->assertSame([], $this->inner->writes(), 'a preview writes nothing');
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('hops')]
    public function test_the_automatic_hop_is_the_safest_that_works_for_how_the_other_proxy_serves_the_name(string $setup, string $scheme, int $port, string $fragment, bool $verifyOff): void
    {
        $this->mirrorOn();
        $this->cert($this->inner, 1, ['*.example.dev']);
        $this->cert($this->outer, 5, ['*.example.dev']);

        if ($setup === 'plain') {
            $this->outer->addHost(['domain_names' => ['blog.example.dev'], 'forward_host' => '198.51.100.7', 'forward_port' => 80, 'certificate_id' => 0]);
        } elseif ($setup === 'trusted') {
            $this->outer->addHost(['domain_names' => ['blog.example.dev'], 'forward_host' => '198.51.100.7', 'forward_port' => 80, 'certificate_id' => 5]);
        } elseif ($setup === 'origin') {
            $this->external->update(['certificate_policy' => ['edge_managed' => true, 'origin_certificates' => [5]]]);
            $this->outer->addHost(['domain_names' => ['blog.example.dev'], 'forward_host' => '198.51.100.7', 'forward_port' => 80, 'certificate_id' => 5]);
        } elseif ($setup === 'unreadable') {
            $this->outer->password = 'changed-elsewhere'; // the other proxy cannot be read: decide from its policy
        }

        $p = $this->mirror($this->sync()->pushPlan())['data']['payload'];

        $this->assertSame($scheme, $p['forward_scheme']);
        $this->assertSame($port, $p['forward_port']);
        $this->assertStringContainsString($fragment, $p['advanced_config']);
        $this->assertSame($verifyOff, str_contains($p['advanced_config'], 'proxy_ssl_verify off;'));
    }

    public static function hops(): array
    {
        return [
            'no certificate on the source: plain http' => ['plain', 'http', 80, 'proxy_set_header Host $host;', false],
            'a trusted certificate: https, verified' => ['trusted', 'https', 443, 'proxy_ssl_verify on;', false],
            'an origin certificate: https, not verified' => ['origin', 'https', 443, 'proxy_ssl_server_name on;', true],
            'the source cannot be read: from its policy' => ['unreadable', 'https', 443, 'proxy_ssl_verify on;', false],
        ];
    }

    public function test_a_hop_chosen_by_hand_is_used_as_is(): void
    {
        $this->mirrorOn('https_insecure');
        $this->cert($this->inner, 1, ['*.example.dev']);

        $p = $this->mirror($this->sync()->pushPlan())['data']['payload'];

        $this->assertSame('https', $p['forward_scheme']);
        $this->assertStringContainsString('proxy_ssl_verify off;', $p['advanced_config']);
    }

    public function test_a_mirror_that_would_loop_or_has_nowhere_to_forward_is_blocked_with_the_reason(): void
    {
        $this->mirrorOn();
        $this->cert($this->inner, 1, ['*.example.dev']);

        $this->external->update(['proxy_address' => null]);
        $none = $this->mirror($this->sync()->pushPlan());
        $this->assertSame('skip', $none['action']);
        $this->assertStringContainsString('Set the proxy address of the External zone', $none['warnings'][0]);

        $this->external->update(['proxy_address' => 'blog.example.dev']);
        $loop = $this->mirror($this->sync()->pushPlan());
        $this->assertSame('skip', $loop['action']);
        $this->assertStringContainsString('would loop', $loop['warnings'][0]);

        $this->external->update(['proxy_address' => 'edge-proxy.example.dev']);
        $name = $this->mirror($this->sync()->pushPlan());
        $this->assertSame('create', $name['action']);
        $this->assertStringContainsString('if it resolves to this proxy the mirror loops', implode(' ', $name['warnings']));
    }

    public function test_a_mirror_needs_a_browser_trusted_certificate_and_is_not_created_without_one(): void
    {
        $this->mirrorOn();

        $none = $this->mirror($this->sync()->pushPlan());
        $this->assertSame('skip', $none['action']);
        $this->assertStringContainsString('No browser-trusted certificate is available for blog.example.dev', $none['warnings'][0]);

        // The CDN origin certificate does not qualify on a proxy browsers talk to directly.
        $this->cert($this->inner, 7, ['*.example.dev']);
        $this->internal->update(['certificate_policy' => ['origin_certificates' => [7]]]);
        $origin = $this->mirror($this->sync()->pushPlan());
        $this->assertSame('skip', $origin['action']);
        $this->assertSame([], $this->inner->hosts);
    }

    public function test_a_certificate_can_be_requested_for_the_mirror_only_on_apply(): void
    {
        $this->mirrorOn();
        $this->internal->update(['certificate_policy' => ['strategy' => 'letsencrypt_http', 'letsencrypt_email' => 'admin@example.com']]);

        $plan = $this->sync()->pushPlan();
        $this->assertSame('create', $this->mirror($plan)['action']);
        $this->assertSame([], $this->inner->certificateRequests, 'previewing requests nothing');

        $this->sync()->applyPush($this->pick($plan));

        $this->assertCount(1, $this->inner->certificateRequests);
        $this->assertSame(['blog.example.dev'], $this->inner->certificateRequests[0]['domain_names']);
        $this->assertSame(101, array_values($this->inner->hosts)[0]['certificate_id']);
    }

    public function test_a_mirror_never_fights_a_service_of_its_own_zone_that_has_the_same_name(): void
    {
        $this->mirrorOn();
        $this->cert($this->inner, 1, ['*.example.dev']);
        Service::create(['name' => 'Local blog', 'address' => '192.0.2.60', 'public_domain' => 'blog.example.dev', 'scheme' => 'http', 'path' => '/']);

        $item = $this->mirror($this->sync()->pushPlan());

        $this->assertSame('skip', $item['action']);
        $this->assertStringContainsString('Local blog in the Internal zone already has this name', $item['warnings'][0]);
    }

    public function test_a_lower_zone_reaching_into_a_higher_one_is_warned_but_not_blocked(): void
    {
        $this->external->update(['level' => 90, 'domains' => ['public' => ['example.dev']]]);
        $this->internal->update(['level' => 20, 'mirror_sources' => [['zone_id' => $this->external->id, 'upstream' => 'auto']]]);
        $this->cert($this->inner, 1, ['*.example.dev']);

        $item = $this->mirror($this->sync($this->internal->fresh())->pushPlan());

        $this->assertSame('create', $item['action']);
        $this->assertStringContainsString('Lower zones should not initiate connections to higher ones', implode(' ', $item['warnings']));
    }

    public function test_a_host_that_forwards_to_a_server_in_a_higher_zone_gets_the_same_warning(): void
    {
        $mgmt = Zone::create(['name' => 'Management', 'level' => 95]);
        $server = Server::create(['name' => 'vault', 'address' => '192.0.2.99', 'zone_id' => $mgmt->id]);
        $svc = Service::create(['name' => 'Vault', 'server_id' => $server->id, 'zone_id' => $this->internal->id, 'domain' => 'vault.home.example.com', 'scheme' => 'http', 'path' => '/']);

        $plan = $this->sync()->pushPlan();
        $item = collect($plan)->firstWhere('key', 'svc:'.$svc->id);

        $this->assertStringContainsString('more trusted Management zone', implode(' ', $item['warnings']));
    }

    // ------------------------------------------------------------- apply, drift and orphans

    private function applyMirror(): void
    {
        $this->mirrorOn();
        $this->cert($this->inner, 1, ['*.example.dev']);
        $this->sync()->applyPush($this->pick($this->sync()->pushPlan()));
    }

    public function test_applying_creates_the_host_and_records_it_and_the_next_preview_is_in_sync(): void
    {
        $this->applyMirror();

        $this->assertCount(1, $this->inner->hosts);
        $host = array_values($this->inner->hosts)[0];
        $this->assertSame(['blog.example.dev'], $host['domain_names']);
        $this->assertSame('203.0.113.5', $host['forward_host']);
        $record = Mirror::first();
        $this->assertSame($this->internal->id, $record->zone_id);
        $this->assertSame('blog.example.dev', $record->domain);
        $this->assertSame((int) $host['id'], $record->npm_host_id);
        $this->assertNull($this->blog->fresh()->npm_public_host_id, 'a mirror is not the service\'s own proxy host');

        $this->assertSame('unchanged', $this->mirror($this->sync()->pushPlan())['action']);
    }

    public function test_a_changed_source_shows_the_mirror_as_drifted_and_the_next_preview_updates_it(): void
    {
        $this->applyMirror();

        $this->external->update(['proxy_address' => '203.0.113.9']);
        $this->blog->update(['websockets' => true]);

        $item = $this->mirror($this->sync()->pushPlan());

        $this->assertSame('update', $item['action']);
        $this->assertEqualsCanonicalizing(['forward_host', 'allow_websocket_upgrade'], array_column($item['changes'], 'field'));

        $this->sync()->applyPush($this->pick([$item]));
        $this->assertSame('203.0.113.9', array_values($this->inner->hosts)[0]['forward_host']);
    }

    public function test_a_mirror_nothing_asks_for_is_orphaned_listed_and_never_deleted(): void
    {
        $this->applyMirror();
        $hostId = Mirror::first()->npm_host_id;

        // The service is renamed: the old name's mirror is orphaned, the new name gets a new mirror.
        $this->blog->update(['public_domain' => 'journal.example.dev']);
        $plan = $this->sync()->pushPlan();

        $orphan = collect($plan)->firstWhere('action', 'orphan');
        $this->assertStringContainsString('blog.example.dev', $orphan['title']);
        $this->assertStringContainsString('never deletes anything in NPM', $orphan['warnings'][0]);
        $this->assertSame('create', $this->mirror($plan)['action'], 'the wildcard covers the new name too');
        $this->assertStringContainsString('journal.example.dev', $this->mirror($plan)['title']);
    }

    public function test_orphans_after_a_deleted_service_or_a_switched_off_rule_are_never_deleted_and_can_be_forgotten(): void
    {
        $this->applyMirror();
        $record = Mirror::first();

        $this->internal->update(['mirror_sources' => null]);
        $plan = $this->sync()->pushPlan();
        $this->assertSame(['orphan'], array_column($plan, 'action'));
        $this->assertSame([], $this->sync()->applyPush($this->pick($plan))['results'], 'an orphan cannot be applied');
        $this->assertCount(1, $this->inner->hosts, 'the host is still there');
        $this->assertNotContains('DELETE', $this->inner->methods());

        $this->get(route('npm.mirrors'))->assertOk()->assertSee('Orphaned')->assertSee('blog.example.dev')->assertSee('Forget');
        $this->delete(route('npm.mirrors.forget', $record))->assertRedirect(route('npm.mirrors'));
        $this->assertSame(0, Mirror::count());
        $this->assertCount(1, $this->inner->hosts, 'forgetting changes nothing in NPM');

        // Deleting the service orphans too, and the record outlives it.
        $this->mirrorOn();
        $this->sync()->applyPush($this->pick($this->sync()->pushPlan()));
        $this->blog->delete();
        $this->assertSame('orphan', collect($this->sync()->pushPlan())->firstWhere('action', 'orphan')['action']);
    }

    public function test_an_existing_host_with_the_mirrored_name_is_taken_over_with_a_warning(): void
    {
        $this->mirrorOn();
        $this->cert($this->inner, 1, ['*.example.dev']);
        $this->inner->addHost(['domain_names' => ['blog.example.dev'], 'forward_host' => '198.51.100.7', 'forward_port' => 80, 'certificate_id' => 1]);

        $item = $this->mirror($this->sync()->pushPlan());

        $this->assertSame('update', $item['action']);
        $this->assertStringContainsString('already serves this name', implode(' ', $item['warnings']));
    }

    public function test_the_import_does_not_offer_a_managed_mirror_as_a_service(): void
    {
        $this->applyMirror();

        $items = $this->sync()->importPlan();

        $this->assertSame('skip', $items[0]['action']);
        $this->assertStringContainsString('a mirror LabMan manages', $items[0]['warnings'][0]);
    }

    public function test_automatic_updates_follow_a_source_change_into_the_mirroring_proxy(): void
    {
        $this->applyMirror();
        NpmAuto::setEnabled(true);

        $this->blog->update(['websockets' => true]);
        NpmAuto::flush();

        $this->assertTrue(array_values($this->inner->hosts)[0]['allow_websocket_upgrade']);
    }

    // ------------------------------------------------------------------ screens and YAML

    public function test_the_zone_form_saves_mirror_rules_and_the_service_form_saves_its_publish_list(): void
    {
        $this->put(route('zones.update', $this->internal), ['name' => 'Internal', 'level' => 60, 'mirror_from' => [$this->external->id], 'mirror_upstream' => [$this->external->id => 'https']])->assertSessionHasNoErrors();
        $this->assertSame([$this->external->id => 'https'], Mirrors::sourcesOf($this->internal->fresh()));

        $this->get(route('zones.edit', $this->internal))->assertOk()->assertSee('Mirror the hosts of')->assertSee('External');

        $body = ['name' => 'Blog', 'address' => '198.51.100.7', 'scheme' => 'http', 'path' => '/', 'expect' => '200-499', 'public_domains' => ['blog.example.dev']];
        $this->get(route('services.edit', $this->blog))->assertOk()->assertSee('Also publish in');
        $this->put(route('services.update', $this->blog), $body + ['publish_form' => 1, 'publish_in' => [$this->internal->id], 'publish_upstream' => [$this->internal->id => 'http']])->assertSessionHasNoErrors();
        $this->assertSame([['zone_id' => $this->internal->id, 'upstream' => 'http']], $this->blog->fresh()->publish_zones);

        $this->put(route('services.update', $this->blog), $body + ['publish_form' => 1])->assertSessionHasNoErrors();
        $this->assertNull($this->blog->fresh()->publish_zones, 'unticking everything clears it');

        $this->put(route('zones.update', $this->internal), ['name' => 'Internal', 'level' => 60])->assertSessionHasNoErrors();
        $this->assertSame([], Mirrors::sourcesOf($this->internal->fresh()), 'a zone form with nothing ticked turns mirroring off');
    }

    public function test_the_mirrors_page_lists_what_each_proxy_should_hold(): void
    {
        $this->get(route('npm.mirrors'))->assertOk()->assertSee('No mirrors are set up');

        $this->mirrorOn();
        $this->get(route('npm.mirrors'))->assertOk()->assertSee('In the Internal proxy')->assertSee('blog.example.dev')->assertSee('not created yet')->assertSee('mirror of Blog');
    }

    public function test_mirror_settings_round_trip_through_yaml_and_unknown_zones_are_rejected(): void
    {
        $this->mirrorOn('https');
        $this->blog->update(['publish_zones' => [['zone_id' => $this->internal->id, 'upstream' => 'http']]]);

        $yaml = YamlConfig::export();
        $this->assertStringContainsString('mirrors:', $yaml);
        $this->assertStringContainsString('publish_in:', $yaml);

        $this->internal->update(['mirror_sources' => null]);
        $this->blog->update(['publish_zones' => null]);
        YamlConfig::import(YamlConfig::parse($yaml));

        $this->assertSame([$this->external->id => 'https'], Mirrors::sourcesOf($this->internal->fresh()));
        $this->assertSame([['zone_id' => $this->internal->id, 'upstream' => 'http']], $this->blog->fresh()->publish_zones);

        foreach ([
            "zones: [{name: A, mirrors: [Nowhere]}]\nservers: []\nservices: []\n" => "zone 'Nowhere' is not defined",
            "servers: []\nservices: [{name: S, address: 192.0.2.1, publish_in: [Nowhere]}]\n" => "zone 'Nowhere' is not defined",
            "zones: [{name: A, mirrors: [{zone: A, upstream: carrier-pigeon}]}]\nservers: []\nservices: []\n" => 'upstream must be one of',
        ] as $bad => $message) {
            try {
                YamlConfig::parse($bad);
                $this->fail("expected: {$message}");
            } catch (ConfigException $e) {
                $this->assertStringContainsString($message, implode(' | ', $e->errors));
            }
        }
    }
}
