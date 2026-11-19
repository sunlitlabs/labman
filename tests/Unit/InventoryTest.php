<?php

namespace Tests\Unit;

use App\Inventory\Inventory;
use App\Inventory\InventoryException;
use App\Inventory\RepoConfig;
use App\Inventory\Slug;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Yaml\Yaml;
use Tests\TestCase;

class InventoryTest extends TestCase
{
    private const INI = <<<'INI'
# comment
ungrouped1.example.com

[web]
web1 ansible_host=192.0.2.11 ansible_user=deploy ansible_port=2222
web2.example.com   ; not a comment marker mid-line
web3:2200 ansible_ssh_pass="p w" # trailing comment

[db]
db1 ansible_host=192.0.2.21

[ranges]
node[01:03].example.com

[prod:children]
web
db

[web:vars]
http_port=8080

[all:vars]
ansible_python_interpreter=/usr/bin/python3
INI;

    public function test_ini_is_parsed_into_hosts_groups_and_typed_vars(): void
    {
        $inv = Inventory::parse(self::INI, 'hosts.ini');
        $hosts = $inv->hosts();

        $this->assertSame('ini', $inv->format);
        $this->assertSame(['ungrouped1.example.com', 'web1', 'web2.example.com', 'web3', 'db1', 'node[01:03].example.com'], array_keys($hosts));

        $this->assertSame('192.0.2.11', $hosts['web1']['vars']['ansible_host']);
        $this->assertSame(2222, $hosts['web1']['vars']['ansible_port'], 'numbers are typed');
        $this->assertSame(2200, $hosts['web3']['vars']['ansible_port'], 'host:port syntax');
        $this->assertSame('p w', $hosts['web3']['vars']['ansible_ssh_pass'], 'quotes are removed');
        $this->assertArrayNotHasKey('ansible_port', $hosts['web2.example.com']['vars']);

        $this->assertSame([], $hosts['ungrouped1.example.com']['groups']);
        $this->assertSame(['web', 'prod'], $hosts['web1']['groups'], 'direct group plus its ancestors');
        $this->assertSame(['web'], $hosts['web1']['direct']);
        $this->assertTrue($hosts['node[01:03].example.com']['pattern']);
        $this->assertFalse($hosts['web1']['pattern']);
        $this->assertEqualsCanonicalizing(['web', 'db', 'ranges', 'prod'], $inv->groupNames());
    }

    public function test_yaml_is_parsed_including_nested_groups_and_lenient_forms(): void
    {
        $yaml = <<<'YAML'
all:
  hosts:
    lone.example.com:
  vars:
    ansible_user: ops
  children:
    web:
      hosts:
        web1:
          ansible_host: 192.0.2.11
        web2:
      children:
        nginx:
          hosts:
            web1:
    db:
      hosts: [db1, db2]
extra_group:
  hosts:
    x1:
YAML;
        $inv = Inventory::parse($yaml, 'inv.yml');
        $hosts = $inv->hosts();

        $this->assertSame('yaml', $inv->format);
        $this->assertEqualsCanonicalizing(['lone.example.com', 'web1', 'web2', 'db1', 'db2', 'x1'], array_keys($hosts));
        $this->assertEqualsCanonicalizing(['web', 'nginx'], $hosts['web1']['groups'], 'web1 is in nginx, a child of web');
        $this->assertSame(['extra_group'], $hosts['x1']['direct'], 'top-level groups other than all are children of all');
        $this->assertSame([], $hosts['lone.example.com']['direct']);
    }

    /** @return array<string, array{0: string, 1: string, 2: string}> */
    public static function invalid(): array
    {
        return [
            'broken yaml' => ["all: [unclosed\n", 'x.yml', 'not valid YAML'],
            'list at top' => ["- a\n- b\n", 'x.yml', 'must be a mapping of groups'],
            'scalar group' => ["all:\n  children:\n    web: nope\n", 'x.yml', "Group 'web' must be a mapping"],
            'unknown key' => ["all:\n  hostz: {}\n", 'x.yml', 'unexpected key'],
            'bad host vars' => ["all:\n  hosts:\n    a: 5\n", 'x.yml', "Host 'a'"],
            'json' => ['{}', 'x.json', 'JSON inventories are not supported'],
            'ini header' => ["[web\nhost1\n", 'x.ini', 'malformed section header'],
            'ini vars' => ["[web:vars]\nnovalue\n", 'x.ini', 'key=value'],
            'too big' => [str_repeat('# x'.PHP_EOL, 200000), 'x.ini', 'larger than 512 KB'],
        ];
    }

    #[DataProvider('invalid')]
    public function test_bad_input_is_rejected_with_a_clear_message(string $content, string $path, string $message): void
    {
        $this->expectException(InventoryException::class);
        $this->expectExceptionMessage($message);

        Inventory::parse($content, $path);
    }

    public function test_the_format_follows_the_file_extension_like_ansible(): void
    {
        $this->assertSame('yaml', Inventory::formatFor('a/b.yml'));
        $this->assertSame('yaml', Inventory::formatFor('a/b.YAML'));
        $this->assertSame('ini', Inventory::formatFor('hosts'));
        $this->assertSame('ini', Inventory::formatFor('a/hosts.ini'));
        $this->assertSame('ini', Inventory::formatFor('a/hosts.cfg'));
    }

    public function test_editing_hosts_and_variables(): void
    {
        $inv = Inventory::parse(self::INI, 'hosts.ini');

        $inv->setHostVar('web1', 'ansible_user', 'ops');
        $inv->setHostVar('web1', 'ansible_port', null);
        $inv->setHostVar('web2.example.com', 'ansible_host', '192.0.2.12');
        $inv->addHost('cache1', ['ansible_host' => '192.0.2.31'], 'cache');
        $inv->addHost('loose1', [], null);
        $inv->addToGroup('db1', 'prod_db');
        $inv->removeHost('web3');

        $hosts = $inv->hosts();
        $this->assertSame('ops', $hosts['web1']['vars']['ansible_user']);
        $this->assertArrayNotHasKey('ansible_port', $hosts['web1']['vars']);
        $this->assertSame('192.0.2.11', $hosts['web1']['vars']['ansible_host'], 'untouched vars survive');
        $this->assertSame('192.0.2.12', $hosts['web2.example.com']['vars']['ansible_host']);
        $this->assertSame(['cache'], $hosts['cache1']['direct']);
        $this->assertSame([], $hosts['loose1']['direct']);
        $this->assertContains('prod_db', $hosts['db1']['direct']);
        $this->assertContains('db', $hosts['db1']['direct']);
        $this->assertArrayNotHasKey('web3', $hosts);
    }

    public function test_a_host_in_several_groups_is_removed_everywhere(): void
    {
        $inv = Inventory::parse("all:\n  children:\n    a:\n      hosts:\n        h1:\n    b:\n      hosts:\n        h1:\n", 'x.yml');

        $inv->removeHost('h1');

        $this->assertArrayNotHasKey('h1', $inv->hosts());
    }

    public function test_converting_ini_to_yaml_keeps_everything(): void
    {
        $ini = Inventory::parse(self::INI, 'hosts.ini');

        $yaml = Inventory::parse($ini->dump(), 'hosts.yml');

        $this->assertSame($ini->hosts(), $yaml->hosts());
        $this->assertEqualsCanonicalizing($ini->groupNames(), $yaml->groupNames());
        $this->assertSame('yaml', $yaml->format);
        $this->assertStringContainsString("ansible_python_interpreter: /usr/bin/python3", $ini->dump(), 'group and all variables are carried over');
        $this->assertStringContainsString('http_port: 8080', $ini->dump());
    }

    public function test_the_dump_is_clean_yaml_without_empty_sections(): void
    {
        $inv = Inventory::empty();
        $inv->addHost('a.example.com', ['ansible_host' => '192.0.2.1', 'ansible_port' => 2222], 'web');
        $inv->addHost('b', [], null);

        $out = $inv->dump();

        $this->assertSame(
            ['all' => ['hosts' => ['b' => null], 'children' => ['web' => ['hosts' => ['a.example.com' => ['ansible_host' => '192.0.2.1', 'ansible_port' => 2222]]]]]],
            Yaml::parse($out),
        );
        $this->assertStringNotContainsString('vars', $out);
        $this->assertStringNotContainsString('[]', $out);
        $this->assertStringNotContainsString('{}', $out);
        $this->assertSame("all:\n", Inventory::empty()->dump());
    }

    public function test_yaml_round_trip_is_stable(): void
    {
        $first = Inventory::parse(self::INI, 'hosts.ini')->dump();
        $second = Inventory::parse($first, 'hosts.yml')->dump();

        $this->assertSame($first, $second);
    }

    public function test_names_are_made_ansible_safe(): void
    {
        $this->assertSame('Docker-Host', Slug::host('Docker Host'));
        $this->assertSame('nas.example.com', Slug::host(' nas.example.com '));
        $this->assertSame('host', Slug::host('!!!'));
        $this->assertSame('Home_Lab_Servers', Slug::group('Home Lab / Servers'));
        $this->assertSame('group', Slug::group('---'));
    }

    public function test_repository_settings_are_validated(): void
    {
        config(['labman.git.allow_local' => false]);

        foreach (['ssh://git@example.com/x.git', 'git@example.com:x.git', 'ftp://example.com/x.git', 'file:///tmp/x', '/tmp/x', 'https://user:pw@example.com/x.git', 'https://example.com', 'https://-bad.example.com/x.git', '--upload-pack=evil', 'https://exa mple.com/x.git'] as $bad) {
            try {
                RepoConfig::cleanUrl($bad);
                $this->fail("Accepted {$bad}");
            } catch (\InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
        $this->assertSame('https://git.example.com/org/inventory.git', RepoConfig::cleanUrl(' https://git.example.com/org/inventory.git/ '));
        $this->assertSame('http://192.0.2.5:3000/org/inv.git', RepoConfig::cleanUrl('http://192.0.2.5:3000/org/inv.git'));

        foreach (['../x.yml', '/etc/passwd', 'a/../b.yml', '.git/config', 'a/.git/x', 'dir/', 'a b.yml', '', 'a//b.yml', 'x;rm.yml'] as $bad) {
            try {
                RepoConfig::cleanPath($bad);
                $this->fail("Accepted path {$bad}");
            } catch (\InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
        $this->assertSame('inventory/hosts.yml', RepoConfig::cleanPath('inventory/hosts.yml'));
        $this->assertSame('hosts', RepoConfig::cleanPath('hosts'));

        foreach (['-x', 'a..b', 'a b', 'x.lock', 'a/', '$(id)'] as $bad) {
            try {
                RepoConfig::cleanBranch($bad);
                $this->fail("Accepted branch {$bad}");
            } catch (\InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
        $this->assertSame('release/1.0', RepoConfig::cleanBranch('release/1.0'));

        $this->assertSame('LabMan', RepoConfig::cleanIdentity('LabMan', 'name'));
        $this->expectException(\InvalidArgumentException::class);
        RepoConfig::cleanIdentity("evil\nname", 'name');
    }
}
