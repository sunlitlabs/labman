<?php

namespace App\Npm;

/**
 * Decides which certificate a proxy host should use, without touching NPM: a certificate that already covers the
 * name always beats requesting a new one, an explicit choice (the host's own setting, a pin for its domain, the
 * default-zone certificate chosen on the NPM page) beats the zone's strategy, and a certificate that browsers do not
 * trust (a CDN origin certificate) is never picked for a proxy that browsers talk to directly.
 *
 * The result says what to do and why; the caller turns it into a preview line and, on apply only, a request.
 */
final class CertificateChooser
{
    /**
     * @param  list<array{id: int, name: string, domains: list<string>, expires_on: ?string}>|null  $certs  null when NPM's list could not be read
     * @param  string|null  $override  the host's own setting: none | letsencrypt_http | letsencrypt_dns | existing:<id>
     * @param  int  $legacyId  the certificate the default zone chose on the NPM page for this kind of domain (0 = none)
     * @param  bool  $auto  automatic updates never request a new certificate
     * @param  list<string>  $zoneDomains
     * @return array{mode: 'use'|'request'|'none'|'missing', certificate_id: ?int, method: ?string, domains: list<string>, explicit: bool, why: string, warnings: list<string>}
     */
    public static function choose(string $host, CertificatePolicy $policy, ?array $certs, ?string $override = null, int $legacyId = 0, bool $auto = false, array $zoneDomains = []): array
    {
        $host = mb_strtolower($host);
        $trusted = ! $policy->edgeManaged; // browsers talk to this proxy directly, so an origin certificate is no good
        $byId = collect($certs ?? [])->keyBy('id');
        $decision = fn (string $mode, array $extra = []) => $extra + ['mode' => $mode, 'certificate_id' => null, 'method' => null, 'domains' => [], 'explicit' => false, 'why' => '', 'warnings' => []];

        // The list could not be read: keep the older behaviour (an explicitly chosen certificate), and say nothing else.
        if ($certs === null) {
            return $legacyId > 0
                ? $decision('use', ['certificate_id' => $legacyId, 'explicit' => true, 'why' => "certificate #{$legacyId}"])
                : $decision('none', ['warnings' => ["NPM's certificate list could not be read, so no certificate was chosen for {$host}."]]);
        }

        $problems = function (array $cert) use ($host, $policy, $trusted): array {
            $w = [];
            if (! Certificates::coversHost($cert, $host)) {
                $w[] = Certificates::label($cert)." does not cover {$host} (it covers ".(implode(', ', $cert['domains']) ?: 'no names').').';
            }
            if (Certificates::expired($cert)) {
                $w[] = Certificates::label($cert).' has expired.';
            } elseif (Certificates::expiring($cert)) {
                $w[] = Certificates::label($cert).' expires in '.Certificates::daysLeft($cert).' day(s).';
            }
            if ($trusted && in_array($cert['id'], $policy->originIds, true)) {
                $w[] = Certificates::label($cert).' is marked as an origin certificate, which browsers do not trust on a proxy they connect to directly.';
            }

            return $w;
        };

        // 1. The host's own setting.
        if ($override !== null && $override !== '') {
            if ($override === 'none') {
                return $decision('none', ['explicit' => true, 'why' => 'no certificate (set on the service)']);
            }
            if (preg_match('/^existing:(\d+)$/', $override, $m)) {
                $cert = $byId->get((int) $m[1]);

                return $cert
                    ? $decision('use', ['certificate_id' => $cert['id'], 'explicit' => true, 'why' => Certificates::label($cert).' (set on the service)', 'warnings' => $problems($cert)])
                    : $decision('missing', ['explicit' => true, 'warnings' => ["The certificate #{$m[1]} chosen on the service does not exist in this proxy."]]);
            }
            if (in_array($override, ['letsencrypt_http', 'letsencrypt_dns'], true)) {
                return self::viaLetsEncrypt($host, $override, $policy, $trusted, $certs, $auto, $zoneDomains, true, $decision);
            }
        }

        // 2. A pin: always this certificate for names under the domain.
        if ($pin = $policy->pinFor($host)) {
            $cert = $byId->get($pin['certificate_id']);
            if ($cert === null) {
                return $decision('missing', ['explicit' => true, 'warnings' => ["The certificate #{$pin['certificate_id']} pinned for {$pin['domain']} does not exist in this proxy."]]);
            }
            if ($trusted && in_array($cert['id'], $policy->originIds, true)) {
                return $decision('missing', ['explicit' => true, 'warnings' => [Certificates::label($cert)." is pinned for {$pin['domain']} but marked as an origin certificate, so it is not used on a proxy that browsers connect to directly."]]);
            }

            return $decision('use', ['certificate_id' => $cert['id'], 'explicit' => true, 'why' => Certificates::label($cert)." (pinned for {$pin['domain']})", 'warnings' => $problems($cert)]);
        }

        // 3. The default zone's choice on the NPM page.
        if ($legacyId > 0) {
            $cert = $byId->get($legacyId);

            return $decision('use', ['certificate_id' => $legacyId, 'explicit' => true, 'why' => $cert ? Certificates::label($cert) : "certificate #{$legacyId}", 'warnings' => $cert ? $problems($cert) : []]);
        }

        // 4. The zone's strategy.
        if ($policy->strategy === 'none') {
            return $decision('none', ['why' => 'no certificate (zone policy)']);
        }

        if ($policy->strategy === 'existing') {
            $cover = self::covering($host, $certs, $policy, $trusted);

            return $cover
                ? $decision('use', ['certificate_id' => $cover['id'], 'why' => Certificates::label($cover).' (already covers '.$host.')', 'warnings' => $problems($cover)])
                : $decision('missing', ['warnings' => ["No certificate in this proxy covers {$host}. Add one in NPM, or let the zone request one (zone certificate settings)."]]);
        }

        return self::viaLetsEncrypt($host, $policy->strategy, $policy, $trusted, $certs, $auto, $zoneDomains, false, $decision);
    }

    /** @return array<string, mixed> */
    private static function viaLetsEncrypt(string $host, string $method, CertificatePolicy $policy, bool $trusted, array $certs, bool $auto, array $zoneDomains, bool $explicit, \Closure $decision): array
    {
        // A certificate that already covers the name is always preferred; a duplicate is never requested.
        if ($cover = self::covering($host, $certs, $policy, $trusted)) {
            return $decision('use', ['certificate_id' => $cover['id'], 'explicit' => $explicit, 'why' => Certificates::label($cover).' (already covers '.$host.')']);
        }

        if ($policy->edgeManaged) {
            return $decision('missing', ['warnings' => ["{$host} sits behind a CDN or tunnel, which cannot validate a Let's Encrypt request. Pin the CDN's certificate for the domain instead."]]);
        }
        if ($auto) {
            return $decision('missing', ['warnings' => ["No certificate covers {$host}. A Let's Encrypt certificate is requested only from the preview, not by automatic updates."]]);
        }
        if ($policy->email === null) {
            return $decision('missing', ['warnings' => ["Set an e-mail address for Let's Encrypt in the zone's certificate settings to request a certificate for {$host}."]]);
        }
        if ($method === 'letsencrypt_dns' && $policy->dnsTemplate === null) {
            return $decision('missing', ['warnings' => ["Choose an existing DNS-challenge certificate in the zone's certificate settings, so its DNS provider can be reused for {$host}."]]);
        }

        $domains = [$host];
        if ($method === 'letsencrypt_dns' && $policy->wildcard && ($owner = CertificatePolicy::wildcardFor($host, $zoneDomains)) !== null) {
            $domains = ['*.'.$owner, $owner]; // one wildcard certificate then covers the zone's other hosts too
        }

        return $decision('request', [
            'method' => $method, 'domains' => $domains, 'explicit' => $explicit,
            'why' => "a new Let's Encrypt certificate (".($method === 'letsencrypt_dns' ? 'DNS' : 'HTTP').' challenge) for '.implode(', ', $domains),
        ]);
    }

    /**
     * The best usable certificate that covers the host: not expired, not an untrusted origin certificate when
     * browsers connect directly, then not about to expire, an exact name over a wildcard, and the longest-lived.
     *
     * @param  list<array{id: int, name: string, domains: list<string>, expires_on: ?string}>  $certs
     * @return array{id: int, name: string, domains: list<string>, expires_on: ?string}|null
     */
    public static function covering(string $host, array $certs, CertificatePolicy $policy, bool $trusted): ?array
    {
        $candidates = array_values(array_filter($certs, fn ($c) => Certificates::coversHost($c, $host)
            && ! Certificates::expired($c)
            && ! ($trusted && in_array($c['id'], $policy->originIds, true))));

        usort($candidates, fn ($a, $b) => [Certificates::expiring($a), ! in_array(mb_strtolower($host), array_map('mb_strtolower', $a['domains']), true), -($a['expires_on'] ? strtotime($a['expires_on']) : PHP_INT_MAX)]
            <=> [Certificates::expiring($b), ! in_array(mb_strtolower($host), array_map('mb_strtolower', $b['domains']), true), -($b['expires_on'] ? strtotime($b['expires_on']) : PHP_INT_MAX)]);

        return $candidates[0] ?? null;
    }
}
