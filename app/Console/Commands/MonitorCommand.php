<?php

namespace App\Console\Commands;

use App\Monitoring\HealthChecker;
use App\Support\Settings;
use Illuminate\Console\Command;
use Throwable;

class MonitorCommand extends Command
{
    protected $signature = 'labman:monitor {--once : Run a single round and exit}';

    protected $description = 'Resident health-check loop: runs a round, sleeps for the configured interval, repeats';

    private bool $stop = false;

    public function handle(HealthChecker $checker): int
    {
        if (function_exists('pcntl_async_signals')) {
            pcntl_async_signals(true);
            foreach ([SIGTERM, SIGINT] as $signal) {
                pcntl_signal($signal, fn () => $this->stop = true);
            }
        }

        do {
            $started = microtime(true);

            try {
                $summary = $checker->run();
                $this->line(sprintf(
                    '[%s] checked %d server(s), %d service(s) in %.2fs',
                    now()->toTimeString(), $summary['servers'], $summary['services'], $summary['seconds'],
                ));
            } catch (Throwable $e) {
                // A failed round must never end the loop; the entrypoint would restart us anyway.
                report($e);
                $this->error('round failed: '.$e->getMessage());
            }

            if ($this->option('once')) {
                break;
            }

            // Settings are re-read here, so interval changes apply without a restart.
            Settings::flush();
            $wake = $started + Settings::interval();

            while (! $this->stop && microtime(true) < $wake) {
                usleep(200_000);
            }
        } while (! $this->stop);

        return self::SUCCESS;
    }
}
