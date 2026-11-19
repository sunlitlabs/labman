<?php

namespace Tests\Feature;

use App\Config\ConfigException;
use App\Config\YamlConfig;
use App\Models\Server;
use App\Models\Service;
use App\Support\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class YamlConfigTest extends TestCase
{
    use RefreshDatabase;

    /** @return array<string, array{0: string, 1: string}> */
    public static function invalidConfigs(): array
    {
        $srv = "servers:\n  - {name: x, address: 192.0.2.1}\n";
        $svc = fn (string $extra) => $srv."services:\n  - {name: x, address: 192.0.2.5{$extra}}\n";

        return [
            'not a mapping' => ["- a\n- b\n", "the file must contain a mapping with 'servers' and 'services'"],
            'empty file' => ['', "the file must contain a mapping with 'servers' and 'services'"],
            'missing services' => ["servers: []\n", "the file must contain a mapping with 'servers' and 'services'"],
            'unknown top-level key' => ["servers: []\nservices: []\nbogus: 1\n", 'unknown top-level key(s): bogus'],
            'two unknown keys' => ["servers: []\nservices: []\na: 1\nb: 2\n", 'unknown top-level key(s): a, b'],

            'settings not a mapping' => ["settings: 5\nservers: []\nservices: []\n", 'settings: must be a mapping'],
            'bad ssh_target' => ["settings: {ssh_target: host}\nservers: []\nservices: []\n", "settings: ssh_target must be 'address' or 'domain'"],
            'interval not a number' => ["settings: {check_interval: abc}\nservers: []\nservices: []\n", 'settings: check_interval and timeout must be numbers'],
            'timeout not a number' => ["settings: {timeout: fast}\nservers: []\nservices: []\n", 'settings: check_interval and timeout must be numbers'],
            'interval too small' => ["settings: {check_interval: 2}\nservers: []\nservices: []\n", 'settings: check_interval must be at least 5 seconds'],
            'timeout too large' => ["settings: {timeout: 90}\nservers: []\nservices: []\n", 'settings: timeout must be between 0.5 and 60 seconds'],
            'timeout too small' => ["settings: {timeout: 0.1}\nservers: []\nservices: []\n", 'settings: timeout must be between 0.5 and 60 seconds'],

            'server not a mapping' => ["servers:\n  - 5\nservices: []\n", 'servers[1]: each server must be a mapping'],
            'server without name' => ["servers:\n  - {address: 192.0.2.1}\nservices: []\n", "servers[1]: 'name' is required"],
            'server without address' => ["servers:\n  - {name: x}\nservices: []\n", "server 'x': 'address' (IP or hostname) is required"],
            'duplicate server' => ["servers:\n  - {name: x, address: 192.0.2.1}\n  - {name: x, address: 192.0.2.2}\nservices: []\n", "server 'x': duplicate server name"],
            'ports not a list' => ["servers:\n  - {name: x, address: 192.0.2.1, ports: 445}\nservices: []\n", "server 'x': 'ports' must be a list"],
            'port out of range' => ["servers:\n  - {name: x, address: 192.0.2.1, ports: [99999]}\nservices: []\n", "server 'x': port 99999 is outside 1-65535"],
            'port zero' => ["servers:\n  - {name: x, address: 192.0.2.1, ports: [0]}\nservices: []\n", "server 'x': port 0 is outside 1-65535"],
            'port not a number' => ["servers:\n  - {name: x, address: 192.0.2.1, ports: [abc]}\nservices: []\n", "server 'x': port 'abc' is not a number"],

            'service not a mapping' => [$srv."services:\n  - 7\n", 'services[1]: each service must be a mapping'],
            'service without name' => [$srv."services:\n  - {address: 192.0.2.5}\n", "services[1]: 'name' is required"],
            'duplicate service' => [$srv."services:\n  - {name: x, address: 192.0.2.5}\n  - {name: x, address: 192.0.2.6}\n", "service 'x': duplicate service name"],
            'neither server nor address' => [$srv."services:\n  - {name: x}\n", "service 'x': set exactly one of 'server' (a server name) or 'address'"],
            'both server and address' => [$srv."services:\n  - {name: x, server: x, address: 192.0.2.5}\n", "service 'x': set exactly one of 'server' (a server name) or 'address'"],
            'unknown server' => [$srv."services:\n  - {name: x, server: ghost}\n", "service 'x': server 'ghost' is not in the servers list"],
            'bad scheme' => [$svc(', scheme: ftp'), "service 'x': 'scheme' must be http or https"],
            'path without slash' => [$svc(', path: wiki'), "service 'x': 'path' must start with /"],
            'expect not a range' => [$svc(', expect: abc'), "service 'x': 'expect' must look like 200 or 200-399, not 'abc'"],
            'expect backwards' => [$svc(', expect: 399-200'), "service 'x': 'expect' range 399-200 is backwards"],
            'verify_tls not a bool' => [$svc(', verify_tls: maybe'), "service 'x': 'verify_tls' must be true or false"],
            'public_check not a bool' => [$svc(', public_check: 1'), "service 'x': 'public_check' must be true or false"],
            'websockets not a bool' => [$svc(', websockets: nope'), "service 'x': 'websockets' must be true or false"],
            'service port out of range' => [$svc(', port: 70000'), "service 'x': port 70000 is outside 1-65535"],
        ];
    }

    #[DataProvider('invalidConfigs')]
    public function test_invalid_config_reports_the_expected_message(string $yaml, string $message): void
    {
        try {
            YamlConfig::parse($yaml);
            $this->fail('Expected a ConfigException');
        } catch (ConfigException $e) {
            $this->assertContains($message, $e->errors, 'Got: '.implode(' | ', $e->errors));
        }
    }

    public function test_all_problems_are_reported_together(): void
    {
        $yaml = "settings: {check_interval: 1, ssh_target: nope}\nservers:\n  - {name: a}\nservices:\n  - {name: b, path: x}\n";

        try {
            YamlConfig::parse($yaml);
            $this->fail('Expected a ConfigException');
        } catch (ConfigException $e) {
            $this->assertGreaterThanOrEqual(4, count($e->errors));
        }
    }

    public function test_unsafe_values_are_rejected_on_import(): void
    {
        $yaml = "servers:\n  - {name: a, address: '192.0.2.1; rm -rf /'}\n  - {name: b, address: 192.0.2.2, ssh_user: '-oProxyCommand=x'}\n  - {name: c, address: '-bad'}\nservices: []\n";

        try {
            YamlConfig::parse($yaml);
            $this->fail('Expected a ConfigException');
        } catch (ConfigException $e) {
            $joined = implode("\n", $e->errors);
            $this->assertStringContainsString("server 'a': 'address' must be an IP address or host name", $joined);
            $this->assertStringContainsString("server 'b': 'ssh_user' may only contain", $joined);
            $this->assertStringContainsString("server 'c': 'address' must be an IP address or host name", $joined);
        }
    }

    public function test_the_shipped_example_file_imports_cleanly(): void
    {
        $config = YamlConfig::parse((string) file_get_contents(base_path('labman.example.yml')));
        $result = YamlConfig::import($config);

        $this->assertSame(['servers' => 2, 'services' => 2, 'removed' => 0], $result);
        $this->assertSame('192.0.2.10', Server::where('name', 'nas')->value('address'));
        $this->assertSame([445], Server::where('name', 'nas')->first()->ports);
        $this->assertSame('docker-1', Service::where('name', 'Wiki')->first()->server->name);
        $this->assertSame('200-499', Service::where('name', 'Router')->value('expect'));
        $this->assertFalse(Service::where('name', 'Router')->first()->public_check);
        $this->assertSame(30, Settings::get('check_interval'));
        $this->assertSame('admin', Settings::get('ssh_user'));
    }

    public function test_import_upserts_by_name_and_replace_removes_the_rest(): void
    {
        $base = "servers:\n  - {name: a, address: 192.0.2.1}\n  - {name: b, address: 192.0.2.2}\nservices:\n  - {name: s, server: a}\n";
        YamlConfig::import(YamlConfig::parse($base));

        $changed = "servers:\n  - {name: a, address: 192.0.2.99, group: Lab}\nservices:\n  - {name: s, server: a, port: 8080}\n";
        $result = YamlConfig::import(YamlConfig::parse($changed));

        $this->assertSame(2, Server::count(), 'upsert keeps b without replace');
        $this->assertSame(1, Service::count());
        $this->assertSame('192.0.2.99', Server::where('name', 'a')->value('address'));
        $this->assertSame(8080, Service::where('name', 's')->value('port'));
        $this->assertSame(0, $result['removed']);

        $result = YamlConfig::import(YamlConfig::parse($changed), replace: true);
        $this->assertSame(1, Server::count());
        $this->assertSame(1, $result['removed']);
    }

    public function test_export_round_trips(): void
    {
        YamlConfig::import(YamlConfig::parse((string) file_get_contents(base_path('labman.example.yml'))));
        $exported = YamlConfig::export();

        Server::query()->delete();
        Service::query()->delete();

        YamlConfig::import(YamlConfig::parse($exported));

        $this->assertSame(2, Server::count());
        $this->assertSame(2, Service::count());
        $this->assertSame('docker-1', Service::where('name', 'Wiki')->first()->server->name);
        $this->assertSame('deploy', Server::where('name', 'docker-1')->value('ssh_user'));
    }

    public function test_import_and_export_commands(): void
    {
        $path = sys_get_temp_dir().'/labman-'.uniqid().'.yml';
        copy(base_path('labman.example.yml'), $path);

        $this->artisan('labman:import', ['path' => $path])->assertSuccessful();
        $this->assertSame(2, Server::count());

        $out = sys_get_temp_dir().'/labman-out-'.uniqid().'.yml';
        $this->artisan('labman:export', ['path' => $out])->assertSuccessful();
        $this->assertStringContainsString('name: nas', (string) file_get_contents($out));

        file_put_contents($path, "servers: []\n");
        $this->artisan('labman:import', ['path' => $path])->assertFailed();

        @unlink($path);
        @unlink($out);
    }
}
