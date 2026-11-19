<?php

namespace App\Inventory;

use App\Domain\ServerInput;
use App\Models\Server;
use App\Support\Hosts;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Two-way sync between LabMan's servers and an Ansible inventory file in a git repository.
 *
 *   compare()  pull the repo and line the two sides up: in sync / different / only in LabMan / only in the inventory
 *   review()   turn the admin's choices into an exact preview (a git diff plus the LabMan changes) and a fingerprint
 *   apply()    re-run the review, refuse if anything moved, push the inventory commit first, then update LabMan
 *
 * Nothing is ever changed by compare/review. The inventory is written as YAML; an INI inventory is read and,
 * when a write is needed, converted to a YAML file next to it.
 */
final class InventorySync
{
    public const FIELD_OPTIONS = ['labman', 'inventory', 'skip'];

    /** @param array{url: string, branch: string, path: string, username: string, token: string, author_name: string, author_email: string} $config */
    public function __construct(private readonly array $config, private readonly ?GitRepo $repo = null) {}

    /** @throws InventoryException */
    public static function fromStored(): self
    {
        $config = RepoConfig::get();

        if ($config === null) {
            throw new InventoryException('No inventory repository is connected yet. Save its address, branch, file and access token first.');
        }

        return new self($config);
    }

    public function repo(): GitRepo
    {
        return $this->repo ?? new GitRepo($this->config);
    }

    // ================================================================ compare

    /**
     * @return array<string, mixed>
     *
     * @throws InventoryException
     */
    public function compare(): array
    {
        [$head, $inventory, $content] = $this->load();

        $hosts = array_filter($inventory->hosts(), fn ($h) => ! $h['pattern']);
        $ignored = array_keys(array_filter($inventory->hosts(), fn ($h) => $h['pattern']));
        $servers = Server::query()->orderBy('name')->get();

        $pairs = $this->match($servers, $hosts);
        $matchedHosts = array_column($pairs, 'host');

        $inSync = [];
        $differences = [];
        foreach ($pairs as $pair) {
            /** @var Server $server */
            $server = $pair['server'];
            $fields = $this->fieldDiffs($server, $pair['host'], $hosts[$pair['host']]);

            if ($fields === []) {
                $inSync[] = ['server' => $server->name, 'host' => $pair['host'], 'key' => 'pair:'.$server->id];
            } else {
                $differences[] = ['key' => 'pair:'.$server->id, 'server_id' => $server->id, 'server' => $server->name, 'host' => $pair['host'], 'fields' => $fields];
            }
        }

        $matchedIds = array_map(fn ($p) => $p['server']->id, $pairs);
        $onlyLabman = [];
        foreach ($servers as $server) {
            if (! in_array($server->id, $matchedIds, true)) {
                $onlyLabman[] = [
                    'key' => 'srv:'.$server->id, 'server_id' => $server->id, 'name' => $server->name, 'address' => $server->address,
                    'group' => $server->group, 'ssh_user' => $server->ssh_user, 'ssh_port' => (int) $server->ssh_port,
                    'inventory_name' => $this->freeHostName($server, $inventory),
                ];
            }
        }

        $onlyInventory = [];
        foreach ($hosts as $name => $info) {
            if (! in_array($name, $matchedHosts, true)) {
                $onlyInventory[] = ['key' => 'host:'.$name, 'host' => $name, 'groups' => $info['direct'], 'notes' => $this->hostNotes($info)] + ['suggested' => $this->suggest($name, $info)];
            }
        }

        $convert = $inventory->format === 'ini' ? $this->conversion() : null;

        return [
            'head' => $head,
            'branch' => $this->config['branch'],
            'path' => $this->config['path'],
            'format' => $inventory->format,
            'convert' => $convert,
            'host_count' => count($hosts),
            'in_sync' => $inSync,
            'differences' => $differences,
            'only_labman' => $onlyLabman,
            'only_inventory' => $onlyInventory,
            'ignored_patterns' => $ignored,
            'candidates' => [],
        ];
    }

    // ================================================================= review

    /**
     * Build the exact result of the admin's choices without changing anything.
     *
     * @param  array<string, array<string, mixed>>  $choices  see ChoiceParser; keys are plan item keys
     * @return array{fingerprint: string, diff: string, labman: list<string>, inventory: list<string>, commit_message: string, convert: ?array, changes: bool, head: string}
     *
     * @throws InventoryException
     */
    public function review(array $choices, bool $deleteOld = false, bool $convert = false): array
    {
        $built = $this->build($choices, $deleteOld, $convert);

        $diff = ($built['writes'] || $built['deletes']) ? $this->repo()->preview($built['writes'], $built['deletes']) : '';

        return [
            'fingerprint' => $built['fingerprint'],
            'diff' => $diff,
            'labman' => $built['labman_lines'],
            'inventory' => $built['inventory_lines'],
            'commit_message' => $built['message'],
            'convert' => $built['convert'],
            'changes' => $built['writes'] !== [] || $built['deletes'] !== [] || $built['ops'] !== [],
            'head' => $built['head'],
        ];
    }

    // ================================================================== apply

    /**
     * @param  array<string, array<string, mixed>>  $choices
     * @return array{pushed: bool, commit: ?string, labman: int}
     *
     * @throws InventoryException
     */
    public function apply(array $choices, string $fingerprint, bool $deleteOld = false, bool $convert = false): array
    {
        $built = $this->build($choices, $deleteOld, $convert);

        if (! hash_equals($built['fingerprint'], $fingerprint)) {
            throw new InventoryException('The inventory or LabMan changed since you reviewed these changes, so nothing was applied. Compare again.');
        }

        $commit = null;
        if ($built['writes'] || $built['deletes']) {
            // The inventory goes first: if the push is refused, LabMan has not been touched.
            $commit = $this->repo()->commitAndPush($built['writes'], $built['deletes'], $built['message']);
        }

        DB::transaction(function () use ($built) {
            foreach ($built['ops'] as $op) {
                match ($op['op']) {
                    'create' => Server::create($op['data'] + ['inventory_host' => $op['host']]),
                    'update' => Server::whereKey($op['id'])->firstOrFail()->update($op['data']),
                    'delete' => Server::whereKey($op['id'])->delete(),
                    'link' => Server::whereKey($op['id'])->update(['inventory_host' => $op['host']]),
                };
            }
        });

        if ($built['convert']) {
            RepoConfig::setPath($built['convert']['to']);
        }

        return ['pushed' => $commit !== null, 'commit' => $commit, 'labman' => count($built['ops'])];
    }

    /**
     * Drop choices that mean "do nothing" ("leave", "skip") and fields that are not used, so the same
     * decision always has the same shape (and the same fingerprint) however a client sends it.
     *
     * @param  array<string, mixed>  $choices
     * @return array<string, array<string, mixed>>
     */
    public static function normalize(array $choices): array
    {
        $out = [];

        foreach ($choices as $key => $choice) {
            $key = (string) $key;
            if (! is_array($choice)) {
                continue;
            }

            $item = [];

            $fields = array_filter((array) ($choice['fields'] ?? []), fn ($option) => $option !== 'skip' && $option !== '' && $option !== null);
            if ($fields) {
                ksort($fields);
                $item['fields'] = array_map('strval', $fields);
            }

            $action = (string) ($choice['action'] ?? 'leave');
            if ($action !== 'leave' && $action !== '') {
                $item['action'] = $action;

                if ($action === 'add_labman') {
                    $setup = [];
                    foreach (['name', 'address', 'group', 'ssh_user', 'ssh_port'] as $field) {
                        $value = $choice['setup'][$field] ?? null;
                        if ($value !== null && trim((string) $value) !== '') {
                            $setup[$field] = is_int($value) ? $value : trim((string) $value);
                        }
                    }
                    if ($setup) {
                        $item['setup'] = $setup;
                    }
                }
            }

            if ($item) {
                $out[$key] = $item;
            }
        }

        ksort($out);

        return $out;
    }

    // =================================================================== core

    /** @return array{0: string, 1: Inventory, 2: string} head, parsed inventory, raw content */
    private function load(): array
    {
        $head = $this->repo()->sync();
        $content = $this->repo()->read($this->config['path']);

        if ($content === null) {
            $candidates = $this->repo()->candidates();
            throw new InventoryException("The file '{$this->config['path']}' does not exist on branch '{$this->config['branch']}'."
                .($candidates ? ' Inventory-like files in the repository: '.implode(', ', array_slice($candidates, 0, 8)).'.' : ''));
        }

        return [$head, Inventory::parse($content, $this->config['path']), $content];
    }

    /**
     * @param  array<string, array<string, mixed>>  $choices
     * @return array<string, mixed>
     */
    private function build(array $choices, bool $deleteOld, bool $wantConvert = false): array
    {
        $choices = self::normalize($choices);
        [$head, $inventory, $content] = $this->load();

        $hosts = array_filter($inventory->hosts(), fn ($h) => ! $h['pattern']);
        $servers = Server::query()->orderBy('name')->get()->keyBy('id');
        $pairs = $this->match($servers->values(), $hosts);
        $pairByKey = [];
        foreach ($pairs as $pair) {
            $pairByKey['pair:'.$pair['server']->id] = $pair;
        }
        $matchedHosts = array_column($pairs, 'host');
        $matchedIds = array_map(fn ($p) => $p['server']->id, $pairs);

        $edited = Inventory::parse($content, $this->config['path']);
        $ops = [];
        $labmanLines = [];
        $inventoryLines = [];
        $inventoryChanged = false;
        $usedServerNames = $servers->pluck('name')->map(fn ($n) => mb_strtolower($n))->all();

        ksort($choices);

        foreach ($choices as $key => $choice) {
            if (str_starts_with($key, 'pair:')) {
                $pair = $pairByKey[$key] ?? throw new InventoryException("'{$key}' no longer exists. Compare again.");
                /** @var Server $server */
                $server = $pair['server'];
                $diffs = collect($this->fieldDiffs($server, $pair['host'], $hosts[$pair['host']]))->keyBy('field');
                $changes = [];

                foreach ($choice['fields'] ?? [] as $field => $option) {
                    $diff = $diffs[$field] ?? throw new InventoryException("'{$field}' of {$server->name} is no longer different. Compare again.");
                    if (! in_array($option, $diff['options'], true)) {
                        throw new InventoryException("'{$option}' is not possible for {$field} of {$server->name}.");
                    }

                    if ($option === 'labman') {
                        $inventoryChanged = true;
                        $inventoryLines[] = $this->writeField($edited, $pair['host'], $field, $server, $hosts[$pair['host']]);
                    } elseif ($option === 'inventory') {
                        $changes[$field] = $diff['inventory_value'];
                        $labmanLines[] = "Set {$field} of {$server->name} to ".($diff['inventory_value'] === null ? '(none)' : $diff['inventory_value']).' (from the inventory)';
                    }
                }

                if ($changes) {
                    $ops[] = ['op' => 'update', 'id' => $server->id, 'data' => $this->validated($server->name, $changes, $server)];
                }
            } elseif (str_starts_with($key, 'srv:')) {
                $id = (int) substr($key, 4);
                $server = $servers[$id] ?? throw new InventoryException("'{$key}' no longer exists. Compare again.");
                if (in_array($id, $matchedIds, true)) {
                    throw new InventoryException("{$server->name} now matches an inventory host. Compare again.");
                }
                $action = $choice['action'] ?? 'leave';

                if ($action === 'add_inventory') {
                    $name = $this->freeHostName($server, $edited);
                    $this->addServerToInventory($edited, $server, $name);
                    $inventoryChanged = true;
                    $inventoryLines[] = "Add host {$name} (".$server->address.')'.($server->group ? ' to group '.Slug::group($server->group) : '');
                    $ops[] = ['op' => 'link', 'id' => $server->id, 'host' => $name];
                } elseif ($action === 'delete_labman') {
                    $ops[] = ['op' => 'delete', 'id' => $server->id];
                    $labmanLines[] = "Delete server {$server->name} from LabMan";
                } elseif ($action !== 'leave') {
                    throw new InventoryException("'{$action}' is not possible for {$server->name}.");
                }
            } elseif (str_starts_with($key, 'host:')) {
                $name = substr($key, 5);
                if (! isset($hosts[$name]) || in_array($name, $matchedHosts, true)) {
                    throw new InventoryException("Host '{$name}' is no longer unmatched. Compare again.");
                }
                $action = $choice['action'] ?? 'leave';

                if ($action === 'add_labman') {
                    $setup = array_merge($this->suggest($name, $hosts[$name]), array_filter($choice['setup'] ?? [], fn ($v) => $v !== null && $v !== ''));
                    $lower = mb_strtolower((string) ($setup['name'] ?? ''));
                    if (in_array($lower, $usedServerNames, true)) {
                        throw new InventoryException("A server named '{$setup['name']}' already exists. Pick another name for host {$name}.");
                    }
                    $usedServerNames[] = $lower;
                    $data = $this->validated($name, $setup);
                    $ops[] = ['op' => 'create', 'data' => $data, 'host' => $name];
                    $labmanLines[] = "Create server {$data['name']} ({$data['address']}) from inventory host {$name}";
                } elseif ($action === 'remove_inventory') {
                    $edited->removeHost($name);
                    $inventoryChanged = true;
                    $inventoryLines[] = "Remove host {$name}";
                } elseif ($action !== 'leave') {
                    throw new InventoryException("'{$action}' is not possible for host {$name}.");
                }
            } else {
                throw new InventoryException("Unknown item '{$key}'.");
            }
        }

        // Link every matched server to its host, so later syncs follow renames and address changes.
        foreach ($pairs as $pair) {
            if ($pair['server']->inventory_host !== $pair['host']) {
                $ops[] = ['op' => 'link', 'id' => $pair['server']->id, 'host' => $pair['host']];
            }
        }

        $writes = [];
        $deletes = [];
        $convert = null;

        // Asking to create the YAML file (or to delete the INI one) is a change on its own, even when no host changes.
        $convertNow = $inventory->format === 'ini' && ($wantConvert || $deleteOld);

        if ($inventoryChanged || $convertNow) {
            $target = $this->config['path'];

            if ($inventory->format === 'ini') {
                $convert = $this->conversion();
                $target = $convert['to'];
                if ($this->repo()->read($target) !== null) {
                    throw new InventoryException("Cannot convert to YAML: '{$target}' already exists in the repository. Remove it or choose another inventory file.");
                }
                if ($deleteOld) {
                    $deletes[] = $this->config['path'];
                }
                $inventoryLines[] = "Convert {$this->config['path']} to YAML as {$target}".($deleteOld ? ' and delete the old file' : ' (the old file is kept)');
            }

            $new = $edited->dump();
            if ($inventory->format === 'yaml' && $new === $content) {
                $inventoryChanged = false;
            } else {
                $writes[$target] = $new;
            }
        }

        $messageLines = array_values(array_unique($inventoryLines));
        $message = "Update inventory from LabMan\n\n".($messageLines ? '- '.implode("\n- ", $messageLines)."\n" : '');

        $fingerprint = sha1(json_encode([$head, $this->config['path'], $choices, $deleteOld, $convertNow, $writes, $deletes, $ops]));

        return [
            'head' => $head, 'writes' => $writes, 'deletes' => $deletes, 'ops' => $ops, 'convert' => $convert,
            'labman_lines' => $labmanLines, 'inventory_lines' => array_values(array_unique($inventoryLines)),
            'message' => $message, 'fingerprint' => $fingerprint,
        ];
    }

    // ================================================================ matching

    /**
     * Pair LabMan servers with inventory hosts (see HostMatcher).
     *
     * @param  iterable<Server>  $servers
     * @param  array<string, array<string, mixed>>  $hosts
     * @return list<array{server: Server, host: string}>
     */
    private function match(iterable $servers, array $hosts): array
    {
        return HostMatcher::pair($servers, $hosts);
    }

    // ============================================================ field logic

    /**
     * @param  array{vars: array<string, mixed>, groups: list<string>, direct: list<string>, pattern: bool}  $info
     * @return list<array<string, mixed>>
     */
    private function fieldDiffs(Server $server, string $host, array $info): array
    {
        $diffs = [];
        $vars = $info['vars'];

        $address = $this->address($host, $vars);
        if ($address !== null && strcasecmp($address, $server->address) !== 0) {
            $diffs[] = $this->diff('address', $server->address, $address, ['labman', 'inventory', 'skip']);
        }

        $user = $this->user($vars);
        if ($user !== false) {
            $mine = $server->ssh_user ?: null;
            if ($mine !== $user) {
                $diffs[] = $this->diff('ssh_user', $mine, $user, ['labman', 'inventory', 'skip']);
            }
        }

        $port = $this->port($vars);
        if ($port !== null && $port !== ((int) $server->ssh_port ?: 22)) {
            $diffs[] = $this->diff('ssh_port', (int) $server->ssh_port ?: 22, $port, ['labman', 'inventory', 'skip']);
        }

        $groups = $info['groups'];
        $mine = $server->group ?: null;
        if ($mine === null && $info['direct'] !== []) {
            $diffs[] = $this->diff('group', null, $info['direct'][0], ['inventory', 'skip']);
        } elseif ($mine !== null) {
            $in = array_map('mb_strtolower', $groups);
            if (! in_array(mb_strtolower($mine), $in, true) && ! in_array(mb_strtolower(Slug::group($mine)), $in, true)) {
                $diffs[] = $this->diff('group', $mine, $info['direct'][0] ?? null, $info['direct'] === [] ? ['labman', 'skip'] : ['labman', 'inventory', 'skip']);
            }
        }

        return $diffs;
    }

    /** @param list<string> $options @return array<string, mixed> */
    private function diff(string $field, mixed $labman, mixed $inventory, array $options): array
    {
        return ['field' => $field, 'labman' => $labman, 'inventory' => $inventory, 'inventory_value' => $inventory, 'options' => $options];
    }

    /** The address Ansible connects to, or null when it is a template we cannot evaluate. @param array<string, mixed> $vars */
    private function address(string $host, array $vars): ?string
    {
        return HostMatcher::address($host, $vars);
    }

    /** @param array<string, mixed> $vars @return string|null|false null = not set, false = a template (unknown) */
    private function user(array $vars): string|null|false
    {
        $value = $vars['ansible_user'] ?? $vars['ansible_ssh_user'] ?? null;

        if ($value === null || $value === '') {
            return null;
        }

        return str_contains((string) $value, '{{') ? false : (string) $value;
    }

    /** @param array<string, mixed> $vars */
    private function port(array $vars): ?int
    {
        $value = $vars['ansible_port'] ?? $vars['ansible_ssh_port'] ?? 22;

        return is_numeric($value) ? (int) $value : null;
    }

    /** What to put into the inventory so it carries the LabMan value for a field. @param array<string, mixed> $info */
    private function writeField(Inventory $edited, string $host, string $field, Server $server, array $info): string
    {
        switch ($field) {
            case 'address':
                $same = strcasecmp($host, $server->address) === 0;
                $edited->setHostVar($host, 'ansible_host', $same ? null : $server->address);
                $edited->setHostVar($host, 'ansible_ssh_host', null);

                return "Set ansible_host of {$host} to {$server->address}";
            case 'ssh_user':
                $edited->setHostVar($host, 'ansible_user', $server->ssh_user ?: null);
                $edited->setHostVar($host, 'ansible_ssh_user', null);

                return $server->ssh_user ? "Set ansible_user of {$host} to {$server->ssh_user}" : "Remove ansible_user from {$host}";
            case 'ssh_port':
                $port = (int) $server->ssh_port ?: 22;
                $edited->setHostVar($host, 'ansible_port', $port === 22 ? null : $port);
                $edited->setHostVar($host, 'ansible_ssh_port', null);

                return $port === 22 ? "Remove ansible_port from {$host} (default 22)" : "Set ansible_port of {$host} to {$port}";
            case 'group':
                $group = Slug::group((string) $server->group);
                $edited->addToGroup($host, $group);

                return "Add {$host} to group {$group}";
        }

        throw new InventoryException("Unknown field '{$field}'.");
    }

    private function addServerToInventory(Inventory $edited, Server $server, string $name): void
    {
        $vars = [];
        if (strcasecmp($name, $server->address) !== 0) {
            $vars['ansible_host'] = $server->address;
        }
        if ($server->ssh_user) {
            $vars['ansible_user'] = $server->ssh_user;
        }
        if (((int) $server->ssh_port ?: 22) !== 22) {
            $vars['ansible_port'] = (int) $server->ssh_port;
        }

        $edited->addHost($name, $vars, $server->group ? Slug::group($server->group) : null);
    }

    /** An inventory name for a LabMan server that does not collide with an existing host. */
    private function freeHostName(Server $server, Inventory $inventory): string
    {
        $existing = array_map('mb_strtolower', array_keys($inventory->hosts()));
        $base = Slug::host($server->name);
        $name = $base;

        for ($i = 2; in_array(mb_strtolower($name), $existing, true); $i++) {
            $name = "{$base}-{$i}";
        }

        return $name;
    }

    /** What the setup form for an inventory-only host starts with. @param array<string, mixed> $info @return array<string, mixed> */
    private function suggest(string $host, array $info): array
    {
        $vars = $info['vars'];
        $user = $this->user($vars);

        return [
            'name' => $host,
            'address' => $this->address($host, $vars) ?? $host,
            'group' => $info['direct'][0] ?? null,
            'ssh_user' => $user === false ? null : $user,
            'ssh_port' => $this->port($vars) ?? 22,
        ];
    }

    /** @param array<string, mixed> $info @return list<string> */
    private function hostNotes(array $info): array
    {
        $notes = [];
        if ($this->address('x', $info['vars']) === null) {
            $notes[] = 'Its address is an Ansible template, so LabMan cannot read it. Enter the address yourself.';
        }
        if ($this->user($info['vars']) === false) {
            $notes[] = 'Its user is an Ansible template and was not copied.';
        }
        if (isset($info['vars']['ansible_connection']) && $info['vars']['ansible_connection'] !== 'ssh') {
            $notes[] = 'Connects with "'.$info['vars']['ansible_connection'].'", not SSH.';
        }

        return $notes;
    }

    /** @return array{from: string, to: string} */
    private function conversion(): array
    {
        $path = $this->config['path'];
        $dir = pathinfo($path, PATHINFO_DIRNAME);
        $name = pathinfo($path, PATHINFO_FILENAME);

        return ['from' => $path, 'to' => ($dir === '.' ? '' : $dir.'/').$name.'.yml'];
    }

    /** @param array<string, mixed> $data @return array<string, mixed> validated server payload @throws InventoryException */
    private function validated(string $what, array $data, ?Server $existing = null): array
    {
        try {
            return ServerInput::validate($data, $existing);
        } catch (ValidationException $e) {
            throw new InventoryException("{$what}: ".implode(' ', $e->validator->errors()->all()));
        }
    }
}
