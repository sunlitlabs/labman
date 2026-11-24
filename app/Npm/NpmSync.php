<?php

namespace App\Npm;

use App\Domain\ServiceInput;
use App\Models\Server;
use App\Models\Mirror;
use App\Models\Service;
use App\Models\Zone;
use App\Support\Domains;
use App\Support\Hosts;
use App\Support\Settings;
use App\Support\Zones;
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
    /** The zone this sync is for: one proxy serves the services of one zone. None means the default zone. */
    private readonly Zone $zone;

    public function __construct(private readonly NpmClient $client, ?Zone $zone = null)
    {
        $this->zone = $zone ?? Zones::default() ?? throw new NpmException('There is no zone to sync.');
    }

    public function zone(): Zone
    {
        return $this->zone;
    }

    // ====================================================================== import

    /** @return list<array<string, mixed>> plan items (NPM -> LabMan) */
    public function importPlan(): array
    {
        $hosts = $this->client->proxyHosts();
        $services = Zones::servicesIn($this->zone)->with('server')->get();
        $servers = Zones::serversIn($this->zone)->get();
        $usedNames = $services->pluck('name')->map(fn ($n) => mb_strtolower($n))->all();

        $mirrored = Mirror::query()->where('zone_id', $this->zone->id)->whereNotNull('npm_host_id')->pluck('service_name', 'npm_host_id');

        $items = [];
        foreach ($hosts as $host) {
            if (isset($mirrored[(int) ($host['id'] ?? 0)])) {
                $items[] = $this->finish(['key' => 'host:'.(int) $host['id'], 'title' => implode(', ', (array) ($host['domain_names'] ?? [])), 'host_id' => (int) $host['id'], 'action' => 'skip'], ['This is a mirror LabMan manages (of '.$mirrored[(int) $host['id']].'), not a service.']);

                continue;
            }
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

                // A service made from this proxy's host belongs to this proxy's zone: through its server when that is
                // in the zone, otherwise by naming the zone itself.
                if ($existing === null) {
                    $server = ($payload['server_id'] ?? null) ? Server::find($payload['server_id']) : null;
                    $effective = $server !== null ? ($server->zone_id ?? Zones::defaultId()) : Zones::defaultId();
                    if ($effective !== $this->zone->id) {
                        $payload['zone_id'] = $this->zone->id;
                    }
                }

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
        if ($service === null && $this->zone->scopeOf($usable[0]) !== null) {
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

    /**
     * @param  list<int>|null  $only  only these services (null = all)
     * @param  bool  $auto  for automatic updates: never request a certificate, and leave a host's own working certificate alone
     * @return list<array<string, mixed>> plan items (LabMan -> NPM)
     */
    public function pushPlan(?array $only = null, bool $auto = false): array
    {
        $hosts = collect($this->client->proxyHosts());
        $items = [];

        $services = Zones::servicesIn($this->zone)->with('server')->where(fn ($q) => $q->whereNotNull('domain')->orWhereNotNull('public_domain'))
            ->when($only !== null, fn ($q) => $q->whereIn('id', $only))->orderBy('name')->get();

        foreach ($services as $service) {
            foreach (['local' => $service->domain, 'public' => $service->public_domain] as $scope => $name) {
                if (filled($name)) {
                    $items[] = $this->pushItem($service, $hosts, $scope, $auto);
                }
            }
        }

        return [...$items, ...$this->mirrorItems($hosts, $only, $auto)];
    }

    /**
     * @param  array<string, string>  $selected  item key => fingerprint shown in the preview
     * @return array{applied: int, results: list<array<string, string>>}
     */
    public function applyPush(array $selected): array
    {
        $results = [];
        $applied = 0;

        $requested = [];

        foreach ($this->selectedItems($this->pushPlan(), $selected, ['create', 'update']) as $item) {
            try {
                $data = $item['data'];
                $note = '';

                // A certificate is requested here and nowhere else: only for an item that was previewed and ticked, and once per set of names.
                if (! empty($data['cert_request'])) {
                    $req = $data['cert_request'];
                    $policy = $this->zone->certificatePolicy();
                    $key = $req['method'].'|'.implode(',', $req['domains']);
                    $certId = $requested[$key] ??= $this->client->requestCertificate($req['domains'], (string) $policy->email, $req['method'] === 'letsencrypt_dns' ? $policy->dnsTemplate : null);
                    $data['payload']['certificate_id'] = $certId;
                    if ($req['ssl']) {
                        $data['payload'] = array_merge($data['payload'], ['ssl_forced' => true, 'http2_support' => true]);
                    }
                    $this->certs = false; // the list changed
                    $note = " Requested certificate #{$certId} for ".implode(', ', $req['domains']).'.';
                }

                if ($item['action'] === 'create') {
                    $created = $this->client->createProxyHost($data['payload']);
                    $hostId = (int) ($created['id'] ?? 0);
                } else {
                    $this->client->updateProxyHost($data['host_id'], $data['payload']);
                    $hostId = $data['host_id'];
                }

                if (! empty($data['mirror'])) {
                    // LabMan's record of the mirror, so a later preview can tell drift and orphans apart.
                    Mirror::updateOrCreate(['zone_id' => $this->zone->id, 'domain' => $data['mirror']['domain']], [
                        'service_id' => $data['service_id'], 'service_name' => $data['mirror']['service'], 'npm_host_id' => $hostId ?: null, 'upstream' => $data['mirror']['upstream'],
                    ]);
                } elseif ($hostId > 0) {
                    Service::whereKey($data['service_id'])->update([$data['link_field'] => $hostId]);
                }

                $applied++;
                $results[] = ['title' => $item['title'], 'outcome' => 'applied', 'message' => (! empty($data['mirror']) ? ($item['action'] === 'create' ? 'Mirror created in NPM.' : 'Mirror updated in NPM.') : ($item['action'] === 'create' ? 'Proxy host created in NPM.' : 'Proxy host updated in NPM.')).$note];
            } catch (NpmException $e) {
                $results[] = ['title' => $item['title'], 'outcome' => 'failed', 'message' => $e->getMessage()];
            }
        }

        return ['applied' => $applied, 'results' => $results];
    }

    /** @param Collection<int, array<string, mixed>> $hosts */
    private function pushItem(Service $service, Collection $hosts, string $scope = 'local', bool $auto = false): array
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
            // The certificate: one that already covers the name, else what the zone's policy asks for.
            $decision = $this->decide($service, $domain, $scope, $auto);
            $cert = $decision['mode'] === 'use' ? (int) $decision['certificate_id'] : 0;
            $request = $decision['mode'] === 'request' ? $this->requestData($decision, true) : null;
            $payload = $desired + [
                'domain_names' => [$domain],
                'certificate_id' => $cert, 'ssl_forced' => $cert > 0, 'http2_support' => $cert > 0, 'hsts_enabled' => false, 'hsts_subdomains' => false,
                'block_exploits' => true, 'caching_enabled' => false, 'access_list_id' => 0, 'advanced_config' => '', 'enabled' => true,
                'meta' => new \stdClass, 'locations' => [],
            ];

            $changes = [['field' => 'proxy host', 'from' => null, 'to' => "{$domain} → {$service->scheme}://{$address}:{$port}"]];

            if ($cert > 0 || $request) {
                $changes[] = ['field' => 'certificate', 'from' => null, 'to' => $decision['why']];
            }
            $intro = match (true) {
                $cert > 0 => 'No proxy host for this domain exists yet. A new one is created with '.$decision['why'].', forcing SSL.',
                $request !== null => 'No proxy host for this domain exists yet. A new one is created with '.$decision['why'].'. It is requested when you apply, and SSL is forced.',
                default => 'No proxy host for this domain exists yet. A new one is created without SSL; set a certificate policy for the zone (or pick a certificate) so new hosts get one.',
            };

            return $this->finish($base + [
                'action' => 'create', 'changes' => $changes,
                'data' => ['service_id' => $service->id, 'link_field' => $linkField, 'scope' => $scope, 'payload' => $payload, 'cert_request' => $request],
            ], [$intro, ...$decision['warnings'], ...(($o = $this->orderingWarning($service)) ? [$o] : [])]);
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

        // The certificate: an explicit choice wins; otherwise a host with no certificate (or a broken one) gets one that covers it.
        $decision = $this->decide($service, $domain, $scope, $auto);
        $currentCert = (int) ($host['certificate_id'] ?? 0);
        $known = $currentCert > 0 ? collect($this->certificates() ?? [])->firstWhere('id', $currentCert) : null;
        $problem = $known !== null ? $this->certificateProblems($known, $domain) : [];
        $broken = $known !== null && (! Certificates::coversHost($known, $domain) || Certificates::expired($known) || $this->untrusted($known));
        $mayReplace = $decision['explicit'] || $currentCert === 0 || $broken;
        $mayReplace = $mayReplace && ! ($auto && ! $decision['explicit'] && $currentCert > 0); // automatic updates never swap a host's own certificate
        $from = $currentCert > 0 ? "#{$currentCert}" : 'none';
        $certRequest = null;

        if ($decision['mode'] === 'use' && (int) $decision['certificate_id'] !== $currentCert && $mayReplace) {
            $payload['certificate_id'] = (int) $decision['certificate_id'];
            if ($currentCert === 0) {
                $payload += ['ssl_forced' => true, 'http2_support' => true];
            }
            $changes[] = ['field' => 'certificate', 'from' => $from, 'to' => $decision['why']];
        } elseif ($decision['mode'] === 'request' && $mayReplace && ($currentCert === 0 || $broken)) {
            $certRequest = $this->requestData($decision, $currentCert === 0);
            $changes[] = ['field' => 'certificate', 'from' => $from, 'to' => $decision['why']];
        }

        $names = array_values((array) ($host['domain_names'] ?? []));
        if (! in_array($domain, array_map('mb_strtolower', $names), true)) {
            $payload['domain_names'] = array_merge($names, [$domain]);
            $changes[] = ['field' => 'domain_names', 'from' => implode(', ', $names), 'to' => implode(', ', $payload['domain_names'])];
        }

        // The decision's own notes (a missing certificate, a pin that does not exist), plus what is wrong with the certificate the host has now.
        $ordering = $this->orderingWarning($service);
        $warnings = in_array($decision['mode'], ['none', 'missing'], true) || ($decision['mode'] === 'use' && (int) $decision['certificate_id'] === $currentCert) ? $decision['warnings'] : [];
        $warnings = array_values(array_unique([...$warnings, ...$problem, ...($ordering ? [$ordering] : [])]));
        if (count($names) > 1) {
            $warnings[] = 'This proxy host serves several names ('.implode(', ', $names).'); changes apply to all of them.';
        }

        return $this->finish($base + [
            'action' => $changes ? 'update' : 'unchanged',
            'changes' => $changes,
            'data' => ['service_id' => $service->id, 'host_id' => $hostId, 'link_field' => $linkField, 'scope' => $scope, 'payload' => $payload, 'modified_on' => $host['modified_on'] ?? null, 'cert_request' => $certRequest],
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

        return $this->zone->scopeOf($domain) ?? 'local';
    }

    /**
     * Where every host name of this zone stands on certificates: read-only, nothing is requested or changed.
     * States: ok, expiring, expired, uncovered (the certificate does not cover the name), untrusted (an origin
     * certificate on a directly served proxy), missing (none, but the policy wants one), none (none, by policy),
     * unknown (a certificate id NPM does not list) and no_host (no proxy host yet).
     *
     * @return array{rows: list<array<string, mixed>>, counts: array<string, int>}
     */
    public function certificateReport(): array
    {
        $hosts = collect($this->client->proxyHosts());
        $certs = collect($this->certificates() ?? [])->keyBy('id');
        $rows = [];

        $services = Zones::servicesIn($this->zone)->with('server')->where(fn ($q) => $q->whereNotNull('domain')->orWhereNotNull('public_domain'))->orderBy('name')->get();
        foreach ($services as $service) {
            foreach (['local' => $service->domain, 'public' => $service->public_domain] as $scope => $name) {
                if (! filled($name)) {
                    continue;
                }
                $domain = mb_strtolower($name);
                $linked = $service->{$scope === 'public' ? 'npm_public_host_id' : 'npm_host_id'};
                $host = $hosts->first(fn ($h) => $linked !== null && (int) $h['id'] === $linked)
                    ?? $hosts->first(fn ($h) => in_array($domain, array_map('mb_strtolower', (array) ($h['domain_names'] ?? [])), true));
                $row = ['service' => $service->name, 'domain' => $domain, 'state' => 'no_host', 'detail' => 'No proxy host yet.', 'certificate' => null];

                if ($host !== null) {
                    $id = (int) ($host['certificate_id'] ?? 0);
                    if ($id === 0) {
                        $d = $this->decide($service, $domain, $scope, false);
                        $row['state'] = $d['mode'] === 'none' ? 'none' : 'missing';
                        $row['detail'] = $d['mode'] === 'none' ? 'No certificate, as the policy says.' : 'No certificate. '.($d['mode'] === 'use' ? 'Covered by '.$d['why'].'.' : ($d['mode'] === 'request' ? 'The policy would request '.$d['why'].'.' : ($d['warnings'][0] ?? '')));
                    } elseif (($cert = $certs->get($id)) === null) {
                        $row['state'] = 'unknown';
                        $row['detail'] = "The host uses certificate #{$id}, which NPM does not list.";
                        $row['certificate'] = "#{$id}";
                    } else {
                        $row['certificate'] = Certificates::label($cert);
                        $problems = $this->certificateProblems($cert, $domain);
                        $row['state'] = match (true) {
                            ! Certificates::coversHost($cert, $domain) => 'uncovered',
                            Certificates::expired($cert) => 'expired',
                            $this->untrusted($cert) => 'untrusted',
                            Certificates::expiring($cert) => 'expiring',
                            default => 'ok',
                        };
                        $row['detail'] = $problems ? implode(' ', $problems) : 'Covered until '.($cert['expires_on'] ? substr($cert['expires_on'], 0, 10) : 'an unknown date').'.';
                    }
                }

                $rows[] = $row;
            }
        }

        $counts = array_fill_keys(['ok', 'expiring', 'expired', 'uncovered', 'untrusted', 'missing', 'none', 'unknown', 'no_host'], 0);
        foreach ($rows as $r) {
            $counts[$r['state']]++;
        }

        return ['rows' => $rows, 'counts' => $counts];
    }

    /** Lower zones should not initiate connections to higher ones: warn (never block) when a host forwards upward. */
    private function orderingWarning(Service $service): ?string
    {
        $server = $service->server;
        $serverZone = $server?->zone_id ? Zone::query()->find($server->zone_id) : null;

        return $serverZone !== null && $serverZone->level > $this->zone->level
            ? "The {$this->zone->name} proxy (level {$this->zone->level}) forwards to {$server->name}, which is in the more trusted {$serverZone->name} zone (level {$serverZone->level}). Lower zones should not initiate connections to higher ones."
            : null;
    }

    /** @var array<int, array<int, array<string, mixed>>|null> the proxy hosts of other zones, read at most once each */
    private array $otherHosts = [];

    /** @var array<int, list<array<string, mixed>>|null> */
    private array $otherCerts = [];

    /** Another zone's proxy hosts and certificates, or null when it is not connected or cannot be read (the plan still works). */
    private function otherZone(Zone $zone): ?array
    {
        if (! array_key_exists($zone->id, $this->otherHosts)) {
            try {
                $client = NpmClient::fromStored($zone);
                $this->otherHosts[$zone->id] = $client->proxyHosts();
                $this->otherCerts[$zone->id] = $client->certificates();
            } catch (NpmException) {
                $this->otherHosts[$zone->id] = null;
                $this->otherCerts[$zone->id] = null;
            }
        }

        return $this->otherHosts[$zone->id];
    }

    /**
     * The mirrors this zone's proxy should hold, as plan items: create, update when drifted, in sync, blocked (with
     * the reason) and orphaned (a mirror LabMan made that nothing asks for any more; it is listed, never deleted).
     *
     * @param  Collection<int, array<string, mixed>>  $hosts  this proxy's hosts
     * @param  list<int>|null  $only
     * @return list<array<string, mixed>>
     */
    private function mirrorItems(Collection $hosts, ?array $only, bool $auto): array
    {
        $zones = Zone::query()->get();
        $wanted = Mirrors::wanted($this->zone, $only, $zones);
        $records = Mirror::query()->where('zone_id', $this->zone->id)->get()->keyBy('domain');
        $items = [];

        foreach ($wanted as $domain => $w) {
            $items[] = $this->mirrorItem($w, $hosts, $records->get($domain), $auto);
        }

        // Orphans are only reported for a full plan; a run limited to some services must not list unrelated ones.
        if ($only === null) {
            foreach (Mirrors::orphans($this->zone, $wanted) as $orphan) {
                $why = $orphan->service_id && Service::query()->whereKey($orphan->service_id)->exists()
                    ? 'No mirror rule or publish setting asks for it any more.'
                    : 'Its service no longer exists or no longer has this name.';
                $items[] = $this->finish([
                    'key' => 'mirror-orphan:'.$orphan->id, 'title' => 'Orphaned mirror: '.$orphan->domain.' ('.$orphan->service_name.')',
                    'action' => 'orphan', 'host_id' => $orphan->npm_host_id,
                ], [$why.' LabMan never deletes anything in NPM: remove proxy host '.($orphan->npm_host_id ? '#'.$orphan->npm_host_id : 'for '.$orphan->domain).' in NPM yourself, then forget it here.']);
            }
        }

        return $items;
    }

    /** @param array{service: Service, source: Zone, domain: string, upstream: string} $w @return array<string, mixed> */
    private function mirrorItem(array $w, Collection $hosts, ?Mirror $record, bool $auto): array
    {
        /** @var Service $service */
        $service = $w['service'];
        $source = $w['source'];
        $domain = $w['domain'];
        $base = ['key' => "mirror:{$service->id}:{$domain}", 'title' => "{$domain} ← {$source->name} zone (mirror of {$service->name})"];
        $blocked = fn (string $why) => $this->finish($base + ['action' => 'skip'], [$why]);

        $target = $source->proxy_address;
        if ($target === null || $target === '') {
            return $blocked("Set the proxy address of the {$source->name} zone (what other proxies forward to). Without it there is nothing to forward the mirror to.");
        }
        if (mb_strtolower($target) === $domain) {
            return $blocked("The {$source->name} zone's proxy address is the mirrored name itself, so the mirror would loop. Use an IP address or a name that resolves straight to that proxy.");
        }

        // Another service of this zone already has this name: a mirror would fight it.
        $own = Zones::servicesIn($this->zone)->where(fn ($q) => $q->where('domain', $domain)->orWhere('public_domain', $domain))->first();
        if ($own !== null) {
            return $blocked("{$own->name} in the {$this->zone->name} zone already has this name, so it is not mirrored. Choose which zone owns it.");
        }

        // What to forward to: automatic looks at how the source zone's proxy serves the name.
        $sourceHosts = $this->otherZone($source);
        $sourceHost = $sourceHosts === null ? null : (collect($sourceHosts)->first(fn ($h) => in_array($domain, array_map('mb_strtolower', (array) ($h['domain_names'] ?? [])), true)));
        $sourceCert = $sourceHost !== null && (int) ($sourceHost['certificate_id'] ?? 0) > 0 ? collect($this->otherCerts[$source->id] ?? [])->firstWhere('id', (int) $sourceHost['certificate_id']) : null;
        $choice = Mirrors::chooseUpstream($w['upstream'], $source, $sourceHost, $sourceCert);
        $upstream = $choice['upstream'];
        $port = $upstream === 'http' ? 80 : 443;
        $scheme = $upstream === 'http' ? 'http' : 'https';

        $desired = [
            'forward_scheme' => $scheme, 'forward_host' => $target, 'forward_port' => $port,
            'allow_websocket_upgrade' => (bool) $service->websockets,
            'advanced_config' => Mirrors::advancedConfig($upstream, $source->name),
        ];

        // The mirror's own certificate on this proxy: browser-trusted, never the source's (they are different proxies).
        $decision = CertificateChooser::choose($domain, $this->zone->certificatePolicy(), $this->certificates(), null, 0, $auto, $this->zone->domainNames());
        $warnings = [];
        if ($this->zone->level < $source->level) {
            $warnings[] = "The {$this->zone->name} proxy (level {$this->zone->level}) reaches into the more trusted {$source->name} zone (level {$source->level}). Lower zones should not initiate connections to higher ones.";
        }
        if (! filter_var($target, FILTER_VALIDATE_IP) && ($owner = Zones::owners($target)) !== []) {
            $warnings[] = "The {$source->name} proxy address '{$target}' is a name under a zone domain; if it resolves to this proxy the mirror loops. An IP address is safest.";
        }
        $note = "Forwards to {$target}:{$port} over {$scheme} ({$choice['why']}), keeping the original Host header".($scheme === 'https' ? ' and SNI' : '').'.';

        $host = $hosts->first(fn ($h) => $record?->npm_host_id !== null && (int) $h['id'] === (int) $record->npm_host_id)
            ?? $hosts->first(fn ($h) => in_array($domain, array_map('mb_strtolower', (array) ($h['domain_names'] ?? [])), true));
        $data = ['mirror' => ['domain' => $domain, 'service' => $service->name, 'upstream' => $upstream, 'source_zone_id' => $source->id], 'service_id' => $service->id, 'scope' => 'mirror'];

        if ($host === null) {
            if ($decision['mode'] === 'missing') {
                return $blocked("No browser-trusted certificate is available for {$domain} on the {$this->zone->name} proxy, so the mirror is not created (it would show a certificate warning). ".implode(' ', $decision['warnings']).' Add a wildcard or let the zone request one (zone certificate settings).');
            }
            $cert = $decision['mode'] === 'use' ? (int) $decision['certificate_id'] : 0;
            $request = $decision['mode'] === 'request' ? $this->requestData($decision, true) : null;
            $payload = $desired + [
                'domain_names' => [$domain], 'certificate_id' => $cert, 'ssl_forced' => $cert > 0, 'http2_support' => $cert > 0, 'hsts_enabled' => false, 'hsts_subdomains' => false,
                'block_exploits' => true, 'caching_enabled' => false, 'access_list_id' => 0, 'enabled' => true, 'meta' => new \stdClass, 'locations' => [],
            ];
            $changes = [['field' => 'mirror', 'from' => null, 'to' => "{$domain} → {$scheme}://{$target}:{$port}"]];
            if ($cert > 0 || $request) {
                $changes[] = ['field' => 'certificate', 'from' => null, 'to' => $decision['why']];
            }

            return $this->finish($base + ['action' => 'create', 'changes' => $changes, 'data' => $data + ['payload' => $payload, 'cert_request' => $request]], [$note, ...$warnings, ...$decision['warnings']]);
        }

        // An existing host: drifted when anything LabMan owns about the mirror differs.
        $payload = [];
        $changes = [];
        $current = [
            'forward_scheme' => $host['forward_scheme'] ?? null, 'forward_host' => $host['forward_host'] ?? null,
            'forward_port' => (int) ($host['forward_port'] ?? 0), 'allow_websocket_upgrade' => (bool) ($host['allow_websocket_upgrade'] ?? false),
            'advanced_config' => (string) ($host['advanced_config'] ?? ''),
        ];
        foreach ($desired as $field => $value) {
            $differs = $field === 'forward_host' ? strcasecmp((string) $current[$field], (string) $value) !== 0 : $current[$field] !== $value;
            if ($differs) {
                $payload[$field] = $value;
                $changes[] = ['field' => $field === 'advanced_config' ? 'Host header and SNI settings' : $field, 'from' => is_bool($current[$field]) ? ($current[$field] ? 'on' : 'off') : ($field === 'advanced_config' ? ($current[$field] === '' ? 'none' : 'different') : $current[$field]), 'to' => is_bool($value) ? ($value ? 'on' : 'off') : ($field === 'advanced_config' ? 'managed by LabMan' : $value)];
            }
        }

        $currentCert = (int) ($host['certificate_id'] ?? 0);
        $known = $currentCert > 0 ? collect($this->certificates() ?? [])->firstWhere('id', $currentCert) : null;
        $broken = $known !== null && (! Certificates::coversHost($known, $domain) || Certificates::expired($known) || $this->untrusted($known));
        $request = null;
        if ($decision['mode'] === 'use' && (int) $decision['certificate_id'] !== $currentCert && ($currentCert === 0 || $broken) && ! ($auto && $currentCert > 0)) {
            $payload['certificate_id'] = (int) $decision['certificate_id'];
            if ($currentCert === 0) {
                $payload += ['ssl_forced' => true, 'http2_support' => true];
            }
            $changes[] = ['field' => 'certificate', 'from' => $currentCert > 0 ? "#{$currentCert}" : 'none', 'to' => $decision['why']];
        } elseif ($decision['mode'] === 'request' && ($currentCert === 0 || $broken)) {
            $request = $this->requestData($decision, $currentCert === 0);
            $changes[] = ['field' => 'certificate', 'from' => $currentCert > 0 ? "#{$currentCert}" : 'none', 'to' => $decision['why']];
        }
        if ($known !== null) {
            $warnings = [...$warnings, ...$this->certificateProblems($known, $domain)];
        } elseif ($currentCert === 0 && $decision['mode'] === 'missing') {
            $warnings[] = "This mirror has no certificate and none is available for {$domain}: browsers will show a certificate warning. ".implode(' ', $decision['warnings']);
        }
        if ($record === null || (int) $record->npm_host_id !== (int) $host['id']) {
            $warnings[] = 'An existing proxy host already serves this name. LabMan takes it over as the mirror of '.$source->name.' and changes it to forward there.';
            $changes[] = ['field' => 'tracking', 'from' => 'not tracked by LabMan', 'to' => 'tracked as the mirror of '.$service->name];
        }

        $hostId = (int) $host['id'];

        return $this->finish($base + [
            'action' => $changes ? 'update' : 'unchanged', 'changes' => $changes,
            'data' => $data + ['host_id' => $hostId, 'payload' => $payload, 'modified_on' => $host['modified_on'] ?? null, 'cert_request' => $request],
        ], [$note, ...array_values(array_unique($warnings))]);
    }

    /** @var list<array{id: int, name: string, domains: list<string>, expires_on: ?string, provider: string}>|null|false false = not read yet */
    private array|null|false $certs = false;

    /** NPM's certificates, read once per sync; null when the list cannot be read (the rest of the plan still works). */
    private function certificates(): ?array
    {
        if ($this->certs === false) {
            try {
                $this->certs = $this->client->certificates();
            } catch (NpmException) {
                $this->certs = null;
            }
        }

        return $this->certs;
    }

    /** Which certificate a host should have, from the service's own setting, the zone's pins and strategy, and what NPM holds. @return array<string, mixed> */
    private function decide(Service $service, string $domain, string $scope, bool $auto): array
    {
        return CertificateChooser::choose($domain, $this->zone->certificatePolicy(), $this->certificates(), $service->cert_mode, $this->certFor($scope), $auto, $this->zone->domainNames());
    }

    /** @param array<string, mixed> $decision @return array{method: string, domains: list<string>, ssl: bool} */
    private function requestData(array $decision, bool $ssl): array
    {
        return ['method' => $decision['method'], 'domains' => $decision['domains'], 'ssl' => $ssl];
    }

    private function untrusted(array $cert): bool
    {
        $policy = $this->zone->certificatePolicy();

        return ! $policy->edgeManaged && in_array($cert['id'], $policy->originIds, true);
    }

    /** What is wrong with a certificate for this name. @return list<string> */
    private function certificateProblems(array $cert, string $host): array
    {
        $w = [];
        if (! Certificates::coversHost($cert, $host)) {
            $w[] = Certificates::label($cert)." does not cover {$host} (it covers ".(implode(', ', $cert['domains']) ?: 'no names').').';
        }
        if (Certificates::expired($cert)) {
            $w[] = Certificates::label($cert).' has expired.';
        } elseif (Certificates::expiring($cert)) {
            $w[] = Certificates::label($cert).' expires in '.Certificates::daysLeft($cert).' day(s).';
        }
        if ($this->untrusted($cert)) {
            $w[] = Certificates::label($cert).' is marked as an origin certificate, which browsers do not trust on a proxy they connect to directly.';
        }

        return $w;
    }

    /**
     * The NPM certificate id new and existing hosts of this kind of domain should use (0 = none). Only the default
     * zone has one so far (the two choices on the NPM page); other zones get theirs with the certificate policies.
     */
    private function certFor(string $scope): int
    {
        return $this->zone->is_default ? (int) Settings::get('npm_cert_'.$scope) : 0;
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
