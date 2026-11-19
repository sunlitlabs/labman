<?php

namespace App\Npm;

use App\Domain\ServiceInput;
use App\Models\Server;
use App\Models\Service;
use App\Support\Domains;
use App\Support\Hosts;
use App\Support\Settings;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * Two-way sync between LabMan services and Nginx Proxy Manager proxy hosts, always
 * in two steps: a PLAN (read-only, lists every change) and an APPLY of the items the
 * admin ticked. Each plan item carries a fingerprint of what was previewed; apply
 * recomputes the plan and refuses any item whose fingerprint no longer matches, so
 * nothing is ever written that the admin did not see.
 *
 * Mapping: a service is linked to a proxy host by `npm_host_id` (set by import/push),
 * else by its domain appearing in the host's domain names.
 *   import  NPM -> LabMan: creates/updates services (scheme, backend, port, websockets, link)
 *   push    LabMan -> NPM: updates/creates proxy hosts (domain, scheme, forward host/port, websockets)
 * LabMan never deletes anything in NPM, and never deletes services during an import.
 */
final class NpmSync
{
    public function __construct(private readonly NpmClient $client) {}

    // ====================================================================== import

    /** @return list<array<string, mixed>> plan items (NPM -> LabMan) */
    public function importPlan(): array
    {
        $hosts = $this->client->proxyHosts();
        $services = Service::with('server')->get();
        $servers = Server::all();
        $usedNames = $services->pluck('name')->map(fn ($n) => mb_strtolower($n))->all();

        $items = [];
        foreach ($hosts as $host) {
            $items[] = $this->importItem($host, $services, $servers, $usedNames);
        }

        return $items;
    }

    /**
     * @param  array<string, string>  $selected  item key => fingerprint shown in the preview
     * @return array{applied: int, results: list<array<string, string>>}
     */
    public function applyImport(array $selected): array
    {
        $results = [];
        $applied = 0;

        foreach ($this->selectedItems($this->importPlan(), $selected, ['create', 'update']) as $item) {
            try {
                $existing = $item['data']['service_id'] ? Service::find($item['data']['service_id']) : null;
                $payload = ServiceInput::validate($item['data']['input'], $existing);
                $payload[$item['data']['link_field']] = $item['data']['host_id'];

                ($existing ?? new Service)->fill($payload)->save();

                $applied++;
                $results[] = ['title' => $item['title'], 'outcome' => 'applied', 'message' => $item['action'] === 'create' ? 'Service created.' : 'Service updated.'];
            } catch (ValidationException $e) {
                $results[] = ['title' => $item['title'], 'outcome' => 'failed', 'message' => implode(' ', $e->validator->errors()->all())];
            }
        }

        return ['applied' => $applied, 'results' => $results];
    }

    /** @param array<string, mixed> $host */
    private function importItem(array $host, Collection $services, Collection $servers, array &$usedNames): array
    {
        $id = (int) ($host['id'] ?? 0);
        $domains = array_values(array_map(fn ($d) => mb_strtolower((string) $d), (array) ($host['domain_names'] ?? [])));
        $usable = array_values(array_filter($domains, fn ($d) => Hosts::isHostname($d)));
        $title = $domains ? implode(', ', $domains) : "proxy host #{$id}";
        $base = ['key' => "host:{$id}", 'title' => $title, 'host_id' => $id];

        $forwardHost = trim((string) ($host['forward_host'] ?? ''));
        $port = (int) ($host['forward_port'] ?? 0);
        $scheme = in_array($host['forward_scheme'] ?? null, ['http', 'https'], true) ? $host['forward_scheme'] : 'http';

        if ($usable === []) {
            return $this->finish($base + ['action' => 'skip'], ['No usable domain name (wildcards are not supported).']);
        }
        if (! Hosts::isHostOrIp($forwardHost) || ! Hosts::isPort($port)) {
            return $this->finish($base + ['action' => 'skip'], ["Forward target '{$forwardHost}:{$port}' is not a valid host and port."]);
        }

        $service = $services->first(fn (Service $s) => $s->npm_host_id === $id || $s->npm_public_host_id === $id)
            ?? $services->first(fn (Service $s) => ($s->domain && in_array(mb_strtolower($s->domain), $usable, true))
                || ($s->public_domain && in_array(mb_strtolower($s->public_domain), $usable, true)));

        // Is this the local-domain host or the public-domain host? Evidence on the service first, then the configured domains.
        $scope = $this->scopeOf($usable[0], $service, $id);

        // Nothing matched yet: the same app under the other domain (wiki.<local> vs wiki.<public>) belongs to the same service.
        if ($service === null && Domains::scope($usable[0]) !== null) {
            $label = Domains::label($usable[0]);
            $service = $services->first(fn (Service $s) => ($scope === 'public' ? ($s->public_domain === null && $s->npm_public_host_id === null) : ($s->domain === null && $s->npm_host_id === null))
                && (($s->domain && Domains::label($s->domain) === $label) || ($s->public_domain && Domains::label($s->public_domain) === $label) || mb_strtolower($s->name) === $label));
        }
        $domainField = $scope === 'public' ? 'public_domain' : 'domain';
        $linkField = $scope === 'public' ? 'npm_public_host_id' : 'npm_host_id';

        $server = $servers->first(fn (Server $s) => strcasecmp($s->address, $forwardHost) === 0);
        $websockets = (bool) ($host['allow_websocket_upgrade'] ?? false);
        $warnings = [];
        if (count($usable) > 1) {
            $warnings[] = 'Only "'.$usable[0].'" is used; other names on this host are not imported: '.implode(', ', array_slice($usable, 1)).'.';
        }
        if (count($usable) < count($domains)) {
            $warnings[] = 'Wildcard or invalid names on this host are ignored.';
        }
        if (isset($host['enabled']) && ! $host['enabled']) {
            $warnings[] = 'This host is disabled in NPM, so the public check is off for the imported service.';
        }

        $backendLabel = fn (?int $serverId, ?string $address) => $serverId ? 'server '.optional($servers->firstWhere('id', $serverId))->name : ($address ?: 'none');

        if ($service === null) {
            $name = $this->freeName($usable[0], $usedNames, $id);
            $input = [
                'name' => $name, $domainField => $usable[0], 'scheme' => $scheme, 'port' => $this->storedPort($scheme, $port),
                'server_id' => $server?->id, 'address' => $server ? null : $forwardHost,
                'websockets' => $websockets, 'public_check' => (bool) ($host['enabled'] ?? true),
                'notes' => "Imported from Nginx Proxy Manager host #{$id}",
            ];

            $changes = [
                ['field' => 'name', 'from' => null, 'to' => $name],
                ['field' => $domainField, 'from' => null, 'to' => $usable[0]],
                ['field' => 'backend', 'from' => null, 'to' => "{$scheme}://".$backendLabel($server?->id, $forwardHost).":{$port}"],
                ['field' => 'websockets', 'from' => null, 'to' => $websockets ? 'on' : 'off'],
            ];

            return $this->finish($base + ['action' => 'create', 'changes' => $changes, 'data' => ['service_id' => null, 'host_id' => $id, 'link_field' => $linkField, 'scope' => $scope, 'input' => $input]], $warnings);
        }

        // Existing service: touch only the NPM-owned fields.
        $input = ['scheme' => $scheme, 'port' => $this->storedPort($scheme, $port), 'server_id' => $server?->id, 'address' => $server ? null : $forwardHost, 'websockets' => $websockets];
        $changes = [];

        $linked = $service->{$linkField};
        if ($linked !== $id) {
            $changes[] = ['field' => 'link', 'from' => $linked ? "NPM host #{$linked}" : 'not linked', 'to' => "NPM host #{$id}"];
        }
        $currentName = $service->{$domainField};
        if (! $currentName || ! in_array(mb_strtolower($currentName), $usable, true)) {
            $input[$domainField] = $usable[0];
            $changes[] = ['field' => $domainField, 'from' => $currentName, 'to' => $usable[0]];
        }
        if ($service->scheme !== $scheme) {
            $changes[] = ['field' => 'scheme', 'from' => $service->scheme, 'to' => $scheme];
        }
        if ($service->effectivePort() !== $port) {
            $changes[] = ['field' => 'port', 'from' => $service->effectivePort(), 'to' => $port];
        }
        $currentBackend = mb_strtolower((string) $service->backendAddress());
        if ($currentBackend !== mb_strtolower($forwardHost)) {
            $changes[] = ['field' => 'backend', 'from' => $backendLabel($service->server_id, $service->address).' ('.($service->backendAddress() ?? 'no address').')', 'to' => $backendLabel($server?->id, $forwardHost).' ('.$forwardHost.')'];
        }
        if ($service->websockets !== $websockets) {
            $changes[] = ['field' => 'websockets', 'from' => $service->websockets ? 'on' : 'off', 'to' => $websockets ? 'on' : 'off'];
        }

        $base['title'] = $service->name.' ← '.$title;

        return $this->finish($base + [
            'action' => $changes ? 'update' : 'unchanged',
            'changes' => $changes,
            'data' => ['service_id' => $service->id, 'host_id' => $id, 'link_field' => $linkField, 'scope' => $scope, 'input' => $input],
        ], $warnings);
    }

    // ======================================================================== push

    /** @return list<array<string, mixed>> plan items (LabMan -> NPM) */
    public function pushPlan(): array
    {
        $hosts = collect($this->client->proxyHosts());
        $items = [];

        $services = Service::with('server')->where(fn ($q) => $q->whereNotNull('domain')->orWhereNotNull('public_domain'))->orderBy('name')->get();

        foreach ($services as $service) {
            foreach (['local' => $service->domain, 'public' => $service->public_domain] as $scope => $name) {
                if (filled($name)) {
                    $items[] = $this->pushItem($service, $hosts, $scope);
                }
            }
        }

        return $items;
    }

    /**
     * @param  array<string, string>  $selected  item key => fingerprint shown in the preview
     * @return array{applied: int, results: list<array<string, string>>}
     */
    public function applyPush(array $selected): array
    {
        $results = [];
        $applied = 0;

        foreach ($this->selectedItems($this->pushPlan(), $selected, ['create', 'update']) as $item) {
            try {
                $data = $item['data'];

                if ($item['action'] === 'create') {
                    $created = $this->client->createProxyHost($data['payload']);
                    $hostId = (int) ($created['id'] ?? 0);
                } else {
                    $this->client->updateProxyHost($data['host_id'], $data['payload']);
                    $hostId = $data['host_id'];
                }

                if ($hostId > 0) {
                    Service::whereKey($data['service_id'])->update([$data['link_field'] => $hostId]);
                }

                $applied++;
                $results[] = ['title' => $item['title'], 'outcome' => 'applied', 'message' => $item['action'] === 'create' ? 'Proxy host created in NPM.' : 'Proxy host updated in NPM.'];
            } catch (NpmException $e) {
                $results[] = ['title' => $item['title'], 'outcome' => 'failed', 'message' => $e->getMessage()];
            }
        }

        return ['applied' => $applied, 'results' => $results];
    }

    /** @param Collection<int, array<string, mixed>> $hosts */
    private function pushItem(Service $service, Collection $hosts, string $scope = 'local'): array
    {
        $public = $scope === 'public';
        $domain = mb_strtolower((string) ($public ? $service->public_domain : $service->domain));
        $linkField = $public ? 'npm_public_host_id' : 'npm_host_id';
        $linkedId = $service->{$linkField};
        $base = ['key' => $public ? "svc:{$service->id}:public" : "svc:{$service->id}", 'title' => $service->name.' → '.$domain];
        $address = $service->backendAddress();

        if ($address === null) {
            return $this->finish($base + ['action' => 'skip'], ['The service has no address, so there is nothing to forward to.']);
        }

        $port = $service->effectivePort();
        $desired = [
            'forward_scheme' => $service->scheme,
            'forward_host' => $address,
            'forward_port' => $port,
            'allow_websocket_upgrade' => (bool) $service->websockets,
        ];

        $host = $hosts->first(fn ($h) => $linkedId !== null && (int) $h['id'] === $linkedId)
            ?? $hosts->first(fn ($h) => in_array($domain, array_map('mb_strtolower', (array) ($h['domain_names'] ?? [])), true));

        if ($host === null) {
            // The certificate chosen for this kind of domain (Settings > Domains), so a new host is not left without SSL.
            $cert = (int) Settings::get('npm_cert_'.$scope);
            $payload = $desired + [
                'domain_names' => [$domain],
                'certificate_id' => $cert, 'ssl_forced' => $cert > 0, 'http2_support' => $cert > 0, 'hsts_enabled' => false, 'hsts_subdomains' => false,
                'block_exploits' => true, 'caching_enabled' => false, 'access_list_id' => 0, 'advanced_config' => '', 'enabled' => true,
                'meta' => new \stdClass, 'locations' => [],
            ];

            $changes = [['field' => 'proxy host', 'from' => null, 'to' => "{$domain} → {$service->scheme}://{$address}:{$port}"]];

            return $this->finish($base + [
                'action' => 'create', 'changes' => $changes,
                'data' => ['service_id' => $service->id, 'link_field' => $linkField, 'scope' => $scope, 'payload' => $payload],
            ], [$cert > 0
                ? "No proxy host for this domain exists yet. A new one is created with the {$scope}-domain certificate chosen in Settings (#{$cert}), forcing SSL."
                : 'No proxy host for this domain exists yet. A new one is created without SSL; add a certificate in NPM afterwards (or pick one under Settings > Domains so new hosts get it).']);
        }

        $hostId = (int) $host['id'];
        $payload = [];
        $changes = [];
        $current = [
            'forward_scheme' => $host['forward_scheme'] ?? null,
            'forward_host' => $host['forward_host'] ?? null,
            'forward_port' => (int) ($host['forward_port'] ?? 0),
            'allow_websocket_upgrade' => (bool) ($host['allow_websocket_upgrade'] ?? false),
        ];

        foreach ($desired as $field => $value) {
            $differs = $field === 'forward_host' ? strcasecmp((string) $current[$field], (string) $value) !== 0 : $current[$field] !== $value;
            if ($differs) {
                $payload[$field] = $value;
                $changes[] = ['field' => $field, 'from' => is_bool($current[$field]) ? ($current[$field] ? 'on' : 'off') : $current[$field], 'to' => is_bool($value) ? ($value ? 'on' : 'off') : $value];
            }
        }

        $names = array_values((array) ($host['domain_names'] ?? []));
        if (! in_array($domain, array_map('mb_strtolower', $names), true)) {
            $payload['domain_names'] = array_merge($names, [$domain]);
            $changes[] = ['field' => 'domain_names', 'from' => implode(', ', $names), 'to' => implode(', ', $payload['domain_names'])];
        }

        $warnings = [];
        if (count($names) > 1) {
            $warnings[] = 'This proxy host serves several names ('.implode(', ', $names).'); changes apply to all of them.';
        }

        return $this->finish($base + [
            'action' => $changes ? 'update' : 'unchanged',
            'changes' => $changes,
            'data' => ['service_id' => $service->id, 'host_id' => $hostId, 'link_field' => $linkField, 'scope' => $scope, 'payload' => $payload, 'modified_on' => $host['modified_on'] ?? null],
        ], $warnings);
    }

    // ================================================================== helpers

    /**
     * Keep only items the admin selected whose fingerprint still matches the preview.
     *
     * @param  list<array<string, mixed>>  $plan
     * @param  array<string, string>  $selected
     * @param  list<string>  $actions
     * @return list<array<string, mixed>>
     *
     * @throws NpmException
     */
    private function selectedItems(array $plan, array $selected, array $actions): array
    {
        $chosen = [];
        $byKey = collect($plan)->keyBy('key');

        foreach ($selected as $key => $fingerprint) {
            $item = $byKey->get($key);

            if ($item === null || ! in_array($item['action'], $actions, true) || ! hash_equals($item['fingerprint'], (string) $fingerprint)) {
                throw new NpmException("'{$key}' changed since the preview was shown (in LabMan or in NPM), so nothing was applied. Run the preview again.");
            }

            $chosen[] = $item;
        }

        return $chosen;
    }

    /** @param array<string, mixed> $item @param list<string> $warnings */
    private function finish(array $item, array $warnings): array
    {
        // Note: items are built with `$base + [...]`, where the left side wins, so $base must not predefine these.
        $item['changes'] ??= [];
        $item['warnings'] = array_merge($item['warnings'] ?? [], $warnings);
        $item['fingerprint'] = sha1(json_encode([$item['key'], $item['action'], $item['changes'], $item['data'] ?? null]));

        return $item;
    }

    /** 'local' or 'public' for a proxy host's name: what is known about the service first, then the configured domains. */
    private function scopeOf(string $domain, ?Service $service, int $hostId): string
    {
        if ($service !== null) {
            if ($service->npm_public_host_id === $hostId) {
                return 'public';
            }
            if ($service->npm_host_id === $hostId) {
                return 'local';
            }
            if ($service->public_domain && mb_strtolower($service->public_domain) === $domain) {
                return 'public';
            }
            if ($service->domain && mb_strtolower($service->domain) === $domain) {
                return 'local';
            }
        }

        return Domains::scope($domain) ?? 'local';
    }

    private function storedPort(string $scheme, int $port): ?int
    {
        return $port === ($scheme === 'https' ? 443 : 80) ? null : $port;
    }

    /** A service name that is not taken: the first label ("Wiki"), else the full domain. */
    private function freeName(string $domain, array &$used, int $hostId): string
    {
        $label = ucfirst(explode('.', $domain)[0]);

        foreach ([$label, $domain, "{$domain} (#{$hostId})"] as $candidate) {
            if (! in_array(mb_strtolower($candidate), $used, true)) {
                $used[] = mb_strtolower($candidate);

                return $candidate;
            }
        }

        return "{$domain} (#{$hostId})";
    }
}
