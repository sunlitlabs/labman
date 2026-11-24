<?php

namespace Tests\Feature;

use App\Config\ConfigException;
use App\Config\YamlConfig;
use App\Models\Service;
use App\Models\Zone;
use App\Npm\CertificateChooser;
use App\Npm\CertificatePolicy;
use App\Npm\Certificates;
use App\Npm\Connection;
use App\Npm\NpmAuto;
use App\Npm\NpmClient;
use App\Npm\NpmSync;
use App\Support\Settings;
use App\Support\Zones;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\FakeNpm;
use Tests\TestCase;

/** Step 3 of zones: certificates matched to hosts, zone policies, edge-managed zones, and requests only on apply. */
class CertificatesTest extends TestCase
{
    use RefreshDatabase;

    private FakeNpm $npm;

    private Zone $zone;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs($this->admin(['email' => 'root@example.com']));
        $this->npm = FakeNpm::install();
        NpmAuto::setEnabled(false);
        Settings::set(['local_domains' => 'home.example.com']);
        $this->zone = Zones::default();
        Connection::save(FakeNpm::URL, 'npm@example.com', 'npm-secret', true);
    }

    /** @param list<string> $domains */
    private function cert(int $id, array $domains, int $days = 90, string $provider = 'other', array $meta = []): array
    {
        $cert = ['id' => $id, 'nice_name' => 'Cert '.$id, 'provider' => $provider, 'domain_names' => $domains, 'expires_on' => gmdate('Y-m-d H:i:s', time() + $days * 86400), 'meta' => $meta];
        $this->npm->certificates[] = $cert;

        return $cert;
    }

    private function policy(array $policy): void
    {
        $this->zone->update(['certificate_policy' => $policy]);
        $this->zone = $this->zone->fresh();
    }

    private function service(string $name, array $extra = []): Service
    {
        return Service::create(['name' => $name, 'address' => '192.0.2.50', 'domain' => strtolower($name).'.home.example.com', 'scheme' => 'http', 'path' => '/'] + $extra);
    }

    private function sync(): NpmSync
    {
        return new NpmSync(NpmClient::fromStored($this->zone), $this->zone);
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

    private function item(array $plan, Service $service): array
    {
        foreach ($plan as $item) {
            if ($item['key'] === 'svc:'.$service->id) {
                return $item;
            }
        }
        $this->fail('no plan item for '.$service->name);
    }

    // ----------------------------------------------------------------------- name matching

    #[PHPUnitFrameworkAttributesDataProvider('coverage')]
    public function test_a_certificate_name_covers_a_host_by_the_single_label_rule(string $pattern, string $host, bool $covers): void
    {
        $this->assertSame($covers, Certificates::covers($pattern, $host));
    }

    public static function coverage(): array
    {
        return [
            'exact' => ['wiki.example.dev', 'wiki.example.dev', true],
            'exact, any case' => ['Wiki.Example.Dev', 'wiki.example.dev.', true],
            'wildcard, one label' => ['*.example.dev', 'wiki.example.dev', true],
            'sub wildcard, one label' => ['*.sub.example.dev', 'a.sub.example.dev', true],
            'sub wildcard, two labels' => ['*.sub.example.dev', 'a.b.sub.example.dev', false],
            'wildcard does not cover its apex' => ['*.example.dev', 'example.dev', false],
            'sub wildcard does not cover the parent' => ['*.sub.example.dev', 'wiki.example.dev', false],
            'wildcard of another domain' => ['*.example.dev', 'wiki.example.me', false],
            'suffix must end at a dot' => ['*.example.dev', 'wiki.badexample.dev', false],
            'a plain name covers nothing else' => ['example.dev', 'wiki.example.dev', false],
            'empty' => ['', 'wiki.example.dev', false],
        ];
    }

    public function test_days_left_and_the_expiry_warning_window(): void
    {
        $now = \Carbon\CarbonImmutable::parse('2026-06-01 12:00:00', 'UTC');

        $this->assertSame(10, Certificates::daysLeft(['expires_on' => '2026-06-11 12:00:00'], $now));
        $this->assertSame(-1, Certificates::daysLeft(['expires_on' => '2026-06-01 11:00:00'], $now));
        $this->assertNull(Certificates::daysLeft(['expires_on' => null], $now));
        $this->assertTrue(Certificates::expiring(['expires_on' => '2026-06-21 11:00:00'], $now), 'under 21 days');
        $this->assertFalse(Certificates::expiring(['expires_on' => '2026-06-23 12:00:00'], $now));
        $this->assertTrue(Certificates::expired(['expires_on' => '2026-05-01 00:00:00'], $now));
        $this->assertFalse(Certificates::expiring(['expires_on' => '2026-05-01 00:00:00'], $now), 'expired is its own state');
    }

    // ------------------------------------------------------------------------- the chooser

    private function listed(array ...$certs): array
    {
        return array_map(fn ($c) => ['id' => $c['id'], 'name' => $c['nice_name'] ?? 'Cert', 'domains' => $c['domain_names'], 'expires_on' => $c['expires_on'], 'provider' => $c['provider'] ?? 'other'], $certs);
    }

    public function test_a_covering_certificate_is_used_instead_of_requesting_a_new_one(): void
    {
        $certs = $this->listed($this->cert(1, ['*.home.example.com']));
        $policy = CertificatePolicy::fromArray(['strategy' => 'letsencrypt_http', 'letsencrypt_email' => 'admin@example.com']);

        $d = CertificateChooser::choose('wiki.home.example.com', $policy, $certs);

        $this->assertSame('use', $d['mode']);
        $this->assertSame(1, $d['certificate_id']);
    }

    public function test_an_exact_certificate_beats_a_wildcard_and_an_expired_one_is_never_chosen(): void
    {
        $certs = $this->listed(
            $this->cert(1, ['*.home.example.com']),
            $this->cert(2, ['wiki.home.example.com']),
            $this->cert(3, ['wiki.home.example.com'], -5),
        );

        $d = CertificateChooser::choose('wiki.home.example.com', new CertificatePolicy, $certs);

        $this->assertSame(2, $d['certificate_id']);
        $this->assertSame(1, CertificateChooser::choose('other.home.example.com', new CertificatePolicy, $certs)['certificate_id']);
    }

    public function test_a_certificate_about_to_expire_is_used_only_when_nothing_better_covers_the_name(): void
    {
        $certs = $this->listed($this->cert(1, ['wiki.home.example.com'], 5), $this->cert(2, ['*.home.example.com'], 80));

        $this->assertSame(2, CertificateChooser::choose('wiki.home.example.com', new CertificatePolicy, $certs)['certificate_id']);

        $only = $this->listed($this->cert(1, ['wiki.home.example.com'], 5));
        $d = CertificateChooser::choose('wiki.home.example.com', new CertificatePolicy, $only);
        $this->assertSame(1, $d['certificate_id']);
        $this->assertStringContainsString('expires in', $d['warnings'][0]);
    }

    public function test_an_origin_certificate_is_never_reused_on_a_proxy_browsers_connect_to_directly(): void
    {
        $certs = $this->listed($this->cert(7, ['*.home.example.com']));

        $direct = CertificatePolicy::fromArray(['origin_certificates' => [7]]);
        $edge = CertificatePolicy::fromArray(['origin_certificates' => [7], 'edge_managed' => true]);

        $this->assertSame('missing', CertificateChooser::choose('wiki.home.example.com', $direct, $certs)['mode']);
        $this->assertSame(7, CertificateChooser::choose('wiki.home.example.com', $edge, $certs)['certificate_id']);
    }

    public function test_a_pin_always_wins_and_the_longest_pinned_domain_is_used(): void
    {
        $certs = $this->listed($this->cert(1, ['*.home.example.com']), $this->cert(2, ['*.lab.home.example.com']), $this->cert(3, ['x.example.net']));
        $policy = CertificatePolicy::fromArray(['pins' => [['domain' => 'home.example.com', 'certificate_id' => 1], ['domain' => 'lab.home.example.com', 'certificate_id' => 2]]]);

        $this->assertSame(2, CertificateChooser::choose('a.lab.home.example.com', $policy, $certs)['certificate_id']);
        $this->assertSame(1, CertificateChooser::choose('wiki.home.example.com', $policy, $certs)['certificate_id']);

        $missing = CertificatePolicy::fromArray(['pins' => [['domain' => 'home.example.com', 'certificate_id' => 99]]]);
        $d = CertificateChooser::choose('wiki.home.example.com', $missing, $certs);
        $this->assertSame('missing', $d['mode']);
        $this->assertStringContainsString('#99', $d['warnings'][0]);
    }

    public function test_the_hosts_own_setting_beats_the_policy(): void
    {
        $certs = $this->listed($this->cert(1, ['*.home.example.com']), $this->cert(2, ['other.example.net']));
        $policy = new CertificatePolicy;

        $this->assertSame('none', CertificateChooser::choose('wiki.home.example.com', $policy, $certs, 'none')['mode']);
        $this->assertSame(2, CertificateChooser::choose('wiki.home.example.com', $policy, $certs, 'existing:2')['certificate_id']);
        $this->assertSame('missing', CertificateChooser::choose('wiki.home.example.com', $policy, $certs, 'existing:42')['mode']);
    }

    public function test_requests_need_an_email_and_for_dns_a_certificate_to_borrow_the_provider_from(): void
    {
        $none = [];
        $http = CertificatePolicy::fromArray(['strategy' => 'letsencrypt_http']);
        $this->assertStringContainsString('e-mail', CertificateChooser::choose('wiki.home.example.com', $http, $none)['warnings'][0]);

        $dns = CertificatePolicy::fromArray(['strategy' => 'letsencrypt_dns', 'letsencrypt_email' => 'admin@example.com']);
        $this->assertStringContainsString('DNS-challenge certificate', CertificateChooser::choose('wiki.home.example.com', $dns, $none)['warnings'][0]);

        $ready = CertificatePolicy::fromArray(['strategy' => 'letsencrypt_dns', 'letsencrypt_email' => 'admin@example.com', 'dns_template' => 5, 'wildcard' => true]);
        $d = CertificateChooser::choose('wiki.home.example.com', $ready, $none, null, 0, false, ['home.example.com']);
        $this->assertSame('request', $d['mode']);
        $this->assertSame(['*.home.example.com', 'home.example.com'], $d['domains']);

        $single = CertificatePolicy::fromArray(['strategy' => 'letsencrypt_http', 'letsencrypt_email' => 'admin@example.com']);
        $this->assertSame(['wiki.home.example.com'], CertificateChooser::choose('wiki.home.example.com', $single, $none)['domains']);
    }

    public function test_automatic_updates_and_edge_managed_zones_never_request(): void
    {
        $policy = CertificatePolicy::fromArray(['strategy' => 'letsencrypt_http', 'letsencrypt_email' => 'admin@example.com']);
        $this->assertSame('missing', CertificateChooser::choose('wiki.home.example.com', $policy, [], null, 0, true)['mode']);

        $edge = CertificatePolicy::fromArray(['strategy' => 'letsencrypt_http', 'letsencrypt_email' => 'admin@example.com', 'edge_managed' => true]);
        $d = CertificateChooser::choose('wiki.home.example.com', $edge, [], 'letsencrypt_http');
        $this->assertSame('missing', $d['mode']);
        $this->assertStringContainsString('CDN or tunnel', $d['warnings'][0]);
    }

    public function test_policy_validation_rejects_what_cannot_work(): void
    {
        [, $errors] = CertificatePolicy::validate(['strategy' => 'letsencrypt_http', 'edge_managed' => true]);
        $this->assertStringContainsString('cannot request', $errors[0]);

        [, $errors] = CertificatePolicy::validate(['strategy' => 'nonsense', 'letsencrypt_email' => 'not-an-email', 'pins' => [['domain' => 'bad domain!', 'certificate_id' => 1], ['domain' => 'example.dev', 'certificate_id' => 0]], 'origin_certificates' => ['x']]);
        $this->assertCount(5, $errors);

        [$stored, $errors] = CertificatePolicy::validate(['strategy' => 'existing', 'pins' => CertificatePolicy::parsePinText("example.dev = 12\n*.sub.example.dev: 13")]);
        $this->assertSame([], $errors);
        $this->assertSame([['domain' => 'example.dev', 'certificate_id' => 12], ['domain' => 'sub.example.dev', 'certificate_id' => 13]], $stored['pins']);
    }

    // ------------------------------------------------------------ previews and applies in NPM

    public function test_a_new_host_gets_the_certificate_that_already_covers_it_and_a_preview_requests_nothing(): void
    {
        $this->cert(1, ['*.home.example.com']);
        $wiki = $this->service('Wiki');

        $item = $this->item($this->sync()->pushPlan(), $wiki);

        $this->assertSame('create', $item['action']);
        $this->assertSame(1, $item['data']['payload']['certificate_id']);
        $this->assertTrue($item['data']['payload']['ssl_forced']);
        $this->assertContains('certificate', array_column($item['changes'], 'field'));
        $this->assertSame([], $this->npm->certificateRequests);
        $this->assertSame([], $this->npm->writes());
    }

    public function test_a_request_happens_only_on_apply_once_per_set_of_names_and_borrows_the_dns_provider(): void
    {
        $this->cert(5, ['dns.example.net'], 90, 'letsencrypt', ['dns_challenge' => true, 'dns_provider' => 'examplednsprovider', 'dns_provider_credentials' => 'api_token=SECRETTOKEN', 'propagation_seconds' => 30]);
        $this->policy(['strategy' => 'letsencrypt_dns', 'letsencrypt_email' => 'admin@example.com', 'dns_template' => 5, 'wildcard' => true]);
        $wiki = $this->service('Wiki');
        $blog = $this->service('Blog');

        $plan = $this->sync()->pushPlan();
        $this->assertSame([], $this->npm->certificateRequests, 'previewing requests nothing');
        $this->assertSame('create', $this->item($plan, $wiki)['action']);
        $this->assertStringContainsString("new Let's Encrypt certificate", $this->item($plan, $wiki)['changes'][array_search('certificate', array_column($this->item($plan, $wiki)['changes'], 'field'))]['to']);

        $result = $this->sync()->applyPush($this->pick($plan));

        $this->assertSame(2, $result['applied']);
        $this->assertCount(1, $this->npm->certificateRequests, 'one wildcard certificate serves both hosts');
        $body = $this->npm->certificateRequests[0];
        $this->assertSame(['*.home.example.com', 'home.example.com'], $body['domain_names']);
        $this->assertSame('examplednsprovider', $body['meta']['dns_provider']);
        $this->assertSame('api_token=SECRETTOKEN', $body['meta']['dns_provider_credentials'], 'the provider settings reach NPM');
        $this->assertSame('admin@example.com', $body['meta']['letsencrypt_email']);
        foreach ($this->npm->hosts as $host) {
            $this->assertSame(101, $host['certificate_id']);
            $this->assertTrue($host['ssl_forced']);
        }
        $this->assertStringContainsString('Requested certificate #101', $result['results'][0]['message']);
        $this->assertStringNotContainsString('SECRETTOKEN', json_encode($this->zone->fresh()->certificate_policy), 'LabMan never keeps the provider token');
        $this->assertStringNotContainsString('SECRETTOKEN', json_encode(\App\Models\Setting::all()->toArray()));
    }

    public function test_a_certificate_that_cannot_be_requested_fails_that_item_and_writes_no_host(): void
    {
        $this->policy(['strategy' => 'letsencrypt_http', 'letsencrypt_email' => 'admin@example.com']);
        $wiki = $this->service('Wiki');
        $this->npm->failCertificateRequests = true;

        $result = $this->sync()->applyPush($this->pick($this->sync()->pushPlan()));

        $this->assertSame(0, $result['applied']);
        $this->assertSame('failed', $result['results'][0]['outcome']);
        $this->assertStringContainsString('challenge failed', $result['results'][0]['message']);
        $this->assertSame([], $this->npm->hosts);
        $this->assertNull($wiki->fresh()->npm_host_id);
    }

    public function test_a_dns_certificate_with_no_provider_settings_to_borrow_is_refused_clearly(): void
    {
        $this->cert(5, ['dns.example.net'], 90, 'other');
        $this->policy(['strategy' => 'letsencrypt_dns', 'letsencrypt_email' => 'admin@example.com', 'dns_template' => 5]);
        $this->service('Wiki');

        $result = $this->sync()->applyPush($this->pick($this->sync()->pushPlan()));

        $this->assertStringContainsString('no DNS provider settings', $result['results'][0]['message']);
        $this->assertSame([], $this->npm->certificateRequests);
    }

    public function test_a_host_with_an_expired_certificate_is_moved_to_a_covering_one_but_a_working_own_certificate_is_left_alone(): void
    {
        $this->cert(1, ['*.home.example.com']);
        $this->cert(9, ['wiki.home.example.com'], -3);
        $this->cert(3, ['*.home.example.com']);
        $wiki = $this->service('Wiki');
        $blog = $this->service('Blog');
        $a = $this->npm->addHost(['domain_names' => ['wiki.home.example.com'], 'forward_host' => '192.0.2.50', 'forward_port' => 80, 'certificate_id' => 9, 'ssl_forced' => true]);
        $b = $this->npm->addHost(['domain_names' => ['blog.home.example.com'], 'forward_host' => '192.0.2.50', 'forward_port' => 80, 'certificate_id' => 3, 'ssl_forced' => true]);
        $wiki->update(['npm_host_id' => $a]);
        $blog->update(['npm_host_id' => $b]);

        $plan = $this->sync()->pushPlan();

        $w = $this->item($plan, $wiki);
        $this->assertSame('update', $w['action']);
        $this->assertSame(1, $w['data']['payload']['certificate_id']);
        $this->assertStringContainsString('has expired', implode(' ', $w['warnings']));
        $this->assertSame('unchanged', $this->item($plan, $blog)['action'], 'a working certificate is not swapped');
    }

    public function test_automatic_updates_attach_a_covering_certificate_to_a_bare_host_but_never_swap_or_request(): void
    {
        $this->cert(1, ['*.home.example.com']);
        $this->cert(9, ['wiki.home.example.com'], -3);
        $wiki = $this->service('Wiki');
        $bare = $this->service('Bare');
        $a = $this->npm->addHost(['domain_names' => ['wiki.home.example.com'], 'forward_host' => '192.0.2.50', 'forward_port' => 80, 'certificate_id' => 9]);
        $b = $this->npm->addHost(['domain_names' => ['bare.home.example.com'], 'forward_host' => '192.0.2.50', 'forward_port' => 80, 'certificate_id' => 0]);
        $wiki->update(['npm_host_id' => $a]);
        $bare->update(['npm_host_id' => $b]);

        $auto = $this->sync()->pushPlan(null, true);

        $this->assertSame('unchanged', $this->item($auto, $wiki)['action'], "a host's own certificate is not swapped automatically");
        $this->assertSame(1, $this->item($auto, $bare)['data']['payload']['certificate_id']);

        // And with a request-only policy, automatic updates leave the host bare rather than requesting.
        $this->npm->certificates = [];
        $this->policy(['strategy' => 'letsencrypt_http', 'letsencrypt_email' => 'admin@example.com']);
        $autoNoCert = $this->sync()->pushPlan(null, true);
        $this->assertNotSame('request', $this->item($autoNoCert, $bare)['data']['cert_request'] ?? null);
        $this->assertNull($this->item($autoNoCert, $bare)['data']['cert_request']);
        $this->assertSame([], $this->npm->certificateRequests);
    }

    public function test_a_certificate_that_does_not_cover_the_host_or_is_expiring_is_warned_about(): void
    {
        $this->cert(2, ['other.example.net']);
        $this->cert(4, ['expiring.home.example.com'], 10);
        $wrong = $this->service('Wrong');
        $soon = $this->service('Expiring');
        $a = $this->npm->addHost(['domain_names' => ['wrong.home.example.com'], 'forward_host' => '192.0.2.50', 'forward_port' => 80, 'certificate_id' => 2]);
        $b = $this->npm->addHost(['domain_names' => ['expiring.home.example.com'], 'forward_host' => '192.0.2.50', 'forward_port' => 80, 'certificate_id' => 4]);
        $wrong->update(['npm_host_id' => $a]);
        $soon->update(['npm_host_id' => $b]);

        $plan = $this->sync()->pushPlan();

        $this->assertSame('unchanged', $this->item($plan, $wrong)['action'], 'nothing better to offer, so only a warning');
        $this->assertStringContainsString('does not cover wrong.home.example.com', implode(' ', $this->item($plan, $wrong)['warnings']));
        $this->assertStringContainsString('expires in', implode(' ', $this->item($plan, $soon)['warnings']));
    }

    public function test_the_service_can_choose_its_own_certificate_setting(): void
    {
        $this->cert(1, ['*.home.example.com']);
        $this->cert(2, ['pinned.example.net']);
        $plain = $this->service('Plain', ['cert_mode' => 'none']);
        $chosen = $this->service('Chosen', ['cert_mode' => 'existing:2']);
        $auto = $this->service('Auto');

        $plan = $this->sync()->pushPlan();

        $this->assertSame(0, $this->item($plan, $plain)['data']['payload']['certificate_id']);
        $this->assertSame(2, $this->item($plan, $chosen)['data']['payload']['certificate_id']);
        $this->assertSame(1, $this->item($plan, $auto)['data']['payload']['certificate_id']);
    }

    public function test_an_edge_managed_zone_uses_its_pinned_origin_certificate_and_a_direct_zone_refuses_it(): void
    {
        $this->cert(7, ['*.home.example.com'], 3000);
        $this->policy(['edge_managed' => true, 'pins' => [['domain' => 'home.example.com', 'certificate_id' => 7]], 'origin_certificates' => [7]]);
        $wiki = $this->service('Wiki');

        $item = $this->item($this->sync()->pushPlan(), $wiki);
        $this->assertSame(7, $item['data']['payload']['certificate_id']);
        $this->assertSame([], $this->npm->certificateRequests);

        // The same pin on a proxy that browsers talk to directly: the origin certificate is not used.
        $this->policy(['pins' => [['domain' => 'home.example.com', 'certificate_id' => 7]], 'origin_certificates' => [7]]);
        $direct = $this->item($this->sync()->pushPlan(), $wiki);
        $this->assertSame(0, $direct['data']['payload']['certificate_id']);
        $this->assertStringContainsString('origin certificate', implode(' ', $direct['warnings']));
    }

    public function test_the_certificate_report_names_each_hosts_state(): void
    {
        $this->cert(1, ['*.home.example.com']);
        $this->cert(2, ['other.example.net']);
        $this->cert(3, ['old.home.example.com'], -2);
        $services = [];
        foreach (['Fine' => 1, 'Wrong' => 2, 'Old' => 3, 'Bare' => 0, 'Ghost' => 77] as $name => $cert) {
            $services[$name] = $this->service($name);
            $services[$name]->update(['npm_host_id' => $this->npm->addHost(['domain_names' => [strtolower($name).'.home.example.com'], 'forward_host' => '192.0.2.50', 'forward_port' => 80, 'certificate_id' => $cert])]);
        }
        $this->service('Nohost');

        $report = $this->sync()->certificateReport();
        $states = array_column($report['rows'], 'state', 'service');

        $this->assertSame(['Bare' => 'missing', 'Fine' => 'ok', 'Ghost' => 'unknown', 'Nohost' => 'no_host', 'Old' => 'expired', 'Wrong' => 'uncovered'], $states);
        $this->assertSame(1, $report['counts']['ok']);
        $this->assertSame(1, $report['counts']['expired']);
    }

    // ------------------------------------------------------------------------- screens and YAML

    public function test_the_zone_form_saves_the_policy_and_rejects_a_cdn_zone_that_asks_for_lets_encrypt(): void
    {
        $base = ['name' => 'Default', 'level' => 50];

        $this->put(route('zones.update', $this->zone), $base + [
            'cert_strategy' => 'letsencrypt_dns', 'cert_email' => 'admin@example.com', 'cert_dns_template' => 5, 'cert_wildcard' => 1,
            'cert_pins' => "home.example.com = 7\n", 'cert_origin' => '7, 8',
        ])->assertSessionHasNoErrors();

        $policy = $this->zone->fresh()->certificatePolicy();
        $this->assertSame('letsencrypt_dns', $policy->strategy);
        $this->assertSame([7, 8], $policy->originIds);
        $this->assertSame(5, $policy->dnsTemplate);
        $this->assertTrue($policy->wildcard);
        $this->assertSame('home.example.com = 7', $policy->pinText());

        $this->put(route('zones.update', $this->zone), $base + ['cert_strategy' => 'letsencrypt_http', 'cert_edge_managed' => 1])->assertSessionHasErrors('certificates');
        $this->assertSame('letsencrypt_dns', $this->zone->fresh()->certificatePolicy()->strategy, 'a rejected form changes nothing');

        $this->cert(1, ['*.home.example.com']);
        $this->get(route('zones.edit', $this->zone))->assertOk()->assertSee('Certificates in this proxy')->assertSee('#1 Cert 1')->assertSee('days left');
    }

    public function test_the_npm_page_shows_the_certificate_report_with_a_way_to_the_fixes(): void
    {
        $this->cert(1, ['*.home.example.com']);
        $bare = $this->service('Bare');
        $bare->update(['npm_host_id' => $this->npm->addHost(['domain_names' => ['bare.home.example.com'], 'forward_host' => '192.0.2.50', 'forward_port' => 80, 'certificate_id' => 0])]);

        $this->get(route('npm.index'))->assertOk()->assertSee('Certificates')->assertSee('1 to look at')->assertSee('bare.home.example.com')
            ->assertSee('Preview the fixes')->assertSee('nothing is requested until you apply a previewed change');
    }

    public function test_the_service_form_saves_the_certificate_override(): void
    {
        $this->get(route('services.create'))->assertOk()->assertSee('Certificate on the proxy');
        $body = ['name' => 'Wiki', 'address' => '192.0.2.5', 'scheme' => 'http', 'path' => '/', 'expect' => '200-499'];

        $this->post('/services', $body + ['cert_mode' => 'letsencrypt_http'])->assertSessionHasNoErrors();
        $this->assertSame('letsencrypt_http', Service::first()->cert_mode);

        $this->put(route('services.update', Service::first()), $body + ['cert_mode' => 'auto', 'cert_existing' => 12])->assertSessionHasNoErrors();
        $this->assertSame('existing:12', Service::first()->cert_mode, 'a specific id wins');

        $this->put(route('services.update', Service::first()), $body + ['cert_mode' => 'auto'])->assertSessionHasNoErrors();
        $this->assertNull(Service::first()->cert_mode);

        $this->post('/services', ['name' => 'Bad'] + $body + ['cert_mode' => 'whatever'])->assertSessionHasErrors('cert_mode');
    }

    public function test_policies_and_overrides_round_trip_through_yaml_and_bad_ones_are_rejected(): void
    {
        $this->policy(['strategy' => 'letsencrypt_dns', 'letsencrypt_email' => 'admin@example.com', 'dns_template' => 5, 'pins' => [['domain' => 'example.dev', 'certificate_id' => 12]]]);
        Zone::create(['name' => 'Edge', 'level' => 10, 'certificate_policy' => ['edge_managed' => true, 'origin_certificates' => [12]]]);
        $this->service('Wiki', ['cert_mode' => 'existing:12']);

        $yaml = YamlConfig::export();
        $this->assertStringContainsString('certificates:', $yaml);
        $this->assertStringContainsString('certificate: \'existing:12\'', $yaml);

        Service::query()->delete();
        $this->zone->update(['certificate_policy' => null]);
        Zone::where('name', 'Edge')->update(['certificate_policy' => null]);
        YamlConfig::import(YamlConfig::parse($yaml));

        $this->assertSame('letsencrypt_dns', $this->zone->fresh()->certificatePolicy()->strategy);
        $this->assertSame(12, $this->zone->fresh()->certificatePolicy()->pins[0]['certificate_id']);
        $this->assertTrue(Zone::where('name', 'Edge')->first()->certificatePolicy()->edgeManaged);
        $this->assertSame('existing:12', Service::where('name', 'Wiki')->first()->cert_mode);

        foreach ([
            "zones: [{name: A, certificates: {strategy: letsencrypt_http, edge_managed: true}}]\nservers: []\nservices: []\n" => 'cannot request',
            "zones: [{name: A, certificates: {bogus: 1}}]\nservers: []\nservices: []\n" => 'unknown key',
            "servers: []\nservices: [{name: S, address: 192.0.2.1, certificate: yes please}]\n" => "'certificate' must be none",
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
