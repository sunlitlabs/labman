<?php

namespace Tests\Feature;

use App\Config\ConfigException;
use App\Config\YamlConfig;
use App\Models\Server;
use App\Models\Service;
use App\Support\ExpectRange;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** The health, monitoring and icon options in the YAML format, and the widened expect syntax. */
class YamlHealthOptionsTest extends TestCase
{
    use RefreshDatabase;

    private const YAML = <<<'YAML'
servers:
  - {name: nas, address: 192.0.2.10, monitor: false, check_ssh: false, icon: Synology}
  - {name: box, address: 192.0.2.11}
services:
  - name: Wiki
    server: box
    port: 3000
    expect: 200-399,401
    health_path: /health
    monitor: false
    check_tcp: false
    icon: wikijs
  - {name: Plain, address: 192.0.2.5}
YAML;

    public function test_options_are_imported_and_defaults_apply_when_absent(): void
    {
        YamlConfig::import(YamlConfig::parse(self::YAML));

        $wiki = Service::where('name', 'Wiki')->first();
        $this->assertSame('/health', $wiki->health_path);
        $this->assertFalse($wiki->monitor);
        $this->assertFalse($wiki->check_tcp);
        $this->assertSame('wikijs', $wiki->icon);
        $this->assertSame('200-399,401', $wiki->expect);

        $plain = Service::where('name', 'Plain')->first();
        $this->assertTrue($plain->monitor);
        $this->assertTrue($plain->check_tcp);
        $this->assertNull($plain->health_path);
        $this->assertSame('200-499', $plain->expect);

        $nas = Server::where('name', 'nas')->first();
        $this->assertFalse($nas->monitor);
        $this->assertFalse($nas->check_ssh);
        $this->assertSame('synology', $nas->icon);
        $this->assertTrue(Server::where('name', 'box')->first()->monitor);
    }

    public function test_export_round_trips_and_omits_defaults(): void
    {
        YamlConfig::import(YamlConfig::parse(self::YAML));

        $yaml = YamlConfig::export();
        $this->assertStringContainsString('health_path: /health', $yaml);
        $this->assertStringContainsString('monitor: false', $yaml);
        $this->assertStringNotContainsString('monitor: true', $yaml);

        Service::query()->delete();
        Server::query()->delete();
        YamlConfig::import(YamlConfig::parse($yaml));

        $this->assertFalse(Service::where('name', 'Wiki')->first()->monitor);
        $this->assertSame('/health', Service::where('name', 'Wiki')->first()->health_path);
        $this->assertFalse(Server::where('name', 'nas')->first()->check_ssh);
    }

    public function test_invalid_options_are_reported_by_name(): void
    {
        $bad = "servers: []\nservices:\n  - {name: x, address: 192.0.2.5, health_path: nope, monitor: maybe, check_tcp: 1, icon: '../x'}\n";

        try {
            YamlConfig::parse($bad);
            $this->fail('expected a ConfigException');
        } catch (ConfigException $e) {
            $text = implode("\n", $e->errors);
            $this->assertStringContainsString("service 'x': 'health_path' must start with /", $text);
            $this->assertStringContainsString("service 'x': 'monitor' must be true or false", $text);
            $this->assertStringContainsString("service 'x': 'check_tcp' must be true or false", $text);
            $this->assertStringContainsString("service 'x': 'icon' must be an icon name", $text);
        }
    }

    public function test_expect_ranges_can_be_lists(): void
    {
        $range = ExpectRange::parse('200-399, 401,403');

        $this->assertTrue($range->contains(204));
        $this->assertTrue($range->contains(401));
        $this->assertTrue($range->contains(403));
        $this->assertFalse($range->contains(402));
        $this->assertFalse($range->contains(500));
        $this->assertSame('200-399,401,403', (string) $range);
        $this->assertTrue(ExpectRange::parse(ExpectRange::DEFAULT)->contains(404));
        $this->assertFalse(ExpectRange::parse(ExpectRange::DEFAULT)->contains(500));

        $this->expectException(\InvalidArgumentException::class);
        ExpectRange::parse('200-399,abc');
    }
}
