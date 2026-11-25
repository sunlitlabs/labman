<?php

namespace App\Dns;

/**
 * Compares the "inside" records of the DNS plan with the DNS rewrites in AdGuard Home, and applies the ones an
 * admin approved. Like the NPM push: a preview lists every change with a fingerprint, apply recomputes the plan and
 * refuses any item whose fingerprint no longer matches, and nothing is ever deleted. Rewrites LabMan has no record
 * for are left alone (only counted).
 *
 *   create     no rewrite of that name and type exists
 *   update     exactly one rewrite of that name and type exists with another answer: its answer is changed
 *   unchanged  the rewrite is already there
 *   skip       cannot be decided safely (several rewrites of that name and type): the reason is an error
 */
final class AdguardSync
{
    /** @param list<array<string, mixed>>|null $records the planned records; null builds the DNS plan */
    public function __construct(private readonly AdguardClient $client, private readonly ?array $records = null) {}

    /**
     * @return array{items: list<array<string, mixed>>, others: int}
     */
    public function plan(): array
    {
        $existing = $this->client->rewrites();
        $wanted = [];

        foreach ($this->records ?? DnsPlan::build()['records'] as $r) {
            if ($r['resolver'] === 'inside' && $r['value'] !== null) {
                $wanted[$r['name'].'|'.$r['type']] ??= $r;
            }
        }

        $byDomain = [];
        $rawOf = [];
        foreach ($existing as $row) {
            $byDomain[$row['domain']][] = $row['answer'];
            $rawOf[$row['domain']] = $row['raw'];
        }

        $items = [];
        $touched = [];

        foreach ($wanted as $key => $r) {
            $domain = $r['name'];
            $answers = $byDomain[$domain] ?? [];
            $sameType = array_values(array_filter($answers, fn ($a) => self::typeOf($a) === $r['type']));
            $otherType = array_values(array_filter($answers, fn ($a) => self::typeOf($a) !== $r['type']));
            $touched[$domain] = true;

            $warnings = [];
            $errors = [];
            if ($otherType !== []) {
                $warnings[] = 'AdGuard also has '.implode(', ', array_map(fn ($a) => self::typeOf($a).' '.$a, $otherType)).' for this name. A CNAME next to other answers can make the name resolve unpredictably.';
            }
            if ($r['kind'] === 'wildcard' && str_starts_with($domain, '*.') === false) {
                $warnings[] = 'This is not written as *.domain, so AdGuard treats it as one exact name.';
            }

            $base = ['key' => $key, 'title' => $domain, 'type' => $r['type'], 'zone' => $r['zone'], 'warnings' => $warnings, 'errors' => $errors];

            if (in_array($r['value'], $sameType, true)) {
                $items[] = $this->finish($base + ['action' => 'unchanged', 'changes' => [], 'data' => ['domain' => $domain, 'answer' => $r['value']]]);
            } elseif ($sameType === []) {
                $items[] = $this->finish($base + ['action' => 'create', 'changes' => [['field' => $r['type'], 'from' => null, 'to' => $r['value']]], 'data' => ['domain' => $domain, 'answer' => $r['value']]]);
            } elseif (count($sameType) === 1) {
                $items[] = $this->finish($base + ['action' => 'update', 'changes' => [['field' => $r['type'], 'from' => $sameType[0], 'to' => $r['value']]], 'data' => ['domain' => $domain, 'answer' => $r['value'], 'old' => $sameType[0], 'old_domain' => $rawOf[$domain] ?? $domain]]);
            } else {
                $base['errors'][] = 'AdGuard has '.count($sameType).' '.$r['type'].' rewrites for this name ('.implode(', ', $sameType).'). Remove the extra ones in AdGuard Home, then run the preview again.';
                $items[] = $this->finish($base + ['action' => 'skip', 'changes' => [], 'data' => ['domain' => $domain, 'answer' => $r['value']]]);
            }
        }

        $others = count(array_filter($existing, fn ($row) => ! isset($touched[$row['domain']])));

        return ['items' => $items, 'others' => $others];
    }

    /**
     * Apply the approved items. One failing does not stop the rest.
     *
     * @param  array<string, string>  $selected  item key => fingerprint shown in the preview
     * @return array{applied: int, results: list<array{key: string, title: string, outcome: string, message: string}>}
     *
     * @throws AdguardException when an item changed since the preview, or cannot be applied
     */
    public function apply(array $selected): array
    {
        $byKey = collect($this->plan()['items'])->keyBy('key');
        $chosen = [];

        foreach ($selected as $key => $fingerprint) {
            $item = $byKey->get($key);

            if ($item === null || ! in_array($item['action'], ['create', 'update'], true) || ! hash_equals($item['fingerprint'], (string) $fingerprint)) {
                throw new AdguardException("'{$key}' changed since the preview was shown (in LabMan or in AdGuard Home), so nothing was applied. Run the preview again.");
            }
            if (! empty($item['errors'])) {
                throw new AdguardException("'{$key}' has an error and cannot be applied: ".$item['errors'][0]);
            }
            $chosen[] = $item;
        }

        if ($chosen === []) {
            throw new AdguardException('Select at least one previewed change to apply.');
        }

        $results = [];
        $applied = 0;

        foreach ($chosen as $item) {
            $d = $item['data'];
            try {
                if ($item['action'] === 'create') {
                    $this->client->add($d['domain'], $d['answer']);
                    $message = "Added {$d['domain']} → {$d['answer']}.";
                } else {
                    $this->client->change($d['old_domain'], $d['old'], $d['domain'], $d['answer']);
                    $message = "Changed {$d['domain']} from {$d['old']} to {$d['answer']}.";
                }
                $applied++;
                $results[] = ['key' => $item['key'], 'title' => $item['title'], 'outcome' => $item['action'] === 'create' ? 'created' : 'updated', 'message' => $message];
            } catch (AdguardException $e) {
                $results[] = ['key' => $item['key'], 'title' => $item['title'], 'outcome' => 'failed', 'message' => $e->getMessage()];
            }
        }

        return ['applied' => $applied, 'results' => $results];
    }

    /** 'A', 'AAAA' or 'CNAME' for a rewrite answer. */
    public static function typeOf(string $answer): string
    {
        return match (true) {
            filter_var($answer, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false => 'A',
            filter_var($answer, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false => 'AAAA',
            default => 'CNAME',
        };
    }

    /** @param array<string, mixed> $item @return array<string, mixed> */
    private function finish(array $item): array
    {
        $item['fingerprint'] = sha1(json_encode([$item['key'], $item['action'], $item['changes'], $item['data'] ?? null]));

        return $item;
    }
}
