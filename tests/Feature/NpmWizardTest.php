<?php

namespace Tests\Feature;

use App\Models\Server;
use App\Models\Service;
use App\Npm\Connection;
use App\Npm\NpmClient;
use App\Npm\NpmSync;
use App\Npm\NpmWizard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\FakeNpm;
use Tests\TestCase;

/** The guided "match everything up" step that follows connecting to NPM. */
class NpmWizardTest extends TestCase
{
    use RefreshDatabase;

    private FakeNpm $npm;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin();
        $this->npm = FakeNpm::install();
        Connection::save(FakeNpm::URL, 'npm@example.com', 'npm-secret', true);
    }

    private function build(?int $only = null): array
    {
        return (new NpmWizard(new NpmSync(NpmClient::fromStored())))->build($only);
    }

    /** One of each kind of row. @return array<string, int> ids */
    private function scenario(): array
    {
        Server::create(['name' => 'docker-1', 'address' => '192.0.2.20']);

        $new = $this->npm->addHost(['domain_names' => ['wiki.example.com'], 'forward_host' => '192.0.2.20', 'forward_port' => 3000]);
        $linkable = $this->npm->addHost(['domain_names' => ['git.example.com'], 'forward_host' => '192.0.2.21', 'forward_port' => 80]);
        $differs = $this->npm->addHost(['domain_names' => ['media.example.com'], 'forward_host' => '192.0.2.22', 'forward_port' => 8096]);
        $same = $this->npm->addHost(['domain_names' => ['same.example.com'], 'forward_host' => '192.0.2.23', 'forward_port' => 80]);
        $wild = $this->npm->addHost(['domain_names' => ['*.example.org']]);

        $git = Service::create(['name' => 'Git', 'domain' => 'git.example.com', 'address' => '192.0.2.21', 'scheme' => 'http', 'path' => '/']);
        $media = Service::create(['name' => 'Media', 'domain' => 'media.example.com', 'address' => '192.0.2.22', 'port' => 9000, 'scheme' => 'http', 'path' => '/', 'npm_host_id' => $differs]);
        Service::create(['name' => 'Same', 'domain' => 'same.example.com', 'address' => '192.0.2.23', 'scheme' => 'http', 'path' => '/', 'npm_host_id' => $same]);
        $local = Service::create(['name' => 'Only here', 'domain' => 'only.example.com', 'address' => '192.0.2.24', 'scheme' => 'http', 'path' => '/']);

        return compact('new', 'linkable', 'differs', 'same', 'wild') + ['git' => $git->id, 'media' => $media->id, 'local' => $local->id];
    }

    public function test_every_kind_of_row_gets_a_sensible_default(): void
    {
        $ids = $this->scenario();
        ['rows' => $rows, 'counts' => $counts] = $this->build();
        $byKey = collect($rows)->keyBy('id');

        $this->assertSame('new_here', $byKey["host:{$ids['new']}"]['kind']);
        $this->assertSame('import', $byKey["host:{$ids['new']}"]['default']);

        $this->assertSame('link_only', $byKey["host:{$ids['linkable']}"]['kind']);
        $this->assertSame('import', $byKey["host:{$ids['linkable']}"]['default']);

        $differs = $byKey["host:{$ids['differs']}"];
        $this->assertSame('differs', $differs['kind']);
        $this->assertSame('import', $differs['default'], "NPM's values are the default");
        $this->assertSame(['import', 'push', 'skip'], array_keys($differs['options']));

        $this->assertSame('in_sync', $byKey["host:{$ids['same']}"]['kind']);
        $this->assertSame('skipped', $byKey["host:{$ids['wild']}"]['kind']);

        $there = $byKey["svc:{$ids['local']}"];
        $this->assertSame('new_there', $there['kind']);
        $this->assertSame('skip', $there['default'], 'creating proxy hosts in NPM is opt-in');

        $this->assertSame(['new_here' => 1, 'link_only' => 1, 'differs' => 1, 'new_there' => 1, 'in_sync' => 1, 'skipped' => 1], $counts);
    }

    public function test_the_page_renders_with_defaults_ticked(): void
    {
        $this->scenario();

        $html = $this->actingAs(\App\Models\User::where('role', 'admin')->first())->get('/npm/wizard')->assertOk()
            ->assertSee('In NPM, not in LabMan')->assertSee('Same thing, not linked yet')->assertSee('Linked, but the settings differ')
            ->assertSee('In LabMan, not in NPM')->assertSee('Apply my choices')->getContent();

        $this->assertMatchesRegularExpression('#name="choice\[host:\d+\]" value="import"\s+checked#', $html);
        $this->assertMatchesRegularExpression('#name="choice\[svc:\d+\]" value="skip"\s+checked#', $html);
    }

    public function test_applying_the_defaults_changes_only_labman(): void
    {
        $ids = $this->scenario();
        $admin = \App\Models\User::where('role', 'admin')->first();

        $page = $this->actingAs($admin)->get('/npm/wizard')->getContent();
        $this->assertSame(200, $this->get('/npm/wizard')->status());

        $this->post('/npm/wizard', $this->submitDefaults($page))->assertRedirect(route('npm.index'))->assertSessionHas('npm_results');

        $this->assertSame([], $this->npm->writes(), 'the defaults must never write to NPM');
        $this->assertNotNull(Service::where('name', 'Wiki')->first(), 'the new proxy host became a service');
        $this->assertSame($ids['linkable'], Service::where('name', 'Git')->value('npm_host_id'));
        $this->assertSame(8096, Service::where('name', 'Media')->first()->effectivePort(), "NPM's port won");
        $this->assertNull(Service::where('name', 'Only here')->value('npm_host_id'));
        $this->assertSame('docker-1', Service::where('name', 'Wiki')->first()->server->name, 'matched to the server by address');
    }

    public function test_choosing_labmans_values_pushes_them_to_npm(): void
    {
        $ids = $this->scenario();
        $admin = \App\Models\User::where('role', 'admin')->first();
        $page = $this->actingAs($admin)->get('/npm/wizard')->getContent();

        $form = $this->submitDefaults($page);
        $form['choice']["host:{$ids['differs']}"] = 'push';
        $form['choice']["svc:{$ids['local']}"] = 'push';

        $this->post('/npm/wizard', $form)->assertRedirect(route('npm.index'));

        $this->assertSame(9000, (int) $this->npm->hosts[$ids['differs']]['forward_port'], "LabMan's port was pushed");
        $this->assertCount(6, $this->npm->hosts, 'one proxy host was created for the service that had none');
        $this->assertNotNull(Service::find($ids['local'])->npm_host_id);
    }

    public function test_a_change_since_the_page_was_shown_refuses_everything(): void
    {
        $ids = $this->scenario();
        $admin = \App\Models\User::where('role', 'admin')->first();
        $form = $this->submitDefaults($this->actingAs($admin)->get('/npm/wizard')->getContent());

        $this->npm->hosts[$ids['new']]['forward_port'] = 9999; // someone edits it in NPM meanwhile

        $this->post('/npm/wizard', $form)->assertRedirect(route('npm.wizard'))->assertSessionHasErrors('npm');
        $this->assertSame(0, Service::where('name', 'Wiki')->count());
    }

    public function test_one_service_can_be_compared_on_its_own(): void
    {
        $ids = $this->scenario();

        ['rows' => $rows] = $this->build($ids['media']);

        $this->assertCount(1, $rows);
        $this->assertSame('differs', $rows[0]['kind']);
    }

    public function test_the_service_page_links_to_the_wizard_only_when_connected_and_with_a_domain(): void
    {
        $service = Service::create(['name' => 'Wiki', 'domain' => 'wiki.example.com', 'address' => '192.0.2.5', 'scheme' => 'http', 'path' => '/']);
        $admin = \App\Models\User::where('role', 'admin')->first();

        $this->actingAs($admin)->get(route('services.edit', $service))->assertSee('Compare with NPM');

        Connection::forget();
        $this->get(route('services.edit', $service))->assertDontSee('Compare with NPM');
    }

    public function test_nothing_selected_changes_nothing(): void
    {
        $admin = \App\Models\User::where('role', 'admin')->first();

        $this->actingAs($admin)->post('/npm/wizard', ['choice' => ['host:1' => 'skip']])
            ->assertRedirect(route('npm.index'));
        $this->assertSame([], $this->npm->writes());
    }

    /** Re-submit what the page offered, exactly as a browser would with nothing changed. */
    private function submitDefaults(string $html): array
    {
        $form = ['choice' => [], 'fp_import' => [], 'push_key' => [], 'fp_push' => []];

        preg_match_all('#<input type="radio" name="choice\[([^\]]+)\]" value="([a-z]+)"\s+checked#', $html, $m, PREG_SET_ORDER);
        foreach ($m as [, $id, $value]) {
            $form['choice'][$id] = $value;
        }
        foreach (['fp_import', 'push_key', 'fp_push'] as $field) {
            preg_match_all('#name="'.$field.'\[([^\]]+)\]" value="([^"]+)"#', $html, $f, PREG_SET_ORDER);
            foreach ($f as [, $id, $value]) {
                $form[$field][$id] = $value;
            }
        }

        return $form;
    }
}
