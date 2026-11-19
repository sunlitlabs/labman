<?php

namespace App\Npm;

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
    public function build(?int $onlyService = null): array
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

        $counts = array_fill_keys(['new_here', 'link_only', 'differs', 'new_there', 'in_sync', 'skipped'], 0);
        foreach ($rows as $row) {
            $counts[$row['kind']]++;
        }

        return ['rows' => $rows, 'counts' => $counts];
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
