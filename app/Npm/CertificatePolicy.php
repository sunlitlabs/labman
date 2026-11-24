<?php

namespace App\Npm;

use App\Support\Domains;
use App\Support\Hosts;

/**
 * How a zone's proxy gets certificates. The default is a strategy for hosts that have none; pins say "always use
 * this certificate for names under this domain" (the edge-managed wildcard case); the rest is what a request needs.
 * No secret lives here: a DNS-challenge request borrows the provider settings of an existing certificate in NPM.
 */
final class CertificatePolicy
{
    public const STRATEGIES = ['existing', 'letsencrypt_http', 'letsencrypt_dns', 'none'];

    public const STRATEGY_LABELS = [
        'existing' => 'Use a certificate that already covers the name',
        'letsencrypt_http' => "Let's Encrypt, HTTP challenge (for names reachable from the internet)",
        'letsencrypt_dns' => "Let's Encrypt, DNS challenge (wildcards and names not reachable from the internet)",
        'none' => 'None (plain HTTP)',
    ];

    /**
     * @param  list<array{domain: string, certificate_id: int}>  $pins
     * @param  list<int>  $originIds  certificates on this proxy that browsers do not trust (a CDN's origin certificate)
     */
    public function __construct(
        public readonly string $strategy = 'existing',
        public readonly bool $edgeManaged = false,
        public readonly array $pins = [],
        public readonly array $originIds = [],
        public readonly ?string $email = null,
        public readonly ?int $dnsTemplate = null,
        public readonly bool $wildcard = false,
    ) {}

    public static function fromArray(?array $data): self
    {
        $data ??= [];
        $strategy = in_array($data['strategy'] ?? null, self::STRATEGIES, true) ? $data['strategy'] : 'existing';

        return new self(
            $strategy,
            (bool) ($data['edge_managed'] ?? false),
            array_values(array_filter(array_map(fn ($p) => is_array($p) && isset($p['domain'], $p['certificate_id'])
                ? ['domain' => mb_strtolower((string) $p['domain']), 'certificate_id' => (int) $p['certificate_id']] : null, (array) ($data['pins'] ?? [])))),
            array_values(array_unique(array_map('intval', (array) ($data['origin_certificates'] ?? [])))),
            isset($data['letsencrypt_email']) && $data['letsencrypt_email'] !== '' ? (string) $data['letsencrypt_email'] : null,
            isset($data['dns_template']) && (int) $data['dns_template'] > 0 ? (int) $data['dns_template'] : null,
            (bool) ($data['wildcard'] ?? false),
        );
    }

    /** What is stored (and exported): only the settings that differ from the defaults. @return array<string, mixed> */
    public function toArray(): array
    {
        return array_filter([
            'strategy' => $this->strategy !== 'existing' ? $this->strategy : null,
            'edge_managed' => $this->edgeManaged ?: null,
            'pins' => $this->pins ?: null,
            'origin_certificates' => $this->originIds ?: null,
            'letsencrypt_email' => $this->email,
            'dns_template' => $this->dnsTemplate,
            'wildcard' => $this->wildcard ?: null,
        ], fn ($v) => $v !== null);
    }

    /** The pin with the longest domain that the host sits under, or null. @return array{domain: string, certificate_id: int}|null */
    public function pinFor(string $host): ?array
    {
        $host = mb_strtolower($host);
        $best = null;

        foreach ($this->pins as $pin) {
            if (($host === $pin['domain'] || str_ends_with($host, '.'.$pin['domain'])) && ($best === null || strlen($pin['domain']) > strlen($best['domain']))) {
                $best = $pin;
            }
        }

        return $best;
    }

    /** May this proxy request Let's Encrypt certificates at all? Never behind a CDN that cannot validate the names. */
    public function canRequest(): bool
    {
        return ! $this->edgeManaged && in_array($this->strategy, ['letsencrypt_http', 'letsencrypt_dns'], true);
    }

    /**
     * Clean and validate what a form or YAML file gives.
     *
     * @param  array<string, mixed>  $in  strategy, edge_managed, pins (list of {domain, certificate_id}), origin_certificates, letsencrypt_email, dns_template, wildcard
     * @return array{0: array<string, mixed>, 1: list<string>} the policy to store, and the problems
     */
    public static function validate(array $in): array
    {
        $errors = [];

        $strategy = $in['strategy'] ?? 'existing';
        if (! in_array($strategy, self::STRATEGIES, true)) {
            $errors[] = 'strategy must be one of: '.implode(', ', self::STRATEGIES);
            $strategy = 'existing';
        }

        $edge = filter_var($in['edge_managed'] ?? false, FILTER_VALIDATE_BOOLEAN);
        if ($edge && in_array($strategy, ['letsencrypt_http', 'letsencrypt_dns'], true)) {
            $errors[] = "a zone behind a CDN or tunnel cannot request Let's Encrypt certificates (it cannot validate the names); pin its certificate or use an existing one";
        }

        $pins = [];
        foreach ((array) ($in['pins'] ?? []) as $pin) {
            $domain = is_array($pin) ? ltrim(mb_strtolower(trim((string) ($pin['domain'] ?? ''))), '*.') : '';
            $id = is_array($pin) ? ($pin['certificate_id'] ?? null) : null;
            if ($domain === '' || ! Hosts::isHostname($domain)) {
                $errors[] = "a pinned domain must be a domain name, not '".(is_array($pin) ? ($pin['domain'] ?? '') : '')."'";
            } elseif (! is_numeric($id) || (int) $id < 1) {
                $errors[] = "the pin for {$domain} needs an NPM certificate id";
            } else {
                $pins[$domain] = ['domain' => $domain, 'certificate_id' => (int) $id];
            }
        }

        $origin = [];
        foreach ((array) ($in['origin_certificates'] ?? []) as $id) {
            if (is_numeric($id) && (int) $id > 0) {
                $origin[] = (int) $id;
            } elseif ($id !== '' && $id !== null) {
                $errors[] = "origin certificate ids must be whole numbers, not '{$id}'";
            }
        }

        $email = trim((string) ($in['letsencrypt_email'] ?? ''));
        if ($email !== '' && ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors[] = "'{$email}' is not an e-mail address";
            $email = '';
        }

        $dns = $in['dns_template'] ?? null;
        if ($dns !== null && $dns !== '' && (! is_numeric($dns) || (int) $dns < 1)) {
            $errors[] = 'dns_template must be the id of an existing DNS-challenge certificate in NPM';
            $dns = null;
        }

        $policy = new self($strategy, $edge, array_values($pins), array_values(array_unique($origin)), $email !== '' ? $email : null, $dns ? (int) $dns : null, filter_var($in['wildcard'] ?? false, FILTER_VALIDATE_BOOLEAN));

        return [$policy->toArray(), $errors];
    }

    /** "example.dev=12" lines (or comma separated) from a textarea as pins. @return list<array{domain: string, certificate_id: string}> */
    public static function parsePinText(string $text): array
    {
        $pins = [];
        foreach (preg_split('/[\r\n,;]+/', $text) as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            [$domain, $id] = array_pad(preg_split('/\s*[=:]\s*|\s+/', $line, 2), 2, '');
            $pins[] = ['domain' => $domain, 'certificate_id' => $id];
        }

        return $pins;
    }

    /** The pins back as text for the form. */
    public function pinText(): string
    {
        return implode("\n", array_map(fn ($p) => $p['domain'].' = '.$p['certificate_id'], $this->pins));
    }

    /** Domain names the zone requests a wildcard for: the one the host sits under. @param list<string> $zoneDomains */
    public static function wildcardFor(string $host, array $zoneDomains): ?string
    {
        $best = null;
        foreach (Domains::parse($zoneDomains) as $domain) {
            if (($host === $domain || str_ends_with($host, '.'.$domain)) && ($best === null || strlen($domain) > strlen($best))) {
                $best = $domain;
            }
        }

        return $best;
    }
}
