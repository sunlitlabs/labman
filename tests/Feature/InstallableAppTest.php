<?php

namespace Tests\Feature;

use App\Support\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** LabMan can be installed as an app: a manifest named after the site, real icon files, and a worker at the root. */
class InstallableAppTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_manifest_is_public_and_follows_the_site_title(): void
    {
        $this->admin();
        Settings::set(['title' => 'Home Lab']);

        $res = $this->get('/manifest.webmanifest')->assertOk();
        $this->assertStringContainsString('application/manifest+json', $res->headers->get('Content-Type'));
        $manifest = $res->json();

        $this->assertSame('Home Lab', $manifest['name']);
        $this->assertSame('standalone', $manifest['display']);
        $purposes = collect($manifest['icons'])->pluck('purpose')->all();
        $this->assertContains('maskable', $purposes);

        foreach ($manifest['icons'] as $icon) {
            $this->assertFileExists(public_path(ltrim(parse_url($icon['src'], PHP_URL_PATH), '/')));
        }
    }

    public function test_pages_link_the_manifest_and_icons_and_the_worker_ships(): void
    {
        $this->actingAs($this->admin());

        $this->get('/')->assertOk()
            ->assertSee('rel="manifest"', false)->assertSee('apple-touch-icon', false)->assertSee('name="theme-color"', false)->assertSee('brand-mark', false);

        $this->assertFileExists(public_path('sw.js'));
        $this->assertStringContainsString("addEventListener('fetch'", file_get_contents(public_path('sw.js')));
        $this->assertStringContainsString('/sw.js', file_get_contents(public_path('js/labman.js')));
    }

    public function test_the_png_icons_are_real_images_of_the_stated_size(): void
    {
        foreach (['icon-192.png' => 192, 'icon-512.png' => 512, 'maskable-512.png' => 512, 'apple-touch-icon.png' => 180] as $file => $size) {
            $bytes = file_get_contents(public_path('app-icons/'.$file));
            $this->assertSame("\x89PNG\r\n\x1a\n", substr($bytes, 0, 8), $file);
            $this->assertSame([$size, $size], array_values(unpack('N2', substr($bytes, 16, 8))), $file);
        }
    }
}
