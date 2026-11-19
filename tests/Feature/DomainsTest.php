<?php

namespace Tests\Feature;

use App\Domain\SettingsInput;
use App\Models\Service;
use App\Monitoring\HealthChecker;
use App\Monitoring\Verdict;
use App\Npm\Connection;
use App\Npm\NpmClient;
use App\Npm\NpmSync;
use App\Support\Domains;
use App\Support\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use Tests\Concerns\UsesLocalServers;
use Tests\Support\FakeNpm;
use Tests\TestCase;

/** Local and public domains are configuration: nothing here is a rule baked into the code. */
class DomainsTest extends TestCase
{
    use RefreshDatabase, UsesLocalServers;

    private function configure(): void
    {
        SettingsInput::update(['local_domains' => 'home.example.com', 'public_domains' => 'Example.org, lab.example.net', 'npm_cert_local' => '3', 'npm_cert_public' => '7']);
    }

    public function test_domain_lists_are_normalised_validated_and_stored(): void
    {
        $this->configure();

        $this->assertSame(['home.example.com'], Domains::local());
        $this->assertSame(['example.org', 'lab.example.net'], Domains::public());
        $this->assertSame('example.org, lab.example.net', Settings::get('public_domains'));
        $this->assertSame('3', Settings::get('npm_cert_local'));

        $this->expectException(ValidationException::class);
        SettingsInput::update(['local_domains' => 'not a domain!']);
    }

    public function test_scope_follows_whatever_is_configured(): void
    {
        $this->assertNull(Domains::scope('wiki.home.example.com'), 'nothing is assumed when nothing is configured');

        $this->configure();

        $this->assertSame('local', Domains::scope('wiki.home.example.com'));
        $this->assertSame('public', Domains::scope('wiki.example.org'));
        $this->assertSame('public', Domains::scope('x.lab.example.net'));
        $this->assertNull(Domains::scope('wiki.other.test'));
        $this->assertNull(Domains::scope('notexample.org'), 'a suffix match must be on a label boundary');
        $this->assertSame('wiki.example.org', Domains::suggest('Wiki', 'public'));
    }

    public function test_a_service_can_have_both_names_and_opens_the_primary_one(): void
    {
        $service = Service::create(['name' => 'Wiki', 'domain' => 'wiki.home.example.com', 'public_domain' => 'wiki.example.org', 'address' => '192.0.2.5', 'scheme' => 'http', 'path' => '/']);

        $this->assertSame('https://wiki.home.example.com/', $service->openUrl());
        $this->assertSame('https://wiki.example.org/', $service->publicDomainUrl());

        $only = Service::create(['name' => 'Pub', 'public_domain' => 'pub.example.org', 'address' => '192.0.2.6', 'scheme' => 'http', 'path' => '/']);
        $this->assertSame('https://pub.example.org/', $only->openUrl());
    }

    public function test_both_names_are_checked_and_a_failing_one_degrades(): void
    {
        $port = $this->startHttpServer();
        Http::fake([
            'https://wiki.home.example.com/*' => Http::response('ok', 200),
            'https://wiki.example.org/*' => Http::response('bad gateway', 502),
        ]);

        $service = Service::create([
            'name' => 'Wiki', 'domain' => 'wiki.home.example.com', 'public_domain' => 'wiki.example.org', 'address' => '127.0.0.1', 'port' => $port,
            'scheme' => 'http', 'path' => '/ok', 'public_check' => true, 'check_tcp' => false,
        ]);

        $result = (new HealthChecker)->checkServices([$service], 3)[$service->id];

        $this->assertSame(Verdict::DEGRADED, $result['state']);
        $this->assertSame(['backend', 'local', 'public'], array_map(fn ($c) => $c->label, $result['checks']));
        $this->assertFalse($result['checks'][2]->ok);
    }

    public function test_either_name_answering_keeps_an_unreachable_backend_up(): void
    {
        Http::fake(['https://wiki.example.org/*' => Http::response('ok', 200), 'https://wiki.home.example.com/*' => Http::response('', 500)]);

        $service = Service::create([
            'name' => 'Wiki', 'domain' => 'wiki.home.example.com', 'public_domain' => 'wiki.example.org', 'address' => '127.0.0.1', 'port' => $this->closedPort(),
            'scheme' => 'http', 'path' => '/', 'public_check' => true,
        ]);

        $this->assertSame(Verdict::UP, (new HealthChecker)->checkServices([$service], 2)[$service->id]['state']);
    }

    // ------------------------------------------------------------------- NPM

    private function npmSync(?FakeNpm &$npm = null): NpmSync
    {
        $npm = FakeNpm::install();
        Connection::save(FakeNpm::URL, 'npm@example.com', 'npm-secret', true);

        return new NpmSync(NpmClient::fromStored());
    }

    public function test_a_public_host_becomes_the_public_name_of_the_matching_service(): void
    {
        $this->configure();
        $sync = $this->npmSync($npm);
        $local = Service::create(['name' => 'Wiki', 'domain' => 'wiki.home.example.com', 'address' => '192.0.2.5', 'scheme' => 'http', 'path' => '/']);
        $hostId = $npm->addHost(['domain_names' => ['wiki.example.org'], 'forward_host' => '192.0.2.5', 'forward_port' => 80]);

        $plan = collect($sync->importPlan())->keyBy('key');
        $item = $plan["host:{$hostId}"];

        $this->assertSame('update', $item['action'], 'matched to the existing service by label, not created as a duplicate');
        $this->assertSame($local->id, $item['data']['service_id']);
        $this->assertSame('npm_public_host_id', $item['data']['link_field']);
        $this->assertContains('public_domain', array_column($item['changes'], 'field'));

        $sync->applyImport([$item['key'] => $item['fingerprint']]);

        $fresh = $local->fresh();
        $this->assertSame('wiki.example.org', $fresh->public_domain);
        $this->assertSame($hostId, $fresh->npm_public_host_id);
        $this->assertNull($fresh->npm_host_id);
        $this->assertSame('wiki.home.example.com', $fresh->domain, 'the local name is untouched');
    }

    public function test_pushing_creates_one_host_per_domain_with_the_right_certificate(): void
    {
        $this->configure();
        $sync = $this->npmSync($npm);
        $service = Service::create(['name' => 'Wiki', 'domain' => 'wiki.home.example.com', 'public_domain' => 'wiki.example.org', 'address' => '192.0.2.5', 'scheme' => 'http', 'path' => '/']);

        $plan = collect($sync->pushPlan())->keyBy('key');
        $this->assertSame(['svc:'.$service->id, 'svc:'.$service->id.':public'], $plan->keys()->all());

        $sync->applyPush($plan->map(fn ($i) => $i['fingerprint'])->all());

        $created = collect($npm->hosts)->keyBy(fn ($h) => $h['domain_names'][0]);
        $this->assertSame(3, $created['wiki.home.example.com']['certificate_id'], 'the local certificate');
        $this->assertSame(7, $created['wiki.example.org']['certificate_id'], 'the public certificate');
        $this->assertTrue($created['wiki.example.org']['ssl_forced']);

        $fresh = $service->fresh();
        $this->assertSame($created['wiki.home.example.com']['id'], $fresh->npm_host_id);
        $this->assertSame($created['wiki.example.org']['id'], $fresh->npm_public_host_id);
    }

    public function test_without_configured_certificates_new_hosts_have_none(): void
    {
        $sync = $this->npmSync($npm);
        Service::create(['name' => 'Wiki', 'domain' => 'wiki.example.com', 'address' => '192.0.2.5', 'scheme' => 'http', 'path' => '/']);

        $item = $sync->pushPlan()[0];

        $this->assertSame(0, $item['data']['payload']['certificate_id']);
        $this->assertFalse($item['data']['payload']['ssl_forced']);
    }

    public function test_certificates_are_listed_for_the_settings_page(): void
    {
        $this->admin();
        $npm = FakeNpm::install();
        Connection::save(FakeNpm::URL, 'npm@example.com', 'npm-secret', true);
        $npm->certificates = [
            ['id' => 3, 'nice_name' => 'Local wildcard', 'domain_names' => ['*.home.example.com']],
            ['id' => 7, 'nice_name' => 'Cloudflare', 'domain_names' => ['example.org', '*.example.org']],
        ];

        $this->actingAs(\App\Models\User::where('role', 'admin')->first())->get('/settings')->assertOk()
            ->assertSee('Local wildcard')->assertSee('Cloudflare')->assertSee('Local domains');
    }

    public function test_the_settings_form_saves_the_domains(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)->put('/settings', ['section' => 'general', 'title' => 'LabMan', 'check_interval' => 30, 'timeout' => 4, 'ssh_target' => 'address', 'ssh_user' => '',
            'icons' => 1, 'local_domains' => 'home.example.com', 'public_domains' => 'example.org', 'npm_cert_local' => '', 'npm_cert_public' => ''])
            ->assertSessionHasNoErrors();

        $this->assertSame(['home.example.com'], Domains::local());

        $this->actingAs($admin)->put('/settings', ['section' => 'general', 'title' => 'LabMan', 'check_interval' => 30, 'timeout' => 4, 'ssh_target' => 'address',
            'local_domains' => 'bad_domain!'])->assertSessionHasErrors('local_domains');
    }

    public function test_the_service_form_validates_the_public_domain(): void
    {
        $admin = $this->admin();
        $base = ['name' => 'X', 'address' => '192.0.2.9', 'scheme' => 'http', 'path' => '/', 'expect' => '200-499'];

        $this->actingAs($admin)->post('/services', $base + ['domain' => 'x.home.example.com', 'public_domain' => 'X.Example.org'])->assertSessionHasNoErrors();
        $this->assertSame('x.example.org', Service::where('name', 'X')->value('public_domain'));

        $this->post('/services', ['name' => 'Y'] + $base + ['domain' => 'same.example.com', 'public_domain' => 'same.example.com'])->assertSessionHasErrors('public_domain');
    }
}
