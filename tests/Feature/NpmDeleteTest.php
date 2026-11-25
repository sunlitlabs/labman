<?php

namespace Tests\Feature;

use App\Models\ManagedHost;
use App\Models\Service;
use App\Npm\Connection;
use App\Npm\NpmAuto;
use App\Npm\NpmClient;
use App\Npm\NpmException;
use App\Npm\NpmSync;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\FakeNpm;
use Tests\TestCase;

/** Deleting in NPM: only a proxy host LabMan created, only once nothing asks for it, only as a ticked, previewed item. */
class NpmDeleteTest extends TestCase
{
    use RefreshDatabase;

    private FakeNpm $npm;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs($this->admin(['email' => 'root@example.com']));
        $this->npm = FakeNpm::install();
        NpmAuto::setEnabled(false);
        \App\Support\Settings::set(['local_domains' => 'home.example.com']);
        Connection::save(FakeNpm::URL, 'npm@example.com', 'npm-secret', true);
    }

    private function sync(): NpmSync
    {
        return new NpmSync(NpmClient::fromStored());
    }

    /** @return array<string, string> the create/update items, as the review would tick them */
    private function ticked(array $plan): array
    {
        $out = [];
        foreach ($plan as $item) {
            if (in_array($item['action'], ['create', 'update'], true)) {
                $out[$item['key']] = $item['fingerprint'];
            }
        }

        return $out;
    }

    private function wiki(): Service
    {
        $service = Service::create(['name' => 'Wiki', 'address' => '192.0.2.50', 'domain' => 'wiki.home.example.com', 'scheme' => 'http', 'path' => '/']);
        $this->sync()->applyPush($this->ticked($this->sync()->pushPlan()));

        return $service->fresh();
    }

    public function test_a_host_labman_created_is_remembered_and_in_use_is_not_offered(): void
    {
        $service = $this->wiki();

        $this->assertSame(1, ManagedHost::count());
        $this->assertSame($service->npm_host_id, ManagedHost::first()->host_id);
        $this->assertSame([], collect($this->sync()->pushPlan())->where('action', 'delete')->all());
    }

    public function test_when_the_service_is_gone_its_host_is_offered_and_deleted_only_when_ticked(): void
    {
        $service = $this->wiki();
        $hostId = $service->npm_host_id;
        $service->delete();

        $plan = $this->sync()->pushPlan();
        $item = collect($plan)->firstWhere('action', 'delete');
        $this->assertNotNull($item);
        $this->assertSame($hostId, $item['data']['host_id']);
        $this->assertStringContainsString('cannot be undone', $item['warnings'][0]);
        $this->assertSame([], $this->ticked($plan), 'a delete is never ticked for you');
        $this->assertArrayHasKey($hostId, $this->npm->hosts, 'the preview deleted nothing');

        $result = $this->sync()->applyPush([$item['key'] => $item['fingerprint']]);

        $this->assertSame(1, $result['applied']);
        $this->assertArrayNotHasKey($hostId, $this->npm->hosts);
        $this->assertSame(0, ManagedHost::count());
    }

    public function test_a_service_that_lost_its_domain_orphans_the_host_it_created(): void
    {
        $service = $this->wiki();
        $service->update(['domain' => null]);

        $this->assertNotNull(collect($this->sync()->pushPlan())->firstWhere('action', 'delete'));
    }

    public function test_a_host_labman_did_not_create_is_never_offered(): void
    {
        $this->npm->addHost(['domain_names' => ['theirs.home.example.com'], 'forward_host' => '192.0.2.9', 'forward_port' => 80, 'certificate_id' => 0]);

        $this->assertSame([], collect($this->sync()->pushPlan())->where('action', 'delete')->all());
    }

    public function test_a_host_renamed_in_npm_is_no_longer_labmans_to_delete(): void
    {
        $service = $this->wiki();
        $this->npm->hosts[$service->npm_host_id]['domain_names'] = ['somethingelse.home.example.com'];
        $service->delete();

        $this->assertSame([], collect($this->sync()->pushPlan())->where('action', 'delete')->all());
    }

    public function test_automatic_updates_never_delete(): void
    {
        $service = $this->wiki();
        $service->delete();

        $this->assertSame([], collect($this->sync()->pushPlan(null, true))->where('action', 'delete')->all());
        NpmAuto::setEnabled(true);
        $service2 = Service::create(['name' => 'Blog', 'address' => '192.0.2.51', 'domain' => 'blog.home.example.com', 'scheme' => 'http', 'path' => '/']);
        NpmAuto::run([$service2->id], []);

        $this->assertNotContains('DELETE', $this->npm->methods());
    }

    public function test_a_stale_or_forged_selection_deletes_nothing(): void
    {
        $service = $this->wiki();
        $hostId = $service->npm_host_id;
        $service->delete();
        $item = collect($this->sync()->pushPlan())->firstWhere('action', 'delete');

        foreach ([[$item['key'] => str_repeat('0', 40)], ['host-orphan:9999' => $item['fingerprint']]] as $selection) {
            try {
                $this->sync()->applyPush($selection);
                $this->fail('expected a refusal');
            } catch (NpmException $e) {
                $this->assertStringContainsString('changed since the preview', $e->getMessage());
            }
        }
        $this->assertArrayHasKey($hostId, $this->npm->hosts);
        $this->assertNotContains('DELETE', $this->npm->methods());
    }

    public function test_the_preview_page_shows_a_delete_unticked(): void
    {
        $service = $this->wiki();
        $service->delete();

        $html = $this->get(route('npm.push'))->assertOk()->assertSee('Unused proxy host')->assertSee('delete from NPM')->getContent();

        $this->assertDoesNotMatchRegularExpression('/value="host-orphan:\d+" checked/', $html);
        $this->assertMatchesRegularExpression('/name="apply\[\]" value="host-orphan:\d+"/', $html);
    }
}
