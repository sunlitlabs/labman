<?php

namespace App\Npm;

use App\Models\Server;
use App\Models\Service;
use App\Support\DomainSuggestions;
use App\Support\Domains;

/**
 * The guided view of an NPM sync: instead of two separate previews (import and push), one list of "things to
 * match up", each with sensible default. It only reads the two plans NpmSync already builds, and it applies
 * through NpmSync with the same fingerprints, so it can never write anything the admin was not shown.
 *
 * Row kinds and their defaults:
 *   new_here   a proxy host with no service         -> import it (adds a service; NPM untouched)
 *   link_only  matched by domain but not yet linked -> link them (LabMan only)
 *   differs    linked, but the settings differ      -> use NPM's values (LabMan only; NPM is what really routes)
 *   new_there  a service with a domain, no host     -> leave alone (creating proxy hosts is opt-in)
 *   in_sync / skipped                               -> nothing to choose
 */
final class NpmWizard
{
    public function __construct(private readonly NpmSync $sync) {}

    /**
     * @return array{rows: list<array<string, mixed>>, counts: array<string, int>}
     */
    public function build(?int $onlyService = null, bool $suggestDomains = false): array
    {
        $import = collect($this->sync->importPlan());
        $push = collect($this->sync->pushPlan())->keyBy('key');
        $rows = [];
        $coveredPush = [];

        foreach ($import as $item) {
            $serviceId = $item['data']['service_id'] ?? null;
            $pushKey = ($item['data']['scope'] ?? 'local') === 'public' ? "svc:{$serviceId}:public" : "svc:{$serviceId}";
            $pushItem = $serviceId ? $push->get($pushKey) : null;

            if ($pushItem) {
                $coveredPush[$pushItem['key']] = true;
            }
            if ($onlyService !== null && $serviceId !== $onlyService) {
                continue;
            }

            $rows[] = $this->row($item, $pushItem);
        }

        foreach ($push as $key => $item) {
            if (isset($coveredPush[$key])) {
                continue;
            }
            if ($onlyService !== null && ($item['data']['service_id'] ?? null) !== $onlyService) {
                continue;
            }

            $rows[] = $item['action'] === 'create' ? $this->serviceOnly($item) : $this->plain($item, 'skipped');
        }

        if ($suggestDomains) {
            array_push($rows, ...$this->domainRows($onlyService));
        }

        $counts = array_fill_keys(['new_here', 'link_only', 'differs', 'new_there', 'suggest_domain', 'in_sync', 'skipped'], 0);
        foreach ($rows as $row) {
            $counts[$row['kind']]++;
        }

        return ['rows' => $rows, 'counts' => $counts];
    }

    /**
     * Opt-in rows: a local domain guessed from the name for every service and server without one. Applying one only
     * saves the name in LabMan; a later push can then create its proxy host.
     *
     * @return list<array<string, mixed>>
     */
    private function domainRows(?int $onlyService): array
    {
        $rows = [];
        $suggestions = DomainSuggestions::all();

        foreach ([['services', 'svc', Service::class], ['servers', 'srv', Server::class]] as [$kind, $prefix, $model]) {
            foreach ($suggestions[$kind] as $id => $name) {
                if ($onlyService !== null && ($prefix !== 'svc' || $id !== $onlyService)) {
                    continue;
                }
                $label = $model::find($id)?->name;
                $rows[] = [
                    'id' => "suggest:{$prefix}:{$id}", 'kind' => 'suggest_domain', 'title' => ($prefix === 'svc' ? 'Service ' : 'Server ').$label,
                    'import' => null, 'push' => null, 'default' => 'apply', 'warnings' => [],
                    'changes' => [['field' => 'domain', 'from' => null, 'to' => $name]],
                    'options' => ['apply' => 'Give it this local name', 'skip' => 'Leave it without'],
                ];
            }
        }

        return $rows;
    }

    /**
     * Save the suggested names the admin kept ticked. The name is worked out again here, never taken from the form.
     *
     * @param  array<string, string>  $choices
     * @return list<array{title: string, outcome: string, message: string}>
     */
    public static function applyDomainChoices(array $choices, array $edited = []): array
    {
        $wanted = array_keys(array_filter($choices, fn ($c, $id) => $c === 'apply' && str_starts_with($id, 'suggest:'), ARRAY_FILTER_USE_BOTH));
        if ($wanted === []) {
            return [];
        }

        $suggestions = DomainSuggestions::all();
        $results = [];

        foreach ($wanted as $id) {
            [, $prefix, $key] = explode(':', $id) + [null, null, null];
            $model = $prefix === 'svc' ? Service::find((int) $key) : ($prefix === 'srv' ? Server::find((int) $key) : null);
            $name = $suggestions[$prefix === 'svc' ? 'services' : 'servers'][(int) $key] ?? null;

            // The person may have edited the suggested name; use theirs when it is a valid, unused host name.
            if ($model !== null && isset($edited[$id]) && trim((string) $edited[$id]) !== '') {
                $given = Domains::parse((string) $edited[$id]);
                if (count($given) !== 1 || Domains::firstInvalid($given) !== null) {
                    $results[] = ['title' => $model->name, 'outcome' => 'failed', 'message' => '"'.trim((string) $edited[$id]).'" is not a valid domain name.'];

                    continue;
                }
                if ($given[0] !== $name && self::nameTaken($given[0], $model)) {
                    $results[] = ['title' => $model->name, 'outcome' => 'failed', 'message' => "{$given[0]} is already used by something else."];

                    continue;
                }
                $name = $given[0];
            }

            if ($model === null || $name === null) {
                $results[] = ['title' => $id, 'outcome' => 'failed', 'message' => 'That suggestion no longer applies (the item changed or the name is taken).'];

                continue;
            }

            $model->forceFill(['domain' => $name])->save();
            $results[] = ['title' => $model->name, 'outcome' => 'applied', 'message' => "Local domain set to {$name}."];
        }

        return $results;
    }

    private static function nameTaken(string $name, $except): bool
    {
        foreach ([...Service::all(), ...Server::all()] as $item) {
            if ($item::class === $except::class && $item->id === $except->id) {
                continue;
            }
            foreach (Domains::namesOf($item) as $list) {
                if (in_array($name, $list, true)) {
                    return true;
                }
            }
        }

        return false;
    }

    /** @param array<string, mixed> $import @param array<string, mixed>|null $push */
    private function row(array $import, ?array $push): array
    {
        $base = ['id' => $import['key'], 'title' => $import['title'], 'import' => $import, 'push' => $push, 'warnings' => $import['warnings']];

        if ($import['action'] === 'skip') {
            return $base + ['kind' => 'skipped', 'default' => 'skip', 'options' => [], 'changes' => [], 'reason' => implode(' ', $import['warnings'])];
        }

        if ($import['action'] === 'create') {
            return $base + [
                'kind' => 'new_here', 'default' => 'import', 'changes' => $import['changes'],
                'options' => ['import' => 'Add it to LabMan', 'skip' => 'Leave it out'],
            ];
        }

        $nonLink = fn (array $item) => array_values(array_filter($item['changes'], fn ($c) => $c['field'] !== 'link'));
        $differences = $nonLink($import);
        $pushChanges = $push ? $nonLink($push) : [];

        if ($differences === [] && $pushChanges === []) {
            return $import['action'] === 'update'
                ? $base + ['kind' => 'link_only', 'default' => 'import', 'changes' => $import['changes'], 'options' => ['import' => 'Link them', 'skip' => 'Not now']]
                : $base + ['kind' => 'in_sync', 'default' => 'skip', 'options' => [], 'changes' => []];
        }

        $options = ['import' => "Use NPM's values (updates LabMan)"];
        if ($push && in_array($push['action'], ['update'], true)) {
            $options['push'] = "Use LabMan's values (updates NPM)";
        }
        $options['skip'] = 'Leave both as they are';

        return $base + ['kind' => 'differs', 'default' => 'import', 'changes' => $import['changes'], 'push_changes' => $push['changes'] ?? [], 'options' => $options];
    }

    /** @param array<string, mixed> $push */
    private function serviceOnly(array $push): array
    {
        return [
            'id' => $push['key'], 'title' => $push['title'], 'kind' => 'new_there', 'import' => null, 'push' => $push, 'default' => 'skip',
            'changes' => $push['changes'], 'warnings' => $push['warnings'],
            'options' => ['skip' => 'Leave it alone', 'push' => 'Create the proxy host in NPM'],
        ];
    }

    /** @param array<string, mixed> $item */
    private function plain(array $item, string $kind): array
    {
        return [
            'id' => $item['key'], 'title' => $item['title'], 'kind' => $kind, 'import' => null, 'push' => $item, 'default' => 'skip',
            'options' => [], 'changes' => [], 'warnings' => [], 'reason' => implode(' ', $item['warnings']),
        ];
    }

    /**
     * Turn the submitted choices into the selections NpmSync applies.
     *
     * Everything is keyed by row id; a row's import item and push item have their own keys and fingerprints,
     * which the form posts alongside the choice.
     *
     * @param  array<string, string>  $choices  row id => import|push|skip
     * @param  array<string, string>  $importFp  row id => fingerprint of the import item shown
     * @param  array<string, string>  $pushKey  row id => key of the push item
     * @param  array<string, string>  $pushFp  row id => fingerprint of the push item shown
     * @return array{import: array<string, string>, push: array<string, string>}
     */
    public static function selections(array $choices, array $importFp, array $pushKey, array $pushFp): array
    {
        $import = [];
        $push = [];

        foreach ($choices as $id => $choice) {
            if ($choice === 'import' && isset($importFp[$id])) {
                $import[$id] = $importFp[$id];
            } elseif ($choice === 'push' && isset($pushKey[$id], $pushFp[$id])) {
                $push[$pushKey[$id]] = $pushFp[$id];
            }
        }

        return ['import' => $import, 'push' => $push];
    }
}
