<?php

namespace App\Console\Commands;

use App\Models\Server;
use App\Models\Service;
use App\Monitoring\HealthChecker;
use Illuminate\Console\Command;

class CheckCommand extends Command
{
    protected $signature = 'labman:check';

    protected $description = 'Run a single health-check round now and print the results';

    public function handle(HealthChecker $checker): int
    {
        $summary = $checker->run();

        $rows = [];
        foreach (Server::orderBy('name')->get() as $s) {
            $rows[] = ['server', $s->name, $s->state, $this->details($s->checks)];
        }
        foreach (Service::orderBy('name')->get() as $s) {
            $rows[] = ['service', $s->name, $s->state, $this->details($s->checks)];
        }

        $this->table(['Kind', 'Name', 'State', 'Checks'], $rows);
        $this->info(sprintf('Done in %.2fs.', $summary['seconds']));

        return self::SUCCESS;
    }

    private function details(?array $checks): string
    {
        return collect($checks ?? [])
            ->map(fn ($c) => "{$c['label']}: {$c['detail']}".($c['ms'] !== null ? " ({$c['ms']} ms)" : ''))
            ->implode('; ');
    }
}
