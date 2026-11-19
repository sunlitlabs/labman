<?php

namespace App\Semaphore;

use App\Inventory\HostMatcher;
use App\Inventory\Inventory;
use App\Inventory\InventoryException;
use App\Inventory\Slug;
use App\Models\Notice;
use App\Models\Server;
use App\Support\Secrets;
use Illuminate\Support\Collection;
use Throwable;

/**
 * Keeps one Semaphore inventory (the host list Semaphore runs playbooks against) lined up with LabMan's servers.
 *
 *  - sync(): LabMan wins for the servers it knows: it adds missing hosts and fixes address / user / port / group.
 *    It never removes a host it did not put there, and never touches hosts that are only in Semaphore.
 *  - watch(): reads the host list now and then, remembers it (so the server list can show matches without a
 *    request to Semaphore) and tells the admins when hosts appear that LabMan does not have.
 *
 * Only static inventories (INI or YAML stored inside Semaphore) can be read and written. A static INI inventory is
 * switched to YAML when LabMan first writes it.
 */
final class HostSync
{
    /** Semaphore inventory type => format of its content. */
    public const TYPES = ['static' => 'x.ini', 'static-yaml' => 'x.yml'];

    private const WATCH_EVERY = 600;

    /** What is waiting to be sent, kept on the application instance so it never leaks between requests (or tests). */
    private static function pending(): \ArrayObject
    {
        if (! app()->bound('labman.semaphore.pending')) {
            app()->instance('labman.semaphore.pending', new \ArrayObject(['queued' => false, 'removals' => []]));
        }

        return app('labman.semaphore.pending');
    }

    // ================================================================ target

    /** @return array{project: int, inventory: int, auto: bool}|null */
    public static function target(): ?array
    {
        $project = (int) Secrets::plain('semaphore.inv_project');
        $inventory = (int) Secrets::plain('semaphore.inv_id');

        return ($project > 0 && $inventory > 0) ? ['project' => $project, 'inventory' => $inventory, 'auto' => Secrets::plain('semaphore.inv_auto') === '1'] : null;
    }

    public static function saveTarget(int $project, int $inventory, bool $auto): void
    {
        $old = self::target();
        if ($old && ($old['project'] !== $project || $old['inventory'] !== $inventory)) {
            self::forgetKnowledge();
        }

        Secrets::putPlain('semaphore.inv_project', (string) $project);
        Secrets::putPlain('semaphore.inv_id', (string) $inventory);
        Secrets::putPlain('semaphore.inv_auto', $auto ? '1' : '0');
    }

    public static function forgetTarget(): void
    {
        Secrets::forget(['semaphore.inv_project', 'semaphore.inv_id', 'semaphore.inv_auto']);
        self::forgetKnowledge();
    }

    private static function forgetKnowledge(): void
    {
        Secrets::forget(['semaphore.hosts', 'semaphore.sync', 'semaphore.seen', 'semaphore.ignored', 'semaphore.watch_at']);
        Notice::resolve('semaphore-hosts');
        Notice::resolve('semaphore-sync');
        Notice::resolve('semaphore-watch');
    }

    public static function auto(): bool
    {
        $t = self::target();

        return $t !== null && $t['auto'] && Connection::exists();
    }

    // ================================================================ reading

    /**
     * The chosen inventory as Semaphore holds it, parsed.
     *
     * @return array{0: array<string, mixed>, 1: Inventory}
     *
     * @throws SemaphoreException
     */
    public static function load(SemaphoreClient $client, int $project, int $id): array
    {
        $found = null;
        foreach ($client->inventories($project) as $inv) {
            if ((int) ($inv['id'] ?? 0) === $id) {
                $found = $inv;
                break;
            }
        }

        if ($found === null) {
            throw new SemaphoreException("Inventory {$id} was not found in project {$project}. Choose the inventory again.");
        }

        $type = (string) ($found['type'] ?? '');
        if (! isset(self::TYPES[$type])) {
            throw new SemaphoreException('The inventory "'.($found['name'] ?? $id)."\" is of type '{$type}'. LabMan can only read and update static inventories (INI or YAML kept inside Semaphore); for a file in git use the Inventory page.");
        }

        try {
            $inventory = trim((string) ($found['inventory'] ?? '')) === '' ? Inventory::empty() : Inventory::parse((string) $found['inventory'], self::TYPES[$type]);
        } catch (InventoryException $e) {
            throw new SemaphoreException('Cannot read the inventory "'.($found['name'] ?? $id).'": '.$e->getMessage());
        }

        return [$found, $inventory];
    }

    /** Static inventories the person can pick, across all projects. @return list<array{project: int, project_name: string, id: int, name: string, type: string, usable: bool}> */
    public static function choices(SemaphoreClient $client): array
    {
        $out = [];
        foreach ($client->projects() as $p) {
            foreach ($client->inventories((int) $p['id']) as $inv) {
                $type = (string) ($inv['type'] ?? '');
                $out[] = ['project' => (int) $p['id'], 'project_name' => (string) ($p['name'] ?? $p['id']), 'id' => (int) $inv['id'], 'name' => (string) ($inv['name'] ?? $inv['id']), 'type' => $type, 'usable' => isset(self::TYPES[$type])];
            }
        }

        return $out;
    }

    // ================================================================== sync

    /**
     * Bring the inventory in line with LabMan's servers.
     *
     * @param  list<string>  $remove  host names to take out (servers that were deleted in LabMan)
     * @return array{changed: bool, added: int, updated: int, removed: int, converted: bool}
     *
     * @throws SemaphoreException
     */
    public static function sync(array $remove = []): array
    {
        $target = self::target() ?? throw new SemaphoreException('Choose which Semaphore inventory to keep up to date first.');
        $client = SemaphoreClient::fromStored();
        [$object, $inventory] = self::load($client, $target['project'], $target['inventory']);

        $servers = Server::query()->orderBy('name')->get();
        $hosts = array_filter($inventory->hosts(), fn ($h) => ! $h['pattern']);
        $pairs = HostMatcher::pair($servers, $hosts);
        $pairedIds = [];
        $links = [];
        $added = $updated = $removed = 0;

        foreach ($pairs as $pair) {
            /** @var Server $server */
            $server = $pair['server'];
            $host = $pair['host'];
            $pairedIds[] = $server->id;
            $touched = self::reconcile($inventory, $host, $hosts[$host], $server);
            $updated += $touched ? 1 : 0;
            if ($server->inventory_host !== $host) {
                $links[$server->id] = $host;
            }
        }

        $existing = array_map('mb_strtolower', array_keys($inventory->hosts()));
        foreach ($servers as $server) {
            if (in_array($server->id, $pairedIds, true)) {
                continue;
            }

            $name = Slug::host($server->name);
            for ($i = 2; in_array(mb_strtolower($name), $existing, true); $i++) {
                $name = Slug::host($server->name).'-'.$i;
            }
            $existing[] = mb_strtolower($name);

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
            $inventory->addHost($name, $vars, $server->group ? Slug::group($server->group) : null);
            $links[$server->id] = $name;
            $added++;
        }

        foreach (array_unique($remove) as $name) {
            if (isset($hosts[$name]) && ! in_array($name, array_values($links), true) && $servers->where('inventory_host', $name)->isEmpty()) {
                $inventory->removeHost($name);
                $removed++;
            }
        }

        $changed = ($added + $updated + $removed) > 0;
        $converted = $changed && ($object['type'] ?? '') === 'static';

        if ($changed) {
            $object['inventory'] = $inventory->dump();
            $object['type'] = 'static-yaml';
            $client->saveInventory($target['project'], $object);
        }

        // Links are stored without model events, so saving them never queues another sync.
        foreach ($links as $id => $host) {
            Server::query()->whereKey($id)->update(['inventory_host' => $host]);
        }

        self::remember($inventory);
        $result = compact('changed', 'added', 'updated', 'removed', 'converted');
        self::record(true, $result);
        Notice::resolve('semaphore-sync');

        return $result;
    }

    /** Make one matched host carry LabMan's values. @param array<string, mixed> $info */
    private static function reconcile(Inventory $inventory, string $host, array $info, Server $server): bool
    {
        $touched = false;
        $vars = $info['vars'];

        $address = HostMatcher::address($host, $vars);
        if ($address !== null && strcasecmp($address, $server->address) !== 0) {
            $inventory->setHostVar($host, 'ansible_host', strcasecmp($host, $server->address) === 0 ? null : $server->address);
            $inventory->setHostVar($host, 'ansible_ssh_host', null);
            $touched = true;
        }

        $user = $vars['ansible_user'] ?? $vars['ansible_ssh_user'] ?? null;
        if ($server->ssh_user && $user !== $server->ssh_user && ! str_contains((string) $user, '{{')) {
            $inventory->setHostVar($host, 'ansible_user', $server->ssh_user);
            $inventory->setHostVar($host, 'ansible_ssh_user', null);
            $touched = true;
        }

        $port = $vars['ansible_port'] ?? $vars['ansible_ssh_port'] ?? 22;
        $mine = (int) $server->ssh_port ?: 22;
        if (is_numeric($port) && (int) $port !== $mine) {
            $inventory->setHostVar($host, 'ansible_port', $mine === 22 ? null : $mine);
            $inventory->setHostVar($host, 'ansible_ssh_port', null);
            $touched = true;
        }

        if ($server->group) {
            $in = array_map('mb_strtolower', $info['groups']);
            if (! in_array(mb_strtolower($server->group), $in, true) && ! in_array(mb_strtolower(Slug::group($server->group)), $in, true)) {
                $inventory->addToGroup($host, Slug::group($server->group));
                $touched = true;
            }
        }

        return $touched;
    }

    // ============================================================ auto update

    /** A server was saved or deleted: update Semaphore once, after the response has gone out. */
    public static function queue(?string $removeHost = null): void
    {
        if (! self::auto()) {
            return;
        }

        if ($removeHost !== null && $removeHost !== '') {
            self::pending()['removals'] = [...self::pending()['removals'], $removeHost];
        }

        if (! self::pending()['queued']) {
            self::pending()['queued'] = true;
            app()->terminating(fn () => self::flush());
        }
    }

    public static function flush(): void
    {
        $remove = self::pending()['removals'];
        self::pending()->exchangeArray(['queued' => false, 'removals' => []]);

        if (! self::auto()) {
            return;
        }

        try {
            self::sync($remove);
        } catch (Throwable $e) {
            self::failed($e);
        }
    }

    private static function failed(Throwable $e): void
    {
        $message = $e instanceof SemaphoreException ? $e->getMessage() : 'Unexpected error: '.$e->getMessage();
        self::record(false, [], $message);
        Notice::raise('semaphore-sync', 'warning', 'Semaphore\'s host list was not updated', $message, route('semaphore.index'));
    }

    // ================================================================ memory

    /** @return array{at: string, hosts: array<string, array{vars: array<string, mixed>, groups: list<string>, pattern: bool}>}|null */
    public static function known(): ?array
    {
        $data = json_decode((string) Secrets::plain('semaphore.hosts'), true);

        return is_array($data) && isset($data['hosts'], $data['at']) ? $data : null;
    }

    private static function remember(Inventory $inventory): void
    {
        $hosts = [];
        foreach ($inventory->hosts() as $name => $info) {
            $keep = array_intersect_key($info['vars'], array_flip(['ansible_host', 'ansible_ssh_host']));
            $hosts[$name] = ['vars' => $keep, 'groups' => $info['groups'], 'pattern' => $info['pattern']];
        }

        Secrets::putPlain('semaphore.hosts', json_encode(['at' => now()->toIso8601String(), 'hosts' => $hosts]));
    }

    /** server id => the host's name in Semaphore, for the servers that have one; null when the host list is not known. @param Collection<int, Server> $servers @return array<int, string>|null */
    public static function pairings(Collection $servers): ?array
    {
        $known = self::known();
        if ($known === null) {
            return null;
        }

        $hosts = array_filter($known['hosts'], fn ($h) => ! $h['pattern']);

        $out = [];
        foreach (HostMatcher::pair($servers, $hosts) as $pair) {
            $out[$pair['server']->id] = $pair['host'];
        }

        return $out;
    }

    /** Hosts in Semaphore that no LabMan server matches (and nobody chose to ignore). @return array<string, array{vars: array<string, mixed>, groups: list<string>, pattern: bool}> */
    public static function strangers(): array
    {
        $known = self::known();
        if ($known === null) {
            return [];
        }

        $hosts = array_filter($known['hosts'], fn ($h) => ! $h['pattern']);
        $matched = array_column(HostMatcher::pair(Server::query()->orderBy('name')->get(), $hosts), 'host');
        $ignored = self::names('semaphore.ignored');

        return array_filter($hosts, fn ($h, $name) => ! in_array($name, $matched, true) && ! in_array($name, $ignored, true), ARRAY_FILTER_USE_BOTH);
    }

    public static function ignore(array $names): void
    {
        Secrets::putPlain('semaphore.ignored', json_encode(array_values(array_unique([...self::names('semaphore.ignored'), ...$names]))));
        self::settleNotice();
    }

    /** @return list<string> */
    private static function names(string $key): array
    {
        $data = json_decode((string) Secrets::plain($key), true);

        return is_array($data) ? array_values(array_map('strval', $data)) : [];
    }

    /** @return array{ok: bool, at: string, added: int, updated: int, removed: int, message: ?string}|null */
    public static function lastSync(): ?array
    {
        $data = json_decode((string) Secrets::plain('semaphore.sync'), true);

        return is_array($data) && isset($data['ok'], $data['at']) ? $data : null;
    }

    /** @param array<string, mixed> $result */
    private static function record(bool $ok, array $result, ?string $message = null): void
    {
        Secrets::putPlain('semaphore.sync', json_encode([
            'ok' => $ok, 'at' => now()->toIso8601String(), 'added' => (int) ($result['added'] ?? 0), 'updated' => (int) ($result['updated'] ?? 0),
            'removed' => (int) ($result['removed'] ?? 0), 'message' => $message ? mb_substr($message, 0, 500) : null,
        ]));
    }

    // ================================================================= watch

    /**
     * Read the host list (at most every ten minutes unless forced) and tell the admins about hosts LabMan does not know.
     *
     * @return list<string>|null the names of the hosts that are new since the last look, or null when nothing was checked
     */
    public static function watch(bool $force = false): ?array
    {
        if (self::target() === null || ! Connection::exists()) {
            return null;
        }

        $last = (int) Secrets::plain('semaphore.watch_at');
        if (! $force && $last > 0 && (time() - $last) < self::WATCH_EVERY) {
            return null;
        }
        Secrets::putPlain('semaphore.watch_at', (string) time());

        try {
            $target = self::target();
            [, $inventory] = self::load(SemaphoreClient::fromStored(), $target['project'], $target['inventory']);
        } catch (SemaphoreException $e) {
            Notice::raise('semaphore-watch', 'warning', 'Cannot read Semaphore\'s host list', $e->getMessage(), route('semaphore.index'));

            return null;
        }

        Notice::resolve('semaphore-watch');
        self::remember($inventory);

        $strangers = array_keys(self::strangers());
        $seen = self::names('semaphore.seen');
        $new = array_values(array_diff($strangers, $seen));

        if ($new !== []) {
            Secrets::putPlain('semaphore.seen', json_encode(array_values(array_unique([...$seen, ...$strangers]))));
            Notice::raise('semaphore-hosts', 'hosts', count($strangers).' host(s) in Semaphore that LabMan does not have', implode(', ', array_slice($strangers, 0, 12)).(count($strangers) > 12 ? ', …' : ''), route('semaphore.hosts'));
        } else {
            self::settleNotice();
        }

        return $new;
    }

    /** Keep the host notice true: drop it when nothing is left to add, refresh its list otherwise. */
    public static function settleNotice(): void
    {
        $strangers = array_keys(self::strangers());

        if ($strangers === []) {
            Notice::resolve('semaphore-hosts');

            return;
        }

        Notice::query()->where('key', 'semaphore-hosts')->update([
            'title' => count($strangers).' host(s) in Semaphore that LabMan does not have',
            'body' => implode(', ', array_slice($strangers, 0, 12)).(count($strangers) > 12 ? ', …' : ''),
        ]);
    }
}
