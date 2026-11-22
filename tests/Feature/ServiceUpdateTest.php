<?php

namespace Tests\Feature;

use App\Models\Server;
use App\Models\Service;
use App\Semaphore\Connection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\FakeSemaphore;
use Tests\TestCase;

/** Kicking off a service's update workbook from the service list. */
class ServiceUpdateTest extends TestCase
{
    use RefreshDatabase;

    private FakeSemaphore $sem;

    protected function setUp(): void
    {
        parent::setUp();

        $this->sem = FakeSemaphore::install();
        Connection::save(FakeSemaphore::URL, 'sem-token', true);
        $this->actingAs($this->admin());
    }

    private function body(array $extra = []): array
    {
        return $extra + ['name' => 'Wiki', 'scheme' => 'http', 'path' => '/', 'expect' => '200-499'];
    }

    public function test_the_service_form_offers_the_workbooks_and_stores_the_choice(): void
    {
        $server = Server::create(['name' => 'docker-1', 'address' => '192.0.2.20']);

        $this->get(route('services.create'))->assertOk()->assertSee('Update with')->assertSee('Home Lab / Update packages', false)->assertSee('value="1:7"', false);

        $this->post('/services', $this->body(['server_id' => $server->id, 'update_workbook' => '1:7']))->assertSessionHasNoErrors();

        $service = Service::where('name', 'Wiki')->first();
        $this->assertSame(1, $service->update_project);
        $this->assertSame(7, $service->update_template);
        $this->assertTrue($service->hasUpdate());

        $this->get(route('services.edit', $service))->assertSee('value="1:7" selected', false);

        $this->put(route('services.update', $service), $this->body(['server_id' => $server->id, 'update_workbook' => '']))->assertSessionHasNoErrors();
        $this->assertFalse($service->fresh()->hasUpdate());
    }

    public function test_the_update_link_opens_the_run_form_aimed_at_the_services_server(): void
    {
        $server = Server::create(['name' => 'Docker Host', 'address' => '192.0.2.20', 'inventory_host' => 'docker-1']);
        $service = Service::create(['name' => 'Wiki', 'server_id' => $server->id, 'scheme' => 'http', 'path' => '/', 'update_project' => 1, 'update_template' => 7]);

        $this->get('/services')->assertOk()->assertSee(route('services.run-update', $service), false);

        $this->get(route('services.run-update', $service))->assertRedirect();
        $page = $this->followingRedirects()->get(route('services.run-update', $service))->assertOk()->assertSee('Run: Update packages')->getContent();

        $this->assertMatchesRegularExpression('#name="hosts\[\]" value="docker-1"\s+checked#', $page);
        $this->assertStringContainsString('value="Update Wiki"', $page);
        $this->assertSame([], $this->sem->posts(), 'nothing is started until the run is confirmed');
    }

    public function test_a_service_without_a_server_opens_the_form_untargeted_and_says_so(): void
    {
        $service = Service::create(['name' => 'Loose', 'address' => '192.0.2.30', 'scheme' => 'http', 'path' => '/', 'update_project' => 1, 'update_template' => 7]);

        $this->followingRedirects()->get(route('services.run-update', $service))->assertOk()->assertSee('not linked to a server');
    }

    public function test_a_service_with_no_workbook_is_sent_back_to_choose_one(): void
    {
        $service = Service::create(['name' => 'Plain', 'address' => '192.0.2.31', 'scheme' => 'http', 'path' => '/']);

        $this->get(route('services.run-update', $service))->assertRedirect(route('services.edit', $service))->assertSessionHasErrors('update_workbook');
        $this->get('/services')->assertDontSee(route('services.run-update', $service), false);
    }

    public function test_only_admins_can_use_it(): void
    {
        $service = Service::create(['name' => 'Wiki', 'address' => '192.0.2.31', 'scheme' => 'http', 'path' => '/', 'update_project' => 1, 'update_template' => 7]);
        auth()->logout();

        $this->get(route('services.run-update', $service))->assertRedirect(route('login'));
        $this->actingAs($this->user())->get(route('services.run-update', $service))->assertForbidden();
    }
}
