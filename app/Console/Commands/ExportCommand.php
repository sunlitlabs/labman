<?php

namespace App\Console\Commands;

use App\Config\YamlConfig;
use Illuminate\Console\Command;

class ExportCommand extends Command
{
    protected $signature = 'labman:export {path : File to write the YAML to} {--suggest-domains : Also suggest a local domain for every service and server without one}';

    protected $description = 'Export settings, servers and services to a YAML file';

    public function handle(): int
    {
        $path = $this->argument('path');

        if (@file_put_contents($path, YamlConfig::export((bool) $this->option('suggest-domains'))) === false) {
            $this->error("Cannot write {$path}");

            return self::FAILURE;
        }

        $this->info("Wrote {$path}");

        return self::SUCCESS;
    }
}
