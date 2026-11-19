<?php

namespace App\Inventory;

use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

/**
 * An Ansible inventory held as one canonical tree (the shape of the YAML inventory format),
 * whether it was read from YAML or from the classic INI format:
 *
 *   all:
 *     hosts:    { name: {vars} | null }
 *     vars:     { ... }
 *     children: { group: { hosts, vars, children } }
 *
 * Reading supports INI and YAML; writing always produces YAML. Anything LabMan does not
 * manage (extra host/group variables, nested groups, unknown hosts) is carried through untouched.
 * Comments and formatting are not preserved, so every write is previewed as a diff.
 */
final class Inventory
{
    private const MAX_BYTES = 524288;

    /** @param array<string, mixed> $doc */
    private function __construct(private array $doc, public readonly string $format) {}

    public static function empty(): self
    {
        return new self(self::blank(), 'yaml');
    }

    /** The format Ansible uses for a path: YAML only for .yml/.yaml, otherwise INI. */
    public static function formatFor(string $path): string
    {
        return in_array(strtolower(pathinfo($path, PATHINFO_EXTENSION)), ['yml', 'yaml'], true) ? 'yaml' : 'ini';
    }

    /** @throws InventoryException */
    public static function parse(string $content, string $path): self
    {
        if (strlen($content) > self::MAX_BYTES) {
            throw new InventoryException('The inventory file is larger than 512 KB, which is more than LabMan will read.');
        }

        if (strtolower(pathinfo($path, PATHINFO_EXTENSION)) === 'json') {
            throw new InventoryException('JSON inventories are not supported. Use a YAML (.yml) or INI inventory.');
        }

        return self::formatFor($path) === 'yaml' ? self::fromYaml($content) : self::fromIni($content);
    }

    // ============================================================== reading

    /** @throws InventoryException */
    private static function fromYaml(string $content): self
    {
        try {
            $data = trim($content) === '' ? [] : Yaml::parse($content);
        } catch (ParseException $e) {
            throw new InventoryException('The inventory is not valid YAML: '.$e->getMessage());
        }

        if ($data === null) {
            $data = [];
        }
        if (! is_array($data) || ($data !== [] && array_is_list($data))) {
            throw new InventoryException('The inventory YAML must be a mapping of groups (usually starting with "all:").');
        }

        $doc = self::blank();
        foreach ($data as $group => $node) {
            $group = (string) $group;
            $node = self::normalizeGroup($group, $node);

            if ($group === 'all') {
                $doc['all'] = self::mergeGroups($doc['all'], $node);
            } else {
                $doc['all']['children'][$group] = self::mergeGroups($doc['all']['children'][$group] ?? self::emptyGroup(), $node);
            }
        }

        return new self($doc, 'yaml');
    }

    /** @return array{hosts: array, vars: array, children: array} */
    private static function normalizeGroup(string $name, mixed $node): array
    {
        if ($node === null) {
            return self::emptyGroup();
        }
        if (! is_array($node)) {
            throw new InventoryException("Group '{$name}' must be a mapping with hosts, vars and/or children.");
        }

        $unknown = array_diff(array_keys($node), ['hosts', 'vars', 'children']);
        if ($unknown) {
            throw new InventoryException("Group '{$name}' has unexpected key(s): ".implode(', ', array_map('strval', $unknown)).'.');
        }

        $out = self::emptyGroup();

        $hosts = $node['hosts'] ?? [];
        if (is_array($hosts) && array_is_list($hosts) && $hosts !== []) {
            $hosts = array_fill_keys(array_map('strval', $hosts), null); // lenient: "hosts: [a, b]"
        }
        if (! is_array($hosts)) {
            throw new InventoryException("'hosts' of group '{$name}' must be a mapping of host names.");
        }
        foreach ($hosts as $host => $vars) {
            if ($vars !== null && ! is_array($vars)) {
                throw new InventoryException("Host '{$host}' in group '{$name}' must be a mapping of variables (or empty).");
            }
            $out['hosts'][(string) $host] = $vars;
        }

        $vars = $node['vars'] ?? [];
        if (! is_array($vars)) {
            throw new InventoryException("'vars' of group '{$name}' must be a mapping.");
        }
        $out['vars'] = $vars;

        $children = $node['children'] ?? [];
        if (! is_array($children) || ($children !== [] && array_is_list($children))) {
            throw new InventoryException("'children' of group '{$name}' must be a mapping of groups.");
        }
        foreach ($children as $child => $childNode) {
            $out['children'][(string) $child] = self::normalizeGroup((string) $child, $childNode);
        }

        return $out;
    }

    /** @throws InventoryException */
    private static function fromIni(string $content): self
    {
        $doc = self::blank();
        $group = 'all';
        $kind = 'hosts';
        $groups = [];

        foreach (preg_split('/\R/', $content) ?: [] as $number => $line) {
            $line = trim($line);
            if ($line === '' || $line[0] === '#' || $line[0] === ';') {
                continue;
            }

            if (preg_match('/^\[([^\]:]+)(?::(vars|children))?\]$/', $line, $m)) {
                $group = trim($m[1]);
                $kind = $m[2] ?? 'hosts';
                if ($group !== 'all') {
                    $groups[$group] ??= self::emptyGroup();
                }

                continue;
            }

            if ($line[0] === '[') {
                throw new InventoryException('The inventory has a malformed section header on line '.($number + 1).": {$line}");
            }

            $target = $group === 'all' ? 'all' : $group;

            if ($kind === 'children') {
                $child = trim(preg_replace('/\s+#.*$/', '', $line) ?? $line);
                $groups[$child] ??= self::emptyGroup();
                if ($group === 'all') {
                    $doc['all']['children'][$child] = self::emptyGroup();
                } else {
                    $groups[$group]['children'][$child] = self::emptyGroup();
                }

                continue;
            }

            if ($kind === 'vars') {
                if (! str_contains($line, '=')) {
                    throw new InventoryException('Expected key=value in the vars section on line '.($number + 1).": {$line}");
                }
                [$key, $value] = explode('=', $line, 2);
                if ($target === 'all') {
                    $doc['all']['vars'][trim($key)] = self::iniValue(trim($value));
                } else {
                    $groups[$target]['vars'][trim($key)] = self::iniValue(trim($value));
                }

                continue;
            }

            [$host, $vars] = self::iniHost($line);
            if ($target === 'all') {
                $doc['all']['hosts'][$host] = $vars ?: null;
            } else {
                $groups[$target]['hosts'][$host] = $vars ?: null;
            }
        }

        foreach ($groups as $name => $node) {
            $doc['all']['children'][$name] = $node;
        }

        return new self($doc, 'ini');
    }

    /** @return array{0: string, 1: array<string, mixed>} */
    private static function iniHost(string $line): array
    {
        $tokens = self::tokenize($line);
        $name = array_shift($tokens);
        $vars = [];

        // "host:2222" means ansible_port, unless the name is an IPv6 literal.
        if (substr_count($name, ':') === 1 && preg_match('/^(.+):(\d+)$/', $name, $m)) {
            $name = $m[1];
            $vars['ansible_port'] = (int) $m[2];
        }

        foreach ($tokens as $token) {
            if (! str_contains($token, '=')) {
                continue;
            }
            [$key, $value] = explode('=', $token, 2);
            $vars[$key] = self::iniValue($value);
        }

        return [$name, $vars];
    }

    /** Split on whitespace outside quotes; stop at an unquoted " #" comment. @return list<string> */
    private static function tokenize(string $line): array
    {
        $tokens = [];
        $current = '';
        $quote = null;
        $length = strlen($line);

        for ($i = 0; $i < $length; $i++) {
            $c = $line[$i];

            if ($quote !== null) {
                $current .= $c;
                if ($c === $quote) {
                    $quote = null;
                }

                continue;
            }

            if ($c === '"' || $c === "'") {
                $quote = $c;
                $current .= $c;
            } elseif ($c === '#' && ($current === '' )) {
                break;
            } elseif (ctype_space($c)) {
                if ($current !== '') {
                    $tokens[] = $current;
                    $current = '';
                }
            } else {
                $current .= $c;
            }
        }

        if ($current !== '') {
            $tokens[] = $current;
        }

        return $tokens;
    }

    private static function iniValue(string $value): mixed
    {
        if (strlen($value) >= 2 && ($value[0] === '"' || $value[0] === "'") && $value[-1] === $value[0]) {
            return substr($value, 1, -1);
        }

        return preg_match('/^(0|[1-9]\d{0,8})$/', $value) ? (int) $value : $value;
    }

    // =========================================================== inspecting

    /**
     * Every host with its merged variables and the groups it belongs to (direct groups plus
     * their ancestors; never "all"). Range patterns (web[01:05]) are flagged and not managed.
     *
     * @return array<string, array{vars: array<string, mixed>, groups: list<string>, direct: list<string>, pattern: bool}>
     */
    public function hosts(): array
    {
        $hosts = [];
        $parents = $this->parentMap();

        foreach ($this->walk() as $entry) {
            foreach ($entry['node']['hosts'] as $host => $vars) {
                $hosts[$host] ??= ['vars' => [], 'groups' => [], 'direct' => [], 'pattern' => (bool) preg_match('/\[[^\]]*:[^\]]*\]/', $host)];
                $hosts[$host]['vars'] = array_merge($hosts[$host]['vars'], $vars ?? []);

                if ($entry['name'] !== 'all') {
                    $hosts[$host]['direct'][] = $entry['name'];
                }
            }
        }

        foreach ($hosts as $host => &$info) {
            $info['direct'] = array_values(array_unique($info['direct']));
            $all = $info['direct'];
            $queue = $info['direct'];
            while ($queue) {
                $group = array_shift($queue);
                foreach ($parents[$group] ?? [] as $parent) {
                    if (! in_array($parent, $all, true)) {
                        $all[] = $parent;
                        $queue[] = $parent;
                    }
                }
            }
            $info['groups'] = $all;
        }
        unset($info);

        return $hosts;
    }

    /** @return list<string> */
    public function groupNames(): array
    {
        $names = [];
        foreach ($this->walk() as $entry) {
            if ($entry['name'] !== 'all') {
                $names[$entry['name']] = true;
            }
        }

        return array_keys($names);
    }

    // =============================================================== editing

    /** Set a variable on a host in the place the host is defined; null removes it. */
    public function setHostVar(string $host, string $key, mixed $value): void
    {
        foreach ($this->hostPaths($host) as $path) {
            $hosts = &$this->ref($path);
            $vars = $hosts[$host] ?? [];
            if ($value === null) {
                unset($vars[$key]);
            } else {
                $vars[$key] = $value;
            }
            $hosts[$host] = $vars === [] ? null : $vars;

            return; // the first definition is the "home" of the host's variables
        }
    }

    /** @param array<string, mixed> $vars */
    public function addHost(string $host, array $vars = [], ?string $group = null): void
    {
        $target = $group === null ? ['all', 'hosts'] : $this->ensureGroup($group);
        $hosts = &$this->ref($target);
        $hosts[$host] = $vars ?: null;
    }

    public function addToGroup(string $host, string $group): void
    {
        $hosts = &$this->ref($this->ensureGroup($group));
        if (! array_key_exists($host, $hosts)) {
            $hosts[$host] = null;
        }
    }

    public function removeHost(string $host): void
    {
        foreach ($this->hostPaths($host) as $path) {
            $hosts = &$this->ref($path);
            unset($hosts[$host]);
        }
    }

    /** The YAML document, with empty sections left out. */
    public function dump(): string
    {
        $doc = ['all' => $this->prune($this->doc['all'])];

        // The dumper leaves a trailing space after empty values ("host: "); keep diffs free of that noise.
        return preg_replace('/[ \t]+$/m', '', Yaml::dump($doc, 12, 2, Yaml::DUMP_NULL_AS_EMPTY)) ?? '';
    }

    // ============================================================== internals

    /** @return array{all: array{hosts: array, vars: array, children: array}} */
    private static function blank(): array
    {
        return ['all' => self::emptyGroup()];
    }

    /** @return array{hosts: array, vars: array, children: array} */
    private static function emptyGroup(): array
    {
        return ['hosts' => [], 'vars' => [], 'children' => []];
    }

    /** @param array{hosts: array, vars: array, children: array} $a @param array{hosts: array, vars: array, children: array} $b */
    private static function mergeGroups(array $a, array $b): array
    {
        $a['hosts'] = array_merge($a['hosts'], $b['hosts']);
        $a['vars'] = array_merge($a['vars'], $b['vars']);
        foreach ($b['children'] as $name => $child) {
            $a['children'][$name] = isset($a['children'][$name]) ? self::mergeGroups($a['children'][$name], $child) : $child;
        }

        return $a;
    }

    /** @return list<array{name: string, path: list<string>, node: array}> every group node in the tree */
    private function walk(): array
    {
        $out = [];
        $visit = function (string $name, array $path, array $node) use (&$visit, &$out) {
            $out[] = ['name' => $name, 'path' => $path, 'node' => $node];
            foreach ($node['children'] as $child => $childNode) {
                $visit($child, [...$path, 'children', $child], $childNode);
            }
        };
        $visit('all', ['all'], $this->doc['all']);

        return $out;
    }

    /** group => list of groups that list it as a child @return array<string, list<string>> */
    private function parentMap(): array
    {
        $map = [];
        foreach ($this->walk() as $entry) {
            foreach (array_keys($entry['node']['children']) as $child) {
                if ($entry['name'] !== 'all') {
                    $map[$child][] = $entry['name'];
                }
            }
        }

        return $map;
    }

    /** @return list<list<string>> paths to every `hosts` map that lists the host */
    private function hostPaths(string $host): array
    {
        $paths = [];
        foreach ($this->walk() as $entry) {
            if (array_key_exists($host, $entry['node']['hosts'])) {
                $paths[] = [...$entry['path'], 'hosts'];
            }
        }

        return $paths;
    }

    /** Path to the `hosts` map of a group, creating the group under `all` when needed. @return list<string> */
    private function ensureGroup(string $group): array
    {
        foreach ($this->walk() as $entry) {
            if ($entry['name'] === $group) {
                return [...$entry['path'], 'hosts'];
            }
        }

        $this->doc['all']['children'][$group] = self::emptyGroup();

        return ['all', 'children', $group, 'hosts'];
    }

    /** @param list<string> $path */
    private function &ref(array $path): mixed
    {
        $node = &$this->doc;
        foreach ($path as $key) {
            $node = &$node[$key];
        }

        return $node;
    }

    /** Drop empty hosts/vars/children keys; empty groups and host entries become null (rendered as "name:"). */
    private function prune(array $group): ?array
    {
        $out = [];

        if ($group['hosts']) {
            $out['hosts'] = array_map(fn ($vars) => $vars ?: null, $group['hosts']);
        }
        if ($group['vars']) {
            $out['vars'] = $group['vars'];
        }
        if ($group['children']) {
            $out['children'] = array_map(fn ($child) => $this->prune($child), $group['children']);
        }

        return $out ?: null;
    }
}
