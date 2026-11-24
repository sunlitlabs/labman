<?php

namespace App\Dns;

/**
 * A DNS plan as text you can paste somewhere: a readable plan, a hosts-style list, AdGuard Home rewrites, or JSON.
 * Records still waiting for an address (a public record with no public address set) are listed in the readable
 * plan only; the machine formats leave them out rather than write a record that points nowhere.
 */
final class DnsExport
{
    public const FORMATS = ['text' => 'Readable plan', 'hosts' => 'Hosts-style list', 'adguard' => 'AdGuard Home rewrites', 'json' => 'JSON'];

    /** @param array{records: list<array<string, mixed>>, conflicts: list<array<string, mixed>>} $plan */
    public static function render(array $plan, string $format): string
    {
        return match ($format) {
            'hosts' => self::hosts($plan),
            'adguard' => self::adguard($plan),
            'json' => self::json($plan),
            default => self::text($plan),
        };
    }

    public static function mime(string $format): string
    {
        return $format === 'json' ? 'application/json; charset=UTF-8' : 'text/plain; charset=UTF-8';
    }

    public static function extension(string $format): string
    {
        return match ($format) {'json' => 'json', 'hosts' => 'hosts.txt', 'adguard' => 'adguard.txt', default => 'txt'};
    }

    private static function text(array $plan): string
    {
        $out = ["# LabMan DNS plan. Nothing here has been written anywhere.\n"];

        foreach (['inside' => 'Inside resolver (your network)', 'public' => 'Public provider (the internet)'] as $resolver => $title) {
            $rows = array_filter($plan['records'], fn ($r) => $r['resolver'] === $resolver);
            $out[] = "## {$title}";
            if ($rows === []) {
                $out[] = '(no records)';
            }
            foreach ($rows as $r) {
                $out[] = sprintf('%-45s %-6s %-30s  [%s, %s]%s', $r['name'], $r['type'], $r['value'] ?? '(set the public address)', $r['zone'], $r['kind'], $r['note'] !== '' ? '  '.$r['note'] : '');
            }
            $out[] = '';
        }

        if ($plan['conflicts']) {
            $out[] = '## Conflicts and notes';
            foreach ($plan['conflicts'] as $c) {
                $out[] = '['.$c['severity'].'] '.$c['message'];
            }
        }

        return implode("\n", $out)."\n";
    }

    private static function hosts(array $plan): string
    {
        $out = ["# Hosts-style list of the inside records. A hosts file cannot hold wildcards or CNAMEs; those are comments.\n"];

        foreach ($plan['records'] as $r) {
            if ($r['resolver'] !== 'inside' || $r['value'] === null) {
                continue;
            }
            $out[] = $r['type'] === 'A' || $r['type'] === 'AAAA' ? ($r['kind'] === 'wildcard' ? "# {$r['value']} {$r['name']}  (wildcard: use a resolver)" : "{$r['value']} {$r['name']}") : "# {$r['name']} CNAME {$r['value']}  (hosts files have no CNAME)";
        }

        return implode("\n", $out)."\n";
    }

    private static function adguard(array $plan): string
    {
        $out = ['! AdGuard Home DNS rewrites for the inside records (one filtering rule each). Check them against your AdGuard Home version.'];

        foreach ($plan['records'] as $r) {
            if ($r['resolver'] !== 'inside' || $r['value'] === null) {
                continue;
            }
            $answer = $r['type'] === 'CNAME' ? 'CNAME;'.$r['value'] : $r['type'].';'.$r['value'];
            $out[] = '|'.$r['name'].'^$dnsrewrite=NOERROR;'.$answer;
        }

        return implode("\n", $out)."\n";
    }

    private static function json(array $plan): string
    {
        $records = array_map(fn ($r) => array_intersect_key($r, array_flip(['name', 'type', 'value', 'kind', 'zone', 'resolver', 'note'])), array_values(array_filter($plan['records'], fn ($r) => $r['value'] !== null)));

        return json_encode(['records' => $records, 'conflicts' => $plan['conflicts']], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)."\n";
    }
}
