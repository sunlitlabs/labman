<?php

namespace Tests\Feature;

use App\Icons\Icons;
use App\Models\Server;
use App\Models\Service;
use App\Support\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** Icon matching, the download-once cache, the same-origin route and the dashboard that uses them. */
class IconsTest extends TestCase
{
    use RefreshDatabase;

    private string $dir;

    private const SVG = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 10 10"><circle cx="5" cy="5" r="4"/></svg>';

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin(); // setup is done once an admin exists
        $this->dir = sys_get_temp_dir().'/labman-icons-'.uniqid();
        config(['labman.icons.path' => $this->dir, 'labman.icons.fetch' => true]);
        Icons::forget();
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->dir);
        Icons::forget();

        parent::tearDown();
    }

    private function library(array $slugs = ['jellyfin', 'home-assistant', 'nginx-proxy-manager', 'semaphore-ui', 'plex', 'code-server', 'proxmox']): void
    {
        Http::fake([
            Icons::BASE.'/tree.json' => Http::response(['svg' => array_map(fn ($s) => "{$s}.svg", $slugs), 'png' => array_map(fn ($s) => "{$s}.png", $slugs)]),
            Icons::BASE.'/svg/*' => Http::response(self::SVG),
            '*' => Http::response('', 404),
        ]);

        $this->assertTrue(Icons::refresh(true));
    }

    // ---------------------------------------------------------------- matching

    public function test_names_are_matched_to_library_icons(): void
    {
        $this->library();

        $this->assertSame('jellyfin', Icons::match('Jellyfin'));
        $this->assertSame('jellyfin', Icons::match('Jellyfin Media Server'));
        $this->assertSame('home-assistant', Icons::match('Home Assistant'));
        $this->assertSame('home-assistant', Icons::match('home_assistant'));
        $this->assertSame('nginx-proxy-manager', Icons::match('NPM'));
        $this->assertSame('semaphore-ui', Icons::match('Semaphore'));
        $this->assertSame('code-server', Icons::match('code-server'));
        $this->assertSame('proxmox', Icons::match('Proxmox VE'));
        $this->assertNull(Icons::match('Elegoo Printer'));
        $this->assertNull(Icons::match(''));
    }

    public function test_the_domain_helps_when_the_name_does_not(): void
    {
        $this->library();

        $this->assertSame('plex', Icons::match('Movies', 'plex.example.com'));
    }

    public function test_an_explicit_icon_wins_and_none_switches_it_off(): void
    {
        $this->library();

        $this->assertSame('plex', Icons::slugFor(new Service(['name' => 'Jellyfin', 'icon' => 'plex'])));
        $this->assertNull(Icons::slugFor(new Service(['name' => 'Jellyfin', 'icon' => 'none'])));
        $this->assertSame('jellyfin', Icons::slugFor(new Service(['name' => 'Jellyfin'])));
        $this->assertSame('proxmox', Icons::slugFor(new Server(['name' => 'Proxmox', 'address' => '192.0.2.1'])));
    }

    public function test_suggestions_prefer_names_that_start_with_the_text(): void
    {
        $this->library();

        $this->assertSame('proxmox', Icons::suggest('prox')[0]);
        $this->assertContains('home-assistant', Icons::suggest('assist'));
        $this->assertSame([], Icons::suggest(''));
    }

    // ------------------------------------------------------------------ serving

    public function test_an_icon_is_downloaded_once_cached_and_served_from_our_own_origin(): void
    {
        $this->library();

        $first = $this->get('/icons/jellyfin')->assertOk();
        $this->assertSame('image/svg+xml', $first->headers->get('Content-Type'));
        $this->assertStringContainsString("default-src 'none'", $first->headers->get('Content-Security-Policy'));
        $this->assertStringContainsString('sandbox', $first->headers->get('Content-Security-Policy'));
        $this->assertSame('nosniff', $first->headers->get('X-Content-Type-Options'));
        $this->assertFileExists($this->dir.'/jellyfin.svg');

        $downloads = fn () => Http::recorded()->filter(fn ($pair) => str_contains($pair[0]->url(), '/svg/jellyfin.svg'))->count();
        $this->assertSame(1, $downloads());

        $this->get('/icons/jellyfin')->assertOk();
        $this->assertSame(1, $downloads(), 'the second request must come from the cache');
    }

    public function test_only_names_in_the_library_are_ever_requested(): void
    {
        $this->library();

        $this->get('/icons/not-in-the-library')->assertNotFound();
        $this->assertCount(0, Http::recorded()->filter(fn ($pair) => str_contains($pair[0]->url(), 'not-in-the-library')));
    }

    public function test_bad_names_are_rejected_before_anything_happens(): void
    {
        $this->library();

        foreach (['UPPER', '..', 'a%2Fb', '-leading', str_repeat('a', 81)] as $slug) {
            $this->get('/icons/'.$slug)->assertNotFound();
        }
    }

    public function test_a_download_that_is_not_an_image_is_not_cached(): void
    {
        Http::fake([
            Icons::BASE.'/tree.json' => Http::response(['svg' => ['evil.svg']]),
            Icons::BASE.'/svg/evil.svg' => Http::response('<html>nope</html>'),
            '*' => Http::response('', 404),
        ]);
        Icons::refresh(true);

        $this->get('/icons/evil')->assertNotFound();
        $this->assertFileDoesNotExist($this->dir.'/evil.svg');
    }

    public function test_switching_icons_off_stops_matching_serving_and_downloading(): void
    {
        $this->library();
        Settings::set(['icons' => '0']);

        $this->assertNull(Icons::slugFor(new Service(['name' => 'Jellyfin'])));
        $this->get('/icons/jellyfin')->assertNotFound();
        $this->assertFalse(Icons::refresh(true));
    }

    public function test_an_unreachable_library_degrades_quietly(): void
    {
        Http::fake(['*' => Http::response('', 500)]);

        $this->assertFalse(Icons::refresh(true));
        $this->assertNull(Icons::match('Jellyfin'));
        $this->get('/icons/jellyfin')->assertNotFound();
    }

    // --------------------------------------------------------------- dashboard

    public function test_the_dashboard_shows_matched_icons_and_letter_fallbacks(): void
    {
        $this->library();
        Service::create(['name' => 'Jellyfin', 'address' => '192.0.2.5', 'scheme' => 'http', 'path' => '/']);
        Service::create(['name' => 'Elegoo', 'address' => '192.0.2.6', 'scheme' => 'http', 'path' => '/']);
        Service::create(['name' => 'Proxmox', 'address' => '192.0.2.7', 'scheme' => 'http', 'path' => '/', 'icon' => 'none']);

        $html = $this->actingAs($this->user())->get('/')->assertOk()->getContent();

        $this->assertStringContainsString(route('icons.show', 'jellyfin'), $html);
        $this->assertStringNotContainsString('icons/proxmox', $html);
        $this->assertStringContainsString('class="ico-letter">E<', $html);
    }

    public function test_the_icon_field_validates_and_lowercases(): void
    {
        $admin = $this->admin();
        $base = ['name' => 'X', 'address' => '192.0.2.9', 'scheme' => 'http', 'path' => '/', 'expect' => '200-499'];

        $this->actingAs($admin)->post('/services', $base + ['icon' => 'Jellyfin'])->assertSessionHasNoErrors();
        $this->assertSame('jellyfin', Service::where('name', 'X')->value('icon'));

        $this->actingAs($admin)->post('/services', ['name' => 'Y', 'icon' => '../etc/passwd'] + $base)->assertSessionHasErrors('icon');
    }

    public function test_icon_search_is_admin_only(): void
    {
        $this->library();

        $this->actingAs($this->user())->getJson('/icon-search?q=prox')->assertForbidden();
        $this->actingAs($this->admin())->getJson('/icon-search?q=prox')->assertOk()->assertJsonPath('icons.0', 'proxmox');
    }
}
