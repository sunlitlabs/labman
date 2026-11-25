<?php

namespace App\Npm;

/**
 * One plain-language sentence for a plan item ("Add wiki.example.dev to the LAN proxy, using ..."), so the review
 * reads like a list of things that will happen. The raw changes stay available behind a toggle.
 */
final class PlanText
{
    /** @param array<string, mixed> $item */
    public static function describe(array $item, string $proxy, bool $isImport): string
    {
        $data = $item['data'] ?? [];
        $changes = $item['changes'] ?? [];
        $payload = $data['payload'] ?? [];
        $cert = collect($changes)->firstWhere('field', 'certificate');
        $using = $cert ? ' using '.$cert['to'] : '';
        $title = (string) $item['title'];

        if (($item['action'] ?? '') === 'orphan') {
            return "{$title}: nothing asks for it any more, and it is no longer in NPM as LabMan made it, so LabMan will not delete it. Remove it in NPM yourself if it is still there, then forget it here.";
        }
        if (($item['action'] ?? '') === 'delete') {
            return "Delete proxy host #{$data['host_id']} ({$data['domain']}) from the {$proxy} proxy. LabMan created it and nothing asks for it any more. This cannot be undone from LabMan.";
        }
        if (($item['action'] ?? '') === 'unchanged') {
            return "{$title} already matches.";
        }
        if (($item['action'] ?? '') === 'skip') {
            return "{$title} is left alone.";
        }
        if (! empty($item['errors'])) {
            return "{$title} cannot be applied yet: ".$item['errors'][0];
        }

        $target = isset($payload['forward_host']) ? ($payload['forward_scheme'] ?? 'http').'://'.$payload['forward_host'].':'.($payload['forward_port'] ?? 80) : null;

        if ($isImport) {
            return $item['action'] === 'create'
                ? "Create the service {$title} in LabMan from this proxy host."
                : "Update {$title} in LabMan: ".self::list($changes).'.';
        }

        if (! empty($data['mirror'])) {
            $name = $data['mirror']['domain'];

            return $item['action'] === 'create'
                ? "Add {$name} to the {$proxy} proxy as a mirror, forwarding to {$target}{$using}."
                : "Update the mirror of {$name} on the {$proxy} proxy: ".self::list($changes).'.';
        }

        $name = $payload['domain_names'][0] ?? trim((string) preg_replace('/^.*→\s*/u', '', $title));

        return $item['action'] === 'create'
            ? "Add {$name} to the {$proxy} proxy, forwarding to {$target}{$using}."
            : "Change {$name} on the {$proxy} proxy: ".self::list($changes).'.';
    }

    /** "port from 80 to 8080; certificate from none to #4" @param list<array<string, mixed>> $changes */
    private static function list(array $changes): string
    {
        return implode('; ', array_map(fn ($c) => $c['field'].(($c['from'] ?? '') !== '' && $c['from'] !== null ? ' from '.$c['from'].' to ' : ' to ').$c['to'], $changes)) ?: 'no field changes';
    }

    /** The raw change set for the toggle. @param array<string, mixed> $item */
    public static function raw(array $item): string
    {
        return json_encode(array_filter(['changes' => $item['changes'] ?? [], 'payload' => $item['data']['payload'] ?? null, 'certificate_request' => $item['data']['cert_request'] ?? null], fn ($v) => $v !== null && $v !== []), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '{}';
    }
}
